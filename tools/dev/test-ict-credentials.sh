#!/usr/bin/env bash
#
# Exercises the credential register through the running API.
#
# The claim this module makes is a negative one - that it never holds a secret - so the first
# thing tested is that a password sent to it is refused rather than stored. After that: that
# rotation dates are derived and not typed, that the numbers worth acting on are counted, and
# that following a link to the vault is recorded.
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
DELETE FROM users WHERE email IN ('c-view@local','c-manage@local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('c-view@local','$HASH',1,'ict'),('c-manage@local','$HASH',1,'ict');" >/dev/null
VID=$($MYSQL -N -e "SELECT id FROM users WHERE email='c-view@local';")
MID=$($MYSQL -N -e "SELECT id FROM users WHERE email='c-manage@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($VID,'credentials.view'),
 ($MID,'credentials.view'),($MID,'credentials.manage');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

VIEWER=$(login c-view@local)
MANAGER=$(login c-manage@local)

echo "It is a register, not a vault:"
for f in password secret passphrase apiKey privateKey token; do
  check "a '$f' field is refused"      'does not store passwords' \
        "$(post "$MANAGER" 'ict/credentials' "{\"name\":\"Nope\",\"$f\":\"hunter2\"}")"
done
check "and nothing was stored"        '0' "$($MYSQL -N -e "SELECT COUNT(*) FROM ict_credentials WHERE name='Nope';")"

echo
echo "Creating:"
check "a manager can create"          '"credential"' \
      "$(post "$MANAGER" 'ict/credentials' '{"name":"Domain registrar","kind":"domain","provider":"Namecheap","accountId":"resok-admin","criticality":"critical","mfaEnabled":false,"vaultUrl":"https://vault.bitwarden.com/#/vault?itemId=abc","lastRotatedOn":"2024-01-15","rotationMonths":12}')"
check "a viewer cannot create"        'credentials.manage' \
      "$(post "$VIEWER" 'ict/credentials' '{"name":"Sneaky"}')"
check "a nameless record is refused"  'Name the account' "$(post "$MANAGER" 'ict/credentials' '{"kind":"domain"}')"
check "a bare-word URL is refused"    'http'  \
      "$(post "$MANAGER" 'ict/credentials' '{"name":"Bad link","vaultUrl":"vault.example.com"}')"
check "a future rotation date refused" 'future' \
      "$(post "$MANAGER" 'ict/credentials' '{"name":"Time traveller","lastRotatedOn":"2030-01-01"}')"

CID=$($MYSQL -N -e "SELECT id FROM ict_credentials WHERE name='Domain registrar' LIMIT 1;")

echo
echo "Rotation dates are derived, not typed:"
check "next rotation computed from the interval" '2025-01-15' \
      "$($MYSQL -N -e "SELECT next_rotation_on FROM ict_credentials WHERE id=$CID;")"
patch "$MANAGER" "ict/credentials/$CID" '{"rotationMonths":6}' >/dev/null
check "changing the interval moves it"  '2024-07-15' \
      "$($MYSQL -N -e "SELECT next_rotation_on FROM ict_credentials WHERE id=$CID;")"
patch "$MANAGER" "ict/credentials/$CID" '{"rotationMonths":0}' >/dev/null
check "no schedule means no date"       'NULL' \
      "$($MYSQL -N -e "SELECT IFNULL(next_rotation_on,'NULL') FROM ict_credentials WHERE id=$CID;")"
patch "$MANAGER" "ict/credentials/$CID" '{"rotationMonths":12}' >/dev/null

echo
echo "The numbers worth acting on:"
OUT=$(get "$MANAGER" 'ict/credentials')
check "counts a critical account"       '"critical":1'      "$OUT"
check "counts critical without 2FA"     '"criticalNoMfa":1' "$OUT"
check "rotation overdue is counted"     '"rotationOverdue":1' "$OUT"
patch "$MANAGER" "ict/credentials/$CID" '{"mfaEnabled":true}' >/dev/null
check "turning 2FA on clears that count" '"criticalNoMfa":0' "$(get "$MANAGER" 'ict/credentials')"

echo
echo "Going to fetch a credential is logged:"
check "the vault link is returned"      'bitwarden' \
      "$(post "$MANAGER" "ict/credentials/$CID/open" '{"reason":"Renewing the domain"}')"
check "and the access recorded"         'Renewing the domain' \
      "$($MYSQL -N -e "SELECT reason FROM ict_credential_access ORDER BY id DESC LIMIT 1;")"
check "a viewer may also fetch"         'bitwarden' \
      "$(post "$VIEWER" "ict/credentials/$CID/open" '{"reason":"Checking"}')"
check "only a manager reads the log"    'credentials.manage' "$(get "$VIEWER" 'ict/credentials/access-log')"
check "a record with no vault entry says so" 'No vault entry' \
      "$(post "$MANAGER" 'ict/credentials' '{"name":"Unlinked account"}' >/dev/null;
         UID2=$($MYSQL -N -e "SELECT id FROM ict_credentials WHERE name='Unlinked account' LIMIT 1;");
         post "$MANAGER" "ict/credentials/$UID2/open" '{}')"

echo
echo "Audit:"
check "creation logged"                 'credential_added'   "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='credential';")"
check "edits logged"                    'credential_updated' "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='credential';")"

$MYSQL -e "DELETE FROM ict_credentials WHERE name IN ('Domain registrar','Unlinked account','Nope','Sneaky','Bad link','Time traveller');
DELETE FROM users WHERE email IN ('c-view@local','c-manage@local');" >/dev/null
rm -f "$VIEWER" "$MANAGER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
