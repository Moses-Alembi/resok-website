#!/usr/bin/env bash
#
# Exercises the staff intranet (lib/staff-portal.php).
#
# What matters most: only active staff and super admins get in; the directory shows work
# details and never HR fields; people edit only their own contact details; records,
# departments and announcements need their permission; only super admins grant permissions,
# never to themselves; announcements reach exactly their audience and drafts reach nobody;
# notifications belong to their owner; and deactivating someone shuts them out at once.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.220s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      did not want: %s\n      got: %.220s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $d = $d[$k] ?? null; echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 30 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 15 "${API}$2"; }

SUPER=$(minted_session dev@resok.local)
get "$SUPER" staff/home >/dev/null   # creates the tables

cleanup() {
    sql "DELETE n FROM staff_notifications n JOIN users u ON u.id = n.user_id WHERE u.email LIKE 'sp-%@resok.local';
         DELETE r FROM staff_announcement_reads r JOIN users u ON u.id = r.user_id WHERE u.email LIKE 'sp-%@resok.local';
         DELETE n FROM staff_notifications n JOIN staff_announcements a ON n.dedupe_key = CONCAT('announcement:', a.id) WHERE a.title LIKE 'SPTEST%';
         DELETE e FROM abs_emails e JOIN staff_announcements a ON e.dedupe_key LIKE CONCAT('staff-ann:', a.id, ':%') WHERE a.title LIKE 'SPTEST%';
         DELETE FROM staff_announcements WHERE title LIKE 'SPTEST%';
         DELETE g FROM staff_grants g JOIN users u ON u.id = g.user_id WHERE u.email LIKE 'sp-%@resok.local';
         DELETE s FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE u.email LIKE 'sp-%@resok.local';
         DELETE FROM staff_departments WHERE name LIKE 'SPTEST%';
         DELETE FROM abs_emails WHERE template = 'staff-announcement' AND to_email LIKE 'sp-%@resok.local';
         DELETE FROM users WHERE email LIKE 'sp-%@resok.local';"
}
cleanup
# Four people: an HR officer (staff.manage), an editor (announcements.publish), two plain staff,
# and a member who is not staff at all.
# Real passwords and real sign-ins: granting a permission or deactivating someone ends their
# sessions, which is exactly what is being tested, so a session has to be one that can be renewed.
PW='StaffTest2026'
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW")
sql "INSERT INTO users (email,password_hash,email_verified,role) VALUES
     ('sp-hr@resok.local','$HASH',1,'member'),('sp-ed@resok.local','$HASH',1,'member'),
     ('sp-a@resok.local','$HASH',1,'member'),('sp-b@resok.local','$HASH',1,'member'),('sp-member@resok.local','$HASH',1,'member');
     INSERT INTO staff_accounts (user_id, full_name) SELECT id, CASE email WHEN 'sp-hr@resok.local' THEN 'Hana HR' WHEN 'sp-ed@resok.local' THEN 'Eddy Editor'
       WHEN 'sp-a@resok.local' THEN 'Alice Staff' ELSE 'Bob Staff' END FROM users WHERE email IN ('sp-hr@resok.local','sp-ed@resok.local','sp-a@resok.local','sp-b@resok.local');"
id() { sql "SELECT id FROM users WHERE email='$1'"; }
HR_ID=$(id sp-hr@resok.local); ED_ID=$(id sp-ed@resok.local); A_ID=$(id sp-a@resok.local); B_ID=$(id sp-b@resok.local)
login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$PW\"}" >/dev/null; echo "$jar"; }
HR=$(login sp-hr@resok.local); ED=$(login sp-ed@resok.local)
A=$(login sp-a@resok.local); B=$(login sp-b@resok.local); MEMBER=$(login sp-member@resok.local)

echo "Who gets in"
check "anonymous is refused" "401" "$(code '' staff/home)"
check "a member who is not staff is refused" "not on the staff list" "$(get "$MEMBER" staff/home)"
check "nor can they read the directory" "403" "$(code "$MEMBER" staff/directory)"
check "staff get their home page" '"onStaffList":true' "$(get "$A" staff/home)"
check "plain staff hold no permissions" '"caps":[]' "$(get "$A" staff/home)"
check "a super admin gets in without being staff" '"super":true' "$(get "$SUPER" staff/home)"

