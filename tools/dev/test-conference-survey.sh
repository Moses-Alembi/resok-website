#!/usr/bin/env bash
#
# Exercises the post-conference evaluation: only delegates scanned in may answer, once each;
# answers are checked against the questions; the organisers see results without names; the
# hourly job invites each attendee once. Uses sv-*@resok.local accounts and "TESTSV "
# categories, removed afterwards; the survey's open/closed state is put back as it was.
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

curl -s "${API}conference/current" >/dev/null
ADMIN=$(minted_session dev@resok.local)
get "$ADMIN" conference/admin/survey >/dev/null   # creates the tables
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED_CONF=$(q "SELECT open FROM conf_settings WHERE event_id=$EV")
SAVED_SV=$(q "SELECT CONCAT_WS('|', open, COALESCE(opened_at,''), COALESCE(closes_at,'')) FROM conf_survey_state WHERE event_id=$EV")
cleanup() {
    $MYSQL -e "
      DELETE x FROM conf_survey_responses x JOIN conf_registrations r ON r.id = x.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSV %';
      DELETE s FROM conf_scans s JOIN conf_registrations r ON r.id = s.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSV %';
      DELETE r FROM conf_registrations r JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSV %';
      DELETE w FROM conf_fee_windows w JOIN conf_categories c ON c.id = w.category_id WHERE c.name LIKE 'TESTSV %';
      DELETE FROM conf_categories WHERE name LIKE 'TESTSV %';
      DELETE FROM abs_emails WHERE to_email LIKE 'sv-%@resok.local' OR (template = 'conf_survey' AND to_email LIKE '%@resok.local');
      DELETE FROM users WHERE email LIKE 'sv-%@resok.local';" 2>/dev/null
}
cleanup
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('sv-came@resok.local','$HASH',1,'member'),('sv-absent@resok.local','$HASH',1,'member'),('sv-unpaid@resok.local','$HASH',1,'member'),('sv-none@resok.local','$HASH',1,'member');"
CAME=$(minted_session sv-came@resok.local); ABSENT=$(minted_session sv-absent@resok.local)
UNPAID=$(minted_session sv-unpaid@resok.local); NONE=$(minted_session sv-none@resok.local)

post "$ADMIN" conference/admin/settings '{"open":true}' >/dev/null
post "$ADMIN" conference/admin/categories '{"name":"TESTSV Delegate","currency":"KES","windows":[{"label":"Standard","price":20000}]}' >/dev/null
CAT=$(q "SELECT id FROM conf_categories WHERE name='TESTSV Delegate'")
for who in "$CAME" "$ABSENT" "$UNPAID"; do
  post "$who" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Survey Tester\",\"institution\":\"KNH\",\"country\":\"Kenya\"}" >/dev/null
done
reg() { q "SELECT r.id FROM conf_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='$1'"; }
RCAME=$(reg sv-came@resok.local); RABSENT=$(reg sv-absent@resok.local); RUNPAID=$(reg sv-unpaid@resok.local)
TODAY=$(q "SELECT CURDATE()")
q "UPDATE conf_registrations SET status='confirmed' WHERE id IN ($RCAME, $RABSENT);
   INSERT INTO conf_scans (event_id, client_id, code, registration_id, scan_day, result, scanned_at) VALUES
     ($EV, 'sv-1', 'RSK-TESTSV', $RCAME, '$TODAY', 'ok', NOW()), ($EV, 'sv-2', 'RSK-TESTSV', $RUNPAID, '$TODAY', 'ok', NOW())"

echo "Opening:"
post "$ADMIN" conference/admin/survey '{"open":false,"closesAt":""}' >/dev/null
r=$(get "$CAME" conference/survey)
check "before it opens, no questions are shown"                 '"open":false' "$r"
r=$(post "$CAME" conference/survey '{"answers":{"overall":5}}')
check "and nobody can answer"                                   'not open' "$r"
r=$(post "$CAME" conference/admin/survey '{"open":true}')
check "a delegate cannot open the evaluation"                   'Only conference administrators' "$r"
r=$(get "$CAME" conference/admin/survey)
check "or see the results"                                      'Only conference administrators' "$r"
r=$(post "$ADMIN" conference/admin/survey '{"closesAt":"next week"}')
check "a nonsense closing date is refused"                      'must look like' "$r"
r=$(post "$ADMIN" conference/admin/survey '{"open":true}')
check "an administrator opens it"                               '"open":true' "$r"

