#!/usr/bin/env bash
#
# Exercises the Virtual Academy through the running API.
#
# The claims worth testing are about what the catalogue promises and what a learner has
# actually done. Browsing must work signed out. A draft must not be visible to the public. A
# course with no lessons must not be publishable, and must not be enrollable. Lesson material
# must need an enrolment, not just a lesson id. And progress must be recomputed from the
# lesson rows every time, so adding a lesson to a finished course un-finishes it - because
# the learner has not seen the new one.
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
        printf '  %-58s FAIL\n      response was an error, so the absence proves nothing:\n      %.150s\n' "$1" "$3"
        fail=$((fail+1)); return
    fi
    if [[ "$3" != *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      did not want: %s\n      got: %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
jsonint() { $PHP -r 'preg_match("/\"'"$2"'\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";' <<< "$1"; }

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('ac-author@resok.local','ac-editor@resok.local','ac-learner@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('ac-author@resok.local','$HASH',1,'author'),
 ('ac-editor@resok.local','$HASH',1,'content_manager'),
 ('ac-learner@resok.local','$HASH',1,'member');" >/dev/null
$MYSQL -e "DELETE FROM academy_courses WHERE slug LIKE 'test-academy%';" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()    { curl -s -b "$1" --max-time 15 "${API}$2"; }
anon()   { curl -s --max-time 15 "${API}$1"; }
post()   { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
patch()  { curl -s -b "$1" -X PATCH --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
del()    { curl -s -b "$1" -X DELETE --max-time 15 "${API}$2"; }

AUTHOR=$(login ac-author@resok.local)
EDITOR=$(login ac-editor@resok.local)
LEARNER=$(login ac-learner@resok.local)

echo "Building a course:"
NEW=$(post "$AUTHOR" 'academy/admin/courses' \
  '{"title":"Test Academy Spirometry Basics","summary":"A short course.","category":"Diagnostics","level":"introductory","passMark":70}')
check "an author can create a course"          '"title":"Test Academy Spirometry Basics"' "$NEW"
check "it is a draft, never published outright" '"status":"draft"'   "$NEW"
check "a slug is generated"                    'test-academy-spirometry-basics' "$NEW"
CID=$(jsonint "$NEW" id)

check "a learner cannot create courses"        'do not have permission' \
      "$(post "$LEARNER" 'academy/admin/courses' '{"title":"Nope"}')"
check "a pass mark over 100 is refused"        'between 1 and 100' \
      "$(post "$AUTHOR" 'academy/admin/courses' '{"title":"Bad mark","passMark":140}')"

echo
echo "Before it has lessons:"
check "an empty course cannot be published"    'Add at least one lesson' \
      "$(post "$EDITOR" "academy/admin/courses/$CID/status" '{"status":"published"}')"

echo
echo "Modules and lessons:"
MODS=$(post "$AUTHOR" "academy/admin/courses/$CID/modules" '{"title":"Getting started"}')
check "a module is added"                      '"title":"Getting started"' "$MODS"
MID=$(jsonint "$MODS" id)

check "a video lesson needs a source"          'video lesson needs its link' \
      "$(post "$AUTHOR" "academy/admin/modules/$MID/lessons" '{"title":"Empty","kind":"video"}')"
check "a written lesson needs its text"        'written lesson needs its text' \
      "$(post "$AUTHOR" "academy/admin/modules/$MID/lessons" '{"title":"Empty","kind":"text"}')"

L1=$(post "$AUTHOR" "academy/admin/modules/$MID/lessons" \
  '{"title":"What spirometry measures","kind":"video","source":"dQw4w9WgXcQ","minutes":12}')
check "a lesson is added"                      'What spirometry measures' "$L1"
L2=$(post "$AUTHOR" "academy/admin/modules/$MID/lessons" \
  '{"title":"Reading the curve","kind":"text","body":"The flow-volume loop...","minutes":8}')
check "a second lesson is added"               'Reading the curve'  "$L2"

COURSE=$(get "$AUTHOR" "academy/admin/courses/$CID")
check "the course length is summed, not typed" '"estimatedMinutes":20' "$COURSE"
check "and formatted once for every surface"   '"duration":"20 min"'   "$COURSE"

echo
echo "Publishing:"
check "an author cannot publish"               "editor's decision" \
      "$(post "$AUTHOR" "academy/admin/courses/$CID/status" '{"status":"published"}')"
PUB=$(post "$EDITOR" "academy/admin/courses/$CID/status" '{"status":"published"}')
check "an editor can"                          '"status":"published"' "$PUB"

echo
echo "The public catalogue:"
CAT=$(anon 'academy/courses')
check "browsing works signed out"              'Test Academy Spirometry Basics' "$CAT"
check "categories are listed"                  'Diagnostics'        "$CAT"
ONE=$(anon 'academy/courses/test-academy-spirometry-basics')
check "a course opens by slug"                 '"lessonCount":2'    "$ONE"
check "the contents page lists lessons"        'Reading the curve'  "$ONE"
absent "but never the lesson material"         'flow-volume loop'   "$ONE"

DRAFT=$(post "$EDITOR" "academy/admin/courses/$CID/status" '{"status":"draft"}')
check "withdrawing returns it to draft"        '"status":"draft"'   "$DRAFT"
check "a draft is invisible to the public"     'No published course' \
      "$(anon 'academy/courses/test-academy-spirometry-basics')"
absent "and gone from the catalogue"           'Test Academy Spirometry' "$(anon 'academy/courses')"
post "$EDITOR" "academy/admin/courses/$CID/status" '{"status":"published"}' >/dev/null

echo
echo "Enrolling and learning:"
LID1=$($MYSQL -N -e "SELECT id FROM academy_lessons WHERE title='What spirometry measures' LIMIT 1;")
LID2=$($MYSQL -N -e "SELECT id FROM academy_lessons WHERE title='Reading the curve' LIMIT 1;")

check "material needs an enrolment, not an id" 'Enrol in the course' \
      "$(get "$LEARNER" "academy/lessons/$LID1")"
check "signing in is required to enrol"        'Missing token' \
      "$(curl -s -X POST --max-time 15 "${API}academy/courses/test-academy-spirometry-basics/enrol" -d '{}')"

ENR=$(post "$LEARNER" 'academy/courses/test-academy-spirometry-basics/enrol' '{}')
check "a learner enrols"                       '"progress":0'       "$ENR"
check "enrolling twice is the same enrolment"  '"progress":0' \
      "$(post "$LEARNER" 'academy/courses/test-academy-spirometry-basics/enrol' '{}')"
check "one enrolment row, not two"             '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM academy_enrolments e JOIN academy_courses c ON c.id=e.course_id WHERE c.id=$CID;")"

check "now the material opens"                 'flow-volume loop' \
      "$(get "$LEARNER" "academy/lessons/$LID2")"

P1=$(post "$LEARNER" "academy/lessons/$LID1/progress" '{"seconds":300,"completed":true}')
check "finishing one of two is 50%"            '"progress":50'      "$P1"
P2=$(post "$LEARNER" "academy/lessons/$LID2/progress" '{"seconds":200,"completed":true}')
check "finishing both is 100%"                 '"progress":100'     "$P2"
check "and the course is marked complete"      '1' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM academy_enrolments WHERE course_id=$CID AND completed_at IS NOT NULL;")"

check "time is added, not replaced"            '500' \
      "$($MYSQL -N -e "SELECT SUM(seconds_spent) FROM academy_lesson_progress p
                        JOIN academy_enrolments e ON e.id=p.enrolment_id WHERE e.course_id=$CID;")"

MINE=$(get "$LEARNER" 'academy/my-courses')
check "it appears in my courses"               'Test Academy Spirometry Basics' "$MINE"
check "with the progress on it"                '"progress":100'     "$MINE"

echo
echo "Adding a lesson to a finished course:"
post "$AUTHOR" "academy/admin/modules/$MID/lessons" \
  '{"title":"Common errors","kind":"video","source":"abc123","minutes":10}' >/dev/null
check "un-finishes it, because there is more"  '66' \
      "$($MYSQL -N -e "SELECT progress_percent FROM academy_enrolments WHERE course_id=$CID LIMIT 1;")"
check "and clears the completion date"         '0' \
      "$($MYSQL -N -e "SELECT COUNT(*) FROM academy_enrolments WHERE course_id=$CID AND completed_at IS NOT NULL;")"
check "the course length grows with it"        '"estimatedMinutes":30' \
      "$(get "$AUTHOR" "academy/admin/courses/$CID")"

echo
echo "Reordering:"
IDS=$($MYSQL -N -e "SELECT GROUP_CONCAT(id ORDER BY position) FROM academy_lessons WHERE module_id=$MID;")
REV=$(echo "$IDS" | tr ',' '\n' | tac | paste -sd, -)
check "a stale list is refused outright"       'does not match' \
      "$(post "$AUTHOR" 'academy/admin/lesson/reorder' "{\"parentId\":$MID,\"order\":[1,2]}")"
check "a full list reorders"                   '"position":1' \
      "$(post "$AUTHOR" 'academy/admin/lesson/reorder' "{\"parentId\":$MID,\"order\":[$REV]}")"
check "and the first lesson really moved"      'Common errors' \
      "$($MYSQL -N -e "SELECT title FROM academy_lessons WHERE module_id=$MID ORDER BY position LIMIT 1;")"

echo
echo "Deleting:"
DEL=$(del "$AUTHOR" "academy/admin/lesson/$LID1")
absent "the lesson is gone from the outline"   'What spirometry measures' "$DEL"
check "progress is recomputed after"           '50' \
      "$($MYSQL -N -e "SELECT progress_percent FROM academy_enrolments WHERE course_id=$CID LIMIT 1;")"

$MYSQL -e "DELETE FROM academy_courses WHERE id=$CID;
           DELETE FROM users WHERE email IN ('ac-author@resok.local','ac-editor@resok.local','ac-learner@resok.local');" >/dev/null

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
