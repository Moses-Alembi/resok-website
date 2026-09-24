#!/usr/bin/env bash
#
# Exercises the admin / super admin split against the running local API.
#
# The claim: an ordinary admin works on membership only (members, payments, claims, invites,
# a member's CPD points) and is refused everything else - administrators, events and CPD
# tokens, elections, analytics, security, the audit log, migrations, blog and academy
# editing, ICT. A super admin (named in super_admins in config.local.php) reaches all of it.
# Checked by making the requests, not by reading the code that is supposed to enforce it.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {  # check <description> <expected-substring> <actual>
    if [[ "$3" == *"$2"* ]]; then printf '  %-52s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-52s FAIL\n      wanted: %s\n      got:    %.110s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
refused() { check "$1" 'super administrators' "$2"; }
allowed() {  # anything but a refusal or a login failure
    if [[ "$2" != *'super administrators'* && "$2" != *'Admin access required'* && "$2" != *'Missing token'* && "$2" != *'permission'* ]]; then
        printf '  %-52s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-52s FAIL\n      got: %.110s\n' "$1" "$2"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "DELETE FROM users WHERE email='t-plainadmin@local';
INSERT INTO users (email, password_hash, email_verified, role) VALUES ('t-plainadmin@local','$HASH',1,'admin');" >/dev/null

SUPER_EMAIL=$(grep -oE "super_admins' => \['[^']+" resok-portal/public/api/config.local.php | sed "s/.*\['//")
if [ -z "$SUPER_EMAIL" ]; then echo "  no super_admins in config.local.php - cannot test"; exit 2; fi

login() {  # login <email> <password> -> cookie jar path
    local jar; jar=$(mktemp)
    curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' \
         -d "{\"email\":\"$1\",\"password\":\"$2\"}" >/dev/null
    echo "$jar"
}
get() { curl -s -b "$1" --max-time 10 "${API}$2"; }

ADMIN=$(login t-plainadmin@local TestPass123)
# The super admin's session is minted with the local jwt_secret, exactly as the API signs
# one, rather than by logging in: the developer account's password is personal and not
# something a test suite should hard-code (the ICT suite does, and breaks when it changes).
SUPER_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='$SUPER_EMAIL';")
SUPER_TOKEN=$(SUPER_ID="$SUPER_ID" SUPER_EMAIL="$SUPER_EMAIL" $PHP -r '
    $c = require "resok-portal/public/api/config.php";
    $b = fn($d) => rtrim(strtr(base64_encode($d), "+/", "-_"), "=");
    $body = $b(json_encode(["userId" => (int)getenv("SUPER_ID"), "email" => getenv("SUPER_EMAIL"),
                            "role" => "admin", "exp" => time() + 600, "seen" => time()]));
    echo $body . "." . $b(hash_hmac("sha256", $body, $c["jwt_secret"], true));' 2>/dev/null)
SUPER=$(mktemp)
printf 'localhost\tFALSE\t/\tFALSE\t0\tresok_token\t%s\n' "$SUPER_TOKEN" > "$SUPER"

echo "An ordinary admin - membership work is open:"
check   "whoami says not super"               '"isSuperAdmin":false' "$(get "$ADMIN" 'admin/whoami')"
allowed "lists members"                        "$(get "$ADMIN" 'members')"
allowed "sees the review queue"                "$(get "$ADMIN" 'members/review-queue')"
allowed "sees claim emails"                    "$(get "$ADMIN" 'members/claims')"
allowed "sees invites"                         "$(get "$ADMIN" 'invites')"

echo
echo "An ordinary admin - everything else is refused:"
refused "administrators list"                  "$(get "$ADMIN" 'admins')"
refused "events"                               "$(get "$ADMIN" 'admin/events')"
refused "event attendees"                      "$(get "$ADMIN" 'admin/events/1/attendees')"
refused "elections"                            "$(get "$ADMIN" 'admin/elections')"
refused "analytics"                            "$(get "$ADMIN" 'admin/analytics')"
refused "threat assessment"                    "$(get "$ADMIN" 'security/assessment')"
refused "audit log"                            "$(get "$ADMIN" 'admin/audit-log')"
refused "migrations"                           "$(get "$ADMIN" 'admin/migrations')"
check   "blog editing"                         'permission' "$(get "$ADMIN" 'blog/admin/articles')"
check   "academy course editing"               'permission' "$(get "$ADMIN" 'academy/admin/courses')"
check   "ICT (no capability granted)"          '"hasAccess":false' "$(get "$ADMIN" 'ict/me')"
check   "helpdesk queue"                       'permission' "$(get "$ADMIN" 'ict/tickets/assignees')"

echo
echo "The super admin ($SUPER_EMAIL) reaches all of it:"
check   "whoami says super"                    '"isSuperAdmin":true' "$(get "$SUPER" 'admin/whoami')"
allowed "lists members"                        "$(get "$SUPER" 'members')"
allowed "administrators list"                  "$(get "$SUPER" 'admins')"
allowed "events"                               "$(get "$SUPER" 'admin/events')"
allowed "elections"                            "$(get "$SUPER" 'admin/elections')"
allowed "analytics"                            "$(get "$SUPER" 'admin/analytics')"
allowed "threat assessment"                    "$(get "$SUPER" 'security/assessment')"
allowed "audit log"                            "$(get "$SUPER" 'admin/audit-log')"
allowed "blog editing"                         "$(get "$SUPER" 'blog/admin/articles')"
allowed "academy course editing"               "$(get "$SUPER" 'academy/admin/courses')"
allowed "helpdesk queue (member reports)"      "$(get "$SUPER" 'ict/tickets')"

$MYSQL -e "DELETE FROM users WHERE email='t-plainadmin@local';" >/dev/null
rm -f "$ADMIN" "$SUPER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
