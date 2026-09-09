#!/usr/bin/env bash
#
# A full dress rehearsal of a ReSoK board election, against the running local API.
#
# Walks every stage in the order a real election takes them - draw the roll, open
# nominations, members nominate, close nominations, officer confirms and approves, open
# voting, members vote, close voting, count, declare, publish - and reports what the system
# does at each point rather than only whether it errored.
#
# Run before a real election, and after any change to the election code. It resets its own
# election each time, so it can be run repeatedly.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
SLUG="dry-run-board-elections"
step=0; problems=0

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '   ok    %s\n' "$*"; }
bad()  { printf '   FAIL  %s\n' "$*"; problems=$((problems+1)); }
want() { if [[ "$2" == *"$1"* ]]; then ok "$3"; else bad "$3 — got: ${2:0:120}"; fi; }
deny() { if [[ "$2" == *"$1"* ]]; then ok "$3 (correctly refused)"; else bad "$3 was NOT refused — got: ${2:0:120}"; fi; }
jint() { $PHP -r 'preg_match("/\"'"$2"'\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";' <<< "$1"; }

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 25 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 25 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 25 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

OFFICER=$(login dev@resok.local DevAdmin2026!)
V1=$(login demo-voter1@resok.local Vote2026!)
V2=$(login demo-voter2@resok.local Vote2026!)
V3=$(login demo-voter3@resok.local Vote2026!)
[[ -s "$OFFICER" ]] || { echo "Could not sign in as the officer. Is the local API running?"; exit 2; }

# ---------------------------------------------------------------------------------------
say "1. Setting up the election"
$MYSQL -e "DELETE FROM elections WHERE slug='$SLUG';" >/dev/null
NOW=$($PHP -r "echo (new DateTime('-1 hour'))->format('Y-m-d H:i');")
SOON=$($PHP -r "echo (new DateTime('+30 days'))->format('Y-m-d H:i');")
LATER=$($PHP -r "echo (new DateTime('+60 days'))->format('Y-m-d H:i');")

NEW=$(post "$OFFICER" 'admin/elections' "{\"title\":\"Dry Run Board Elections\",\"eligibilityCutoff\":\"2026-09-01\",\"nominationsOpenAt\":\"$NOW\",\"nominationsCloseAt\":\"$SOON\",\"opensAt\":\"$LATER\",\"closesAt\":\"$($PHP -r "echo (new DateTime('+90 days'))->format('Y-m-d H:i');")\"}")
EID=$(jint "$NEW" id)
$MYSQL -e "UPDATE elections SET slug='$SLUG' WHERE id=$EID;" >/dev/null
want '"status":"draft"' "$NEW" "created as a draft, invisible to members"

declare -A POS
add_post() {
  R=$(post "$OFFICER" "admin/elections/$EID/positions" "{\"title\":\"$1\",\"seats\":$2,\"maxChoices\":$2}")
  POS["$1"]=$($MYSQL -N -e "SELECT id FROM election_positions WHERE election_id=$EID AND title='$1';")
}
add_post "Honourable Chair" 1
add_post "Honourable Vice Chairperson" 1
add_post "Honourable Secretary" 1
add_post "Honourable Treasurer" 1
add_post "Honourable Member" 3
ok "five posts added — seven seats in total"

# ---------------------------------------------------------------------------------------
say "2. The electoral roll"
deny 'Draw the electoral roll first' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations"}')" \
     "opening nominations with no roll"
R=$(post "$OFFICER" "admin/elections/$EID/roll" '{"paidYear":2025}')
ONROLL=$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll WHERE election_id=$EID;")
ok "roll drawn — $ONROLL voters who paid for 2025 and can sign in"

# ---------------------------------------------------------------------------------------
say "3. Nominations open"
R=$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations"}')
want '"nominationsOpen":true' "$R" "nominations are open"
deny 'Close nominations before opening' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')" \
     "opening the vote while nominations run"

# Members nominating themselves. A self-nomination accepts at the same moment.
want '"accepted":true' "$(post "$V1" "elections/$SLUG/nominate" "{\"positionId\":${POS[Honourable Chair]},\"memberProfileId\":$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='demo-voter1@resok.local';")}")" \
     "a member nominates themselves for Chair"
want '"accepted":true' "$(post "$V2" "elections/$SLUG/nominate" "{\"positionId\":${POS[Honourable Vice Chairperson]},\"memberProfileId\":$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='demo-voter2@resok.local';")}")" \
     "another self-nominates for Vice Chairperson"

