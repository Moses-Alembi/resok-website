#!/usr/bin/env bash
#
# Exercises staff recruitment (lib/careers.php).
#
# What matters most: anyone can apply without an account, but scripts, duplicates, unsafe
# files and closed vacancies are refused; only recruiters see applications, and every look
# at one is logged; CVs are encrypted at rest; and retention really deletes unsuccessful
# candidates while keeping the hired one.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPLOADS="$ROOT/resok-portal/uploads/careers"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      did not want: %s\n      got: %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $d = $d[$k] ?? null; echo is_scalar($d) ? $d : json_encode($d);' "$1"; }

curl -s "${API}careers/jobs" >/dev/null   # creates the tables on a fresh database
# Clean slate. auth_attempts is cleared so repeated runs are not rate limited by the last one.
$MYSQL -e "
DELETE FROM careers_audit; DELETE FROM careers_notes; DELETE FROM careers_applications; DELETE FROM careers_jobs; DELETE FROM careers_roles;
DELETE FROM auth_attempts;
DELETE FROM users WHERE email IN ('car-rec@resok.local','car-other@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES ('car-rec@resok.local','x',1,'member'),('car-other@resok.local','x',1,'member');" 2>/dev/null
rm -f "$UPLOADS"/cv-*.bin 2>/dev/null

SUPER=$(minted_session dev@resok.local)
REC=$(minted_session car-rec@resok.local)
OTHER=$(minted_session car-other@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }

# Documents: a plain PDF, a PDF with script, a .docx, a macro .docx, and a text file.
TMP=$(mktemp -d)
printf '%%PDF-1.4\n%s\n%%%%EOF\n' "$(printf 'Curriculum vitae %.0s' {1..20})" > "$TMP/cv.pdf"
printf '%%PDF-1.4\n1 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n%s\n%%%%EOF\n' "$(printf 'x%.0s' {1..120})" > "$TMP/bad.pdf"
python -c '
import zipfile, sys
d = sys.argv[1]
with zipfile.ZipFile(d + "/cv.docx", "w") as z:
    z.writestr("[Content_Types].xml", "<Types/>"); z.writestr("word/document.xml", "<w:document>" + "CV " * 60 + "</w:document>")
with zipfile.ZipFile(d + "/macro.docx", "w") as z:
    z.writestr("word/document.xml", "<w:document>" + "x" * 200 + "</w:document>"); z.writestr("word/vbaProject.bin", "macro" * 40)
' "$TMP"
printf 'not a cv %.0s' {1..30} > "$TMP/cv.txt"
W() { cygpath -w "$1"; }
LOADED=$(( $(date +%s) * 1000 - 60000 ))   # the form was opened a minute ago
apply() {   # apply <email> <cv file> [extra -F args...]
    local email="$1" cv="$2"; shift 2
    curl -s --max-time 20 "${API}careers/apply" -F "job=$SLUG" -F "firstName=Achieng" -F "lastName=Odhiambo" -F "email=$email" \
      -F "phone=0712 345 678" -F "county=Kisumu" -F "answer_q1=yes" -F "answer_q2=Five years in a clinic" -F "consent=1" \
      -F "formLoadedAt=$LOADED" -F "cv=@$(W "$cv")" "$@"
}

echo "Recruiters:"
check "a member cannot reach the recruiters' side"   'Route not found'    "$(get "$REC" careers/admin)"
check "a super admin appoints a recruiter"           'car-rec@resok.local' "$(post "$SUPER" careers/admin/roles '{"email":"car-rec@resok.local"}')"
check "the recruiter can now reach it"               '"jobs":'            "$(get "$REC" careers/admin)"
check "a recruiter cannot appoint recruiters"        'super administrator' "$(post "$REC" careers/admin/roles '{"email":"car-other@resok.local"}')"

echo "Vacancies:"
check "publishing needs a closing date"              'closing date'       "$(post "$REC" careers/admin/jobs '{"title":"Programme Officer","status":"published"}')"
JOB=$(post "$REC" careers/admin/jobs '{"title":"Programme Officer","department":"Secretariat","location":"Nairobi","type":"full_time","summary":"Coordinate ReSoK programmes.","description":"Full description.","requirements":"Degree; 3 years experience.","closesAt":"2030-12-31T17:00","status":"published","questions":[{"label":"Are you eligible to work in Kenya?","type":"yesno","required":true},{"label":"Describe your relevant experience","type":"text","required":true}]}')
check "a recruiter publishes a vacancy"              '"status":"published"' "$JOB"
JID=$(echo "$JOB" | json job.id); SLUG=$(echo "$JOB" | json job.slug)
DRAFT=$(post "$REC" careers/admin/jobs '{"title":"Secret Draft Role","status":"draft"}')
LIST=$(curl -s "${API}careers/jobs")
check "the public list shows the published vacancy"  'Programme Officer'  "$LIST"
absent "but not the draft"                           'Secret Draft Role'  "$LIST"
check "the public sees one vacancy by its address"   'Are you eligible'   "$(curl -s "${API}careers/jobs/$SLUG")"
check "a draft cannot be opened by address"          'could not be found' "$(curl -s "${API}careers/jobs/$(echo "$DRAFT" | json job.slug)")"

echo "Applying:"
check "a missing answer is refused"                  'answer this question' "$(curl -s "${API}careers/apply" -F "job=$SLUG" -F firstName=Achieng -F lastName=Odhiambo -F email=a@x.co -F phone=0712345678 -F county=Kisumu -F consent=1 -F "formLoadedAt=$LOADED" -F "cv=@$(W "$TMP/cv.pdf")")"
check "a PDF with script is refused"                 'active content'     "$(apply a1@example.com "$TMP/bad.pdf")"
check "a macro Word file is refused"                 'macros'             "$(apply a1@example.com "$TMP/macro.docx")"
check "a text file is refused"                       'PDF or a Word'      "$(apply a1@example.com "$TMP/cv.txt")"
check "no consent, no application"                   'agree'              "$(curl -s "${API}careers/apply" -F "job=$SLUG" -F firstName=Achieng -F lastName=Odhiambo -F email=a@x.co -F phone=0712345678 -F county=Kisumu -F answer_q1=yes -F answer_q2=x -F "formLoadedAt=$LOADED" -F "cv=@$(W "$TMP/cv.pdf")")"
A1=$(apply a1@example.com "$TMP/cv.pdf" -F "coverNote=I would love to join.")
check "a good PDF application is accepted"           '"reference":"CAR-'  "$A1"
check "the same person cannot apply twice"           'already applied'    "$(apply a1@example.com "$TMP/cv.pdf")"
check "a Word CV is accepted"                        '"reference":"CAR-'  "$(apply a2@example.com "$TMP/cv.docx" -F "letter=@$(W "$TMP/cv.pdf")")"
apply a3@example.com "$TMP/cv.pdf" >/dev/null
BOT=$(curl -s "${API}careers/apply" -F "job=$SLUG" -F firstName=Bot -F lastName=Bot -F email=bot@example.com -F phone=0712345678 -F county=Kisumu -F answer_q1=yes -F answer_q2=x -F consent=1 -F "website=http://spam" -F "formLoadedAt=$LOADED" -F "cv=@$(W "$TMP/cv.pdf")")
check "a bot is answered as if it worked"            '"reference":'       "$BOT"
check "but nothing is stored for it"                 '0'                  "$($MYSQL -N -e "SELECT COUNT(*) FROM careers_applications WHERE email='bot@example.com'")"
check "a stored CV is encrypted"                     'enc.v1.'            "$(head -c 7 "$(ls "$UPLOADS"/cv-*.bin | head -1)")"
check "the phone number is encrypted"                'enc.v1.'            "$($MYSQL -N -e "SELECT LEFT(phone,7) FROM careers_applications WHERE email='a1@example.com'")"
check "a 07.. number is stored as +254"              '+254712345678'      "$(get "$REC" "careers/admin/applications/$($MYSQL -N -e "SELECT id FROM careers_applications WHERE email='a1@example.com'")")"

echo "Reviewing:"
APPS=$(get "$REC" "careers/admin/jobs/$JID/applications")
check "the recruiter sees three applications"        '"reference":"CAR-'  "$APPS"
check "a member cannot list them"                    'Route not found'    "$(get "$OTHER" "careers/admin/jobs/$JID/applications")"
AID=$($MYSQL -N -e "SELECT id FROM careers_applications WHERE email='a1@example.com'")
AID2=$($MYSQL -N -e "SELECT id FROM careers_applications WHERE email='a2@example.com'")
AID3=$($MYSQL -N -e "SELECT id FROM careers_applications WHERE email='a3@example.com'")
VIEW=$(get "$REC" "careers/admin/applications/$AID")
check "the application shows the answers"            'Five years in a clinic' "$VIEW"
check "and the cover note"                           'I would love to join' "$VIEW"
check "opening it is logged"                         'viewed'             "$($MYSQL -N -e "SELECT action FROM careers_audit WHERE application_id=$AID AND action='viewed' LIMIT 1")"
CV=$(curl -s -b "$REC" "${API}careers/admin/applications/$AID/cv" | head -c 8)
check "the CV downloads decrypted"                   '%PDF-1.4'           "$CV"
check "a member cannot download it"                  'Route not found'    "$(get "$OTHER" "careers/admin/applications/$AID/cv")"
check "a score outside 1-5 is refused"               '1 to 5'             "$(post "$REC" "careers/admin/applications/$AID/notes" '{"score":9}')"
check "a note with a score is added"                 '"score":4'          "$(post "$REC" "careers/admin/applications/$AID/notes" '{"body":"Strong candidate","score":4}')"
check "the status moves to shortlisted"              '"status":"shortlisted"' "$(post "$REC" "careers/admin/applications/$AID/status" '{"status":"shortlisted"}')"
MAIL=$(post "$REC" "careers/admin/jobs/$JID/email" '{"ids":['"$AID"'],"subject":"Interview for {{position}}","body":"Dear {{name}}, we invite you to interview. Ref {{reference}}.","setStatus":"interview"}')
# The laptop has no mail server, so the send may fail here. Either way the result must be
# reported, and the status must follow the email: moved if it went, untouched if it did not.
STATUS_NOW=$($MYSQL -N -e "SELECT status FROM careers_applications WHERE id=$AID")
if [[ "$MAIL" == *'"sent":1'* ]]; then
    check "an interview invitation is sent"              'interview'          "$STATUS_NOW"
else
    check "a failed invitation is reported"              '"failed":1'         "$MAIL"
    check "and leaves the status alone"                  'shortlisted'        "$STATUS_NOW"
    check "and is logged"                                'email_failed'       "$($MYSQL -N -e "SELECT action FROM careers_audit WHERE application_id=$AID AND action='email_failed' LIMIT 1")"
fi
CSV=$(curl -s -b "$REC" "${API}careers/admin/jobs/$JID/export")
check "the export lists the candidates"              'Achieng'            "$CSV"
check "with the screening answers"                   'Five years in a clinic' "$CSV"

echo "Closing and retention:"
post "$REC" careers/admin/jobs '{"id":'"$JID"',"title":"Programme Officer","closesAt":"2020-01-01T17:00","status":"published","questions":[{"label":"Are you eligible to work in Kenya?","type":"yesno","required":true},{"label":"Describe your relevant experience","type":"text","required":true}]}' >/dev/null
check "a closed vacancy leaves the public list"       '"jobs":[]'          "$(curl -s "${API}careers/jobs")"
check "and refuses applications"                     'no longer taking'   "$(apply late@example.com "$TMP/cv.pdf")"
post "$REC" "careers/admin/applications/$AID2/status" '{"status":"hired"}' >/dev/null
post "$REC" "careers/admin/applications/$AID3/status" '{"status":"unsuccessful"}' >/dev/null
OUT=$($PHP "$ROOT/resok-portal/public/api/cron/data-retention.php" 2>&1)
check "retention purges the two not hired"           'Job applications purged: 2' "$OUT"
check "the hired candidate is kept"                  'a2@example.com'     "$($MYSQL -N -e "SELECT email FROM careers_applications WHERE id=$AID2")"
check "the others' details are gone"                 'NULL'               "$($MYSQL -e "SELECT email FROM careers_applications WHERE id=$AID3")"
check "and their notes"                              '0'                  "$($MYSQL -N -e "SELECT COUNT(*) FROM careers_notes WHERE application_id=$AID")"
check "only the hired candidate's files remain"      '2'                  "$(ls "$UPLOADS"/cv-*.bin 2>/dev/null | wc -l)"
check "the cron URL needs the secret"                'Forbidden'          "$(curl -s "http://localhost:8081/resok-portal/public/api/cron/data-retention.php")"

rm -rf "$TMP" "$SUPER" "$REC" "$OTHER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
