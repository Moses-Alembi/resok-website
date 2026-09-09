#!/usr/bin/env bash
#
# Exercises the nomination phase.
#
# The claims worth testing are about who may shape a ballot paper. Only the electorate can
# nominate, and only members in good standing can be nominated. A member may put one name
# forward per post, so nobody can fill a ballot alone. Being nominated by somebody else is
# not the same as agreeing to stand. And voting cannot open while names can still be added.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
jsonint() { $PHP -r 'preg_match("/\"'"$2"'\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";' <<< "$1"; }

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email LIKE 'nom-%@resok.local';
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('nom-a@resok.local','$HASH',1,'member'),
 ('nom-b@resok.local','$HASH',1,'member'),
 ('nom-lapsed@resok.local','$HASH',1,'member'),
 ('nom-outsider@resok.local','$HASH',1,'member');" >/dev/null

for who in a b lapsed outsider; do
  UID_=$($MYSQL -N -e "SELECT id FROM users WHERE email='nom-$who@resok.local';")
  DUE='2026-11-30'; [[ "$who" == "lapsed" ]] && DUE='2024-01-01'
  $MYSQL -e "INSERT INTO member_profiles (user_id,first_name,surname,membership_status,membership_id,renewal_due)
             VALUES ($UID_,'Nom','$who','active','NOM-$who','$DUE');" >/dev/null
  PID=$($MYSQL -N -e "SELECT id FROM member_profiles WHERE user_id=$UID_;")
  # The outsider paid nothing, so never reaches the roll. The lapsed member paid, so is on
  # the roll, but is out of good standing and must not be nominatable.
  if [[ "$who" != "outsider" ]]; then
    $MYSQL -e "INSERT INTO member_payment_years (member_profile_id,year,amount,source)
               VALUES ($PID,2025,5000,'register');" >/dev/null
  fi
done
PID_A=$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='nom-a@resok.local';")
PID_B=$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='nom-b@resok.local';")
PID_L=$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='nom-lapsed@resok.local';")

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 20 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 20 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }

OFFICER=$(login dev@resok.local DevAdmin2026!)
MEM_A=$(login nom-a@resok.local TestPass123)
MEM_B=$(login nom-b@resok.local TestPass123)
OUTSIDER=$(login nom-outsider@resok.local TestPass123)

$MYSQL -e "DELETE FROM elections WHERE slug LIKE 'test-nom%';" >/dev/null

echo "Setting up an election with a nomination window:"
NEW=$(post "$OFFICER" 'admin/elections' \
  '{"title":"Test Nom Election","eligibilityCutoff":"2026-09-01","nominationsOpenAt":"2026-09-01 08:00","nominationsCloseAt":"2026-09-20 17:00","opensAt":"2026-09-25 08:00","closesAt":"2030-01-01 17:00"}')
check "an election with nomination dates is created" '"nominationsOpenAt"' "$NEW"
EID=$(jsonint "$NEW" id)
check "nominations closing after voting opens is refused" 'close before voting opens' \
      "$(post "$OFFICER" 'admin/elections' '{"title":"Overlap","eligibilityCutoff":"2026-09-01","nominationsOpenAt":"2026-09-01 08:00","nominationsCloseAt":"2026-12-01 17:00","opensAt":"2026-10-01 08:00","closesAt":"2030-01-01 17:00"}')"
check "one nomination date without the other is refused" 'both nomination dates' \
      "$(post "$OFFICER" 'admin/elections' '{"title":"Half","eligibilityCutoff":"2026-09-01","nominationsOpenAt":"2026-09-01 08:00","opensAt":"2026-10-01 08:00","closesAt":"2030-01-01 17:00"}')"

POS=$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Secretary","seats":1,"maxChoices":1}')
POSID=$(jsonint "$POS" id)

