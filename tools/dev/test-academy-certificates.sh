#!/usr/bin/env bash
#
# Exercises Virtual Academy certificates through the running API.
#
# The claims worth testing are about trust. A certificate must not be issued before the course
# is finished. Once issued, its name must not change, asking again must not issue a second one,
# and the PDF must belong to its owner alone. The public check must confirm a genuine code
# however it is typed, must not find an invented one, and must not reveal why a certificate
# was revoked. And a certificate already earned must survive lessons being added to its course.
#
# Uses its own cert-*@resok.local accounts and test-cert-* courses, and removes them afterwards.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *'"error"'* ]]; then
        printf '  %-60s FAIL\n      response was an error, so the absence proves nothing:\n      %.160s\n' "$1" "$3"
        fail=$((fail+1)); return
    fi
    if [[ "$3" != *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      did not want: %s\n      got: %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }

cleanup() {
    $MYSQL -e "DELETE FROM academy_courses WHERE slug LIKE 'test-cert-%';
               DELETE FROM users WHERE email LIKE 'cert-%@resok.local';" >/dev/null
}
cleanup

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "
INSERT INTO users (email, password_hash, email_verified, role) VALUES
 ('cert-learner@resok.local', '$HASH', 1, 'member'),
 ('cert-other@resok.local',   '$HASH', 1, 'member'),
 ('cert-editor@resok.local',  '$HASH', 1, 'content_manager');
INSERT INTO member_profiles (user_id, title, first_name, surname, mobile)
 SELECT id, 'Dr', 'Test', 'Learner', '+254700000000' FROM users WHERE email = 'cert-learner@resok.local';
INSERT INTO academy_courses (slug, title, summary, description, category, level, estimated_minutes, members_only, certificate_enabled, pass_mark, status, published_at) VALUES
 ('test-cert-course', 'Test Certificate Course in Pleural Procedures', 'Summary', 'Description', 'Diagnostics', 'introductory', 10, 0, 1, 70, 'published', NOW()),
 ('test-cert-nocert', 'Test Course Without a Certificate', 'Summary', 'Description', 'Diagnostics', 'introductory', 5, 0, 0, 70, 'published', NOW());
INSERT INTO academy_modules (course_id, title, summary, position)
 SELECT id, 'Module one', '', 1 FROM academy_courses WHERE slug IN ('test-cert-course', 'test-cert-nocert');
INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
 SELECT m.id, 'Lesson one', 'text', 'Body', 5, 1 FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
  WHERE c.slug IN ('test-cert-course', 'test-cert-nocert');
INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
 SELECT m.id, 'Lesson two', 'text', 'Body', 5, 2 FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
  WHERE c.slug = 'test-cert-course';" >/dev/null

lesson() { q "SELECT l.id FROM academy_lessons l JOIN academy_modules m ON m.id = l.module_id JOIN academy_courses c ON c.id = m.course_id WHERE c.slug = '$1' AND l.position = $2;"; }
L1=$(lesson test-cert-course 1); L2=$(lesson test-cert-course 2); N1=$(lesson test-cert-nocert 1)

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
anon() { curl -s --max-time 15 "${API}$1"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

LEARNER=$(login cert-learner@resok.local)
OTHER=$(login cert-other@resok.local)
EDITOR=$(login cert-editor@resok.local)

echo "Before the course is finished:"
post "$LEARNER" 'academy/courses/test-cert-course/enrol' '{}' >/dev/null
post "$LEARNER" "academy/lessons/$L1/progress" '{"completed":true,"seconds":60}' >/dev/null
STATUS=$(get "$LEARNER" 'academy/courses/test-cert-course/certificate')
check "the course offers a certificate"                   '"available":true' "$STATUS"
check "half-way is not eligible"                          '"eligible":false' "$STATUS"
check "and says what is still needed"                     'Finish every lesson' "$STATUS"
check "issuing now is refused"                            'Finish every lesson' "$(post "$LEARNER" 'academy/courses/test-cert-course/certificate' '{"name":"Dr Test Learner"}')"
check "signing in is required"                            '"error"' "$(anon 'academy/courses/test-cert-course/certificate')"

echo "Finishing, then issuing:"
post "$LEARNER" "academy/lessons/$L2/progress" '{"completed":true,"seconds":60}' >/dev/null
STATUS=$(get "$LEARNER" 'academy/courses/test-cert-course/certificate')
check "a finished course is eligible"                     '"eligible":true' "$STATUS"
check "the name is suggested from the membership record"  '"suggestedName":"Dr Test Learner"' "$STATUS"
check "an empty name is refused"                          'Enter the name' "$(post "$LEARNER" 'academy/courses/test-cert-course/certificate' '{"name":""}')"
check "a name made of digits is refused"                  'Enter the name' "$(post "$LEARNER" 'academy/courses/test-cert-course/certificate' '{"name":"12345"}')"
ISSUED=$(post "$LEARNER" 'academy/courses/test-cert-course/certificate' '{"name":"Dr  Test   Learner"}')
CODE=$(echo "$ISSUED" | grep -o '"code":"[^"]*"' | head -1 | cut -d'"' -f4)
check "a certificate is issued"                           '"code":"RSK-' "$ISSUED"
check "with a well-formed code"                           'well-formed' "$([[ "$CODE" =~ ^RSK-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$ ]] && echo well-formed || echo "$CODE")"
check "spacing in the name is tidied"                     '"learnerName":"Dr Test Learner"' "$ISSUED"
check "the course title is copied onto it"                'Test Certificate Course in Pleural Procedures' "$ISSUED"
AGAIN=$(post "$LEARNER" 'academy/courses/test-cert-course/certificate' '{"name":"Somebody Else"}')
check "asking again returns the same certificate"         "\"code\":\"$CODE\"" "$AGAIN"
absent "and does not change the name"                     'Somebody Else' "$AGAIN"
check "only one certificate exists for the enrolment"     '1' "$(q "SELECT COUNT(*) FROM academy_certificates WHERE code = '$CODE';")"
check "the preferred name is remembered"                  'Dr Test Learner' "$(q "SELECT l.certificate_name FROM academy_learners l JOIN users u ON u.id = l.user_id WHERE u.email = 'cert-learner@resok.local';")"

echo "The PDF:"
PDF=$(mktemp); HDR=$(mktemp)
CODE_HTTP=$(curl -s -b "$LEARNER" -D "$HDR" -o "$PDF" -w '%{http_code}' "${API}academy/courses/test-cert-course/certificate/pdf")
check "the owner can download it"                         '200' "$CODE_HTTP"
check "as a PDF"                                          'application/pdf' "$(tr -d '\r' < "$HDR" | grep -i '^content-type')"
check "offered as a download"                             'attachment' "$(tr -d '\r' < "$HDR" | grep -i '^content-disposition')"
check "the file really is a PDF"                          '%PDF-1.4' "$(head -c 8 "$PDF")"
check "carrying the learner's name"                       'Dr Test Learner' "$(grep -a -o 'Dr Test Learner' "$PDF" | head -1)"
check "and the code"                                      "$CODE" "$(grep -a -o "$CODE" "$PDF" | head -1)"
check "someone else cannot download it"                   '404' "$(curl -s -b "$OTHER" -o /dev/null -w '%{http_code}' "${API}academy/courses/test-cert-course/certificate/pdf")"

echo "Names beyond plain English letters:"
q "INSERT INTO users (email, password_hash, email_verified, role) VALUES ('cert-accent@resok.local', '$HASH', 1, 'member');"
ACCENT=$(login cert-accent@resok.local)
post "$ACCENT" 'academy/courses/test-cert-course/enrol' '{}' >/dev/null
for LID in "$L1" "$L2"; do post "$ACCENT" "academy/lessons/$LID/progress" '{"completed":true,"seconds":5}' >/dev/null; done
# Written as JSON escapes: a shell on Windows can hand non-ASCII arguments to curl in the local
# code page rather than UTF-8, which would test the terminal instead of the portal.
BS=$(printf '%s' '\')
ISSUED2=$(post "$ACCENT" 'academy/courses/test-cert-course/certificate' "{\"name\":\"Njoki Wanjir${BS}u0169 Kama${BS}u00fa\"}")
check "an accented name is accepted"                      '"code":"RSK-' "$ISSUED2"
check "and kept exactly as typed"                         "Wanjir${BS}u0169 Kama${BS}u00fa" "$ISSUED2"
PDF2=$(mktemp)
curl -s -b "$ACCENT" -o "$PDF2" "${API}academy/courses/test-cert-course/certificate/pdf"
check "its PDF is still a PDF"                            '%PDF-1.4' "$(head -c 8 "$PDF2")"
check "and embeds a font that has those letters"          '/FontFile2' "$(grep -a -o '/FontFile2' "$PDF2" | head -1)"
check "with a text map, so the name can be copied"        '/ToUnicode' "$(grep -a -o '/ToUnicode' "$PDF2" | head -1)"
check "a plain name's PDF carries no embedded font"       'none' "$(grep -a -q '/FontFile2' "$PDF" && echo embedded || echo none)"

echo "Public verification:"
VERIFY=$(anon "certificates/verify/$CODE")
check "a genuine code is valid without signing in"        '"status":"valid"' "$VERIFY"
check "it names the learner"                              '"learnerName":"Dr Test Learner"' "$VERIFY"
check "and the course"                                    'Pleural Procedures' "$VERIFY"
absent "it does not reveal the learner's email"           'cert-learner@resok.local' "$VERIFY"
LOOSE=$(echo "$CODE" | tr 'A-Z' 'a-z' | tr -d '-')
check "the code works however it is typed"                '"status":"valid"' "$(anon "certificates/verify/$LOOSE")"
check "an invented code is not found"                     '"status":"not_found"' "$(anon 'certificates/verify/RSK-AAAA-BBBB-CCCC')"
check "nonsense is not found"                             '"status":"not_found"' "$(anon 'certificates/verify/hello')"

echo "A course without a certificate:"
post "$LEARNER" 'academy/courses/test-cert-nocert/enrol' '{}' >/dev/null
post "$LEARNER" "academy/lessons/$N1/progress" '{"completed":true,"seconds":30}' >/dev/null
check "it says no certificate is offered"                 '"available":false' "$(get "$LEARNER" 'academy/courses/test-cert-nocert/certificate')"
check "and issuing is refused"                            'does not issue a certificate' "$(post "$LEARNER" 'academy/courses/test-cert-nocert/certificate' '{"name":"Dr Test Learner"}')"

echo "Lessons added after issuing:"
MOD=$(q "SELECT m.id FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id WHERE c.slug = 'test-cert-course';")
q "INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position) VALUES ($MOD, 'Lesson three', 'text', 'Body', 5, 3);"
post "$LEARNER" "academy/lessons/$L1/progress" '{"completed":true,"seconds":5}' >/dev/null
check "the certificate already earned is kept"            "\"code\":\"$CODE\"" "$(get "$LEARNER" 'academy/courses/test-cert-course/certificate')"

echo "Revoking:"
CERT_ID=$(q "SELECT id FROM academy_certificates WHERE code = '$CODE';")
check "a learner cannot list certificates"                'permission' "$(get "$LEARNER" 'academy/admin/certificates')"
check "an editor can, and finds this one"                 "$CODE" "$(get "$EDITOR" "academy/admin/certificates&q=cert-learner")"
check "a learner cannot revoke"                           'permission' "$(post "$LEARNER" "academy/admin/certificates/$CERT_ID/revoke" '{"reason":"test"}')"
check "a reason is required"                              'Give a reason' "$(post "$EDITOR" "academy/admin/certificates/$CERT_ID/revoke" '{"reason":""}')"
check "an editor revokes it"                              '"revoked":true' "$(post "$EDITOR" "academy/admin/certificates/$CERT_ID/revoke" '{"reason":"Issued in error during testing"}')"
check "revoking twice is refused"                         'already revoked' "$(post "$EDITOR" "academy/admin/certificates/$CERT_ID/revoke" '{"reason":"again"}')"
REVOKED=$(anon "certificates/verify/$CODE")
check "the public check now says revoked"                 '"status":"revoked"' "$REVOKED"
absent "without saying why"                               'Issued in error' "$REVOKED"
check "the owner can no longer download it"               '410' "$(curl -s -b "$LEARNER" -o /dev/null -w '%{http_code}' "${API}academy/courses/test-cert-course/certificate/pdf")"
check "the revocation is in the audit log"                "$CODE" "$(q "SELECT reason FROM admin_actions WHERE action = 'certificate_revoked' ORDER BY id DESC LIMIT 1;")"

cleanup
rm -f "$LEARNER" "$OTHER" "$EDITOR" "$PDF" "$HDR"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
