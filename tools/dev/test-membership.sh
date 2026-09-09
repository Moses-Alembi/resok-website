#!/usr/bin/env bash
#
# Exercises the membership renewal lifecycle.
#
# The claims worth testing are about money and access. A membership has to end when it is not
# paid for, but not the day after the date - grace exists so that being a fortnight late is
# not treated as resigning. A renewal has to move the date, which it never used to. Renewing
# early must not cost the member the time they have already paid for. And a reminder has to
# survive the job not running, because on shared hosting it will not always run.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
LIB="resok-portal/public/api/lib/membership.php"
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

# Runs a snippet against the library, with no database and no mail involved.
lib() { $PHP -r "require '$LIB'; date_default_timezone_set('Africa/Nairobi'); $1" 2>/dev/null | grep -v Warning; }
day() { $PHP -r "echo (new DateTime('$1 days'))->format('Y-m-d');"; }
# A term is twelve months, not 365 days. Those disagree across a leap year, and the code is
# right to count in months - so the expectation counts in months too rather than approximating.
plusterm() { $PHP -r "echo (new DateTime('$1'))->modify('+12 months')->format('Y-m-d');"; }

echo "Standing, read from the dates:"
standing() { lb=$(lib "\$r = membershipStanding(['membership_status'=>'$1','renewal_due'=>$2]); echo \$r['standing'].'|'.(\$r['benefits']?'yes':'no');"); echo "$lb"; }
check "a year out is current"                  'current|yes'   "$(standing active "'$(day +200)'")"
check "inside 30 days it is due soon"          'due_soon|yes'  "$(standing active "'$(day +14)'")"
check "on the day itself, still due soon"      'due_soon|yes'  "$(standing active "'$(day +0)'")"
check "a day late is grace, benefits kept"     'grace|yes'     "$(standing active "'$(day -1)'")"
check "the last day of grace still has them"   'grace|yes'     "$(standing active "'$(day -30)'")"
check "the day after grace, lapsed"            'lapsed|no'     "$(standing active "'$(day -31)'")"
check "an approved member with no date keeps"  'undated|yes'   "$(standing active NULL)"
check "someone under review has no benefits"   'under_review|no' "$(standing under_review NULL)"
check "a rejected applicant has none"          'rejected|no'   "$(standing rejected NULL)"

echo
echo "The renewal date a payment produces:"
check "renewing early keeps the time paid for" "$(plusterm "$(day +200)")" \
      "$(lib "echo membershipNextRenewalDate('$(day +200)');")"
check "renewing on the day adds a year"        "$(day +365)" \
      "$(lib "echo membershipNextRenewalDate('$(day +0)');")"
check "renewing late runs from today"          "$(day +365)" \
      "$(lib "echo membershipNextRenewalDate('$(day -60)');")"
check "a first membership runs from today"     "$(day +365)" \
      "$(lib "echo membershipNextRenewalDate(null);")"

echo
echo "Reminders that survive the job not running:"
band() { lib "\$b = membershipReminderBand($1, $2); echo \$b === null ? 'none' : \$b;"; }
check "20 days out sits in the 30-day band"    '30'    "$(band 20 null)"
check "10 days out sits in the 14-day band"    '14'    "$(band 10 null)"
check "the last day sits in the 1-day band"    '1'     "$(band 0 null)"
check "a band already sent is not resent"      'none'  "$(band 20 30)"
check "but the next band still fires"          '14'    "$(band 10 30)"
check "and the last one after that"            '1'     "$(band 0 14)"
check "a later run never repeats a stage"      'none'  "$(band 0 1)"
# The bug this replaced: exact-date matching meant a day the cron did not run lost that
# reminder for good. Ten days out is no longer a gap.
check "a missed 30-day run is caught up later" '14'    "$(band 10 null)"
check "even after a week of missed runs"       '1'     "$(band 1 null)"
check "nothing is sent once the date is past"  'none'  "$(band -1 null)"
check "nor far out beyond every band"          'none'  "$(band 90 null)"

