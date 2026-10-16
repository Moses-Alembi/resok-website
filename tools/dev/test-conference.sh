#!/usr/bin/env bash
#
# Exercises conference registration, payment, badges and check-in end to end, following the
# platform schematics: register at a price the server sets, pay, have finance confirm, get a
# badge, be scanned in; and the paths that protect the door - a reused M-Pesa code, a member
# rate claimed by a non-member, a reprinted badge, a transfer, a scan uploaded twice.
#
# Runs against the local site on :8081 and the laptop database. Uses its own conf-*@resok.local
# accounts and "TEST " categories on the current abstract event, and removes them afterwards.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source tools/dev/lib-session.sh
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-64s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-64s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); $p = explode(".", $argv[1]); foreach ($p as $k) { $d = is_array($d) && array_key_exists($k, $d) ? $d[$k] : null; } echo is_scalar($d) ? $d : json_encode($d);' "$1"; }

cleanup() {
    $MYSQL -e "
      DELETE a FROM event_attendees a JOIN cpd_events e ON e.id = a.event_id WHERE e.slug = 'test-conf-cpd-day';
      DELETE l FROM conf_cpd_links l JOIN cpd_events e ON e.id = l.cpd_event_id WHERE e.slug = 'test-conf-cpd-day';
      DELETE FROM cpd_events WHERE slug = 'test-conf-cpd-day';
      DELETE s FROM conf_scans s JOIN conf_registrations r ON r.id = s.registration_id JOIN users u ON u.id = r.user_id WHERE u.email LIKE 'conf-%@resok.local';
      DELETE FROM conf_scans WHERE code LIKE 'RSK-TESTBOGUS%' OR client_id LIKE 't-%';
      DELETE FROM conf_payments WHERE reference LIKE 'TST%' OR reference = 'RCPT-001';
      DELETE FROM abs_emails WHERE to_email LIKE 'conf-%@resok.local';
      DELETE b FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TEST %';
      DELETE p FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TEST %';
      DELETE ra FROM conf_registration_addons ra JOIN conf_addons a ON a.id = ra.addon_id WHERE a.name LIKE 'TEST %';
      DELETE r FROM conf_registrations r JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TEST %';
      DELETE w FROM conf_fee_windows w JOIN conf_categories c ON c.id = w.category_id WHERE c.name LIKE 'TEST %';
      DELETE FROM conf_categories WHERE name LIKE 'TEST %';
      DELETE FROM conf_addons WHERE name LIKE 'TEST %';
      DELETE s FROM conf_staff s JOIN users u ON u.id = s.user_id WHERE u.email LIKE 'conf-%@resok.local';
      DELETE mp FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 'conf-%@resok.local';
      DELETE ap FROM abs_profiles ap JOIN users u ON u.id = ap.user_id WHERE u.email LIKE 'conf-%@resok.local';
      DELETE FROM users WHERE email LIKE 'conf-%@resok.local';" 2>/dev/null
}

# The tables are created on first use, so touch the API once before cleaning.
curl -s "${API}conference/current" >/dev/null
cleanup
SAVED_SETTINGS=$(q "SELECT CONCAT_WS('|', open, COALESCE(opens_at,''), COALESCE(closes_at,'')) FROM conf_settings WHERE event_id = (SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1);")

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('conf-guest@resok.local', '$HASH', 1, 'member'), ('conf-member@resok.local', '$HASH', 1, 'member'),
  ('conf-scan@resok.local', '$HASH', 1, 'member'), ('conf-other@resok.local', '$HASH', 1, 'member');
  INSERT INTO member_profiles (user_id, first_name, surname, mobile, membership_status, renewal_due, institution, country)
  SELECT id, 'Conf', 'Member', '+254700000001', 'active', '2027-12-31', 'KNH', 'Kenya' FROM users WHERE email = 'conf-member@resok.local';" >/dev/null

ADMIN=$(minted_session dev@resok.local)
GUEST=$(minted_session conf-guest@resok.local)
MEMBER=$(minted_session conf-member@resok.local)
SCAN=$(minted_session conf-scan@resok.local)
OTHER=$(minted_session conf-other@resok.local)
FAR="2099-12-31"

