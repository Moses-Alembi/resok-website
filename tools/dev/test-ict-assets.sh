#!/usr/bin/env bash
#
# Exercises the asset lifecycle through the running API.
#
# The claims worth testing are about state, not fields: an asset cannot be handed to two
# people at once, its history survives being reassigned, a damaged return does not go
# straight back on the shelf, and assets.view cannot write.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.130s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('a-view@local','a-manage@local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('a-view@local','$HASH',1,'ict'),('a-manage@local','$HASH',1,'ict');" >/dev/null
VID=$($MYSQL -N -e "SELECT id FROM users WHERE email='a-view@local';")
MID=$($MYSQL -N -e "SELECT id FROM users WHERE email='a-manage@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($VID,'assets.view'),
 ($MID,'assets.view'),($MID,'assets.manage'),($MID,'assets.assign'),($MID,'maintenance.manage');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

VIEWER=$(login a-view@local)
MANAGER=$(login a-manage@local)

echo "Creating:"
OUT=$(post "$MANAGER" 'ict/assets' '{"category":"laptop","name":"Dell Latitude 5420","manufacturer":"Dell","serialNumber":"SN-TEST-001","purchaseDate":"2025-03-10","purchaseCost":"85000","warrantyExpiresOn":"2028-03-10","condition":"good"}')
check "a manager can create"            '"assetTag"'   "$OUT"
check "tag is issued per category"      'ICT-LAP-'     "$OUT"
check "a viewer cannot create"          'assets.manage' "$(post "$VIEWER" 'ict/assets' '{"category":"laptop","name":"Sneaky"}')"
check "warranty before purchase refused" 'cannot expire' \
      "$(post "$MANAGER" 'ict/assets' '{"category":"laptop","name":"Bad dates","purchaseDate":"2025-06-01","warrantyExpiresOn":"2025-01-01"}')"
check "a nameless asset is refused"     'name'          "$(post "$MANAGER" 'ict/assets' '{"category":"laptop"}')"

AID=$($MYSQL -N -e "SELECT id FROM ict_assets WHERE serial_number='SN-TEST-001' LIMIT 1;")
TAG=$($MYSQL -N -e "SELECT asset_tag FROM ict_assets WHERE id=$AID;")

echo
echo "Lookup:"
check "found by numeric id"             '"assetTag"'  "$(get "$MANAGER" "ict/assets/$AID")"
check "found by the tag on the sticker" "$TAG"        "$(get "$MANAGER" "ict/assets/$TAG")"
check "an unknown tag 404s"             'No asset'    "$(get "$MANAGER" 'ict/assets/ICT-LAP-9999')"

echo
echo "Assignment:"
check "assigned to a person"            '"holder"'    \
      "$(post "$MANAGER" "ict/assets/$AID/assign" '{"holderName":"Jane Mwangi","holderEmail":"jane@example.org","department":"Finance","conditionOut":"good"}')"
check "status became assigned"          'assigned'    "$($MYSQL -N -e "SELECT status FROM ict_assets WHERE id=$AID;")"
check "CANNOT be handed to a second person" 'Record its return first' \
      "$(post "$MANAGER" "ict/assets/$AID/assign" '{"holderName":"Someone Else"}')"
check "a viewer cannot assign"          'assets.assign' \
      "$(post "$VIEWER" "ict/assets/$AID/assign" '{"holderName":"Nope"}')"
check "shows in what that person holds" "$TAG" \
      "$(get "$MANAGER" 'ict/assets/held-by&email=jane@example.org')"

echo
echo "Return in poor condition:"
check "return recorded"                 '"holder":null' \
      "$(post "$MANAGER" "ict/assets/$AID/return" '{"conditionIn":"damaged","notes":"Screen cracked"}')"
check "damaged goes to maintenance, not the shelf" 'maintenance' \
      "$($MYSQL -N -e "SELECT status FROM ict_assets WHERE id=$AID;")"
check "condition followed the return"   'damaged' \
      "$($MYSQL -N -e "SELECT \`condition\` FROM ict_assets WHERE id=$AID;")"
check "returning twice is refused"      'Nobody is currently holding' \
      "$(post "$MANAGER" "ict/assets/$AID/return" '{"conditionIn":"good"}')"

echo
echo "History survives:"
check "the closed assignment is still there" 'Jane Mwangi' "$(get "$MANAGER" "ict/assets/$AID")"
check "so are its return notes"         'Screen cracked'   "$(get "$MANAGER" "ict/assets/$AID")"

echo
echo "Maintenance:"
check "repair recorded"                 '"maintenance"' \
      "$(post "$MANAGER" "ict/assets/$AID/maintenance" '{"performedOn":"2026-09-08","kind":"repair","problem":"Cracked screen","workDone":"Panel replaced","cost":"12000","result":"resolved","nextDueOn":"2027-03-08"}')"
check "a viewer cannot record maintenance" 'maintenance.manage' \
      "$(post "$VIEWER" "ict/assets/$AID/maintenance" '{"problem":"x"}')"
check "a write-off retires the asset"   'retired' \
      "$(post "$MANAGER" "ict/assets/$AID/maintenance" '{"result":"written_off","problem":"Beyond repair"}' >/dev/null; $MYSQL -N -e "SELECT status FROM ict_assets WHERE id=$AID;")"
check "a retired asset cannot be assigned" 'cannot be assigned' \
      "$(post "$MANAGER" "ict/assets/$AID/assign" '{"holderName":"Nobody"}')"

echo
echo "Audit:"
check "creation logged"                 'asset_added'    "$($MYSQL -N -e "SELECT GROUP_CONCAT(action) FROM ict_audit WHERE target_type='asset';")"
check "assignment logged"               'asset_assigned' "$($MYSQL -N -e "SELECT GROUP_CONCAT(action) FROM ict_audit WHERE target_type='asset';")"
check "return logged"                   'asset_returned' "$($MYSQL -N -e "SELECT GROUP_CONCAT(action) FROM ict_audit WHERE target_type='asset';")"

$MYSQL -e "DELETE FROM ict_assets WHERE serial_number='SN-TEST-001' OR name IN ('Sneaky','Bad dates');
DELETE FROM users WHERE email IN ('a-view@local','a-manage@local');" >/dev/null
rm -f "$VIEWER" "$MANAGER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
