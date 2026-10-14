#!/usr/bin/env bash
#
# Exercises ILD MDT case submission (lib/mdt.php).
#
# The claims worth testing are the ones that protect patients: identifiers in the text stop a
# submission, clinical text and files are encrypted at rest, image metadata is stripped, each
# person sees only the cases they should, a draft recommendation stays with the panel, and
# retention really removes what it says it removes. The happy path is tested along the way.
#
# Needs 'mdt_enabled' => true in the laptop's config.local.php (the clinician steps act as
# an ordinary account) and a data_encryption_key, as the live server must have.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
UPLOADS="$(cd "$(dirname "$0")/../.." && pwd)/resok-portal/uploads/mdt-files"
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

# Fresh accounts and a clean slate. The test users are ordinary members; roles come from mdt_roles.
$MYSQL -e "
DELETE FROM mdt_audit; DELETE FROM mdt_recommendations; DELETE FROM mdt_messages; DELETE FROM mdt_files;
DELETE FROM mdt_cases; DELETE FROM mdt_meetings; DELETE FROM mdt_roles;
DELETE FROM users WHERE email IN ('mdt-clin@resok.local','mdt-panel@resok.local','mdt-coord@resok.local','mdt-other@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('mdt-clin@resok.local','x',1,'member'),('mdt-panel@resok.local','x',1,'member'),
 ('mdt-coord@resok.local','x',1,'member'),('mdt-other@resok.local','x',1,'member');" 2>/dev/null
rm -f "$UPLOADS"/mdt-*.bin 2>/dev/null

SUPER=$(minted_session dev@resok.local)
CLIN=$(minted_session mdt-clin@resok.local)
PANEL=$(minted_session mdt-panel@resok.local)
COORD=$(minted_session mdt-coord@resok.local)
OTHER=$(minted_session mdt-other@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }

echo "Roles:"
check "a member cannot reach role management"      'Route not found'       "$(post "$COORD" mdt/roles '{"email":"mdt-coord@resok.local","role":"coordinator"}')"
check "a super admin appoints a coordinator"       'mdt-coord@resok.local' "$(post "$SUPER" mdt/roles '{"email":"mdt-coord@resok.local","role":"coordinator"}')"
check "a super admin appoints a panellist"         'mdt-panel@resok.local' "$(post "$SUPER" mdt/roles '{"email":"mdt-panel@resok.local","role":"panellist"}')"
check "a panellist cannot grant roles"           'super administrator'   "$(post "$PANEL" mdt/roles '{"email":"mdt-other@resok.local","role":"coordinator"}')"
check "an unknown email is refused"                'No portal account'     "$(post "$SUPER" mdt/roles '{"email":"nobody@resok.local","role":"panellist"}')"
check "a non-staff account cannot see staff lists" 'Route not found'       "$(get "$CLIN" mdt/admin/cases)"

echo "Meetings:"
MEET=$(post "$COORD" mdt/meetings '{"title":"National ILD MDT Meeting (2)","meetsAt":"2030-10-28T19:00","venue":"Zoom","chairName":"Dr Chair"}')
check "a coordinator creates a meeting"            '"status":"planned"'    "$MEET"
MID=$(echo "$MEET" | json meeting.id)
check "a panellist cannot create one"              'Only an MDT coordinator' "$(post "$PANEL" mdt/meetings '{"title":"x","meetsAt":"2030-11-01T19:00"}')"
check "the clinician sees the upcoming meeting"    'Meeting (2)'           "$(get "$CLIN" mdt/me)"

echo "Drafting:"
CASE=$(post "$CLIN" mdt/cases)
CID=$(echo "$CASE" | json case.id)
check "a clinician starts a draft"                 '"status":"draft"'      "$CASE"
SAVE=$(post "$CLIN" "mdt/cases/$CID" '{"ageYears":58,"sex":"male","county":"Nairobi","clinicianName":"Dr A Clinician","clinicianCouncil":"KMPDC","clinicianRegNo":"A1234","facility":"Test Hospital","history":"Dyspnoea for 2 years. Patient phone 0712 345 678, ID 23456789, email pt@example.com","hrctFindings":"Basal subpleural reticulation, honeycombing.","question":"Is this UIP?","consentBasis":"consent","consentConfirmed":true,"noIdentifiersConfirmed":true,"preferredMeetingId":'"$MID"'}')
check "saving warns about a phone number"          'a phone number'        "$SAVE"
check "saving warns about an ID-length number"     '7 or 8 digit'          "$SAVE"
check "saving warns about an email address"        'an email address'      "$SAVE"
SUB=$(post "$CLIN" "mdt/cases/$CID/submit")
check "submitting is refused while they remain"    'must not receive patient identifiers' "$SUB"
check "and while there are no images"              'key HRCT image'        "$SUB"
PART=$(post "$CLIN" "mdt/cases/$CID" '{"history":"Dyspnoea for 2 years, dry cough, FVC 2.1 L."}')
absent "the cleaned text raises no warning"        'phone number'          "$PART"
check "a partial save keeps the other fields"      '"ageYears":58'         "$PART"
check "and keeps the consent confirmation"         '"consentConfirmed":true' "$PART"
check "another account cannot read the draft"     'No such case'          "$(get "$OTHER" "mdt/cases/$CID")"
check "staff cannot read a draft either"           'No such case'          "$(get "$COORD" "mdt/cases/$CID")"
CONTENT=$($MYSQL -N -e "SELECT LEFT(content,7) FROM mdt_cases WHERE id=$CID")
check "clinical text is encrypted in the database" 'enc.v1.'               "$CONTENT"

echo "Files:"
IMG=$(mktemp --suffix=.jpg)
# A JPEG carrying an EXIF block with a name in it, as a phone camera might write. Python's
# Pillow draws it because the laptop's command-line PHP has no image library.
python -c '
import io, struct, sys
from PIL import Image
buf = io.BytesIO(); Image.new("RGB", (40, 30), (90, 90, 90)).save(buf, "JPEG"); j = buf.getvalue()
exif = b"Exif\x00\x00MM\x00*\x00\x00\x00\x08Wanjiru Kamau"
sys.stdout.buffer.write(j[:2] + b"\xff\xe1" + struct.pack(">H", len(exif) + 2) + exif + j[2:])' > "$IMG"
check "the test image does carry a name"           'Wanjiru'               "$(cat "$IMG" | tr -d '\0')"
UP=$(curl -s -b "$CLIN" -F "file=@$(cygpath -w "$IMG");type=image/jpeg" -F "caption=Axial HRCT" "${API}mdt/cases/$CID/files")
check "an image uploads"                           '"kind":"image"'        "$UP"
FID=$(echo "$UP" | json files.0.id)
STORED=$(ls "$UPLOADS"/mdt-*.bin | head -1)
check "the stored file is encrypted"               'enc.v1.'               "$(head -c 7 "$STORED")"
DL=$(curl -s -b "$CLIN" "${API}mdt/files/$FID" | tr -d '\0')
absent "the name is gone from the served image"    'Wanjiru'               "$DL"
check "another account cannot fetch the file"      'File not found'        "$(get "$OTHER" "mdt/files/$FID")"
PDF=$(mktemp --suffix=.pdf); printf '%%PDF-1.4\n1 0 obj << /OpenAction 2 0 R >> endobj\n2 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n%%%%EOF' > "$PDF"
check "a PDF with script is refused"               'active content'        "$(curl -s -b "$CLIN" -F "file=@$(cygpath -w "$PDF");type=application/pdf" "${API}mdt/cases/$CID/files")"
TXT=$(mktemp --suffix=.txt); echo "hello" > "$TXT"
check "other file types are refused"               'JPG or PNG'            "$(curl -s -b "$CLIN" -F "file=@$(cygpath -w "$TXT")" "${API}mdt/cases/$CID/files")"

echo "Submitting:"
SUB=$(post "$CLIN" "mdt/cases/$CID/submit")
check "the clean case submits"                     '"status":"submitted"'  "$SUB"
REF=$(echo "$SUB" | json case.reference)
check "it gets a reference"                        'MDT-'                  "$REF"
check "the clinician can no longer edit it"        'can no longer be edited' "$(post "$CLIN" "mdt/cases/$CID" '{"county":"Kisumu"}')"
check "a panellist cannot see it before scheduling" 'No such case'         "$(get "$PANEL" "mdt/cases/$CID")"
VIEW=$(get "$COORD" "mdt/cases/$CID")
check "the coordinator can read it"                'Dyspnoea for 2 years'  "$VIEW"
check "and the coordinator's view is logged"       '1'                     "$($MYSQL -N -e "SELECT COUNT(*) FROM mdt_audit WHERE case_id=$CID AND action='viewed'")"

echo "Asking for more information:"
check "a panellist cannot even see it to triage"     'No such case' "$(post "$PANEL" "mdt/cases/$CID/triage" '{"action":"accept","meetingId":'"$MID"'}')"
check "the coordinator asks for more"              '"status":"info_requested"' "$(post "$COORD" "mdt/cases/$CID/triage" '{"action":"request_info","message":"Please add the DLCO."}')"
check "the clinician reads the request"            'Please add the DLCO'   "$(get "$CLIN" "mdt/cases/$CID")"
check "the clinician replies"                      'DLCO 45%'              "$(post "$CLIN" "mdt/cases/$CID/messages" '{"body":"DLCO 45% predicted."}')"
check "and can edit again"                         '"ageYears":58'         "$(post "$CLIN" "mdt/cases/$CID" '{"pft":"FVC 62%, DLCO 45%"}')"
check "a staff note is added"                      'Discuss first'         "$(post "$COORD" "mdt/cases/$CID/messages" '{"body":"Discuss first."}')"
absent "the clinician never sees staff notes"      'Discuss first'         "$(get "$CLIN" "mdt/cases/$CID")"
check "the case is resubmitted"                    '"status":"submitted"'  "$(post "$CLIN" "mdt/cases/$CID/submit")"

echo "Meeting and recommendation:"
check "the coordinator schedules it"               '"status":"scheduled"'  "$(post "$COORD" "mdt/cases/$CID/triage" '{"action":"accept","meetingId":'"$MID"'}')"
check "now the panellist can see it"               "$REF"                  "$(get "$PANEL" "mdt/cases/$CID")"
check "an incomplete recommendation cannot issue"  'Before issuing'        "$(post "$PANEL" "mdt/cases/$CID/recommendation" '{"diagnosis":"IPF","issue":true}')"
check "a draft recommendation saves"               '"issued":false'        "$(post "$PANEL" "mdt/cases/$CID/recommendation" '{"diagnosis":"IPF","summary":"Draft thoughts"}')"
absent "the clinician cannot see the draft"        'Draft thoughts'        "$(get "$CLIN" "mdt/cases/$CID")"
check "the letter is not available yet"            'not been issued'       "$(get "$CLIN" "mdt/cases/$CID/letter")"
check "another account cannot record one"          'No such case'          "$(post "$OTHER" "mdt/cases/$CID/recommendation" '{"issue":true}')"
ISSUE=$(post "$PANEL" "mdt/cases/$CID/recommendation" '{"diagnosis":"Idiopathic pulmonary fibrosis","confidence":"confident","pattern":"uip","summary":"UIP pattern, no exposures or CTD features.","treatment":"Start antifibrotic therapy.","followUp":"PFTs in 3 months.","panel":"Pulmonology, radiology","issue":true}')
check "the panel issues the recommendation"        '"status":"completed"'  "$ISSUE"
check "the chair's name comes from the meeting"    'Dr Chair'              "$ISSUE"
check "a purge date is set"                        '"purgeAfter":"20'      "$ISSUE"
LETTER=$(get "$CLIN" "mdt/cases/$CID/letter")
check "the clinician gets the letter"              'Idiopathic pulmonary fibrosis' "$LETTER"
check "the letter says the confidence in words"    'Confident diagnosis'   "$LETTER"
check "another account cannot get the letter"      'No such case'          "$(get "$OTHER" "mdt/cases/$CID/letter")"
check "the issue is in the audit trail"            '1'                     "$($MYSQL -N -e "SELECT COUNT(*) FROM mdt_audit WHERE case_id=$CID AND action='recommendation_issued'")"

echo "Withdrawing and declining:"
C2=$(post "$CLIN" mdt/cases | json case.id)
check "withdrawing a draft deletes it"             '"deleted":true'        "$(post "$CLIN" "mdt/cases/$C2/withdraw")"

echo "Retention:"
$MYSQL -e "UPDATE mdt_cases SET purge_after = CURDATE() - INTERVAL 1 DAY WHERE id=$CID" 2>/dev/null
OUT=$($PHP "$(dirname "$0")/../../resok-portal/public/api/cron/data-retention.php" 2>&1)
check "the retention job purges the due case"      'MDT cases purged: 1'       "$OUT"
check "its clinical text is gone"                  'NULL'                  "$($MYSQL -e "SELECT content FROM mdt_cases WHERE id=$CID")"
check "its files are gone from disk"               '0'                     "$(ls "$UPLOADS"/mdt-*.bin 2>/dev/null | wc -l)"
check "its messages are gone"                      '0'                     "$($MYSQL -N -e "SELECT COUNT(*) FROM mdt_messages WHERE case_id=$CID")"
check "the anonymous outline is kept"              'Idiopathic pulmonary fibrosis' "$($MYSQL -N -e "SELECT diagnosis FROM mdt_recommendations WHERE case_id=$CID")"
check "the recommendation's free text is gone"     'NULL'                  "$($MYSQL -e "SELECT content FROM mdt_recommendations WHERE case_id=$CID")"
check "the cron URL needs the secret"              'Forbidden'             "$(curl -s "http://localhost:8081/resok-portal/public/api/cron/data-retention.php")"

rm -f "$IMG" "$PDF" "$TXT" "$SUPER" "$CLIN" "$PANEL" "$COORD" "$OTHER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
