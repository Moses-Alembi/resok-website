#!/usr/bin/env bash
#
# Field work and its reports (lib/staff-field.php).
#
# What matters most: location is taken only at check-in and check-out; the times and
# positions the system recorded cannot be rewritten by the owner; one open check-in at a
# time; an activity logged afterwards is marked so; and reports are need-to-see - your own,
# your reporting line's, or everyone's for HR - checked on the server, so knowing a colleague's
# id gets nothing.
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
get()  { curl -s -b "$1" --max-time 20 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 20 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 20 "${API}$2"; }

PW='FieldTest2026'
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW")
login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$PW\"}" >/dev/null; echo "$jar"; }

SUPER=$(minted_session dev@resok.local)
get "$SUPER" staff/field >/dev/null   # creates the table
cleanup() {
    sql "DELETE f FROM staff_field_activities f JOIN users u ON u.id = f.user_id WHERE u.email LIKE 'fw-%@resok.local';
         DELETE g FROM staff_grants g JOIN users u ON u.id = g.user_id WHERE u.email LIKE 'fw-%@resok.local';
         DELETE s FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE u.email LIKE 'fw-%@resok.local';
         DELETE FROM users WHERE email LIKE 'fw-%@resok.local';"
}
cleanup
# A manager with one direct report, who has one of their own; a colleague outside that line;
# and an HR officer.
sql "DELETE FROM auth_attempts;
     INSERT INTO users (email,password_hash,email_verified,role) VALUES
       ('fw-boss@resok.local','$HASH',1,'member'),('fw-mid@resok.local','$HASH',1,'member'),('fw-low@resok.local','$HASH',1,'member'),
       ('fw-other@resok.local','$HASH',1,'member'),('fw-hr@resok.local','$HASH',1,'member');
     INSERT INTO staff_accounts (user_id, full_name) SELECT id, CONCAT('FW ', SUBSTRING_INDEX(SUBSTRING_INDEX(email,'@',1),'-',-1)) FROM users WHERE email LIKE 'fw-%@resok.local';"
uid() { sql "SELECT id FROM users WHERE email='fw-$1@resok.local'"; }
BOSS=$(uid boss); MID=$(uid mid); LOW=$(uid low); OTHER=$(uid other); HR=$(uid hr)
sql "UPDATE staff_accounts SET manager_user_id=$BOSS WHERE user_id=$MID; UPDATE staff_accounts SET manager_user_id=$MID WHERE user_id=$LOW;
     INSERT INTO staff_grants (user_id, capability) VALUES ($HR, 'staff.manage');
     INSERT INTO users (email,password_hash,email_verified,role) VALUES ('fw-member@resok.local','$HASH',1,'member');"
J_BOSS=$(login fw-boss@resok.local); J_MID=$(login fw-mid@resok.local); J_LOW=$(login fw-low@resok.local)
J_OTHER=$(login fw-other@resok.local); J_HR=$(login fw-hr@resok.local)
TODAY=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d');")

echo "Checking in and out"
check "check-in needs a type" "type of activity" "$(post "$J_LOW" staff/field/checkin '{"title":"x"}')"
check "the county must be a real one" "county from the list" "$(post "$J_LOW" staff/field/checkin '{"activityType":"outreach","title":"x","county":"Atlantis"}')"
R=$(post "$J_LOW" staff/field/checkin '{"activityType":"training","title":"Spirometry training","place":"Kitui CRH","county":"Kitui","position":{"lat":-1.3667,"lng":38.0106,"accuracy":12}}')
check "Low checks in with a location" '"checkin":{"lat":-1.3667' "$R"
check "a second check-in is refused" "already checked in" "$(post "$J_LOW" staff/field/checkin '{"activityType":"meeting","title":"Another"}')"
check "the open activity shows on the page" '"status":"in_progress"' "$(get "$J_LOW" staff/field)"
check "check-out needs a summary" "summary" "$(post "$J_LOW" staff/field/checkout '{}')"
sql "UPDATE staff_field_activities SET started_at = NOW() - INTERVAL 150 MINUTE WHERE user_id=$LOW AND status='in_progress'"
R=$(post "$J_LOW" staff/field/checkout '{"summary":"Trained 18 health workers","position":{"lat":-1.3668,"lng":38.0107,"accuracy":20}}')
check "check-out records the end and the location" '"checkout":{"lat":-1.3668' "$R"
check "and the time spent" '"minutes":150' "$R"
check "checking in without a location still works" '"checkin":null' "$(post "$J_LOW" staff/field/checkin '{"activityType":"outreach","title":"School talk"}')"
post "$J_LOW" staff/field/checkout '{"summary":"Talk to 200 pupils"}' >/dev/null
check "a nonsense position is ignored, not stored" '"checkin":null' "$(post "$J_MID" staff/field/checkin '{"activityType":"meeting","title":"County meeting","position":{"lat":999,"lng":38}}')"
post "$J_MID" staff/field/checkout '{"summary":"Met the CHMT"}' >/dev/null

