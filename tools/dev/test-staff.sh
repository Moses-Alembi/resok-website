#!/usr/bin/env bash
#
# Exercises staff accounts (lib/staff.php): adding them from a pasted list, inviting them,
# what a staff account can and cannot reach, and where it lands after sign-in.
#
# What matters most: a pasted To-line is read correctly (names, bare addresses, duplicates,
# the trailing "......"); no email goes out on adding; an existing account keeps its password
# and role; invites set no password and expire; a staff account reaches its own page and the
# helpdesk but nothing administrative; and only super admins manage the list.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      did not want: %s\n      got: %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
sql() { $MYSQL -N -e "$1" 2>/dev/null; }

SUPER=$(minted_session dev@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 60 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 15 "${API}$2"; }

get "$SUPER" staff/admin >/dev/null   # creates the table on a fresh database
cleanup() {
    sql "DELETE s FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE u.email LIKE 'stf-%@resok.local';
         DELETE FROM users WHERE email LIKE 'stf-%@resok.local';"
}
cleanup
# An existing member-style account that must keep its password and role.
sql "INSERT INTO users (email,password_hash,email_verified,role) VALUES ('stf-existing@resok.local','keep-this-hash',1,'member'),
     ('stf-admin@resok.local','x',1,'admin');"
ADMIN=$(minted_session stf-admin@resok.local)

echo "Who can manage it"
check "anonymous is refused" "401" "$(code '' staff/admin)"
check "an ordinary admin is refused" "super administrators" "$(get "$ADMIN" staff/admin)"

echo "Reading a pasted To-line"
LIST='Anne Test <stf-anne@resok.local>, stfbare <stf-bare@resok.local>, "stf-quoted@resok.local" <stf-quoted@resok.local>, Juliet Test <STF-Juliet@resok.local>; stf-plain@resok.local
Anne Again <stf-anne@resok.local>, not-an-email, Stf Named <stf-named@resok.local>, Existing Member <stf-existing@resok.local>, ......'
R=$(post "$SUPER" staff/admin/add "$($PHP -r 'echo json_encode(["list" => $argv[1]]);' "$LIST")")
check "six new accounts are created" '"added":["stf-anne@resok.local","stf-bare@resok.local","stf-quoted@resok.local","stf-juliet@resok.local","stf-plain@resok.local","stf-named@resok.local"]' "$R"
check "an existing account is only marked as staff" '"marked":["stf-existing@resok.local"]' "$R"
check "a name listed twice is added once" '"duplicates":["stf-anne@resok.local"]' "$R"
check "something that is not an address is reported" '"invalid":["not-an-email"]' "$R"
absent "the trailing dots are ignored" '"......"' "$R"
check "addresses are stored in lower case" "stf-juliet@resok.local" "$(sql "SELECT email FROM users WHERE email LIKE 'stf-juliet%'")"
check "a name that is just the mailbox is dropped" "NULL" "$(sql "SELECT IFNULL(s.full_name,'NULL') FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email='stf-bare@resok.local'")"
check "a quoted address as name is dropped" "NULL" "$(sql "SELECT IFNULL(s.full_name,'NULL') FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email='stf-quoted@resok.local'")"
check "a two-word name matching the mailbox is kept" "Stf Named" "$(sql "SELECT s.full_name FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email='stf-named@resok.local'")"
check "a real name is kept" "Anne Test" "$(sql "SELECT s.full_name FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email='stf-anne@resok.local'")"
check "the existing account keeps its password" "keep-this-hash" "$(sql "SELECT password_hash FROM users WHERE email='stf-existing@resok.local'")"
check "new accounts cannot sign in yet (unverified)" "0" "$(sql "SELECT email_verified FROM users WHERE email='stf-anne@resok.local'")"
check "new accounts are plain member role" "member" "$(sql "SELECT role FROM users WHERE email='stf-anne@resok.local'")"
check "no invite has gone out on adding" "0" "$(sql "SELECT COUNT(*) FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email LIKE 'stf-%' AND s.invite_sent_at IS NOT NULL")"
check "adding the same list again changes nothing" '"added":[]' "$(post "$SUPER" staff/admin/add "$($PHP -r 'echo json_encode(["list" => $argv[1]]);' "$LIST")")"

echo "The list"
R=$(get "$SUPER" staff/admin)
check "new accounts show as not invited" '"email":"stf-anne@resok.local","role":"member","member":false,"state":"not_invited"' "$R"
check "the existing account shows as active" '"email":"stf-existing@resok.local","role":"member","member":false,"state":"active"' "$R"

echo "Invites"
ANNE=$(sql "SELECT id FROM users WHERE email='stf-anne@resok.local'")
EXIST=$(sql "SELECT id FROM users WHERE email='stf-existing@resok.local'")
check "an active account is not sent an invite" "Already active" "$(post "$SUPER" staff/admin/$EXIST/invite)"
post "$SUPER" staff/admin/$ANNE/invite >/dev/null   # the laptop has no mail server; the link is still issued
check "an invite issues a password link" "1" "$(sql "SELECT reset_token IS NOT NULL AND reset_expires > NOW() FROM users WHERE id=$ANNE")"
check "which expires in about two weeks" "14" "$(sql "SELECT DATEDIFF(reset_expires, NOW()) FROM users WHERE id=$ANNE" | tr -d ' ')"
TOKEN=$(sql "SELECT reset_token FROM users WHERE id=$ANNE")
R=$(curl -s -X POST "${API}auth/reset-password" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\",\"password\":\"StaffPass2026\"}")
check "the link lets them choose a password" "Password updated" "$R"
sleep 1   # a password change ends every session from that second; sign in after it
check "and the account is now active" '"email":"stf-anne@resok.local","role":"member","member":false,"state":"active"' "$(get "$SUPER" staff/admin)"

echo "Signing in as staff"
# A real sign-in, not a minted session: the password change has just ended every older one.
STAFF=$(mktemp)
R=$(curl -s -c "$STAFF" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d '{"email":"stf-anne@resok.local","password":"StaffPass2026"}')
check "sign-in says this is a staff account" '"isStaff":true' "$R"
check "the staff page loads with their name" '"name":"Anne Test"' "$(get "$STAFF" staff/me)"
check "they can report an ICT problem" '"reference"' "$(post "$STAFF" ict/tickets '{"subject":"Staff test ticket","description":"from test-staff.sh"}')"
check "they cannot open the staff list" "403" "$(code "$STAFF" staff/admin)"
check "nor the asset register" "403" "$(code "$STAFF" ict/assets)"
check "nor the member list" "403" "$(code "$STAFF" members)"
OTHER=$(minted_session stf-existing@resok.local)
sql "DELETE s FROM staff_accounts s WHERE s.user_id=$EXIST"
check "a non-staff account cannot open the staff page" "not on the staff list" "$(get "$OTHER" staff/me)"

echo "Removing"
BARE=$(sql "SELECT id FROM users WHERE email='stf-bare@resok.local'")
post "$SUPER" staff/admin/$BARE/invite >/dev/null
post "$SUPER" staff/admin/$BARE/remove >/dev/null
check "removing takes them off the list" "0" "$(sql "SELECT COUNT(*) FROM staff_accounts WHERE user_id=$BARE")"
check "and cancels their unused invite link" "NULL" "$(sql "SELECT IFNULL(reset_token,'NULL') FROM users WHERE id=$BARE")"

sql "DELETE FROM ict_tickets WHERE subject='Staff test ticket'"
cleanup
sql "DELETE FROM users WHERE email='stf-admin@resok.local'"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