echo "Permissions"
check "staff cannot see the permission list" "super administrator" "$(get "$A" staff/permissions)"
check "staff cannot grant permissions" "super administrator" "$(post "$HR" staff/permissions/$HR_ID '{"caps":["staff.manage"]}')"
check "an unknown permission is refused" "Unknown permission" "$(post "$SUPER" staff/permissions/$HR_ID '{"caps":["users.everything"]}')"
check "a super admin grants staff.manage" '"ok":true' "$(post "$SUPER" staff/permissions/$HR_ID '{"caps":["staff.manage"]}')"
check "and announcements.publish" '"ok":true' "$(post "$SUPER" staff/permissions/$ED_ID '{"caps":["announcements.publish"]}')"
check "granting signs them out so it applies at once" "1" "$(sql "SELECT COUNT(*) FROM session_revocations WHERE user_id=$HR_ID")"
sleep 1; HR=$(login sp-hr@resok.local); ED=$(login sp-ed@resok.local)
check "the HR officer now holds staff.manage" '"caps":["staff.manage"]' "$(get "$HR" staff/home)"
check "but still cannot change permissions" "super administrator" "$(post "$HR" staff/permissions/$A_ID '{"caps":["staff.manage"]}')"

echo "Departments"
check "plain staff cannot add a department" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -X POST -H 'Content-Type: application/json' -d '{"name":"SPTEST Nope"}' "${API}staff/departments")"
check "HR adds one" "SPTEST Programmes" "$(post "$HR" staff/departments '{"name":"SPTEST Programmes"}')"
post "$HR" staff/departments '{"name":"SPTEST Finance"}' >/dev/null
check "names are unique" "already a department" "$(post "$HR" staff/departments '{"name":"SPTEST Finance"}')"
PROG=$(sql "SELECT id FROM staff_departments WHERE name='SPTEST Programmes'")
FIN=$(sql "SELECT id FROM staff_departments WHERE name='SPTEST Finance'")

echo "Staff records"
check "plain staff cannot open staff records" "403" "$(code "$A" staff/people)"
check "nor change someone's title" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -X POST -H 'Content-Type: application/json' -d '{"jobTitle":"Director"}' "${API}staff/people/$A_ID")"
check "HR sets Alice's record" "Programme Officer" "$(post "$HR" staff/people/$A_ID "{\"name\":\"Alice Staff\",\"staffNumber\":\"RS-001\",\"jobTitle\":\"Programme Officer\",\"departmentId\":$PROG,\"managerUserId\":$HR_ID,\"startDate\":\"2025-02-01\"}")"
post "$HR" staff/people/$B_ID "{\"name\":\"Bob Staff\",\"jobTitle\":\"Accountant\",\"departmentId\":$FIN,\"managerUserId\":$A_ID}" >/dev/null
check "staff numbers are unique" "already used" "$(post "$HR" staff/people/$B_ID '{"name":"Bob Staff","staffNumber":"RS-001"}')"
check "nobody reports to themselves" "cannot report to themselves" "$(post "$HR" staff/people/$A_ID "{\"name\":\"Alice Staff\",\"managerUserId\":$A_ID}")"
check "a reporting loop is refused" "make a loop" "$(post "$HR" staff/people/$A_ID "{\"name\":\"Alice Staff\",\"managerUserId\":$B_ID}")"
check "a bad date is refused" "start date" "$(post "$HR" staff/people/$A_ID '{"name":"Alice Staff","startDate":"31/02/2025"}')"
check "the change is in the activity log" "staff_record_updated" "$(sql "SELECT action FROM staff_audit WHERE target_id='$A_ID' ORDER BY id DESC LIMIT 1")"

echo "Own profile"
check "Alice edits her own work phone" '"workPhone":"+254 700 000 001"' "$(post "$A" staff/profile '{"workPhone":"+254 700 000 001","office":"Nairobi","bio":"Programmes"}')"
check "a phone with letters is refused" "work phone" "$(post "$A" staff/profile '{"workPhone":"call me"}')"
R=$(post "$A" staff/profile '{"workPhone":"+254 700 000 001","office":"Nairobi","jobTitle":"Director","staffNumber":"X"}')
check "her title cannot be changed from her own profile" '"jobTitle":"Programme Officer"' "$R"
check "nor her staff number" '"staffNumber":"RS-001"' "$R"

echo "Directory"
D=$(get "$B" staff/directory)
check "staff see each other's work details" '"workPhone":"+254 700 000 001"' "$D"
check "with department and manager" '"department":"SPTEST Programmes","manager":"Hana HR"' "$D"
absent "but never staff numbers" "RS-001" "$D"
absent "nor start dates" "2025-02-01" "$D"
check "search works" "Alice Staff" "$(get "$B" "staff/directory&q=Programme")"