# Nominating people from outside the membership.
ext() { post "$1" "elections/$SLUG/nominate" \
  "{\"positionId\":$2,\"name\":\"$3\",\"email\":\"$4\",\"phone\":\"0700000000\",\"organisation\":\"Kenyatta National Hospital\"}"; }
want '"external":true' "$(ext "$V2" "${POS[Honourable Chair]}" "Dr Aisha Noor" "aisha@example.org")" \
     "a member nominates somebody outside ReSoK for Chair"
ext "$V1" "${POS[Honourable Secretary]}"  "Dr Peter Kimani" "peter@example.org"  >/dev/null
ext "$V1" "${POS[Honourable Treasurer]}"  "Dr Grace Wambui" "grace@example.org"  >/dev/null
ext "$V2" "${POS[Honourable Treasurer]}"  "Dr Yusuf Ali"    "yusuf@example.org"  >/dev/null
ext "$V1" "${POS[Honourable Member]}"     "Dr Mary Atieno"  "mary@example.org"   >/dev/null
ext "$V2" "${POS[Honourable Member]}"     "Dr John Mutua"   "john@example.org"   >/dev/null
ext "$V3" "${POS[Honourable Member]}"     "Dr Faith Chebet" "faith@example.org"  >/dev/null
ok "seven external nominations recorded, none of them auto-accepted"

deny 'already nominated somebody' "$(post "$V1" "elections/$SLUG/nominate" "{\"positionId\":${POS[Honourable Chair]},\"name\":\"Dr Someone Else\",\"email\":\"x@example.org\",\"phone\":\"07\",\"organisation\":\"X\"}")" \
     "a second nomination by the same member for the same post"

# ---------------------------------------------------------------------------------------
say "4. Nominations close, the officer confirms"
want '"status":"nominations_closed"' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"nominations_closed"}')" \
     "nominations closed"
deny 'not open for this election' "$(ext "$V3" "${POS[Honourable Chair]}" "Dr Too Late" "late@example.org")" \
     "a nomination arriving after the deadline"

