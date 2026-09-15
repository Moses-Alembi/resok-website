#!/usr/bin/env bash
#
# Exercises Virtual Academy final assessments through the running API.
#
# The claims worth testing are about a fair test. The browser must never be sent the answers.
# An attempt must keep its questions, so reloading does not redraw them. Marking must be the
# server's, and half of a multiple-choice answer must not count. The attempt limit must hold,
# the right answers must stay hidden until they cannot be used to pass, and a course with a
# final assessment must not issue a certificate until it is passed - with the score on it.
#
# Uses its own quiz-*@resok.local accounts and a test-quiz-* course, and removes them afterwards.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *'"error"'* ]]; then
        printf '  %-62s FAIL\n      response was an error, so the absence proves nothing:\n      %.160s\n' "$1" "$3"
        fail=$((fail+1)); return
    fi
    if [[ "$3" != *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      did not want: %s\n      got: %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
field() { grep -o "\"$1\":[0-9]*" <<< "$2" | head -1 | cut -d: -f2; }

cleanup() {
    $MYSQL -e "DELETE FROM academy_courses WHERE slug LIKE 'test-quiz-%';
               DELETE FROM users WHERE email LIKE 'quiz-%@resok.local';" >/dev/null
}
cleanup

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "
INSERT INTO users (email, password_hash, email_verified, role) VALUES
 ('quiz-a@resok.local', '$HASH', 1, 'member'),
 ('quiz-b@resok.local', '$HASH', 1, 'member'),
 ('quiz-c@resok.local', '$HASH', 1, 'member');
INSERT INTO academy_courses (slug, title, summary, description, category, level, estimated_minutes, members_only, certificate_enabled, pass_mark, status, published_at)
 VALUES ('test-quiz-course', 'Test Course With a Final Assessment', 'Summary', 'Description', 'Diagnostics', 'introductory', 5, 0, 1, 60, 'published', NOW());
INSERT INTO academy_modules (course_id, title, summary, position) SELECT id, 'Module one', '', 1 FROM academy_courses WHERE slug = 'test-quiz-course';
INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
 SELECT m.id, 'Lesson one', 'text', 'Body', 5, 1 FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id WHERE c.slug = 'test-quiz-course';
INSERT INTO academy_assessments (course_id, module_id, title, pass_mark, max_attempts, question_count, shuffle_questions)
 SELECT id, NULL, 'Final assessment', NULL, 2, 0, 1 FROM academy_courses WHERE slug = 'test-quiz-course';
SET @a := (SELECT a.id FROM academy_assessments a JOIN academy_courses c ON c.id = a.course_id WHERE c.slug = 'test-quiz-course');
INSERT INTO academy_questions (assessment_id, prompt, kind, explanation, position) VALUES (@a, 'Quiz question one', 'single', 'Explanation for question one', 1);
SET @q1 := LAST_INSERT_ID();
INSERT INTO academy_options (question_id, label, is_correct, position) VALUES (@q1, 'QOne A', 0, 1), (@q1, 'QOne B', 1, 2), (@q1, 'QOne C', 0, 3), (@q1, 'QOne D', 0, 4);
INSERT INTO academy_questions (assessment_id, prompt, kind, explanation, position) VALUES (@a, 'Quiz question two', 'truefalse', 'Explanation for question two', 2);
SET @q2 := LAST_INSERT_ID();
INSERT INTO academy_options (question_id, label, is_correct, position) VALUES (@q2, 'QTwo True', 1, 1), (@q2, 'QTwo False', 0, 2);
INSERT INTO academy_questions (assessment_id, prompt, kind, explanation, position) VALUES (@a, 'Quiz question three', 'multiple', 'Explanation for question three', 3);
SET @q3 := LAST_INSERT_ID();
INSERT INTO academy_options (question_id, label, is_correct, position) VALUES (@q3, 'QThree A', 1, 1), (@q3, 'QThree B', 0, 2), (@q3, 'QThree C', 1, 3), (@q3, 'QThree D', 0, 4);" >/dev/null

qid() { q "SELECT id FROM academy_questions WHERE prompt = '$1' AND assessment_id = (SELECT a.id FROM academy_assessments a JOIN academy_courses c ON c.id = a.course_id WHERE c.slug = 'test-quiz-course');"; }
oid() { q "SELECT o.id FROM academy_options o JOIN academy_questions qq ON qq.id = o.question_id JOIN academy_assessments a ON a.id = qq.assessment_id JOIN academy_courses c ON c.id = a.course_id WHERE c.slug = 'test-quiz-course' AND o.label = '$1';"; }
Q1=$(qid 'Quiz question one'); Q2=$(qid 'Quiz question two'); Q3=$(qid 'Quiz question three')
A1=$(oid 'QOne A'); B1=$(oid 'QOne B'); T2=$(oid 'QTwo True'); F2=$(oid 'QTwo False')
A3=$(oid 'QThree A'); B3=$(oid 'QThree B'); C3=$(oid 'QThree C')
LESSON=$(q "SELECT l.id FROM academy_lessons l JOIN academy_modules m ON m.id = l.module_id JOIN academy_courses c ON c.id = m.course_id WHERE c.slug = 'test-quiz-course';")

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
C='academy/courses/test-quiz-course'

A=$(login quiz-a@resok.local); B=$(login quiz-b@resok.local); CC=$(login quiz-c@resok.local)

echo "Before the lessons are finished:"
post "$A" "$C/enrol" '{}' >/dev/null
STATUS=$(get "$A" "$C/assessment")
check "the course reports its final assessment"                '"title":"Final assessment"' "$STATUS"
check "with the course's pass mark"                            '"passMark":60' "$STATUS"
check "it cannot be started yet"                               '"canStart":false' "$STATUS"
check "and says why"                                           'finished every lesson' "$STATUS"
check "starting now is refused"                                'finished every lesson' "$(post "$A" "$C/assessment/start" '{}')"

echo "Opening an attempt:"
post "$A" "academy/lessons/$LESSON/progress" '{"completed":true,"seconds":30}' >/dev/null
check "the certificate waits for the assessment"               'Pass the final assessment' "$(get "$A" "$C/certificate")"
PAPER=$(post "$A" "$C/assessment/start" '{}')
ATTEMPT=$(field attemptId "$PAPER")
check "an attempt opens"                                       '"attemptId":' "$PAPER"
check "with every question"                                    '3' "$(grep -o '"prompt"' <<< "$PAPER" | wc -l | tr -d ' ')"
absent "without saying which answers are right"                '"correct"' "$PAPER"
absent "or giving the explanations away"                       'Explanation for question' "$PAPER"
check "reopening returns the same attempt"                     "\"attemptId\":$ATTEMPT" "$(post "$A" "$C/assessment/start" '{}')"
check "and does not draw a second one"                         '1' "$(q "SELECT COUNT(*) FROM academy_attempts t JOIN academy_enrolments e ON e.id = t.enrolment_id JOIN users u ON u.id = e.user_id WHERE u.email = 'quiz-a@resok.local';")"

echo "A failed attempt:"
WRONG=$(post "$A" "$C/assessment/submit" "{\"attemptId\":$ATTEMPT,\"answers\":{\"$Q1\":[$A1],\"$Q2\":[$F2],\"$Q3\":[$B3]}}")
check "it is marked on the server"                             '"scorePercent":0' "$WRONG"
check "and not passed"                                         '"passed":false' "$WRONG"
check "the answers stay hidden while attempts remain"          '"revealed":false' "$WRONG"
absent "no explanation is given away"                          'Explanation for question' "$WRONG"
check "the same attempt cannot be submitted twice"             'already been submitted' "$(post "$A" "$C/assessment/submit" "{\"attemptId\":$ATTEMPT,\"answers\":{}}")"
check "someone else cannot submit it"                          '"error"' "$(post "$B" "$C/assessment/submit" "{\"attemptId\":$ATTEMPT,\"answers\":{}}")"
STATUS=$(get "$A" "$C/assessment")
check "one attempt is left"                                    '"attemptsLeft":1' "$STATUS"
check "and the last score is reported"                         '"lastScore":0' "$STATUS"
check "the certificate still waits"                            'Pass the final assessment' "$(post "$A" "$C/certificate" '{"name":"Quiz Learner"}')"

echo "Passing on the second attempt:"
PAPER=$(post "$A" "$C/assessment/start" '{}')
ATTEMPT2=$(field attemptId "$PAPER")
check "a new attempt opens"                                    'new' "$([[ -n "$ATTEMPT2" && "$ATTEMPT2" != "$ATTEMPT" ]] && echo new || echo "same: $ATTEMPT2")"
# Question three gets only one of its two right options: half a multiple-choice answer is wrong.
RIGHT=$(post "$A" "$C/assessment/submit" "{\"attemptId\":$ATTEMPT2,\"answers\":{\"$Q1\":[$B1],\"$Q2\":$T2,\"$Q3\":[$A3]}}")
check "two of three is 67%"                                    '"scorePercent":67' "$RIGHT"
check "half a multiple-choice answer does not count"           '"correctCount":2' "$RIGHT"
check "67% passes a 60% mark"                                  '"passed":true' "$RIGHT"
check "the answers are revealed once passed"                   '"revealed":true' "$RIGHT"
check "with the explanations"                                  'Explanation for question three' "$RIGHT"
check "starting again after passing is refused"                'already passed' "$(post "$A" "$C/assessment/start" '{}')"
check "the choices were kept for review"                       '1' "$(q "SELECT COUNT(*) FROM academy_answers WHERE attempt_id = $ATTEMPT2 AND question_id = $Q3 AND option_id = $A3 AND was_correct = 0;")"

echo "The certificate carries the score:"
CERT=$(post "$A" "$C/certificate" '{"name":"Quiz Learner"}')
check "the certificate is issued"                              '"code":"RSK-' "$CERT"
check "with the passing score on it"                           '"scorePercent":67' "$CERT"
CODE=$(grep -o '"code":"[^"]*"' <<< "$CERT" | head -1 | cut -d'"' -f4)
check "the public check shows the score"                       '"scorePercent":67' "$(curl -s "${API}certificates/verify/$CODE")"
PDF=$(mktemp)
curl -s -b "$A" -o "$PDF" "${API}$C/certificate/pdf"
check "the PDF says so"                                        'with a score of 67%' "$(grep -a -o 'with a score of 67%' "$PDF" | head -1)"

echo "Running out of attempts:"
post "$B" "$C/enrol" '{}' >/dev/null
post "$B" "academy/lessons/$LESSON/progress" '{"completed":true,"seconds":30}' >/dev/null
for n in 1 2; do
    P=$(post "$B" "$C/assessment/start" '{}')
    BA=$(field attemptId "$P")
    # An option from another question is ignored, not counted.
    LAST=$(post "$B" "$C/assessment/submit" "{\"attemptId\":$BA,\"answers\":{\"$Q1\":[$T2],\"$Q2\":[$F2]}}")
done
check "a foreign option does not score"                        '"scorePercent":0' "$LAST"
check "unanswered questions count as wrong"                    '"total":3' "$LAST"
check "the answers are revealed after the last attempt"        '"revealed":true' "$LAST"
STATUS=$(get "$B" "$C/assessment")
check "no attempts are left"                                   '"attemptsLeft":0' "$STATUS"
check "and starting is refused"                                'used every attempt' "$(post "$B" "$C/assessment/start" '{}')"

echo "Drawing fewer questions than the bank holds:"
q "UPDATE academy_assessments a JOIN academy_courses c ON c.id = a.course_id SET a.question_count = 2 WHERE c.slug = 'test-quiz-course';"
post "$CC" "$C/enrol" '{}' >/dev/null
post "$CC" "academy/lessons/$LESSON/progress" '{"completed":true,"seconds":30}' >/dev/null
check "an attempt draws the configured number"                 '2' "$(post "$CC" "$C/assessment/start" '{}' | grep -o '"prompt"' | wc -l | tr -d ' ')"

cleanup
rm -f "$A" "$B" "$CC" "$PDF"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
