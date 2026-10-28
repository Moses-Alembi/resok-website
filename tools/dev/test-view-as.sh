#!/usr/bin/env bash
#
# "View as member" (lib/view-as.php): a super admin sees the portal as one member and can
# correct their profile, and nothing more.
#
# What matters most: only a super admin can start it, and only on an ordinary member; it is
# useless without the super admin's own session; while on, requests answer as the member;
# the profile can be edited and the edit is logged under the admin; payments, votes and every
# other write are refused; private areas (two-factor, staff, ICT, MDT, reviews) stay closed;
# ending it returns the admin to themselves.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
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
# -c writes back to the jar, so the view-as cookie the API sets is carried on later calls.
get()   { curl -s -b "$1" -c "$1" --max-time 15 "${API}$2"; }
send()  { curl -s -b "$1" -c "$1" -X "$2" --max-time 15 "${API}$3" -H 'Content-Type: application/json' -d "${4:-{\}}"; }
code()  { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" --max-time 15 "${API}$2"; }
scode() { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -X "$2" --max-time 15 "${API}$3" -H 'Content-Type: application/json' -d "${4:-{\}}"; }

cleanup() {
    sql "DELETE FROM admin_actions WHERE target_member_profile_id IN (SELECT id FROM member_profiles WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'vas-%@resok.local'));
         DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'vas-%@resok.local');
         DELETE FROM users WHERE email LIKE 'vas-%@resok.local';"
}
cleanup
sql "INSERT INTO users (email,password_hash,email_verified,role) VALUES
       ('vas-member@resok.local','x',1,'member'), ('vas-admin@resok.local','x',1,'admin'), ('vas-other@resok.local','x',1,'admin');
     INSERT INTO member_profiles (user_id,title,first_name,surname,mobile,county,membership_status,membership_id)
       SELECT id,'Dr','Viewas','Testmember','0700000001','Nairobi','active','VAS-001' FROM users WHERE email='vas-member@resok.local';"
MEMBER_UID=$(sql "SELECT id FROM users WHERE email='vas-member@resok.local'")
PROFILE=$(sql "SELECT id FROM member_profiles WHERE user_id=$MEMBER_UID")
OTHER_ADMIN_UID=$(sql "SELECT id FROM users WHERE email='vas-other@resok.local'")

SUPER=$(minted_session dev@resok.local)
ADMIN=$(minted_session vas-admin@resok.local)
MEMBER=$(minted_session vas-member@resok.local)

echo "Who can start it"
NOBODY=$(mktemp)
check "anonymous is refused" "401" "$(scode "$NOBODY" POST view-as/start "{\"profileId\":$PROFILE}")"
check "an ordinary admin is refused" "super administrators" "$(send "$ADMIN" POST view-as/start "{\"profileId\":$PROFILE}")"
check "a member is refused" "403" "$(scode "$MEMBER" POST view-as/start "{\"profileId\":$PROFILE}")"
check "another administrator cannot be viewed as" "Only ordinary member" "$(send "$SUPER" POST view-as/start "{\"userId\":$OTHER_ADMIN_UID}")"
check "an unknown member is refused" "No such member" "$(send "$SUPER" POST view-as/start '{"profileId":999999999}')"
check "status is off before starting" '"active":false' "$(get "$SUPER" view-as/status)"

echo "Starting"
R=$(send "$SUPER" POST view-as/start "{\"profileId\":$PROFILE}")
check "the super admin can start it" '"active":true' "$R"
check "and is sent to the dashboard" '"goTo":"dashboard"' "$R"
check "the jar holds the view-as cookie" "resok_viewas" "$(cat "$SUPER")"
check "status names the member" "Viewas Testmember" "$(get "$SUPER" view-as/status)"
check "starting is in the audit log" "1" "$(sql "SELECT COUNT(*) FROM admin_actions WHERE action='view_as_started' AND target_member_profile_id=$PROFILE")"

echo "Seeing what the member sees"
ME=$(get "$SUPER" members/me)
check "members/me answers as the member" "VAS-001" "$ME"
check "with the member's mobile" "0700000001" "$ME"
check "admin pages are closed while viewing" "403" "$(code "$SUPER" admin/whoami)"

