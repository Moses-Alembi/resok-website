#!/usr/bin/env bash
#
# Presentation uploads (lib/conference-presentations.php): only the presenter uploads, in
# 1 MB pieces; the file must really be PowerPoint or PDF and not macro-enabled; versions are
# kept; others cannot download it; the programme team can; deadline and reminders work.
# Uses TESTPRES- abstracts and pres-*@resok.local accounts; restores the upload settings.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source tools/dev/lib-session.sh
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.180s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }
json() { $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) && array_key_exists($k, $d) ? $d[$k] : null; } echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
TMP="tools/dev/.pres-tmp"; mkdir -p "$TMP"
UPLOADS="resok-portal/uploads/conference-presentations"

curl -s "${API}conference/current" >/dev/null
ADMIN=$(minted_session dev@resok.local)
get "$ADMIN" conference/admin/presentations >/dev/null   # creates the tables
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED=$(q "SELECT CONCAT_WS('|', open, COALESCE(deadline,'')) FROM conf_presentation_settings WHERE event_id=$EV")
cleanup() {
    for f in $(q "SELECT p.file_name FROM conf_presentations p JOIN abs_abstracts a ON CONCAT('abstract:', a.id) = p.slot WHERE a.reference LIKE 'TESTPRES-%'"); do rm -f "$UPLOADS/$f"; done
    $MYSQL -e "
      DELETE p FROM conf_presentations p JOIN abs_abstracts a ON CONCAT('abstract:', a.id) = p.slot WHERE a.reference LIKE 'TESTPRES-%';
      DELETE FROM conf_presentation_uploads WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'pres-%@resok.local');
      DELETE FROM abs_emails WHERE to_email LIKE 'pres-%@resok.local';
      DELETE d FROM abs_decisions d JOIN abs_abstracts a ON a.id = d.abstract_id WHERE a.reference LIKE 'TESTPRES-%';
      DELETE au FROM abs_authors au JOIN abs_abstracts a ON a.id = au.abstract_id WHERE a.reference LIKE 'TESTPRES-%';
      DELETE FROM abs_abstracts WHERE reference LIKE 'TESTPRES-%';
      DELETE FROM users WHERE email LIKE 'pres-%@resok.local';" 2>/dev/null
}
cleanup
DEV=$(q "SELECT id FROM users WHERE email='dev@resok.local'")
q "INSERT INTO users (email, password_hash, email_verified, role) VALUES ('pres-author@resok.local','x',1,'member'), ('pres-other@resok.local','x',1,'member')"
for n in 1 2; do
  q "INSERT INTO abs_abstracts (event_id, submitter_user_id, reference, title, status, submitted_at) VALUES ($EV, $DEV, 'TESTPRES-$n', 'Test talk $n', 'accepted', NOW());
     SET @a = LAST_INSERT_ID();
     INSERT INTO abs_authors (abstract_id, first_name, last_name, email, affiliation, is_presenting, is_corresponding) VALUES (@a, 'Pres', 'Author$n', 'pres-author@resok.local', 'KNH', 1, 1);
     INSERT INTO abs_decisions (abstract_id, outcome, presentation_type, decided_at, released_at) VALUES (@a, 'accept', 'oral', NOW(), IF($n=1, NOW(), NULL));"
done
A1=$(q "SELECT id FROM abs_abstracts WHERE reference='TESTPRES-1'")
A2=$(q "SELECT id FROM abs_abstracts WHERE reference='TESTPRES-2'")
AUTHOR=$(minted_session pres-author@resok.local); OTHER=$(minted_session pres-other@resok.local)

# Test files: a real-shaped .pptx of ~2.5 MB (so it goes in three pieces), a .pptm with macros
# renamed .pptx, and a text file renamed .pptx.
python - "$TMP" <<'PY'
import sys, os, zipfile
d = sys.argv[1]
def pptx(path, macro=False):
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_STORED) as z:
        z.writestr('[Content_Types].xml', '<Types/>')
        z.writestr('ppt/presentation.xml', '<p:presentation/>')
        z.writestr('ppt/media/image1.bin', os.urandom(2_500_000))
        if macro: z.writestr('ppt/vbaProject.bin', b'macro')
pptx(os.path.join(d, 'talk.pptx'))
pptx(os.path.join(d, 'macro.pptx'), macro=True)
open(os.path.join(d, 'fake.pptx'), 'wb').write(b'not a presentation at all\n' * 100)
PY
# upload <jar> <slot> <file> [name] -> prints the finish response
upload() {
    local jar="$1" slot="$2" file="$3" name="${4:-$(basename "$3")}" size id off=0 chunk=1048576 r
    size=$(stat -c %s "$file")
    r=$(post "$jar" conference/presentations/start "{\"slot\":\"$slot\",\"fileName\":\"$name\",\"size\":$size}")
    id=$(echo "$r" | json uploadId)
    [ -z "$id" ] && { echo "$r"; return; }
    while [ "$off" -lt "$size" ]; do
        dd if="$file" of="$TMP/piece" bs=$chunk skip=$((off / chunk)) count=1 2>/dev/null
        r=$(curl -s -b "$jar" -H 'Content-Type: application/octet-stream' --data-binary @"$TMP/piece" -X POST "${API}conference/presentations/upload/$id&offset=$off")
        off=$(echo "$r" | json received); [ -z "$off" ] && { echo "$r"; return; }
    done
    post "$jar" "conference/presentations/finish/$id" '{}'
}