echo
echo "Opening nominations:"
check "nominations need a roll first"            'Draw the electoral roll' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations"}')"
post "$OFFICER" "admin/elections/$EID/roll" '{"paidYear":2025}' >/dev/null
check "the roll includes the lapsed member"      '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll WHERE election_id=$EID AND member_profile_id=$PID_L;")"
check "but not the one who never paid"           '0' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll r JOIN users u ON u.id=r.user_id
                       WHERE r.election_id=$EID AND u.email='nom-outsider@resok.local';")"
OPENNOM=$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations"}')
check "nominations open"                         '"status":"nominations"' "$OPENNOM"
check "and the window reads as open"             '"nominationsOpen":true'  "$OPENNOM"

echo
echo "Nominating:"
check "someone off the roll cannot nominate"     'on the electoral roll may nominate' \
      "$(post "$OUTSIDER" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_A}")"
check "a lapsed member cannot be nominated"      'not in good standing' \
      "$(post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_L}")"

SELF=$(post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_A}")
check "a member may nominate themselves"         '"nominated":true'   "$SELF"
check "and that counts as accepting"             '"accepted":true'    "$SELF"

check "one nomination per member per post"       'already nominated somebody' \
      "$(post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_B}")"
check "and the same person twice is refused"     'already been nominated' \
      "$(post "$MEM_B" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_A}")"

echo
echo "Nominating somebody else:"
$MYSQL -e "DELETE FROM election_candidates WHERE position_id=$POSID;" >/dev/null
OTHER=$(post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_B}")
check "a member may nominate another"            '"nominated":true'   "$OTHER"
check "which is not an acceptance"               '"accepted":false'   "$OTHER"
CANDID=$($MYSQL -N -e "SELECT id FROM election_candidates WHERE position_id=$POSID AND member_profile_id=$PID_B;")
check "it waits unaccepted"                      'NULL' \
      "$($MYSQL -N -e "SELECT IFNULL(accepted_at,'NULL') FROM election_candidates WHERE id=$CANDID;")"
check "somebody else cannot accept for them"     'not yours to answer' \
      "$(post "$MEM_A" "elections/nominations/$CANDID/accept")"
check "the nominee can accept"                   '"accepted":true' \
      "$(post "$MEM_B" "elections/nominations/$CANDID/accept")"
check "and the acceptance is recorded"           '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM election_candidates WHERE id=$CANDID AND accepted_at IS NOT NULL;")"

echo
echo "Declining is recorded, not deleted:"
$MYSQL -e "DELETE FROM election_candidates WHERE position_id=$POSID;" >/dev/null
post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_B}" >/dev/null
CANDID=$($MYSQL -N -e "SELECT id FROM election_candidates WHERE position_id=$POSID AND member_profile_id=$PID_B;")
check "a nominee may decline"                    '"accepted":false' \
      "$(post "$MEM_B" "elections/nominations/$CANDID/decline")"
check "and the record survives as withdrawn"     'withdrawn' \
      "$($MYSQL -N -e "SELECT status FROM election_candidates WHERE id=$CANDID;")"
check "with the reason on it"                    'Declined the nomination' \
      "$($MYSQL -N -e "SELECT withdrawn_reason FROM election_candidates WHERE id=$CANDID;")"

echo
echo "Voting cannot start while nominations run:"
check "opening the vote is refused"              'Close nominations before opening' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')"
check "nominations close"                        '"status":"nominations_closed"' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations_closed"}')"
check "nominating after the phase ends is refused" 'not open for this election' \
      "$(post "$MEM_A" 'elections/test-nom-election/nominate' "{\"positionId\":$POSID,\"memberProfileId\":$PID_A}")"
check "and the vote still needs an approved name" 'no approved candidates' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')"

echo
echo "The officer approves, then voting can open:"
post "$OFFICER" "admin/elections/positions/$POSID/candidates" \
     "{\"name\":\"Nom a\",\"memberProfileId\":$PID_A,\"status\":\"approved\"}" >/dev/null
check "voting opens on the approved list"        '"status":"open"' \
      "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')"

$MYSQL -e "DELETE FROM elections WHERE slug LIKE 'test-nom%';
           DELETE FROM users WHERE email LIKE 'nom-%@resok.local';" >/dev/null

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
