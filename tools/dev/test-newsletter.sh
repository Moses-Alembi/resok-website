#!/usr/bin/env bash
#
# Exercises the newsletter admin (lib/newsletter.php) and the unsubscribe link it puts in
# every newsletter (newsletter.php ?leave=).
#
# What matters most: only super admins see the list; only confirmed subscribers are sent to,
# each exactly once however often Send is pressed; every copy carries a working unsubscribe
# link that cannot be forged for someone else; and the archive inbox gets one copy, not one
# per subscriber.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
SITE="http://localhost:8081"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
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
sql() { $MYSQL -N -e "$1" 2>/dev/null; }

SUPER=$(minted_session dev@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 30 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }

get "$SUPER" newsletter/admin >/dev/null   # creates the tables on a fresh database
sql "DELETE FROM abs_emails WHERE template = 'newsletter';
DELETE FROM newsletter_campaigns; DELETE FROM newsletter_subscribers WHERE email LIKE 'nl-%@resok.local';
DELETE FROM users WHERE email = 'nl-admin@resok.local';
INSERT INTO users (email,password_hash,email_verified,role) VALUES ('nl-admin@resok.local','x',1,'admin');
INSERT INTO newsletter_subscribers (email, token_hash, status, confirmed_at) VALUES
 ('nl-a@resok.local', SHA2('a',256), 'confirmed', NOW()),
 ('nl-b@resok.local', SHA2('b',256), 'confirmed', NOW()),
 ('nl-p@resok.local', SHA2('p',256), 'pending', NULL),
 ('nl-u@resok.local', SHA2('u',256), 'unsubscribed', NULL);"
ADMIN=$(minted_session nl-admin@resok.local)
CONFIRMED=$(sql "SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")

echo "Who can see it"
check "anonymous is refused" "401" "$(curl -s -o /dev/null -w '%{http_code}' "${API}newsletter/admin")"
check "an ordinary admin is refused" "super administrators" "$(get "$ADMIN" newsletter/admin)"
check "the export refuses an ordinary admin" "super administrators" "$(get "$ADMIN" newsletter/admin/export)"
R=$(get "$SUPER" newsletter/admin)
check "a super admin sees the counts" "\"confirmed\":$CONFIRMED" "$R"
check "and the list" "nl-p@resok.local" "$R"

echo "Export"
CSV=$(get "$SUPER" newsletter/admin/export)
check "confirmed subscribers are exported" "nl-a@resok.local" "$CSV"
absent "pending addresses are not" "nl-p@resok.local" "$CSV"
absent "unsubscribed addresses are not" "nl-u@resok.local" "$CSV"

echo "Drafts"
check "a subject is required" "Write a subject" "$(post "$SUPER" newsletter/admin/campaigns '{"subject":"","body":"x"}')"
check "a button needs a link" "both a label and a link" "$(post "$SUPER" newsletter/admin/campaigns '{"subject":"S","body":"B","ctaLabel":"Go"}')"
check "the link must be https" "https://" "$(post "$SUPER" newsletter/admin/campaigns '{"subject":"S","body":"B","ctaLabel":"Go","ctaUrl":"javascript:alert(1)"}')"
R=$(post "$SUPER" newsletter/admin/campaigns '{"subject":"Test CME","body":"Dear colleague,\n\nJoin us at https://www.resok.org/events.\n\nThanks","ctaLabel":"Register","ctaUrl":"https://www.resok.org/events"}')
ID=$(echo "$R" | json campaign.id)
check "a draft is saved" "\"status\":\"draft\"" "$R"
check "a draft can be edited" "Test CME 2" "$(post "$SUPER" newsletter/admin/campaigns "{\"id\":$ID,\"subject\":\"Test CME 2\",\"body\":\"Dear colleague,\\n\\nJoin us at https://www.resok.org/events.\\n\\nThanks\"}")"

