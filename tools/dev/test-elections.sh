#!/usr/bin/env bash
#
# Exercises the election system through the running API.
#
# The claims worth testing are the ones that decide whether a result can be trusted. Only
# eligible members reach the roll. The roll and the ballot paper cannot change once voting
# has opened. A member votes once, and a second attempt is refused rather than silently
# ignored. Nobody can read the count while voting is still open. And a tie for the last seat
# stops a declaration instead of being resolved by a sort order.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *'"error"'* ]]; then
        printf '  %-58s FAIL\n      response was an error: %.140s\n' "$1" "$3"; fail=$((fail+1)); return
    fi
    if [[ "$3" != *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      did not want: %s\n      got: %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
jsonint() { $PHP -r 'preg_match("/\"'"$2"'\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";' <<< "$1"; }

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)

# A clean electorate: three who paid the election year, one who did not, one unverified.
$MYSQL -e "
DELETE FROM users WHERE email LIKE 'el-%@resok.local';
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('el-officer@resok.local','$HASH',1,'admin'),
 ('el-a@resok.local','$HASH',1,'member'),
 ('el-b@resok.local','$HASH',1,'member'),
 ('el-c@resok.local','$HASH',1,'member'),
 ('el-unpaid@resok.local','$HASH',1,'member'),
 ('el-unverified@resok.local','$HASH',0,'member');" >/dev/null

for who in a b c unpaid unverified; do
  UID_=$($MYSQL -N -e "SELECT id FROM users WHERE email='el-$who@resok.local';")
  $MYSQL -e "INSERT INTO member_profiles (user_id,first_name,surname,membership_status,membership_id,renewal_due)
             VALUES ($UID_,'Voter','$who','active','EL-TEST-$who','2026-11-30');" >/dev/null
  PID=$($MYSQL -N -e "SELECT id FROM member_profiles WHERE user_id=$UID_;")
  if [[ "$who" != "unpaid" ]]; then
    $MYSQL -e "INSERT INTO member_payment_years (member_profile_id,year,amount,source)
               VALUES ($PID,2025,5000,'register');" >/dev/null
  fi
done

# The officer must be a super admin; the config lists dev@resok.local, so use that account.
login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 20 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 20 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

OFFICER=$(login dev@resok.local DevAdmin2026!)
VOTER_A=$(login el-a@resok.local TestPass123)
VOTER_B=$(login el-b@resok.local TestPass123)
VOTER_C=$(login el-c@resok.local TestPass123)
UNPAID=$(login el-unpaid@resok.local TestPass123)

$MYSQL -e "DELETE FROM elections WHERE slug LIKE 'test-board%';" >/dev/null

echo "Setting up:"
NEW=$(post "$OFFICER" 'admin/elections' \
  '{"title":"Test Board Election","eligibilityCutoff":"2026-09-01","opensAt":"2026-09-01 08:00","closesAt":"2030-01-01 17:00"}')
check "an election is created as a draft"       '"status":"draft"'   "$NEW"
EID=$(jsonint "$NEW" id)
check "a member cannot create one"              'Admin access required' \
      "$(post "$VOTER_A" 'admin/elections' '{"title":"Nope"}')"
check "closing before opening is refused"       'must close after' \
      "$(post "$OFFICER" 'admin/elections' '{"title":"Backwards","eligibilityCutoff":"2026-09-01","opensAt":"2026-09-10 08:00","closesAt":"2026-09-01 08:00"}')"

POS=$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Chairperson","seats":1,"maxChoices":1}')
check "a post is added"                         'Chairperson'        "$POS"
PID=$(jsonint "$POS" id)
check "more choices than seats is refused"      'more choices than there are seats' \
      "$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Bad","seats":1,"maxChoices":3}')"

C1=$(post "$OFFICER" "admin/elections/positions/$PID/candidates" '{"name":"Dr Alpha","status":"approved"}')
check "a candidate is added"                    'Dr Alpha'           "$C1"
post "$OFFICER" "admin/elections/positions/$PID/candidates" '{"name":"Dr Beta","status":"approved"}' >/dev/null
post "$OFFICER" "admin/elections/positions/$PID/candidates" '{"name":"Dr Gamma","status":"withdrawn"}' >/dev/null
AID=$($MYSQL -N -e "SELECT id FROM election_candidates WHERE name='Dr Alpha' AND position_id=$PID;")
BID=$($MYSQL -N -e "SELECT id FROM election_candidates WHERE name='Dr Beta' AND position_id=$PID;")
GID=$($MYSQL -N -e "SELECT id FROM election_candidates WHERE name='Dr Gamma' AND position_id=$PID;")

echo
echo "The roll:"
check "cannot open before a roll is drawn"      'electoral roll is empty' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')"
ROLL=$(post "$OFFICER" "admin/elections/$EID/roll" '{"paidYear":2025}')
check "the roll is drawn"                       '"onRoll"'           "$ROLL"
check "only members who paid are on it"         '3' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll r JOIN users u ON u.id=r.user_id
                       WHERE r.election_id=$EID AND u.email LIKE 'el-%';")"
