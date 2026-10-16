#!/usr/bin/env bash
#
# Exercises the conference programme builder (schematic: Abstracts to programme): rooms,
# sessions with a clash check, placing accepted abstracts once and only once, poster boards,
# invited talks, the presenter-registration flag on unplaced abstracts, and publishing.
#
# Uses TESTPROG-* abstracts and "TEST " rooms and sessions on the current abstract event, and
# removes them afterwards.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source tools/dev/lib-session.sh
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }

curl -s "${API}conference/programme" >/dev/null
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED_PUB=$(q "SELECT COALESCE(MAX(published),0) FROM conf_programme_state WHERE event_id=$EV")
cleanup() {
    $MYSQL -e "
      DELETE i FROM conf_session_items i JOIN conf_sessions s ON s.id = i.session_id WHERE s.title LIKE 'TEST %';
      DELETE i FROM conf_session_items i JOIN abs_abstracts a ON a.id = i.abstract_id WHERE a.reference LIKE 'TESTPROG-%';
      DELETE FROM conf_sessions WHERE title LIKE 'TEST %';
      DELETE FROM conf_rooms WHERE name LIKE 'TEST %';
      DELETE d FROM abs_decisions d JOIN abs_abstracts a ON a.id = d.abstract_id WHERE a.reference LIKE 'TESTPROG-%';
      DELETE au FROM abs_authors au JOIN abs_abstracts a ON a.id = au.abstract_id WHERE a.reference LIKE 'TESTPROG-%';
      DELETE FROM abs_abstracts WHERE reference LIKE 'TESTPROG-%';
      DELETE FROM users WHERE email = 'prog-reader@resok.local';" 2>/dev/null
}
cleanup

DEV=$(q "SELECT id FROM users WHERE email='dev@resok.local'")
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES ('prog-reader@resok.local', '$HASH', 1, 'member');"
for n in 1 2 3; do
  $MYSQL -e "INSERT INTO abs_abstracts (event_id, submitter_user_id, reference, title, status, submitted_at) VALUES ($EV, $DEV, 'TESTPROG-$n', 'Test abstract $n on lung health', 'accepted', NOW());
             SET @a = LAST_INSERT_ID();
             INSERT INTO abs_authors (abstract_id, first_name, last_name, email, affiliation, is_presenting, is_corresponding) VALUES (@a, 'Pres', 'Enter$n', 'presenter$n@resok.local', 'KEMRI', 1, 1);
             INSERT INTO abs_decisions (abstract_id, outcome, presentation_type, decided_at) VALUES (@a, IF($n=3,'reject','accept'), IF($n=2,'poster','oral'), NOW());"
done
A1=$(q "SELECT id FROM abs_abstracts WHERE reference='TESTPROG-1'")
A2=$(q "SELECT id FROM abs_abstracts WHERE reference='TESTPROG-2'")
A3=$(q "SELECT id FROM abs_abstracts WHERE reference='TESTPROG-3'")

ADMIN=$(minted_session dev@resok.local)
READER=$(minted_session prog-reader@resok.local)

echo "Building:"
r=$(get "$READER" conference/admin/programme)
check "an ordinary account cannot open the builder"             'Only the programme chair' "$r"
r=$(get "$ADMIN" conference/admin/programme)
check "accepted abstracts are listed to place"                  'TESTPROG-1' "$r"
[[ "$r" == *"TESTPROG-3"* ]] && check "a rejected abstract is not offered" "absent" "present" || check "a rejected abstract is not offered" "x" "x"
check "the presenter's registration is shown"                   '"presenterRegistration":"none"' "$r"
post "$ADMIN" conference/admin/programme/rooms '{"name":"TEST Hall A","capacity":300}' >/dev/null
ROOM=$(q "SELECT id FROM conf_rooms WHERE name='TEST Hall A'")
r=$(post "$ADMIN" conference/admin/programme/sessions "{\"day\":\"2027-08-10\",\"startsAt\":\"11:00\",\"endsAt\":\"10:00\",\"title\":\"TEST Backwards\",\"kind\":\"oral\"}")
check "a session that ends before it starts is refused"         'must end after' "$r"
post "$ADMIN" conference/admin/programme/sessions "{\"day\":\"2027-08-10\",\"startsAt\":\"09:00\",\"endsAt\":\"10:30\",\"roomId\":$ROOM,\"title\":\"TEST Oral abstracts: TB\",\"kind\":\"oral\",\"chairs\":\"Dr Chair\"}" >/dev/null
r=$(post "$ADMIN" conference/admin/programme/sessions "{\"day\":\"2027-08-10\",\"startsAt\":\"10:00\",\"endsAt\":\"11:00\",\"roomId\":$ROOM,\"title\":\"TEST Clash\",\"kind\":\"symposium\"}")
check "two sessions in one room at once are refused"            'already has' "$r"
post "$ADMIN" conference/admin/programme/sessions "{\"day\":\"2027-08-10\",\"startsAt\":\"13:00\",\"endsAt\":\"14:00\",\"title\":\"TEST Posters\",\"kind\":\"poster\"}" >/dev/null
ORAL=$(q "SELECT id FROM conf_sessions WHERE title='TEST Oral abstracts: TB'")
POSTER=$(q "SELECT id FROM conf_sessions WHERE title='TEST Posters'")

r=$(post "$ADMIN" "conference/admin/programme/sessions/$ORAL/items" "{\"abstractId\":$A1,\"startsAt\":\"09:10\"}")
check "an accepted abstract is placed in a session"             'TESTPROG-1' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$POSTER/items" "{\"abstractId\":$A1,\"board\":\"P1\"}")
check "an abstract cannot be placed twice"                      'already placed' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$POSTER/items" "{\"abstractId\":$A2}")
check "a poster needs a board number"                           'board number' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$POSTER/items" "{\"abstractId\":$A2,\"board\":\"P1\"}")
check "a poster is placed on its board"                         '"board":"P1"' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$ORAL/items" "{\"abstractId\":$A3}")
check "a rejected abstract cannot be placed"                    'Only accepted abstracts' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$ORAL/items" '{"title":"Keynote: The future of TB care","speaker":"Prof. Guest","startsAt":"09:40"}')
check "an invited talk is added with its speaker"               'Prof. Guest' "$r"
check "placed abstracts leave the to-place list"                '"unplaced":[]' "$r"

echo "Publishing:"
post "$ADMIN" conference/admin/programme/publish '{"published":false}' >/dev/null
r=$(curl -s "${API}conference/programme")
check "an unpublished programme shows nothing to the public"    '"published":false,"days":[]' "$r"
r=$(get "$ADMIN" conference/programme)
check "the builder sees a preview"                              '"preview":true' "$r"
post "$ADMIN" conference/admin/programme/publish '{"published":true}' >/dev/null
r=$(curl -s "${API}conference/programme")
check "a published programme is public"                         'Keynote: The future of TB care' "$r"
check "abstract titles and presenters come from the abstract"   'Pres Enter1' "$r"
r=$(post "$ADMIN" "conference/admin/programme/sessions/$ORAL/delete" '{}')
check "deleting a session returns its abstracts to the list"    'TESTPROG-1' "$(echo "$r" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d["unplaced"]);')"

cleanup
$MYSQL -e "UPDATE conf_programme_state SET published=$SAVED_PUB WHERE event_id=$EV;" 2>/dev/null
rm -f "$ADMIN" "$READER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
