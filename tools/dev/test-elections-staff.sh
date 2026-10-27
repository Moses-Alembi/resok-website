#!/usr/bin/env bash
#
# Staff welfare elections on the election system (lib/elections.php, electorate = 'staff').
#
# What matters most: staff only. Members never see a welfare election, not in the list and
# not by its address, cannot nominate in it and cannot vote in it. The roll is the active
# staff who can sign in; staff nominate colleagues from that roll, the nominee accepts in the
# portal, and approval keeps them linked to their account. Board elections are untouched.
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
jint() { $PHP -r 'preg_match("/\"'"$2"'\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";' <<< "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
get()  { curl -s -b "$1" --max-time 25 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 25 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 25 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
when() { $PHP -r "echo (new DateTime('$1'))->format('Y-m-d H:i');"; }

PW='WelfareTest2026'
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW")
login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$PW\"}" >/dev/null; echo "$jar"; }

OFFICER=$(minted_session dev@resok.local)
get "$OFFICER" staff/home >/dev/null   # staff tables
cleanup() {
    sql "DELETE FROM elections WHERE title LIKE 'Welfare Test%';
         DELETE s FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE u.email LIKE 'wf-%@resok.local';
         DELETE n FROM staff_notifications n JOIN users u ON u.id = n.user_id WHERE u.email LIKE 'wf-%@resok.local';
         DELETE FROM users WHERE email LIKE 'wf-%@resok.local';"
}
cleanup
sql "DELETE FROM auth_attempts;
     INSERT INTO users (email,password_hash,email_verified,role) VALUES
       ('wf-a@resok.local','$HASH',1,'member'),('wf-b@resok.local','$HASH',1,'member'),
       ('wf-new@resok.local','$HASH',0,'member'),('wf-member@resok.local','$HASH',1,'member');
     INSERT INTO staff_accounts (user_id, full_name) SELECT id, CASE email WHEN 'wf-a@resok.local' THEN 'Wanjiku Staff'
       WHEN 'wf-b@resok.local' THEN 'Baraka Staff' ELSE 'Not Yet Signed In' END FROM users WHERE email IN ('wf-a@resok.local','wf-b@resok.local','wf-new@resok.local');"
A_ID=$(sql "SELECT id FROM users WHERE email='wf-a@resok.local'")
B_ID=$(sql "SELECT id FROM users WHERE email='wf-b@resok.local'")
A=$(login wf-a@resok.local); B=$(login wf-b@resok.local); MEMBER=$(login wf-member@resok.local)

echo "Creating a welfare election"
R=$(post "$OFFICER" admin/elections "{\"electorate\":\"staff\",\"title\":\"Welfare Test Election\",\"nominationsOpenAt\":\"$(when '-1 hour')\",\"nominationsCloseAt\":\"$(when '+30 days')\",\"opensAt\":\"$(when '+60 days')\",\"closesAt\":\"$(when '+90 days')\"}")
check "a staff election is created without a membership date" '"electorate":"staff"' "$R"
EID=$(jint "$R" id); SLUG=$(sql "SELECT slug FROM elections WHERE id=$EID")
POS=$(jint "$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Welfare Chair","seats":1,"maxChoices":1}')" id)
[ -z "$POS" ] && POS=$(sql "SELECT id FROM election_positions WHERE election_id=$EID LIMIT 1")
sql "UPDATE staff_accounts SET staff_number='WF-002' WHERE user_id=$B_ID"
R=$(post "$OFFICER" "admin/elections/$EID/roll" '{}')
check "its roll needs no paid year" '"onRoll":' "$R"
check "only active staff who can sign in are on it" "2" "$(sql "SELECT COUNT(*) FROM election_roll WHERE election_id=$EID AND email LIKE 'wf-%'")"
check "staff who have not set a password are counted, not added" '"eligibleButCannotSignIn":' "$R"
absent "no member is on a staff roll" "wf-member" "$(sql "SELECT GROUP_CONCAT(email) FROM election_roll WHERE election_id=$EID")"
check "hand-adding a member to a staff roll is refused" "staff list" "$(post "$OFFICER" "admin/elections/$EID/roll/add" '{"memberProfileId":1,"reason":"test"}')"
post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations"}' >/dev/null

echo "Staff only"
check "staff see it in the welfare list" "Welfare Test Election" "$(get "$A" "elections&electorate=staff")"
check "members cannot open the welfare list" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$MEMBER" "${API}elections&electorate=staff")"
absent "nor find it among the board elections" "Welfare Test Election" "$(get "$MEMBER" elections)"
check "nor open it by its address" "No such election" "$(get "$MEMBER" "elections/$SLUG")"
check "nor its nominations" "No such election" "$(get "$MEMBER" "elections/$SLUG/nominations")"
absent "and staff do not see it among board elections either" "Welfare Test Election" "$(get "$A" elections)"

echo "Nominations"
N=$(get "$A" "elections/$SLUG/nominations")
check "staff can nominate" '"canNominate":true' "$N"
check "the picker lists staff by account" "\"userId\":$B_ID" "$N"
check "a member cannot nominate" "electoral roll" "$(post "$MEMBER" "elections/$SLUG/nominate" "{\"positionId\":$POS,\"userId\":$A_ID}")"
check "nobody from outside can be nominated" "Only staff on the roll" "$(post "$A" "elections/$SLUG/nominate" "{\"positionId\":$POS,\"name\":\"Outsider\",\"email\":\"o@example.org\",\"phone\":\"07\",\"organisation\":\"X\"}")"
check "Wanjiku nominates Baraka" '"accepted":false' "$(post "$A" "elections/$SLUG/nominate" "{\"positionId\":$POS,\"userId\":$B_ID}")"
check "Baraka is told in the staff portal" "nominated for Welfare Chair" "$(get "$B" staff/notifications)"
CID=$(sql "SELECT c.id FROM election_candidates c WHERE c.position_id=$POS AND c.nominee_user_id=$B_ID")
echo "Staff see only what they need"
NA=$(get "$A" "elections/$SLUG/nominations")
absent "colleagues' staff numbers are not sent" "WF-002" "$NA"
check "the nominator is hidden from the nominator's colleagues" '"nominatedBy":null' "$(get "$MEMBER" "elections/$SLUG/nominations" ; get "$A" "elections/$SLUG/nominations")"
check "the nominee sees who nominated them" '"nominatedBy":"Wanjiku Staff"' "$(get "$B" "elections/$SLUG/nominations")"
check "the officer still sees the staff number" "WF-002" "$(sql "SELECT membership_id FROM election_roll WHERE election_id=$EID AND user_id=$B_ID")"
check "the nomination is his to answer" '"mine":true' "$(get "$B" "elections/$SLUG/nominations")"
check "Wanjiku cannot accept it for him" "not yours" "$(post "$A" "elections/nominations/$CID/accept")"
check "Baraka accepts" '"accepted":true' "$(post "$B" "elections/nominations/$CID/accept")"
check "Baraka self-nominates as well? no: one nomination per nominee per post" "already been nominated" "$(post "$B" "elections/$SLUG/nominate" "{\"positionId\":$POS,\"userId\":$B_ID}")"

echo "Approving keeps the nominee's account"
post "$OFFICER" "admin/elections/positions/$POS/candidates" "{\"id\":$CID,\"name\":\"Baraka Staff\",\"status\":\"approved\"}" >/dev/null
check "approved" "approved" "$(sql "SELECT status FROM election_candidates WHERE id=$CID")"
check "and still linked to Baraka" "$B_ID" "$(sql "SELECT nominee_user_id FROM election_candidates WHERE id=$CID")"

echo "Voting"
post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations_closed"}' >/dev/null
patch "$OFFICER" "admin/elections/$EID" "{\"nominationsOpenAt\":\"$(when '-3 hours')\",\"nominationsCloseAt\":\"$(when '-2 hours')\",\"opensAt\":\"$(when '-1 hour')\",\"closesAt\":\"$(when '+1 day')\"}" >/dev/null
check "voting opens" '"locked":true' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')"
check "a member cannot get a ballot" "not on the electoral roll" "$(get "$MEMBER" "elections/$SLUG/ballot")"
check "nor vote" "roll" "$(post "$MEMBER" "elections/$SLUG/vote" "{\"choices\":{\"$POS\":[$CID]}}")"
check "Wanjiku votes" '"recorded":true' "$(post "$A" "elections/$SLUG/vote" "{\"choices\":{\"$POS\":[$CID]}}")"
check "once only" "already been recorded" "$(post "$A" "elections/$SLUG/vote" "{\"choices\":{\"$POS\":[$CID]}}")"
check "Baraka votes" '"recorded":true' "$(post "$B" "elections/$SLUG/vote" "{\"choices\":{\"$POS\":[$CID]}}")"
post "$OFFICER" "admin/elections/$EID/status" '{"status":"closed"}' >/dev/null
check "the count is read as for any election" '"votes":2' "$(get "$OFFICER" "admin/elections/$EID/tally")"

echo "Board elections are unchanged"
R=$(post "$OFFICER" admin/elections "{\"title\":\"Welfare Test Board Check\",\"eligibilityCutoff\":\"2026-09-01\",\"opensAt\":\"$(when '+60 days')\",\"closesAt\":\"$(when '+90 days')\"}")
check "an election without an electorate is a members' election" '"electorate":"members"' "$R"
BID=$(jint "$R" id)
check "and its roll still needs the paid year" "paid for" "$(post "$OFFICER" "admin/elections/$BID/roll" '{}')"
sql "DELETE FROM elections WHERE id=$BID"

cleanup
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