echo "Logging afterwards"
check "a future date is refused" "future" "$(post "$J_OTHER" staff/field/log "{\"activityType\":\"event\",\"title\":\"x\",\"summary\":\"y\",\"date\":\"2099-01-01\"}")"
check "end must be after start" "end after it starts" "$(post "$J_OTHER" staff/field/log "{\"activityType\":\"event\",\"title\":\"x\",\"summary\":\"y\",\"date\":\"$TODAY\",\"startTime\":\"14:00\",\"endTime\":\"09:00\"}")"
R=$(post "$J_OTHER" staff/field/log "{\"activityType\":\"site_visit\",\"title\":\"Clinic visit\",\"county\":\"Nakuru\",\"summary\":\"Checked stock\",\"date\":\"$TODAY\",\"startTime\":\"09:00\",\"endTime\":\"11:30\"}")
check "a past activity is logged" '"source":"logged"' "$R"
check "with no location" '"checkin":null' "$R"
check "and its time counted" '"minutes":150' "$R"

echo "Corrections"
AID=$(sql "SELECT id FROM staff_field_activities WHERE user_id=$LOW AND title='Spirometry training'")
BEFORE=$(sql "SELECT CONCAT(started_at,'|',ended_at,'|',checkin_lat) FROM staff_field_activities WHERE id=$AID")
check "the owner corrects the description" "Spirometry training (corrected)" "$(post "$J_LOW" staff/field/$AID '{"activityType":"training","title":"Spirometry training (corrected)","county":"Kitui","summary":"Trained 19 health workers","startedAt":"2020-01-01 00:00:00","checkin_lat":0}')"
check "but the recorded times and location stay" "$BEFORE" "$(sql "SELECT CONCAT(started_at,'|',ended_at,'|',checkin_lat) FROM staff_field_activities WHERE id=$AID")"
check "nobody can correct someone else's activity" "No such activity of yours" "$(post "$J_MID" staff/field/$AID '{"activityType":"training","title":"Hijack","summary":"x"}')"
sql "UPDATE staff_field_activities SET created_at = NOW() - INTERVAL 2 DAY WHERE id=$AID"
check "an entry from an earlier day cannot be deleted" "Only an entry made today" "$(post "$J_LOW" staff/field/$AID/delete)"

echo "Who sees whose reports"
check "staff see their own report" '"name":"FW low"' "$(get "$J_LOW" "staff/field/report&period=day&date=$TODAY")"
check "but not a colleague's" "403" "$(code "$J_LOW" "staff/field/report&period=day&date=$TODAY&userId=$OTHER")"
check "nor their manager's" "403" "$(code "$J_LOW" "staff/field/report&period=day&date=$TODAY&userId=$MID")"
check "a manager sees a direct report" '"name":"FW mid"' "$(get "$J_BOSS" "staff/field/report&period=week&date=$TODAY&userId=$MID")"
check "and someone further down the line" '"name":"FW low"' "$(get "$J_BOSS" "staff/field/report&period=week&date=$TODAY&userId=$LOW")"
check "but not someone outside it" "403" "$(code "$J_BOSS" "staff/field/report&period=week&date=$TODAY&userId=$OTHER")"
check "HR sees anyone" '"name":"FW other"' "$(get "$J_HR" "staff/field/report&period=month&date=$TODAY&userId=$OTHER")"
check "a member who is not staff sees nothing" "403" "$(code "$(login fw-member@resok.local)" "staff/field/report&period=day&date=$TODAY")"
check "looking at someone else's report is logged" "field_report_viewed" "$(sql "SELECT action FROM staff_audit WHERE actor_user_id=$BOSS ORDER BY id DESC LIMIT 1")"

echo "Report contents"
R=$(get "$J_BOSS" "staff/field/report&period=day&date=$TODAY&userId=$LOW")
check "the day report counts the activities" '"activities":2' "$R"
check "days in the field" '"daysInField":1' "$R"
check "and counties visited" '"counties":["Kitui"]' "$R"
check "a bad period is refused" "day, week or month" "$(get "$J_LOW" "staff/field/report&period=year&date=$TODAY")"
check "a week runs Monday to Sunday" '"from":"' "$(get "$J_LOW" "staff/field/report&period=week&date=2026-10-29")"
check "the week of 29 Oct 2026 starts on Monday 26th" '"from":"2026-10-26","to":"2026-11-01"' "$(get "$J_LOW" "staff/field/report&period=week&date=2026-10-29")"
check "a month runs first to last day" '"from":"2026-02-01","to":"2026-02-28"' "$(get "$J_LOW" "staff/field/report&period=month&date=2026-02-14")"

echo "Team summary"
T=$(get "$J_BOSS" "staff/field/team&period=day&date=$TODAY")
check "a manager's team lists their line" '"name":"FW low"' "$T"
absent "and nobody outside it" "FW other" "$T"
check "HR's team is everyone" "FW other" "$(get "$J_HR" "staff/field/team&period=day&date=$TODAY")"
CSV=$(get "$J_HR" "staff/field/team&period=day&date=$TODAY&csv=1")
check "it downloads as CSV" "Staff,Period,Activities" "$CSV"
check "with the hours" "FW low" "$CSV"

cleanup
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
