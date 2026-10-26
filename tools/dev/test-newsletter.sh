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
check "a subject is required" "subject and a message" "$(post "$SUPER" newsletter/admin/campaigns '{"subject":"","body":"x"}')"
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

# Leave the queue as it was: the test rows were addressed to made-up local addresses.
sql "DELETE FROM abs_emails WHERE template = 'newsletter'; DELETE FROM newsletter_campaigns;
DELETE FROM newsletter_subscribers WHERE email LIKE 'nl-%@resok.local'; DELETE FROM users WHERE email = 'nl-admin@resok.local';"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