echo "Before uploads open:"
post "$ADMIN" conference/admin/presentations/settings '{"open":false,"deadline":""}' >/dev/null
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"t.pptx\",\"size\":10}")
check "nobody can upload while closed"                         'closed' "$r"
r=$(post "$OTHER" conference/admin/presentations/settings '{"open":true}')
check "a delegate cannot open uploads"                         'Only the programme chair' "$r"
DEADLINE=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d H:i', strtotime('+10 days'));")
r=$(post "$ADMIN" conference/admin/presentations/settings "{\"open\":true,\"deadline\":\"$DEADLINE\"}")
check "the programme team opens uploads with a deadline"       '"open":true' "$r"

echo "Who uploads what:"
r=$(get "$AUTHOR" conference/presentations)
check "the presenting author sees their accepted abstract"     'TESTPRES-1' "$r"
[[ "$r" == *"TESTPRES-2"* ]] && check "an unreleased decision is not offered" "absent" "present" || check "an unreleased decision is not offered" "x" "x"
r=$(get "$OTHER" conference/presentations)
check "someone else has nothing to upload"                     '"slots":[]' "$r"
r=$(post "$OTHER" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"t.pptx\",\"size\":10}")
check "nor can they upload to another's slot"                  'not the presenter' "$r"

echo "Files are checked:"
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"virus.exe\",\"size\":10}")
check "a program is refused by name"                           'PowerPoint' "$r"
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"t.pptm\",\"size\":10}")
check "a macro-enabled .pptm is refused"                       'Macro-enabled' "$r"
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"big.pptx\",\"size\":200000000}")
check "more than 100 MB is refused"                            '100 MB' "$r"
r=$(upload "$AUTHOR" "abstract:$A1" "$TMP/fake.pptx")
check "a text file named .pptx is refused when checked"        'not a valid' "$r"
r=$(upload "$AUTHOR" "abstract:$A1" "$TMP/macro.pptx")
check "a presentation with macros named .pptx is refused"      'not a valid' "$r"

echo "Uploading:"
r=$(upload "$AUTHOR" "abstract:$A1" "$TMP/talk.pptx" "My talk.pptx")
check "a 2.5 MB PowerPoint arrives in pieces"                  '"version":1' "$r"
FILE=$(q "SELECT file_name FROM conf_presentations WHERE slot='abstract:$A1' ORDER BY version DESC LIMIT 1")
check "it is stored outside the web root, intact"              "$(sha256sum "$TMP/talk.pptx" | cut -c1-64)" "$(sha256sum "$UPLOADS/$FILE" 2>/dev/null | cut -c1-64)"
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"t.pptx\",\"size\":2000}")
ID=$(echo "$r" | json uploadId)
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$AUTHOR" -H 'Content-Type: application/octet-stream' --data-binary 'abc' -X POST "${API}conference/presentations/upload/$ID&offset=999")
check "a piece out of order is refused"                        "409" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$OTHER" -H 'Content-Type: application/octet-stream' --data-binary 'abc' -X POST "${API}conference/presentations/upload/$ID&offset=0")
check "someone else cannot add to an upload"                   "400" "$code"
r=$(upload "$AUTHOR" "abstract:$A1" "$TMP/talk.pptx" "My talk v2.pptx")
check "replacing it makes version 2"                           '"version":2' "$r"
check "both versions are kept"                                 "2" "$(q "SELECT COUNT(*) FROM conf_presentations WHERE slot='abstract:$A1'")"

echo "Downloading:"
FID=$(q "SELECT id FROM conf_presentations WHERE slot='abstract:$A1' ORDER BY version DESC LIMIT 1")
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$OTHER" "${API}conference/presentations/file/$FID")
check "another delegate cannot download it"                    "404" "$code"
curl -s -b "$ADMIN" -D "$TMP/h" -o "$TMP/dl.pptx" "${API}conference/presentations/file/$FID"
check "the programme team downloads the newest version"        "$(sha256sum "$TMP/talk.pptx" | cut -c1-64)" "$(sha256sum "$TMP/dl.pptx" | cut -c1-64)"
check "as an attachment"                                       'attachment; filename="My talk v2.pptx"' "$(cat "$TMP/h")"
r=$(get "$ADMIN" conference/admin/presentations)
check "the team sees what is received"                         '"received":1' "$r"

echo "Reminders and the deadline:"
q "INSERT INTO abs_authors (abstract_id, first_name, last_name, email, affiliation, is_presenting, is_corresponding) SELECT $A2, 'X', 'Y', 'x', 'x', 0, 0 FROM DUAL WHERE 0"
q "UPDATE abs_decisions SET released_at = NOW() WHERE abstract_id = $A2"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "a presenter with no file is reminded"                   "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_presentation_reminder' AND subject LIKE '%presentation%' AND to_email='pres-author@resok.local'")"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "only once at each stage"                                "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_presentation_reminder' AND to_email='pres-author@resok.local'")"
PAST=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d H:i', strtotime('-1 hour'));")
post "$ADMIN" conference/admin/presentations/settings "{\"deadline\":\"$PAST\"}" >/dev/null
r=$(post "$AUTHOR" conference/presentations/start "{\"slot\":\"abstract:$A1\",\"fileName\":\"late.pptx\",\"size\":10}")
check "after the deadline, uploads are closed"                 'closed' "$r"

cleanup
if [ -n "$SAVED" ]; then IFS='|' read -r o d <<< "$SAVED"; q "UPDATE conf_presentation_settings SET open=$o, deadline=NULLIF('$d','') WHERE event_id=$EV"; else q "DELETE FROM conf_presentation_settings WHERE event_id=$EV"; fi
rm -rf "$TMP"; rm -f "$ADMIN" "$AUTHOR" "$OTHER"
echo; echo "$pass passed, $fail failed"; [ "$fail" -eq 0 ]
