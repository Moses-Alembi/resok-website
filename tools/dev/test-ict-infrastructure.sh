#!/usr/bin/env bash
#
# Exercises the infrastructure module through the running API, as a signed-in user.
#
# The permission checks matter most: a viewer must be able to read and NOT write, because
# "view" and "manage" being separate capabilities is only true if the server enforces it.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.120s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('i-view@local','i-manage@local','dev@localhost');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('i-view@local','$HASH',1,'ict'),('i-manage@local','$HASH',1,'ict'),('dev@localhost','$HASH',1,'admin');" >/dev/null
VIEW_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='i-view@local';")
MAN_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='i-manage@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($VIEW_ID,'infrastructure.view'),
 ($MAN_ID,'infrastructure.view'),($MAN_ID,'infrastructure.manage');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

VIEWER=$(login i-view@local)
MANAGER=$(login i-manage@local)
SUPER=$(login dev@localhost)

echo "Reading:"
check "a viewer can list infrastructure"   '"items"'  "$(get "$VIEWER" 'ict/infrastructure')"
check "the summary comes with it"          '"summary"' "$(get "$VIEWER" 'ict/infrastructure')"
check "a viewer sees the overview"         '"infrastructure"' "$(get "$VIEWER" 'ict/overview')"

echo
echo "Writing is a separate permission:"
check "a viewer CANNOT create"             'infrastructure.manage' \
      "$(post "$VIEWER" 'ict/infrastructure' '{"kind":"domain","name":"sneaky.example"}')"
check "a manager can create"               '"item"' \
      "$(post "$MANAGER" 'ict/infrastructure' '{"kind":"service","name":"Test service","expiresOn":"2027-01-31"}')"

NEW_ID=$($MYSQL -N -e "SELECT id FROM ict_infrastructure WHERE name='Test service' LIMIT 1;")
check "a viewer CANNOT edit"               'infrastructure.manage' \
      "$(patch "$VIEWER" "ict/infrastructure/$NEW_ID" '{"name":"hijacked"}')"
check "a manager can edit"                 '"item"' \
      "$(patch "$MANAGER" "ict/infrastructure/$NEW_ID" '{"provider":"A provider"}')"

echo
echo "Validation:"
check "an unknown kind is refused"         'Choose one of' \
      "$(post "$MANAGER" 'ict/infrastructure' '{"kind":"spaceship","name":"x"}')"
check "a record with no name is refused"   'name' \
      "$(post "$MANAGER" 'ict/infrastructure' '{"kind":"domain"}')"
check "renewal before start is refused"    'must come after' \
      "$(post "$MANAGER" 'ict/infrastructure' '{"kind":"domain","name":"y","startsOn":"2027-06-01","expiresOn":"2027-01-01"}')"
check "an unreadable date is refused"      'date' \
      "$(post "$MANAGER" 'ict/infrastructure' '{"kind":"domain","name":"z","expiresOn":"soon-ish"}')"

echo
echo "Audit:"
check "the create was logged"              'infrastructure_added' \
      "$($MYSQL -N -e "SELECT action FROM ict_audit WHERE target_type='infrastructure' ORDER BY id DESC LIMIT 3;")"
check "the edit recorded what changed"     'A provider' \
      "$($MYSQL -N -e "SELECT after_json FROM ict_audit WHERE action='infrastructure_updated' ORDER BY id DESC LIMIT 1;")"

echo
echo "Alert de-duplication:"
$MYSQL -e "UPDATE ict_infrastructure SET expires_on=DATE_ADD(CURDATE(), INTERVAL 10 DAY), last_alert_band=NULL WHERE id=$NEW_ID;" >/dev/null
BEFORE=$($PHP -r "require 'resok-portal/public/api/lib/ict-infrastructure.php'; \$c=require 'resok-portal/public/api/config.php'; \$p=new PDO(\"mysql:host={\$c['db_host']};dbname={\$c['db_name']}\",\$c['db_user'],\$c['db_pass'],[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); echo count(ictInfraDueForAlert(\$p));" 2>/dev/null | tail -1)
$MYSQL -e "UPDATE ict_infrastructure SET last_alert_band='critical' WHERE id=$NEW_ID;" >/dev/null
AFTER=$($PHP -r "require 'resok-portal/public/api/lib/ict-infrastructure.php'; \$c=require 'resok-portal/public/api/config.php'; \$p=new PDO(\"mysql:host={\$c['db_host']};dbname={\$c['db_name']}\",\$c['db_user'],\$c['db_pass'],[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); echo count(ictInfraDueForAlert(\$p));" 2>/dev/null | tail -1)
check "a record in a new band is due"      "1" "$([ "$BEFORE" -gt "$AFTER" ] && echo 1 || echo 0)"
check "already alerted is not re-sent"     "1" "$([ "$AFTER" -lt "$BEFORE" ] && echo 1 || echo 0)"

echo
echo "Changing the date re-arms the alert:"
$MYSQL -e "UPDATE ict_infrastructure SET last_alert_band='critical' WHERE id=$NEW_ID;" >/dev/null
patch "$MANAGER" "ict/infrastructure/$NEW_ID" '{"expiresOn":"2027-03-15"}' >/dev/null
check "band cleared on a date change"      "NULL" \
      "$($MYSQL -N -e "SELECT IFNULL(last_alert_band,'NULL') FROM ict_infrastructure WHERE id=$NEW_ID;")"

$MYSQL -e "DELETE FROM ict_infrastructure WHERE name IN ('Test service','sneaky.example');
DELETE FROM users WHERE email IN ('i-view@local','i-manage@local','dev@localhost');" >/dev/null
rm -f "$VIEWER" "$MANAGER" "$SUPER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
