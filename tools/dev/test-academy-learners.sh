#!/usr/bin/env bash
#
# Exercises Academy learner sign-up through the running API.
#
# The claims worth testing are about keeping two kinds of account apart. Anyone can create a
# learner account without the membership application's fifteen fields, and it gets the same
# login, bot screening and password rules as everyone else. A learner has no membership
# record, so they never appear in the members list, can take open courses but not
# members-only ones, and are sent to the Academy rather than the member dashboard. A member is
# still a member. And the verification page speaks to each in their own terms.
#
# This machine runs with email verification off, so a new learner is signed in immediately;
# the verification wording is checked by giving accounts a token directly.
#
# Uses its own learner-*@resok.local accounts and test-learner-* courses, removed afterwards.
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

cleanup() {
    $MYSQL -e "DELETE FROM academy_courses WHERE slug LIKE 'test-learner-%';
               DELETE FROM users WHERE email LIKE 'learner-%@resok.local';
               DELETE FROM auth_attempts WHERE action = 'register';" >/dev/null 2>&1
}
cleanup

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "
INSERT INTO users (email, password_hash, email_verified, role) VALUES
 ('learner-admin@resok.local',  '$HASH', 1, 'admin'),
 ('learner-member@resok.local', '$HASH', 1, 'member');
INSERT INTO member_profiles (user_id, first_name, surname, mobile)
 SELECT id, 'Existing', 'Member', '+254700000000' FROM users WHERE email = 'learner-member@resok.local';
INSERT INTO academy_courses (slug, title, summary, description, category, level, estimated_minutes, members_only, certificate_enabled, pass_mark, status, published_at) VALUES
 ('test-learner-open',    'Test Open Course',         'Summary', 'Description', 'Diagnostics', 'introductory', 5, 0, 1, 70, 'published', NOW()),
 ('test-learner-members', 'Test Members-Only Course', 'Summary', 'Description', 'Diagnostics', 'introductory', 5, 1, 1, 70, 'published', NOW());
INSERT INTO academy_modules (course_id, title, summary, position)
 SELECT id, 'Module one', '', 1 FROM academy_courses WHERE slug LIKE 'test-learner-%';
INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
 SELECT m.id, 'Lesson one', 'text', 'Body', 5, 1 FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id WHERE c.slug LIKE 'test-learner-%';" >/dev/null