echo "Sending"
check "a stale count is refused" "list has changed" "$(post "$SUPER" newsletter/admin/campaigns/$ID/send '{"expected":999}')"
check "the send is queued" "\"queued\":$CONFIRMED" "$(post "$SUPER" newsletter/admin/campaigns/$ID/send "{\"expected\":$CONFIRMED}")"
check "pressing Send again does nothing" "already been sent" "$(post "$SUPER" newsletter/admin/campaigns/$ID/send "{\"expected\":$CONFIRMED}")"
check "a sent newsletter cannot be edited" "already been sent" "$(post "$SUPER" newsletter/admin/campaigns "{\"id\":$ID,\"subject\":\"x\",\"body\":\"y\"}")"
check "one copy per confirmed subscriber, plus one archive copy" "$((CONFIRMED + 1))" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE dedupe_key LIKE 'nl:$ID:%'")"
check "nobody pending gets it" "0" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE dedupe_key LIKE 'nl:$ID:%' AND to_email IN ('nl-p@resok.local','nl-u@resok.local')")"
check "the archive copy is marked" "[Archive] Test CME 2" "$(sql "SELECT subject FROM abs_emails WHERE dedupe_key = 'nl:$ID:archive'")"
HTML=$(sql "SELECT html_body FROM abs_emails WHERE to_email = 'nl-a@resok.local' AND dedupe_key LIKE 'nl:$ID:%'")
check "web addresses become links, without the full stop" 'events</a>.' "$HTML"
check "every copy has an unsubscribe link" "newsletter.php?leave=" "$HTML"
check "the abstracts email log leaves newsletters out" "0" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE (event_id IS NULL) AND template <> 'newsletter' AND dedupe_key LIKE 'nl:%'")"

echo "Unsubscribe link"
LEAVE=$(echo "$HTML" | grep -o 'newsletter.php?leave=[0-9]*\.[a-f0-9]*' | head -1)
SID=$(sql "SELECT id FROM newsletter_subscribers WHERE email='nl-a@resok.local'")
OTHER=$(sql "SELECT id FROM newsletter_subscribers WHERE email='nl-b@resok.local'")
FORGED="newsletter.php?leave=${OTHER}.${LEAVE##*.}"
check "a signature for someone else is refused" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/$FORGED")"
check "nl-b is still subscribed" "confirmed" "$(sql "SELECT status FROM newsletter_subscribers WHERE id=$OTHER")"
check "the real link unsubscribes" "unsubscribed" "$(curl -s "$SITE/$LEAVE")"
check "nl-a is now off the list" "unsubscribed" "$(sql "SELECT status FROM newsletter_subscribers WHERE id=$SID")"

echo "Admin unsubscribe"
sql "UPDATE abs_emails SET status='queued' WHERE dedupe_key = 'nl:$ID:$OTHER'"
check "an admin can take someone off" "\"ok\":true" "$(post "$SUPER" newsletter/admin/subscribers/$OTHER/unsubscribe)"
check "their waiting copy is withdrawn" "0" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE dedupe_key = 'nl:$ID:$OTHER' AND status='queued'")"

echo "Drafts can be deleted, sent newsletters cannot"
D=$(post "$SUPER" newsletter/admin/campaigns '{"subject":"Scrap","body":"x"}' | json campaign.id)
check "deleting a draft" "\"ok\":true" "$(post "$SUPER" newsletter/admin/campaigns/$D/delete)"
check "deleting a sent one is refused" "already been sent" "$(post "$SUPER" newsletter/admin/campaigns/$ID/delete)"