echo "Who may answer:"
r=$(get "$CAME" conference/survey)
check "an attendee sees the questions"                          '"key":"overall"' "$r"
r=$(get "$ABSENT" conference/survey)
check "a paid delegate never scanned in is not asked"           '"eligible":false' "$r"
r=$(post "$ABSENT" conference/survey '{"answers":{"overall":5}}')
check "and cannot answer"                                       'delegates who attended' "$r"
r=$(post "$UNPAID" conference/survey '{"answers":{"overall":5}}')
check "nor can an unconfirmed registration with a scan"         'delegates who attended' "$r"
r=$(post "$NONE" conference/survey '{"answers":{"overall":5}}')
check "nor someone who never registered"                        'delegates who attended' "$r"

echo "Answers are checked:"
r=$(post "$CAME" conference/survey '{"answers":{"content":4}}')
check "the overall rating is required"                          'rate the conference overall' "$r"
r=$(post "$CAME" conference/survey '{"answers":{"overall":9}}')
check "a rating outside 1-5 is refused"                         'from 1 to 5' "$r"
r=$(post "$CAME" conference/survey '{"answers":{"overall":"4.5"}}')
check "a fractional rating is refused"                          'from 1 to 5' "$r"
r=$(post "$CAME" conference/survey '{"answers":{"overall":5,"again":"Definitely"}}')
check "a choice not offered is refused"                         'choose an answer' "$r"
r=$(post "$CAME" conference/survey '{"answers":{"overall":4,"content":5,"recommend":10,"again":"Yes","improve":"=HYPERLINK(\"http://x\")","hacker":"<script>","best":"<b>Great</b> talks"}}')
check "a valid evaluation is accepted"                          '"answered":true' "$r"
check "questions not asked are not stored"                      "0" "$(q "SELECT COUNT(*) FROM conf_survey_responses WHERE registration_id=$RCAME AND answers LIKE '%hacker%'")"
r=$(post "$CAME" conference/survey '{"answers":{"overall":1}}')
check "nobody answers twice"                                    'already sent' "$r"
check "only one response is kept"                               "1" "$(q "SELECT COUNT(*) FROM conf_survey_responses WHERE registration_id=$RCAME")"

echo "Results:"
r=$(get "$ADMIN" conference/admin/survey)
check "the organisers see the average"                          '"average":4' "$r"
check "and the net promoter score"                              '"nps":100' "$r"
check "and the written comments"                                'Great</b> talks' "$r"
[[ "$r" == *"sv-came"* || "$r" == *"Survey Tester"* || "$r" == *"\"ref\""* ]] && check "results carry no names or references" "clean" "leaked" || check "results carry no names or references" "x" "x"
r=$(curl -s -b "$ADMIN" "${API}conference/admin/survey/export")
check "the CSV export downloads"                                'Overall, how would you rate' "$r"
check "a formula in a comment is defused in the CSV"            "'=HYPERLINK" "$r"
[[ "$r" == *"sv-came"* || "$r" == *"Survey Tester"* ]] && check "the CSV carries no names" "clean" "leaked" || check "the CSV carries no names" "x" "x"
r=$(curl -s -b "$CAME" "${API}conference/admin/survey/export")
check "a delegate cannot download the CSV"                      'Only conference administrators' "$r"

echo "Invitations:"
q "DELETE FROM conf_survey_responses WHERE registration_id=$RCAME"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "an attendee is invited"                                  "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_survey' AND to_email='sv-came@resok.local'")"
check "a delegate who never came is not"                        "0" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_survey' AND to_email='sv-absent@resok.local'")"
check "nor an unconfirmed registration"                         "0" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_survey' AND to_email='sv-unpaid@resok.local'")"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "running the job again sends nothing new"                 "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_survey' AND to_email='sv-came@resok.local'")"
q "UPDATE conf_survey_state SET opened_at = NOW() - INTERVAL 6 DAY WHERE event_id=$EV"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "one reminder five days on"                               "2" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_survey' AND to_email='sv-came@resok.local'")"
post "$ADMIN" conference/admin/survey '{"open":false}' >/dev/null
r=$(post "$CAME" conference/survey '{"answers":{"overall":5}}')
check "once closed, no more answers"                            'not open' "$r"

cleanup
if [ -n "$SAVED_SV" ]; then
  IFS='|' read -r o oa ca <<< "$SAVED_SV"
  q "UPDATE conf_survey_state SET open=$o, opened_at=NULLIF('$oa',''), closes_at=NULLIF('$ca','') WHERE event_id=$EV"
else
  q "DELETE FROM conf_survey_state WHERE event_id=$EV"
fi
[ -n "$SAVED_CONF" ] && q "UPDATE conf_settings SET open=$SAVED_CONF WHERE event_id=$EV"
rm -f "$ADMIN" "$CAME" "$ABSENT" "$UNPAID" "$NONE"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