# The form reports when it was loaded; twenty seconds ago reads as a person, not a script.
LOADED=$(( ($(date +%s) - 20) * 1000 ))
body() {  # first, surname, email, password, profession, honeypot
    printf '{"firstName":"%s","surname":"%s","email":"%s","password":"%s","country":"Kenya","profession":"%s","website":"%s","formLoadedAt":%s}' \
        "$1" "$2" "$3" "$4" "$5" "${6:-}" "$LOADED"
}
register() { local jar=$1; shift; curl -s -c "$jar" -X POST "${API}auth/register-learner" -H 'Content-Type: application/json' -d "$1"; }
login() { curl -s -c "$1" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$2\",\"password\":\"TestPass123\"}"; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

SCRATCH=$(mktemp)
echo "Signing up:"
check "missing fields are refused"                             '"error"' "$(register "$SCRATCH" '{"email":"learner-x@resok.local"}')"
check "a bad email is refused"                                 'valid email' "$(register "$SCRATCH" "$(body Amina Wanjiku not-an-email TestPass123 Nurse)")"
check "a weak password is refused"                             'Password must' "$(register "$SCRATCH" "$(body Amina Wanjiku learner-amina@resok.local short Nurse)")"
check "a name made of digits is refused"                       'may only contain letters' "$(register "$SCRATCH" "$(body 12345 Wanjiku learner-amina@resok.local TestPass123 Nurse)")"
BOT=$(register "$SCRATCH" "$(body Bot Account learner-bot@resok.local TestPass123 Nurse 'http://spam.example')")
check "a bot is told it worked"                                'requiresVerification' "$BOT"
check "but no account is created for it"                       '0' "$(q "SELECT COUNT(*) FROM users WHERE email = 'learner-bot@resok.local';")"

LEARNER=$(mktemp)
CREATED=$(register "$LEARNER" "$(body Amina Wanjiku learner-amina@resok.local TestPass123 Nurse)")
check "a learner account is created"                           '"isLearner":true' "$CREATED"
check "and signed in straight away here"                       '"user":' "$CREATED"
check "with no membership record"                              '0' "$(q "SELECT COUNT(*) FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email = 'learner-amina@resok.local';")"
check "and a learner record with their details"                'Amina Wanjiku|Kenya|Nurse' "$(q "SELECT CONCAT(l.display_name, '|', l.country, '|', l.profession) FROM academy_learners l JOIN users u ON u.id = l.user_id WHERE u.email = 'learner-amina@resok.local';")"
check "the same email cannot sign up twice"                    'already exists' "$(register "$SCRATCH" "$(body Amina Wanjiku learner-amina@resok.local TestPass123 Nurse)")"
check "not even in different capitals"                         'already exists' "$(register "$SCRATCH" "$(body Amina Wanjiku LEARNER-AMINA@resok.local TestPass123 Nurse)")"
check "a member's email cannot become a learner account"       'already exists' "$(register "$SCRATCH" "$(body Existing Member learner-member@resok.local TestPass123 '')")"

echo "Signing in:"
check "login marks the account as a learner"                   '"isLearner":true' "$(login "$LEARNER" learner-amina@resok.local | sed 's/TestPass123//')"
MEMBER=$(mktemp)
check "a member is not marked as a learner"                    '"isLearner":false' "$(login "$MEMBER" learner-member@resok.local)"
check "a learner has no member record to show"                 '[]' "$(get "$LEARNER" 'members/me')"

echo "Learning:"
check "a learner can enrol in an open course"                  '"enrolment"' "$(post "$LEARNER" 'academy/courses/test-learner-open/enrol' '{}')"
check "but not in a members-only course"                       'part of ReSoK membership' "$(post "$LEARNER" 'academy/courses/test-learner-members/enrol' '{}')"
LESSON=$(q "SELECT l.id FROM academy_lessons l JOIN academy_modules m ON m.id = l.module_id JOIN academy_courses c ON c.id = m.course_id WHERE c.slug = 'test-learner-open';")
post "$LEARNER" "academy/lessons/$LESSON/progress" '{"completed":true,"seconds":30}' >/dev/null
check "their certificate name starts as the name they gave"    '"suggestedName":"Amina Wanjiku"' "$(get "$LEARNER" 'academy/courses/test-learner-open/certificate')"

echo "Kept apart from the membership:"
ADMIN=$(mktemp)
login "$ADMIN" learner-admin@resok.local >/dev/null
absent "a learner is not in the members list"                  'learner-amina@resok.local' "$(get "$ADMIN" 'members')"

echo "Email verification wording:"
q "INSERT INTO users (email, password_hash, email_verified, role, verification_token) VALUES
   ('learner-unverified@resok.local', '$HASH', 0, 'member', 'learnertoken0000000000000000000000000000000000000000000000000001'),
   ('learner-applicant@resok.local',  '$HASH', 0, 'member', 'membertoken00000000000000000000000000000000000000000000000000001');
   INSERT INTO academy_learners (user_id, display_name) SELECT id, 'Unverified Learner' FROM users WHERE email = 'learner-unverified@resok.local';
   INSERT INTO member_profiles (user_id, first_name, surname, mobile) SELECT id, 'New', 'Applicant', '+254700000001' FROM users WHERE email = 'learner-applicant@resok.local';"
PAGE=$(curl -s "${API}auth/verify/learnertoken0000000000000000000000000000000000000000000000000001")
check "a learner is told their Academy account is ready"       'Virtual Academy account is ready' "$PAGE"
check "and is sent back to the Academy after signing in"       'next=%2Fresok-portal%2Fpublic%2Facademy' "$PAGE"
check "the learner's address is now verified"                  '1' "$(q "SELECT email_verified FROM users WHERE email = 'learner-unverified@resok.local';")"
check "an applicant still hears about their application"       'membership application' "$(curl -s "${API}auth/verify/membertoken00000000000000000000000000000000000000000000000000001")"

cleanup
rm -f "$SCRATCH" "$LEARNER" "$MEMBER" "$ADMIN"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
