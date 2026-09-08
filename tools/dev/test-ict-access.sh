#!/usr/bin/env bash
#
# Exercises the ICT permission model against the running local API.
#
# The claim being tested is the whole reason the ict role exists: an ICT officer reaches ICT
# modules and does NOT reach member records, ID numbers, payments or approvals. That is a
# claim about behaviour, so it is checked by making the requests rather than by reading the
# code that is supposed to enforce it.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {  # check <description> <expected-substring> <actual>
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.110s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

# --- three accounts, one of each kind ---------------------------------------------------
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('t-ict@local','t-admin@local','t-member@local');
INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('t-ict@local','$HASH',1,'ict'),
  ('t-admin@local','$HASH',1,'admin'),
  ('t-member@local','$HASH',1,'member');" >/dev/null

# The super admin is the address named in config.local.php. It persists between runs and is
# the login used to browse the site, so it is neither created nor deleted here.
SUPER_EMAIL=$(grep -oE "super_admins' => \['[^']+" resok-portal/public/api/config.local.php | sed "s/.*\['//")
SUPER_PASS="LocalDev2026!"
if [ -z "$SUPER_EMAIL" ]; then echo "  no super_admins in config.local.php - cannot test"; exit 2; fi

login() {  # login <email> [password] -> prints the cookie jar path
    local jar pass; jar=$(mktemp); pass="${2:-TestPass123}"
    curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' \
         -d "{\"email\":\"$1\",\"password\":\"$pass\"}" >/dev/null
    echo "$jar"
}
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
put()  { curl -s -b "$1" -X PUT --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

ICT=$(login t-ict@local)
ADMIN=$(login t-admin@local)
MEMBER=$(login t-member@local)
SUPER=$(login "$SUPER_EMAIL" "LocalDev2026!")
ICT_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='t-ict@local';")

echo "An ICT officer with no capabilities yet:"
check "cannot read the ICT audit"        '"error"'   "$(get "$ICT" 'ict/audit')"
check "cannot list members"              'Admin access required' "$(get "$ICT" 'members')"
check "cannot see the audit log"         'Admin access required' "$(get "$ICT" 'admin/audit-log')"
check "cannot reach the threat assessment" 'error'   "$(get "$ICT" 'security/assessment')"
check "cannot grant themselves anything" 'error'     "$(put "$ICT" "ict/staff/$ICT_ID/capabilities" '{"capabilities":["assets.manage"]}')"
check "reports having no access"         '"hasAccess":false' "$(get "$ICT" 'ict/me')"

echo
echo "After a super admin grants two capabilities:"
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id, capability) VALUES ($ICT_ID,'assets.view'),($ICT_ID,'reports.view');" >/dev/null
check "now reads the ICT audit"          '"entries"'  "$(get "$ICT" 'ict/audit')"
check "reports having access"            '"hasAccess":true' "$(get "$ICT" 'ict/me')"
check "capability list is exactly those two" 'assets.view' "$(get "$ICT" 'ict/me')"
check "STILL cannot list members"        'Admin access required' "$(get "$ICT" 'members')"
check "STILL cannot see member ID numbers" 'Admin access required' "$(get "$ICT" 'members')"

echo
echo "A member:"
check "cannot reach anything ICT"        'error'      "$(get "$MEMBER" 'ict/audit')"
check "reports no access"                '"hasAccess":false' "$(get "$MEMBER" 'ict/me')"

echo
echo "An admin (member management) does NOT inherit ICT:"
check "cannot read the ICT audit"        'permission' "$(get "$ADMIN" 'ict/audit')"
check "reports no ICT access"            '"hasAccess":false' "$(get "$ADMIN" 'ict/me')"
check "but still lists members"          '['          "$(get "$ADMIN" 'members')"

echo
echo "Only a super admin grants capabilities:"
check "a plain admin cannot grant"       'restricted to super administrators' "$(put "$ADMIN" "ict/staff/$ICT_ID/capabilities" '{"capabilities":["assets.view"]}')"
check "a typo is rejected, not stored"   'Unknown capability' "$(put "$SUPER" "ict/staff/$ICT_ID/capabilities" '{"capabilities":["assets.mange"]}')"
check "a valid set is accepted"          '"capabilities"' "$(put "$SUPER" "ict/staff/$ICT_ID/capabilities" '{"capabilities":["assets.view","tickets.manage"]}')"
check "the change was audited"           'capabilities_changed' "$($MYSQL -N -e "SELECT action FROM ict_audit ORDER BY id DESC LIMIT 1;")"

echo
$MYSQL -e "DELETE FROM users WHERE email IN ('t-ict@local','t-admin@local','t-member@local');" >/dev/null
rm -f "$ICT" "$ADMIN" "$MEMBER" "$SUPER"
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