echo "Setting up the conference:"
r=$(post "$GUEST" conference/admin/settings '{"open":true}')
check "a delegate cannot change settings"                       'do not have access' "$r"
r=$(post "$ADMIN" conference/admin/settings '{"open":true,"opensAt":"","closesAt":""}')
check "an administrator opens registration"                     '"state":"open"' "$r"
post "$ADMIN" conference/admin/settings '{"notes":"TEST pay instructions"}' >/dev/null
r=$(post "$ADMIN" conference/admin/settings '{"open":true}')
check "switching registration on keeps the payment instructions" 'TEST pay instructions' "$r"
post "$ADMIN" conference/admin/categories "{\"name\":\"TEST Member\",\"currency\":\"KES\",\"memberRate\":true,\"windows\":[{\"label\":\"Early bird\",\"price\":15000,\"endsOn\":\"2000-01-01\"},{\"label\":\"Standard\",\"price\":18000,\"endsOn\":\"$FAR\"}]}" >/dev/null
post "$ADMIN" conference/admin/categories "{\"name\":\"TEST Non-member\",\"currency\":\"KES\",\"windows\":[{\"label\":\"Standard\",\"price\":20000,\"endsOn\":\"$FAR\"}]}" >/dev/null
r=$(post "$ADMIN" conference/admin/categories '{"name":"TEST Faculty","currency":"KES","adminOnly":true,"windows":[{"label":"Complimentary","price":0}]}')
check "categories saved"                                        'TEST Faculty' "$r"
r=$(post "$ADMIN" conference/admin/categories '{"name":"TEST Broken","windows":[]}')
check "a category with no fee is refused"                       'at least one fee' "$r"
post "$ADMIN" conference/admin/addons '{"name":"TEST Workshop","price":5000,"currency":"KES","capacity":1}' >/dev/null
CAT_MEMBER=$(q "SELECT id FROM conf_categories WHERE name='TEST Member'")
CAT_NON=$(q "SELECT id FROM conf_categories WHERE name='TEST Non-member'")
CAT_FAC=$(q "SELECT id FROM conf_categories WHERE name='TEST Faculty'")
ADDON=$(q "SELECT id FROM conf_addons WHERE name='TEST Workshop'")

r=$(get "$GUEST" conference/current)
check "the public view prices a category by today's window"     '"label":"Standard","price":18000' "$r"
check "admin-only categories are not offered to delegates"      'TEST Non-member' "$r"
[[ "$r" == *"TEST Faculty"* ]] && check "faculty category hidden" "hidden" "shown" || check "faculty category hidden" "x" "x"

echo "Registering:"
r=$(post "$GUEST" conference/register "{\"categoryId\":$CAT_MEMBER,\"badgeName\":\"Guest Delegate\",\"institution\":\"MOH\",\"country\":\"Kenya\"}")
check "a non-member cannot take the member rate"                'member rate is for ReSoK members' "$r"
r=$(post "$GUEST" conference/register "{\"categoryId\":$CAT_FAC,\"badgeName\":\"Guest Delegate\",\"institution\":\"MOH\",\"country\":\"Kenya\"}")
check "a delegate cannot choose an admin-only category"         'Choose one of the registration categories' "$r"
r=$(post "$GUEST" conference/register "{\"categoryId\":$CAT_NON,\"badgeName\":\"Guest Delegate\",\"institution\":\"MOH\",\"country\":\"Kenya\",\"addons\":[$ADDON],\"amountDue\":1}")
check "a non-member registers with a workshop"                  '"status":"awaiting_payment"' "$r"
check "the price is the server's, not the browser's"            '"amountDue":25000' "$r"
r=$(post "$GUEST" conference/register "{\"categoryId\":$CAT_NON,\"badgeName\":\"Guest Again\",\"institution\":\"MOH\",\"country\":\"Kenya\"}")
check "the same person cannot register twice"                   'already registered' "$r"
r=$(post "$MEMBER" conference/register "{\"categoryId\":$CAT_MEMBER,\"badgeName\":\"Dr Conf Member\",\"institution\":\"KNH\",\"country\":\"Kenya\",\"addons\":[$ADDON]}")
check "a full workshop is refused"                              'is full' "$r"
r=$(post "$MEMBER" conference/register "{\"categoryId\":$CAT_MEMBER,\"badgeName\":\"Dr Conf Member\",\"institution\":\"KNH\",\"country\":\"Kenya\"}")
check "a member in good standing gets the member rate"          '"amountDue":18000' "$r"

echo "Reminders and letters:"
$MYSQL -e "UPDATE conf_registrations r JOIN users u ON u.id = r.user_id SET r.created_at = NOW() - INTERVAL 4 DAY WHERE u.email = 'conf-member@resok.local';"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "an unpaid registration gets one payment reminder"        "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_payment_reminder' AND to_email='conf-member@resok.local'")"
check "a delegate with a payment waiting is not chased"         "0" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_payment_reminder' AND to_email='conf-guest@resok.local'")"
r=$(curl -s -b "$MEMBER" "${API}conference/invitation-letter&passport=AK1234567&nationality=Ugandan")
check "no visa letter before the registration is paid"          'once your registration is confirmed' "$r"

