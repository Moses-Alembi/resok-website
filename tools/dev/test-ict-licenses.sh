#!/usr/bin/env bash
#
# Exercises software licences and seat allocation through the running API.
#
# The claims worth testing: a licence key is refused like any other secret, seats are counted
# rather than stored, a licence cannot be over-allocated, and a released seat keeps its row
# so "who used this last year" survives.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.120s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('l-view@local','l-manage@local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('l-view@local','$HASH',1,'ict'),('l-manage@local','$HASH',1,'ict');" >/dev/null
VID=$($MYSQL -N -e "SELECT id FROM users WHERE email='l-view@local';")
MID=$($MYSQL -N -e "SELECT id FROM users WHERE email='l-manage@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($VID,'licenses.view'),
 ($MID,'licenses.view'),($MID,'licenses.manage');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
del()  { curl -s -b "$1" -X DELETE --max-time 10 "${API}$2"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

VIEWER=$(login l-view@local)
MANAGER=$(login l-manage@local)

echo "A licence key is a secret, like any other:"
for f in licenseKey key serial activationCode productKey; do
  check "a '$f' field is refused"     'not stored here' \
        "$(post "$MANAGER" 'ict/licenses' "{\"name\":\"NopeLic\",\"$f\":\"XXXX-YYYY\"}")"
done
check "and nothing was stored"        '0' "$($MYSQL -N -e "SELECT COUNT(*) FROM ict_licenses WHERE name='NopeLic';")"

echo
echo "Creating:"
check "a manager can create"          '"license"' \
      "$(post "$MANAGER" 'ict/licenses' '{"name":"TestSuite Seats","vendor":"Acme","kind":"subscription","seatsTotal":2,"purchasedOn":"2025-01-10","expiresOn":"2027-01-10","cost":"30000"}')"
check "a viewer cannot create"        'licenses.manage' "$(post "$VIEWER" 'ict/licenses' '{"name":"Sneaky"}')"
check "a nameless licence is refused" 'Name the software' "$(post "$MANAGER" 'ict/licenses' '{"vendor":"Acme"}')"
check "expiry before purchase refused" 'cannot expire' \
      "$(post "$MANAGER" 'ict/licenses' '{"name":"Bad dates","purchasedOn":"2026-06-01","expiresOn":"2026-01-01"}')"

LID=$($MYSQL -N -e "SELECT id FROM ict_licenses WHERE name='TestSuite Seats' LIMIT 1;")

echo
echo "Seats are counted, not stored:"
check "starts with none used"         '"seatsUsed":0' "$(get "$MANAGER" "ict/licenses/$LID")"
check "first seat assigned"           '"seatsUsed":1' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{"holderName":"Jane Mwangi","holderEmail":"jane@example.org","department":"Finance"}')"
check "free seats reported"           '"seatsFree":1' "$(get "$MANAGER" "ict/licenses/$LID")"
check "the same person twice is refused" 'already holds a seat' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{"holderName":"Jane Again","holderEmail":"jane@example.org"}')"
check "second seat assigned"          '"seatsUsed":2' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{"holderName":"Peter Ochieng","holderEmail":"peter@example.org"}')"
check "CANNOT exceed the seat count"  'All 2 seats are in use' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{"holderName":"One Too Many"}')"
check "a viewer cannot assign a seat" 'licenses.manage' \
      "$(post "$VIEWER" "ict/licenses/$LID/seats" '{"holderName":"Nope"}')"
check "a nameless seat is refused"    'Who is the seat for' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{}')"

SEAT=$($MYSQL -N -e "SELECT id FROM ict_license_seats WHERE license_id=$LID AND holder_email='jane@example.org' LIMIT 1;")

echo
echo "Releasing:"
check "seat released"                 '"seatsUsed":1' "$(del "$MANAGER" "ict/licenses/seats/$SEAT")"
check "releasing twice is refused"    'already released' "$(del "$MANAGER" "ict/licenses/seats/$SEAT")"
check "the row survives the release"  'Jane Mwangi' "$(get "$MANAGER" "ict/licenses/$LID")"
check "a freed seat can be reused"    '"seatsUsed":2' \
      "$(post "$MANAGER" "ict/licenses/$LID/seats" '{"holderName":"Amina Yusuf","holderEmail":"amina@example.org"}')"

echo
echo "Unlimited licences have no ceiling:"
post "$MANAGER" 'ict/licenses' '{"name":"TestSuite Unlimited","kind":"open_source","seatsTotal":0}' >/dev/null
ULID=$($MYSQL -N -e "SELECT id FROM ict_licenses WHERE name='TestSuite Unlimited' LIMIT 1;")
post "$MANAGER" "ict/licenses/$ULID/seats" '{"holderName":"A"}' >/dev/null
post "$MANAGER" "ict/licenses/$ULID/seats" '{"holderName":"B"}' >/dev/null
check "seats keep being allocated"    '"seatsUsed":2' "$(get "$MANAGER" "ict/licenses/$ULID")"
check "free seats reported as unknown" '"seatsFree":null' "$(get "$MANAGER" "ict/licenses/$ULID")"

echo
echo "Renewal alerts share the infrastructure machinery:"
patch "$MANAGER" "ict/licenses/$LID" '{"expiresOn":"2026-09-20"}' >/dev/null
check "editing the date clears the alert band" 'NULL' \
      "$($MYSQL -N -e "SELECT IFNULL(last_alert_band,'NULL') FROM ict_licenses WHERE id=$LID;")"
check "it is now inside an alert band" '1'       "$($MYSQL -N -e "SELECT COUNT(*) FROM ict_licenses
                        WHERE id=$LID AND status='active'
                          AND expires_on IS NOT NULL
                          AND expires_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                          AND last_alert_band IS NULL;")"

echo
echo "Audit:"
check "creation logged"               'license_added'         "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='license';")"
check "seat assignment logged"        'license_seat_assigned' "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='license';")"
check "seat release logged"           'license_seat_released' "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='license';")"

$MYSQL -e "DELETE FROM ict_licenses WHERE name IN ('TestSuite Seats','TestSuite Unlimited','NopeLic','Sneaky','Bad dates');
DELETE FROM users WHERE email IN ('l-view@local','l-manage@local');" >/dev/null
rm -f "$VIEWER" "$MANAGER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