check "the unpaid member is not on the roll"    '0' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll r JOIN users u ON u.id=r.user_id
                       WHERE r.election_id=$EID AND u.email='el-unpaid@resok.local';")"
check "nor is the unverified one"               '0' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll r JOIN users u ON u.id=r.user_id
                       WHERE r.election_id=$EID AND u.email='el-unverified@resok.local';")"
check "the rule that drew it is recorded"       'paid 2025' \
      "$($MYSQL -N -e "SELECT DISTINCT standing_at_cutoff FROM election_roll WHERE election_id=$EID LIMIT 1;")"

echo
echo "Before voting opens:"
check "a voter cannot vote yet"                 'not open' \
      "$(post "$VOTER_A" "elections/test-board-election/vote" "{\"choices\":{\"$PID\":$AID}}")"
check "nobody can read the count"               'not available until voting has closed' \
      "$(get "$OFFICER" "admin/elections/$EID/tally")"

echo
echo "Opening:"
OPEN=$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')
check "the election opens"                      '"status":"open"'    "$OPEN"
check "and is now locked"                       '"locked":true'      "$OPEN"
check "posts cannot be changed once open"       'Nothing about it can be changed' \
      "$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Sneaky"}')"
check "candidates cannot be added once open"    'Nothing about it can be changed' \
      "$(post "$OFFICER" "admin/elections/positions/$PID/candidates" '{"name":"Dr Late","status":"approved"}')"
check "the roll cannot be redrawn once open"    'roll for this election is final' \
      "$(post "$OFFICER" "admin/elections/$EID/roll" '{"paidYear":2025}')"

echo
echo "The ballot:"
BALLOT=$(get "$VOTER_A" 'elections/test-board-election/ballot')
check "a voter on the roll gets a ballot"       'Chairperson'        "$BALLOT"
check "approved candidates appear"              'Dr Alpha'           "$BALLOT"
absent "withdrawn candidates do not"            'Dr Gamma'           "$BALLOT"
check "someone off the roll is refused"         'not on the electoral roll' \
      "$(get "$UNPAID" 'elections/test-board-election/ballot')"

echo
echo "Voting:"
check "a withdrawn candidate cannot be voted for" 'not standing' \
      "$(post "$VOTER_A" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":$GID}}")"
check "more marks than allowed is refused"      'choose at most 1' \
      "$(post "$VOTER_A" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":[$AID,$BID]}}")"
check "and nothing was recorded by those"       '0' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_ballots WHERE election_id=$EID;")"

check "a valid ballot is recorded"              '"recorded":true' \
      "$(post "$VOTER_A" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":$AID}}")"
check "voting twice is refused"                 'already been recorded' \
      "$(post "$VOTER_A" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":$BID}}")"
check "and the second vote left no trace"       '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_ballots WHERE election_id=$EID;")"
check "the roll records that they voted"        '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll WHERE election_id=$EID AND voted_at IS NOT NULL;")"
check "someone off the roll cannot vote"        'not on the electoral roll' \
      "$(post "$UNPAID" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":$AID}}")"

post "$VOTER_B" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":$BID}}" >/dev/null
check "an abstention is recorded as one"        '"recorded":true' \
      "$(post "$VOTER_C" 'elections/test-board-election/vote' "{\"choices\":{\"$PID\":\"abstain\"}}")"
check "and stored as an abstention"             '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_ballots WHERE election_id=$EID AND is_abstain=1;")"

echo
echo "While voting is open:"
check "the count is still not readable"         'not available until voting has closed' \
      "$(get "$OFFICER" "admin/elections/$EID/tally")"
check "a member cannot read it either"          'Admin access required' \
      "$(get "$VOTER_A" "admin/elections/$EID/tally")"

echo
echo "Closing and counting:"
check "the election closes"                     '"status":"closed"' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"closed"}')"
TALLY=$(get "$OFFICER" "admin/elections/$EID/tally")
check "the count is now available"              'Chairperson'        "$TALLY"
check "turnout is reported"                     '"turnout"'          "$TALLY"
check "reading the count is logged"             'election_tally_viewed' \
      "$($MYSQL -N -e "SELECT event_type FROM security_events WHERE event_type='election_tally_viewed' LIMIT 1;")"

echo
echo "A tie stops a declaration:"
check "the tied result is refused"              'tied for the last seat' \
      "$(post "$OFFICER" "admin/elections/$EID/declare" '{}')"

# Break the tie with the third voter's ballot changed to Alpha, then declare.
$MYSQL -e "UPDATE election_ballots SET candidate_id=$AID, is_abstain=0
           WHERE election_id=$EID AND is_abstain=1;" >/dev/null
DECL=$(post "$OFFICER" "admin/elections/$EID/declare" '{"note":"Test declaration"}')
check "an untied result declares"               '"declaration":1'    "$DECL"
check "the winner is marked elected"            'Dr Alpha'           "$DECL"
check "the result is stored, not recomputed"    '2' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_results WHERE election_id=$EID AND declaration=1;")"
check "publishing requires a declared result"   '"status":"published"' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"published"}')"

$MYSQL -e "DELETE FROM elections WHERE slug LIKE 'test-board%';
           DELETE FROM users WHERE email LIKE 'el-%@resok.local';" >/dev/null

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