echo "Paying:"
r=$(post "$GUEST" conference/payment '{"method":"mpesa","reference":"short"}')
check "a malformed M-Pesa code is refused"                      '10-character' "$r"
r=$(post "$GUEST" conference/payment '{"method":"mpesa","reference":"TSTAB12345"}')
check "a delegate submits an M-Pesa code"                       '"status":"pending"' "$r"
r=$(post "$GUEST" conference/payment '{"method":"mpesa","reference":"TSTAB99999"}')
check "a second payment waits for the first to be checked"      'already waiting' "$r"
r=$(post "$MEMBER" conference/payment '{"method":"mpesa","reference":"TSTAB12345"}')
check "someone else's M-Pesa code is refused"                   'already been used' "$r"
PAY=$(q "SELECT p.id FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id JOIN users u ON u.id = r.user_id WHERE u.email='conf-guest@resok.local'")
r=$(post "$GUEST" "conference/admin/payments/$PAY/review" '{"decision":"confirm"}')
check "a delegate cannot confirm their own payment"             'do not have access' "$r"
r=$(post "$ADMIN" "conference/admin/payments/$PAY/review" '{"decision":"reject"}')
check "a rejection needs a reason"                              'Say why' "$r"
r=$(post "$ADMIN" "conference/admin/payments/$PAY/review" '{"decision":"reject","note":"Code not on the statement"}')
check "finance rejects a payment"                               '"ok":true' "$r"
r=$(post "$GUEST" conference/payment '{"method":"mpesa","reference":"TSTCD67890"}')
check "the delegate tries again"                                '"status":"pending"' "$r"
PAY2=$(q "SELECT p.id FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id JOIN users u ON u.id = r.user_id WHERE u.email='conf-guest@resok.local' AND p.status='pending'")
post "$ADMIN" "conference/admin/payments/$PAY2/review" '{"decision":"confirm"}' >/dev/null
r=$(get "$GUEST" conference/current)
check "a confirmed payment confirms the registration"           '"status":"confirmed"' "$r"
GUEST_CODE=$(echo "$r" | json me.registration.badge.code)
check "and issues a badge with a random code"                   'RSK-' "$GUEST_CODE"
REG_MEMBER=$(q "SELECT r.id FROM conf_registrations r JOIN users u ON u.id = r.user_id WHERE u.email='conf-member@resok.local'")
r=$(post "$ADMIN" "conference/admin/registrations/$REG_MEMBER/settle" '{"method":"waiver"}')
check "a waiver needs a reason"                                 'Say why' "$r"
r=$(post "$ADMIN" "conference/admin/registrations/$REG_MEMBER/settle" '{"method":"onsite","reference":"RCPT-001"}')
check "the desk takes payment and confirms"                     '"status":"confirmed"' "$r"
MEMBER_CODE=$(get "$MEMBER" conference/current | json me.registration.badge.code)
r=$(curl -s -b "$MEMBER" "${API}conference/invitation-letter&passport=AK1234567&nationality=Ugandan")
check "a confirmed delegate downloads a visa letter"            '%PDF' "${r:0:4}"
check "the letter carries the passport number typed"           'AK1234567' "$r"

echo "Scanning:"
r=$(get "$SCAN" conference/scan/roster)
check "a non-staff account cannot download the roster"          'do not have access' "$r"
post "$ADMIN" conference/admin/staff '{"email":"conf-scan@resok.local","role":"scanner"}' >/dev/null
r=$(get "$SCAN" conference/scan/roster)
check "a scanner downloads the roster"                          "$GUEST_CODE" "$r"
[[ "$r" == *"conf-guest@resok.local"* || "$r" == *"+254"* ]] && check "the roster holds no contact details" "clean" "leaked" || check "the roster holds no contact details" "x" "x"
r=$(get "$SCAN" conference/admin/registrations)
check "a scanner cannot open the registration list"             'do not have access' "$r"
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-1\",\"code\":\"$GUEST_CODE\",\"gate\":\"Hall\",\"scannedAt\":\"$NOW\"}]}")
check "a registered badge scans green"                          '"result":"ok"' "$r"
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-1\",\"code\":\"$GUEST_CODE\",\"scannedAt\":\"$NOW\"}]}")
check "the same scan uploaded twice is stored once"             '"result":"ok"' "$r"
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-2\",\"code\":\"$GUEST_CODE\",\"scannedAt\":\"$NOW\"}]}")
check "the same badge again today scans orange"                 '"result":"duplicate"' "$r"
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-3\",\"code\":\"RSK-TESTBOGUS0000\",\"scannedAt\":\"$NOW\"}]}")
check "an unknown code scans red"                               '"result":"unknown"' "$r"
check "attendance counts one person once"                       "1" "$(q "SELECT COUNT(DISTINCT registration_id) FROM conf_scans WHERE code='$GUEST_CODE' AND result='ok'")"