echo "PDF newsletters"
TMP=$(mktemp -d)
# A real-looking PDF of about 2.5 MB, so it arrives in three pieces.
{ printf '%%PDF-1.4\n'; head -c 2600000 /dev/zero | tr '\0' 'x' | fold -w 100 | sed 's/^/% /'; printf '\n%%%%EOF\n'; } > "$TMP/news.pdf"
{ printf '%%PDF-1.4\n1 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n'; head -c 2000 /dev/zero | tr '\0' 'x'; printf '\n%%%%EOF\n'; } > "$TMP/bad.pdf"
COVER="assets/img/events/2026-09-28-infectious-lung-disorders/poster.jpg"
upload() {   # upload <campaign id> <file>  -> prints the finish response
    local id="$1" f="$2" size off=0 n=0
    size=$(stat -c %s "$f")
    post "$SUPER" "newsletter/admin/campaigns/$id/pdf-start" "{\"fileName\":\"$(basename "$f")\",\"size\":$size}" >/dev/null
    while [ $off -lt $size ]; do
        dd if="$f" bs=1048576 skip=$n count=1 status=none > "$TMP/piece"
        curl -s -b "$SUPER" -X POST --max-time 30 "${API}newsletter/admin/campaigns/$id/pdf-chunk&offset=$off" -H 'Content-Type: application/octet-stream' --data-binary "@$TMP/piece" >/dev/null
        off=$((off + $(stat -c %s "$TMP/piece"))); n=$((n+1))
    done
    post "$SUPER" "newsletter/admin/campaigns/$id/pdf-finish"
}
P=$(post "$SUPER" newsletter/admin/campaigns '{"subject":"October newsletter","body":""}' | json campaign.id)
check "a draft with no message and no PDF cannot be tested" "message or upload a PDF" "$(post "$SUPER" newsletter/admin/campaigns/$P/test)"
check "a PDF over the limit is refused up front" "under 20 MB" "$(post "$SUPER" newsletter/admin/campaigns/$P/pdf-start '{"fileName":"big.pdf","size":30000000}')"
check "only PDFs are accepted" "Upload a PDF" "$(post "$SUPER" newsletter/admin/campaigns/$P/pdf-start '{"fileName":"news.docx","size":5000}')"
check "a PDF with a script is refused" "scripts or embedded files" "$(upload $P "$TMP/bad.pdf")"
R=$(upload $P "$TMP/news.pdf")
check "a large PDF arrives in pieces and is accepted" '"ready":true' "$R"
KEY=$(sql "SELECT pdf_key FROM newsletter_campaigns WHERE id=$P")
check "the stored PDF is byte-for-byte the upload" "$(md5sum < "$TMP/news.pdf" | cut -c1-32)" "$(curl -s "$SITE/newsletter.php?pdf=$KEY" | md5sum | cut -c1-32)"
check "it is served as a PDF" "application/pdf" "$(curl -s -o /dev/null -w '%{content_type}' "$SITE/newsletter.php?pdf=$KEY")"
check "a made-up key is not found" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/newsletter.php?pdf=0123456789abcdef0123456789abcdef")"
check "a cover that is not a JPEG is refused" "preview picture" "$(curl -s -b "$SUPER" -X POST "${API}newsletter/admin/campaigns/$P/cover" -H 'Content-Type: image/jpeg' --data-binary "@$TMP/bad.pdf")"
check "the first-page picture is accepted" '"cover":true' "$(curl -s -b "$SUPER" -X POST "${API}newsletter/admin/campaigns/$P/cover" -H 'Content-Type: image/jpeg' --data-binary "@$COVER")"
check "and served as an image" "image/jpeg" "$(curl -s -o /dev/null -w '%{content_type}' "$SITE/newsletter.php?cover=$KEY")"
sql "INSERT INTO newsletter_subscribers (email, token_hash, status, confirmed_at) VALUES ('nl-c@resok.local', SHA2('c',256), 'confirmed', NOW())"
N=$(sql "SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")
check "a PDF newsletter with no message can be sent" "\"queued\":$N" "$(post "$SUPER" newsletter/admin/campaigns/$P/send "{\"expected\":$N}")"
HTML=$(sql "SELECT html_body FROM abs_emails WHERE to_email = 'nl-c@resok.local' AND dedupe_key LIKE 'nl:$P:%'")
TEXT=$(sql "SELECT text_body FROM abs_emails WHERE to_email = 'nl-c@resok.local' AND dedupe_key LIKE 'nl:$P:%'")
check "the email shows the first page" "newsletter.php?cover=$KEY" "$HTML"
check "with a button that opens the PDF" "Read the newsletter" "$HTML"
check "the plain-text version links the PDF" "newsletter.php?pdf=$KEY" "$TEXT"
check "nothing is attached" "0" "$(sql "SELECT COUNT(*) FROM abs_emails WHERE dedupe_key LIKE 'nl:$P:%' AND html_body LIKE '%Content-Disposition: attachment%'")"
check "a sent newsletter's PDF cannot be replaced" "already been sent" "$(post "$SUPER" newsletter/admin/campaigns/$P/pdf-start '{"fileName":"x.pdf","size":5000}')"
D=$(post "$SUPER" newsletter/admin/campaigns '{"subject":"Scrap PDF","body":"x"}' | json campaign.id)
upload $D "$TMP/news.pdf" >/dev/null
DKEY=$(sql "SELECT pdf_key FROM newsletter_campaigns WHERE id=$D")
post "$SUPER" newsletter/admin/campaigns/$D/delete >/dev/null
check "deleting a draft deletes its PDF" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/newsletter.php?pdf=$DKEY")"
rm -rf "$TMP"

# The test newsletters' files, then the rows.
for k in $(sql "SELECT pdf_key FROM newsletter_campaigns WHERE pdf_key IS NOT NULL"); do
    rm -f "$(dirname "$0")/../../resok-portal/uploads/newsletters/$k".*
done
# Leave the queue as it was: the test rows were addressed to made-up local addresses.
sql "DELETE FROM abs_emails WHERE template = 'newsletter'; DELETE FROM newsletter_campaigns;
DELETE FROM newsletter_subscribers WHERE email LIKE 'nl-%@resok.local'; DELETE FROM users WHERE email = 'nl-admin@resok.local';"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