echo "Announcements"
check "plain staff cannot write one" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$A" -X POST -H 'Content-Type: application/json' -d '{"title":"SPTEST x","body":"y"}' "${API}staff/announcements")"
ALL=$(post "$ED" staff/announcements '{"title":"SPTEST Office closed Friday","body":"The office is closed.\n\nSee you Monday.","requiresAck":true}' | json id)
FINONLY=$(post "$ED" staff/announcements "{\"title\":\"SPTEST Finance only\",\"body\":\"Budget review.\",\"departmentId\":$FIN}" | json id)
absent "a draft reaches nobody" "SPTEST Office closed" "$(get "$A" staff/announcements)"
check "nor can it be opened by number" "404" "$(code "$A" staff/announcements/$ALL)"
check "publishing reaches all active staff" '"audience":' "$(post "$ED" staff/announcements/$ALL/publish '{"email":true}')"
post "$ED" staff/announcements/$FINONLY/publish >/dev/null
check "Alice sees the all-staff one" "SPTEST Office closed" "$(get "$A" staff/announcements)"
absent "but not Finance's" "SPTEST Finance only" "$(get "$A" staff/announcements)"
check "nor by number" "404" "$(code "$A" staff/announcements/$FINONLY)"
check "Bob in Finance sees it" "SPTEST Finance only" "$(get "$B" staff/announcements)"
check "everyone in the audience is notified" "1" "$(sql "SELECT COUNT(*) FROM staff_notifications WHERE user_id=$A_ID AND dedupe_key='announcement:$ALL'")"
check "and emailed once each, through the queue" "1" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE template='staff-announcement' AND dedupe_key='staff-ann:$ALL:$A_ID'")"
post "$ED" staff/announcements/$ALL/publish '{"email":true}' >/dev/null
check "publishing twice notifies nobody twice" "1" "$(sql "SELECT COUNT(*) FROM staff_notifications WHERE user_id=$A_ID AND dedupe_key='announcement:$ALL'")"
check "nor emails anyone twice" "1" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE dedupe_key='staff-ann:$ALL:$A_ID'")"
check "opening it marks it read" '"readAt":"20' "$(get "$A" staff/announcements/$ALL)"
check "and clears its notification" "0" "$(sql "SELECT COUNT(*) FROM staff_notifications WHERE user_id=$A_ID AND dedupe_key='announcement:$ALL' AND read_at IS NULL")"
# Counted as a difference: other notices asking for confirmation may already exist.
WAITING=$(get "$A" staff/home | json toAcknowledge)
check "home counts it as waiting for confirmation" "yes" "$([ "$WAITING" -ge 1 ] && echo yes || echo "no ($WAITING)")"
check "Alice confirms she has read it" '"ok":true' "$(post "$A" staff/announcements/$ALL/ack)"
check "and it is no longer waiting" "$((WAITING - 1))" "$(get "$A" staff/home | json toAcknowledge)"
check "editors see read and confirmation counts" '"acks":1' "$(get "$ED" "staff/announcements&manage=1")"
check "plain staff cannot open the editor list" "403" "$(code "$A" "staff/announcements&manage=1")"
check "a published announcement cannot be deleted" "Only drafts" "$(post "$ED" staff/announcements/$ALL/delete)"
check "it is archived instead" '"ok":true' "$(post "$ED" staff/announcements/$ALL/archive)"
absent "and leaves the staff feed" "SPTEST Office closed" "$(get "$A" staff/announcements)"

echo "Notifications"
NID=$(sql "SELECT id FROM staff_notifications WHERE user_id=$B_ID ORDER BY id DESC LIMIT 1")
post "$A" staff/notifications/read "{\"ids\":[$NID]}" >/dev/null
check "nobody can mark someone else's as read" "NULL" "$(sql "SELECT IFNULL(read_at,'NULL') FROM staff_notifications WHERE id=$NID")"
check "Bob can mark his own" '"unread":0' "$(post "$B" staff/notifications/read '{"all":true}')"

echo "Deactivation"
check "nobody deactivates themselves" "your own account" "$(post "$HR" staff/people/$HR_ID/active '{"active":false}')"
check "HR deactivates Bob" '"active":false' "$(post "$HR" staff/people/$B_ID/active '{"active":false}')"
sleep 1; B=$(login sp-b@resok.local)
check "Bob is out of the staff portal" "not on the staff list" "$(get "$B" staff/home)"
absent "and out of the directory" "Bob Staff" "$(get "$A" staff/directory)"
check "and no longer counts as staff at sign-in" "0" "$(sql "SELECT active FROM staff_accounts WHERE user_id=$B_ID")"
check "reactivating brings him back" '"active":true' "$(post "$HR" staff/people/$B_ID/active '{"active":true}')"
check "every step is in the activity log" "staff_deactivated" "$(get "$SUPER" staff/audit)"
check "which only super admins can read" "super administrator" "$(get "$HR" staff/audit)"

cleanup

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