echo
echo "Through the API:"
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('mem-test@resok.local','mem-admin@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('mem-test@resok.local','$HASH',1,'member'),
 ('mem-admin@resok.local','$HASH',1,'admin');" >/dev/null
UID_=$($MYSQL -N -e "SELECT id FROM users WHERE email='mem-test@resok.local';")
$MYSQL -e "INSERT INTO member_profiles (user_id,first_name,surname,membership_status,membership_id,renewal_due)
           VALUES ($UID_,'Test','Member','active','RESOK-TEST','$(day +200)');" >/dev/null
PID=$($MYSQL -N -e "SELECT id FROM member_profiles WHERE user_id=$UID_;")

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

MEMBER=$(login mem-test@resok.local)
ADMIN=$(login mem-admin@resok.local)

ME=$(get "$MEMBER" 'members/me')
check "members/me carries the standing"        '"standing"'        "$ME"
check "and reports this member as current"     '"standing":"current"' "$ME"
check "with benefits"                          '"benefits":true'   "$ME"

echo
echo "A renewal payment moves the date:"
$MYSQL -e "UPDATE member_profiles SET renewal_due='$(day -5)', last_reminder_days=14 WHERE id=$PID;" >/dev/null
check "overdue but inside grace"               '"standing":"grace"' "$(get "$MEMBER" 'members/me')"

$MYSQL -e "INSERT INTO payments (user_id,member_profile_id,amount,currency,method,status,reference)
           VALUES ($UID_,$PID,5000,'KES','mpesa','pending','TESTRENEW1');" >/dev/null
PAYID=$($MYSQL -N -e "SELECT id FROM payments WHERE reference='TESTRENEW1';")
CONF=$(post "$ADMIN" "payments/$PAYID/confirm" '{}')
check "confirming the payment succeeds"        '"id"'              "$CONF"
check "the renewal date moved a year out"      "$(day +365)" \
      "$($MYSQL -N -e "SELECT renewal_due FROM member_profiles WHERE id=$PID;")"
check "and the member is current again"        '"standing":"current"' "$(get "$MEMBER" 'members/me')"
check "reminders are freed for the next cycle" 'NULL' \
      "$($MYSQL -N -e "SELECT IFNULL(last_reminder_days,'NULL') FROM member_profiles WHERE id=$PID;")"

echo
echo "Members-only courses follow standing, not the status column:"
$MYSQL -e "DELETE FROM academy_courses WHERE slug='test-members-only';" >/dev/null
$MYSQL -e "INSERT INTO academy_courses (slug,title,summary,category,level,members_only,status,published_at)
           VALUES ('test-members-only','Members Only Test','x','Getting started','introductory',1,'published',NOW());" >/dev/null
CID=$($MYSQL -N -e "SELECT id FROM academy_courses WHERE slug='test-members-only';")
$MYSQL -e "INSERT INTO academy_modules (course_id,title,position) VALUES ($CID,'M1',1);" >/dev/null
MID=$($MYSQL -N -e "SELECT id FROM academy_modules WHERE course_id=$CID;")
$MYSQL -e "INSERT INTO academy_lessons (module_id,title,kind,body,duration_minutes,position)
           VALUES ($MID,'L1','text','body',5,1);" >/dev/null

$MYSQL -e "UPDATE member_profiles SET renewal_due='$(day -5)' WHERE id=$PID;" >/dev/null
check "a member inside grace can still enrol"  '"progress"' \
      "$(post "$MEMBER" 'academy/courses/test-members-only/enrol' '{}')"

$MYSQL -e "DELETE e FROM academy_enrolments e WHERE e.user_id=$UID_ AND e.course_id=$CID;
           UPDATE member_profiles SET renewal_due='$(day -45)' WHERE id=$PID;" >/dev/null
check "a lapsed member cannot"                 'Activate your membership' \
      "$(post "$MEMBER" 'academy/courses/test-members-only/enrol' '{}')"
check "even though the column still says active" 'active' \
      "$($MYSQL -N -e "SELECT membership_status FROM member_profiles WHERE id=$PID;")"

echo
echo "The lapse pass:"
check "marks a membership past grace"          '1' \
      "$(lib "\$p=new PDO('mysql:host=127.0.0.1;dbname=resok_portal;charset=utf8mb4','root','');
              \$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
              echo count(membershipMarkLapsed(\$p));")"
check "the column now says expired"            'expired' \
      "$($MYSQL -N -e "SELECT membership_status FROM member_profiles WHERE id=$PID;")"
check "and it does not mark it a second time"  '0' \
      "$(lib "\$p=new PDO('mysql:host=127.0.0.1;dbname=resok_portal;charset=utf8mb4','root','');
              echo count(membershipMarkLapsed(\$p));")"
check "a member inside grace is left alone"    '0' \
      "$($MYSQL -e "UPDATE member_profiles SET membership_status='active', renewal_due='$(day -10)' WHERE id=$PID;" >/dev/null
         lib "\$p=new PDO('mysql:host=127.0.0.1;dbname=resok_portal;charset=utf8mb4','root','');
              echo count(membershipMarkLapsed(\$p));")"

$MYSQL -e "DELETE FROM academy_courses WHERE slug='test-members-only';
           DELETE FROM payments WHERE reference='TESTRENEW1';
           DELETE FROM users WHERE email IN ('mem-test@resok.local','mem-admin@resok.local');" >/dev/null

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