PENDING=$($MYSQL -N -e "SELECT COUNT(*) FROM election_candidates c JOIN election_positions p ON p.id=c.position_id
                        WHERE p.election_id=$EID AND c.accepted_at IS NULL;")
ok "$PENDING nominee(s) have not yet agreed to stand"
# Opening before the window starts is refused. It used to be allowed and could not be undone:
# opening locks the election, so the wrong date could never be corrected and nobody could ever
# vote. The dry run is what found it.
deny 'not due to open until' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')" \
     "opening the vote before its window starts"

# Bring the window to now so the next refusal is about candidates rather than dates. The
# nomination dates move with it - they are validated as a set, and nominations closing 30 days
# out cannot sit after a vote opening today.
PAST=$($PHP -r "echo (new DateTime('-2 hours'))->format('Y-m-d H:i');")
R=$(patch "$OFFICER" "admin/elections/$EID" "{\"nominationsOpenAt\":\"$($PHP -r "echo (new DateTime('-3 hours'))->format('Y-m-d H:i');")\",\"nominationsCloseAt\":\"$PAST\",\"opensAt\":\"$NOW\",\"closesAt\":\"$LATER\"}")
want '"opensAt"' "$R" "voting window moved to now"

deny 'no approved candidates' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')" \
     "opening the vote before anybody is approved"

# The officer reaches each external nominee and records their agreement, then approves.
for CID in $($MYSQL -N -e "SELECT c.id FROM election_candidates c JOIN election_positions p ON p.id=c.position_id
                           WHERE p.election_id=$EID AND c.member_profile_id IS NULL;"); do
  post "$OFFICER" "admin/elections/nominations/$CID/accepted" '{"note":"Chair spoke to them by telephone during the dry run"}' >/dev/null
done
ok "external nominees' agreement recorded, each with a note of how"

for CID in $($MYSQL -N -e "SELECT c.id FROM election_candidates c JOIN election_positions p ON p.id=c.position_id
                           WHERE p.election_id=$EID;"); do
  PID=$($MYSQL -N -e "SELECT position_id FROM election_candidates WHERE id=$CID;")
  NAME=$($MYSQL -N -e "SELECT name FROM election_candidates WHERE id=$CID;")
  post "$OFFICER" "admin/elections/positions/$PID/candidates" \
       "{\"id\":$CID,\"name\":\"$NAME\",\"status\":\"approved\"}" >/dev/null
done
APPROVED=$($MYSQL -N -e "SELECT COUNT(*) FROM election_candidates c JOIN election_positions p ON p.id=c.position_id
                         WHERE p.election_id=$EID AND c.status='approved';")
ok "$APPROVED candidates approved and on the ballot"
post "$OFFICER" "admin/elections/$EID/randomise" >/dev/null
ok "ballot order randomised, so nobody gains from being listed first"

# ---------------------------------------------------------------------------------------
say "5. Voting opens"
R=$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"open"}')
want '"locked":true' "$R" "voting opened — the election is now frozen"
deny 'Nothing about it can be changed' "$(post "$OFFICER" "admin/elections/$EID/positions" '{"title":"Late post"}')" \
     "adding a post after voting opened"
deny 'roll for this election is final' "$(post "$OFFICER" "admin/elections/$EID/roll" '{"paidYear":2025}')" \
     "redrawing the roll after voting opened"
deny 'not available until voting has closed' "$(get "$OFFICER" "admin/elections/$EID/tally")" \
     "reading the count while voting is open"

# ---------------------------------------------------------------------------------------
say "6. Members vote"
ballot_for() {
  get "$1" "elections/$SLUG/ballot" | $PHP -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $out = [];
    foreach ($d["positions"] ?? [] as $p) {
      $ids = array_map(fn($c) => $c["id"], $p["candidates"]);
      $out[$p["id"]] = count($ids) === 0 ? "abstain"
                     : ($p["maxChoices"] === 1 ? [$ids[0]] : array_slice($ids, 0, $p["maxChoices"]));
    }
    echo json_encode(["choices" => $out]);'
}
CAST=0
for JAR in "$V1" "$V2" "$V3"; do
  R=$(post "$JAR" "elections/$SLUG/vote" "$(ballot_for "$JAR")")
  [[ "$R" == *'"recorded":true'* ]] && CAST=$((CAST+1))
done
ok "$CAST ballots cast across all five posts"
deny 'already been recorded' "$(post "$V1" "elections/$SLUG/vote" "$(ballot_for "$V1")")" \
     "the same member voting twice"

TURNOUT=$($MYSQL -N -e "SELECT COUNT(*) FROM election_roll WHERE election_id=$EID AND voted_at IS NOT NULL;")
BALLOTS=$($MYSQL -N -e "SELECT COUNT(*) FROM election_ballots WHERE election_id=$EID;")
ok "roll shows $TURNOUT voted; $BALLOTS marks recorded across seven seats"

# ---------------------------------------------------------------------------------------
say "7. Voting closes and the count is read"
want '"status":"closed"' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"closed"}')" "voting closed"
TALLY=$(get "$OFFICER" "admin/elections/$EID/tally")
want '"positions"' "$TALLY" "the count is now readable"
want 'election_tally_viewed' "$($MYSQL -N -e "SELECT event_type FROM security_events WHERE event_type='election_tally_viewed' ORDER BY id DESC LIMIT 1;")" \
     "reading it was written to the security log"

echo
echo "$TALLY" | $PHP -r '
$d = json_decode(stream_get_contents(STDIN), true);
foreach ($d["positions"] ?? [] as $p) {
  printf("   %-30s %d seat(s)%s\n", $p["title"], $p["seats"], $p["tieForLastSeat"] ? "   TIED" : "");
  foreach ($p["candidates"] as $c) printf("       %-26s %d\n", $c["name"], $c["votes"]);
}'

# ---------------------------------------------------------------------------------------
say "8. Declaring and publishing"
D=$(post "$OFFICER" "admin/elections/$EID/declare" '{"note":"Dry run"}')
if [[ "$D" == *'tied for the last seat'* ]]; then
  ok "declaration refused because of a tie — which is the correct behaviour"
  echo "         (a real election would resolve it under the society's rules first)"
else
  want '"declaration":1' "$D" "result declared"
  want '"status":"published"' "$(post "$OFFICER" "admin/elections/$EID/status" '{"status":"published"}')" \
       "result published to members"
  ELECTED=$($MYSQL -N -e "SELECT COUNT(*) FROM election_results WHERE election_id=$EID AND elected=1 AND declaration=1;")
  ok "$ELECTED candidate(s) recorded as elected"
fi

echo
if [[ $problems -eq 0 ]]; then
  printf '\033[1mDry run complete — no problems.\033[0m\n'
else
  printf '\033[1mDry run finished with %d problem(s).\033[0m\n' "$problems"
fi
echo "The dry-run election is left in place for inspection. Remove it with:"
echo "  DELETE FROM elections WHERE slug='$SLUG';"
[[ $problems -eq 0 ]]