echo "Editing their profile"
R=$(send "$SUPER" PATCH members/me '{"county":"Kisumu","institution":"KNH"}')
check "the profile can be corrected" '"county":"Kisumu"' "$R"
check "it is saved on the member's record" "Kisumu" "$(sql "SELECT county FROM member_profiles WHERE id=$PROFILE")"
check "the edit is logged under the admin" "county, institution" "$(sql "SELECT reason FROM admin_actions WHERE action='view_as_edit' AND target_member_profile_id=$PROFILE")"
DEV_UID=$(sql "SELECT id FROM users WHERE email='dev@resok.local'")
check "with the admin as the one who did it" "$DEV_UID" "$(sql "SELECT admin_user_id FROM admin_actions WHERE action='view_as_edit' AND target_member_profile_id=$PROFILE")"

echo "Nothing else is done in their name"
check "a payment is refused" "Only their profile" "$(send "$SUPER" POST payments '{"amount":1}')"
absent "an M-Pesa prompt is refused" "200" "$(scode "$SUPER" POST payments/stk-push '{"amount":1}')"
check "a vote is refused" "Only their profile" "$(send "$SUPER" POST elections/x/vote '{}')"
check "turning off their two-factor is refused" "stays private" "$(send "$SUPER" POST auth/mfa/disable '{}')"
check "a nomination is refused" "Only their profile" "$(send "$SUPER" POST elections/x/nominate '{}')"
check "a course attempt is refused" "Only their profile" "$(send "$SUPER" POST academy/courses/x/enrol '{}')"
check "two-factor settings stay private" "stays private" "$(get "$SUPER" auth/mfa/status)"
check "passkeys stay private" "stays private" "$(get "$SUPER" auth/passkey/list)"
check "staff pay stays private" "stays private" "$(get "$SUPER" staff/payroll/mine)"
check "ICT stays private" "stays private" "$(get "$SUPER" ict/me)"
check "MDT cases stay private" "stays private" "$(get "$SUPER" mdt)"
check "abstract reviews stay private" "stays private" "$(get "$SUPER" abstracts/reviews)"

echo "Useless without the admin's own session"
STOLEN=$(mktemp); grep resok_viewas "$SUPER" > "$STOLEN"
check "the view-as cookie alone signs nobody in" "401" "$(code "$STOLEN" members/me)"
MJAR=$(mktemp); cat "$MEMBER" > "$MJAR"; grep resok_viewas "$SUPER" >> "$MJAR"
check "beside someone else's session it is ignored" '"email":"vas-member' "$(get "$MJAR" members/me)"
AJAR=$(mktemp); cat "$ADMIN" > "$AJAR"; grep resok_viewas "$SUPER" >> "$AJAR"
absent "an ordinary admin cannot borrow it" "VAS-001" "$(get "$AJAR" members/me)"
FORGED=$(mktemp); cat "$SUPER" | sed -E 's/(resok_viewas\t)[^.]*/\1eyJ0Ijo5OTl9/' > "$FORGED"
absent "a tampered cookie is ignored" "VAS-001" "$(get "$FORGED" members/me)"

echo "Ending"
check "it can be ended" '"active":false' "$(send "$SUPER" POST view-as/end)"
check "ending is in the audit log" "1" "$(sql "SELECT COUNT(*) FROM admin_actions WHERE action='view_as_ended' AND target_member_profile_id=$PROFILE")"
absent "the admin is themselves again" "VAS-001" "$(get "$SUPER" members/me)"
check "admin pages open again" "isSuperAdmin" "$(get "$SUPER" admin/whoami)"

echo "Logging out ends it too"
send "$SUPER" POST view-as/start "{\"profileId\":$PROFILE}" >/dev/null
send "$SUPER" POST auth/logout >/dev/null
absent "after logout the view-as cookie is gone" "resok_viewas" "$(cat "$SUPER")"

cleanup
rm -f "$NOBODY" "$SUPER" "$ADMIN" "$MEMBER" "$STOLEN" "$MJAR" "$AJAR" "$FORGED"
echo; echo "view-as: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