echo "Badges:"
REG_GUEST=$(q "SELECT r.id FROM conf_registrations r JOIN users u ON u.id = r.user_id WHERE u.email='conf-guest@resok.local'")
post "$ADMIN" "conference/admin/registrations/$REG_GUEST/reprint" '{}' >/dev/null
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-4\",\"code\":\"$GUEST_CODE\",\"scannedAt\":\"$NOW\"}]}")
check "a reprinted badge's old code scans grey"                 '"result":"cancelled"' "$r"
r=$(post "$ADMIN" "conference/admin/registrations/$REG_MEMBER/transfer" '{"email":"conf-other@resok.local","badgeName":"Ms Colleague"}')
check "a registration is transferred to a colleague"            '"badgeName":"Ms Colleague"' "$r"
r=$(post "$SCAN" conference/scan/sync "{\"scans\":[{\"clientId\":\"t-5\",\"code\":\"$MEMBER_CODE\",\"scannedAt\":\"$NOW\"}]}")
check "the original holder's badge stops working"               '"result":"cancelled"' "$r"
r=$(get "$OTHER" conference/current)
check "the colleague now holds the registration"                '"badgeName":"Ms Colleague"' "$r"
r=$(get "$ADMIN" "conference/admin/badges&unprinted=1")
check "filters after & reach the server (raw)"                  '"badges":[' "$r"
r=$(curl -s -b "$ADMIN" "${API}conference%2Fadmin%2Fbadges%26unprinted%3D1")
check "and when the page's helper encodes them"                 'Ms Colleague' "$r"

echo "CPD and certificates:"
$MYSQL -e "INSERT INTO cpd_events (slug, title, event_type, format, starts_at, approved_points, status)
           VALUES ('test-conf-cpd-day', 'TEST Conference day 1', 'Conference', 'in_person', NOW(), 6.0, 'published');"
CPD=$(q "SELECT id FROM cpd_events WHERE slug='test-conf-cpd-day'")
TODAY=$(q "SELECT DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+03:00'))")
r=$(get "$ADMIN" conference/admin/cpd)
check "the day with scans is listed for CPD"                    "\"day\":\"$TODAY\"" "$r"
r=$(post "$SCAN" conference/admin/cpd "{\"day\":\"$TODAY\",\"cpdEventId\":$CPD}")
check "a scanner cannot send attendance to CPD"                 'do not have access' "$r"
r=$(post "$ADMIN" conference/admin/cpd "{\"day\":\"$TODAY\",\"cpdEventId\":$CPD}")
check "a day's scans are sent to its CPD event"                 '"cpdEvent":"TEST Conference day 1"' "$r"
check "the attendee is marked as attended in person"           '1|in_person' "$(q "SELECT CONCAT(attended,'|',channel) FROM event_attendees WHERE event_id=$CPD AND email='conf-guest@resok.local'")"
r=$(post "$ADMIN" conference/admin/cpd "{\"day\":\"$TODAY\",\"cpdEventId\":$CPD}")
check "sending again adds nobody twice"                         '"added":0,' "$r"
r=$(curl -s -b "$GUEST" "${API}conference/certificate" | head -c 4)
check "a delegate scanned in downloads a certificate"           '%PDF' "$r"
r=$(get "$OTHER" conference/certificate)
check "no certificate before being scanned in"                  'once you have been scanned in' "$r"
r=$(curl -s -b "$ADMIN" "${API}conference/admin/registrations/$REG_GUEST/certificate" | head -c 4)
check "desk staff download a delegate's certificate"            '%PDF' "$r"
r=$(get "$ADMIN" "conference/admin/badges&unprinted=1")
check "unprinted badges are listed for printing"                'Ms Colleague' "$r"

echo "Closing:"
r=$(curl -s -b "$ADMIN" "${API}conference/admin/export")
check "the export lists registrations"                          'Guest Delegate' "$r"
post "$ADMIN" conference/admin/settings '{"open":false}' >/dev/null
r=$(post "$ADMIN" "conference/admin/registrations/$REG_GUEST/cancel" '{"reason":"Test cancellation"}')
check "a registration is cancelled"                             '"status":"cancelled"' "$r"
r=$(post "$GUEST" conference/register "{\"categoryId\":$CAT_NON,\"badgeName\":\"Late\",\"institution\":\"MOH\",\"country\":\"Kenya\"}")
check "nobody registers once registration is closed"            'not open' "$r"

cleanup
# Put the conference's registration switch back as it was before the test.
IFS='|' read -r o oa ca <<< "${SAVED_SETTINGS:-0||}"
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
$MYSQL -e "UPDATE conf_settings SET open=${o:-0}, opens_at=NULLIF('$oa',''), closes_at=NULLIF('$ca','') WHERE event_id=$EV;" 2>/dev/null
rm -f "$ADMIN" "$GUEST" "$MEMBER" "$SCAN" "$OTHER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
