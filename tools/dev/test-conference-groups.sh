#!/usr/bin/env bash
#
# Exercises group registration and institutional invoices: joining with a code, not paying
# individually, the invoice, and one institutional payment confirming every member with
# their own badge. Uses grp-*@resok.local accounts and "TESTGR " names, removed afterwards.
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
json() { $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) && array_key_exists($k, $d) ? $d[$k] : null; } echo is_scalar($d) ? $d : json_encode($d);' "$1"; }

curl -s "${API}conference/current" >/dev/null
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED=$(q "SELECT CONCAT_WS('|', open, COALESCE(opens_at,''), COALESCE(closes_at,'')) FROM conf_settings WHERE event_id=$EV")
cleanup() {
    $MYSQL -e "
      DELETE gm FROM conf_group_members gm JOIN conf_groups g ON g.id = gm.group_id WHERE g.name LIKE 'TESTGR %';
      DELETE FROM conf_groups WHERE name LIKE 'TESTGR %';
      DELETE b FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTGR %';
      DELETE p FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTGR %';
      DELETE r FROM conf_registrations r JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTGR %';
      DELETE w FROM conf_fee_windows w JOIN conf_categories c ON c.id = w.category_id WHERE c.name LIKE 'TESTGR %';
      DELETE FROM conf_categories WHERE name LIKE 'TESTGR %';
      DELETE FROM abs_emails WHERE to_email LIKE 'grp-%@resok.local';
      DELETE FROM users WHERE email LIKE 'grp-%@resok.local';" 2>/dev/null
}
cleanup
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('grp-a@resok.local','$HASH',1,'member'),('grp-b@resok.local','$HASH',1,'member'),('grp-c@resok.local','$HASH',1,'member'),('grp-usd@resok.local','$HASH',1,'member'),('grp-d@resok.local','$HASH',1,'member');"
ADMIN=$(minted_session dev@resok.local)
A=$(minted_session grp-a@resok.local); B=$(minted_session grp-b@resok.local); C=$(minted_session grp-c@resok.local); U=$(minted_session grp-usd@resok.local); D=$(minted_session grp-d@resok.local)

post "$ADMIN" conference/admin/settings '{"open":true}' >/dev/null
post "$ADMIN" conference/admin/categories '{"name":"TESTGR Delegate","currency":"KES","windows":[{"label":"Standard","price":20000}]}' >/dev/null
post "$ADMIN" conference/admin/categories '{"name":"TESTGR International","currency":"USD","windows":[{"label":"Standard","price":200}]}' >/dev/null
CAT=$(q "SELECT id FROM conf_categories WHERE name='TESTGR Delegate'")
CATUSD=$(q "SELECT id FROM conf_categories WHERE name='TESTGR International'")

echo "Groups:"
r=$(post "$A" conference/admin/groups '{"name":"TESTGR Hospital"}')
check "a delegate cannot create groups"                         'Only finance' "$r"
r=$(post "$ADMIN" conference/admin/groups '{"name":"TESTGR Kenyatta National Hospital","contactName":"Finance Office","contactEmail":"finance@knh.test"}')
check "finance creates a group with an invoice number"          '-G0' "$r"
GID=$(q "SELECT id FROM conf_groups WHERE name='TESTGR Kenyatta National Hospital'")
CODE=$(q "SELECT code FROM conf_groups WHERE id=$GID")
r=$(post "$A" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Grp A\",\"institution\":\"KNH\",\"country\":\"Kenya\",\"groupCode\":\"WRONGCODE\"}")
check "a wrong group code is refused before registering"        'not recognised' "$r"
check "and nothing was saved"                                   "0" "$(q "SELECT COUNT(*) FROM conf_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='grp-a@resok.local'")"
r=$(post "$A" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Grp A\",\"institution\":\"KNH\",\"country\":\"Kenya\",\"groupCode\":\"$(echo $CODE | tr 'A-Z' 'a-z')\"}")
check "a delegate joins with the code (any case)"               '"group":{' "$r"
r=$(post "$A" conference/payment '{"method":"mpesa","reference":"GRPTST0001"}')
check "a group member is not asked to pay"                      'being paid for by' "$r"
post "$B" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Grp B\",\"institution\":\"KNH\",\"country\":\"Kenya\"}" >/dev/null
REFB=$(q "SELECT r.ref FROM conf_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='grp-b@resok.local'")
r=$(post "$ADMIN" "conference/admin/groups/$GID/add" "{\"ref\":\"$REFB\"}")
check "staff add an existing registration by reference"         'Grp B' "$r"
post "$U" conference/register "{\"categoryId\":$CATUSD,\"badgeName\":\"Grp USD\",\"institution\":\"KNH\",\"country\":\"Uganda\"}" >/dev/null
REFU=$(q "SELECT r.ref FROM conf_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='grp-usd@resok.local'")
r=$(post "$ADMIN" "conference/admin/groups/$GID/add" "{\"ref\":\"$REFU\"}")
check "an invoice is in one currency"                           'one currency' "$r"
check "the group totals its members"                            '"KES":40000' "$(get "$ADMIN" conference/admin/groups)"
r=$(curl -s -b "$ADMIN" "${API}conference/admin/groups/$GID/invoice" | head -c 4)
check "the invoice downloads as a PDF"                          '%PDF' "$r"

echo "Reminders skip the group:"
$MYSQL -e "UPDATE conf_registrations r JOIN users u ON u.id=r.user_id SET r.created_at = NOW() - INTERVAL 4 DAY WHERE u.email IN ('grp-a@resok.local','grp-c@resok.local');"
post "$C" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Grp C\",\"institution\":\"MOH\",\"country\":\"Kenya\"}" >/dev/null
$MYSQL -e "UPDATE conf_registrations r JOIN users u ON u.id=r.user_id SET r.created_at = NOW() - INTERVAL 4 DAY WHERE u.email='grp-c@resok.local';"
$PHP resok-portal/public/api/cron/abstracts.php >/dev/null 2>&1
check "a group member gets no payment reminder"                 "0" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_payment_reminder' AND to_email='grp-a@resok.local'")"
check "an individual delegate still does"                       "1" "$(q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_payment_reminder' AND to_email='grp-c@resok.local'")"

echo "Paying:"
r=$(post "$ADMIN" "conference/admin/groups/$GID/paid" '{"reference":""}')
check "a group payment needs the institution's reference"       'Enter the bank' "$r"
r=$(post "$ADMIN" "conference/admin/groups/$GID/paid" '{"reference":"EFT-778899"}')
check "one payment confirms every member"                       '"confirmed":2' "$r"
check "member A is confirmed with a badge"                      'RSK-' "$(get "$A" conference/current | json me.registration.badge.code)"
check "member B is confirmed with a badge"                      'RSK-' "$(get "$B" conference/current | json me.registration.badge.code)"
check "each member's payment carries the group reference"       "2" "$(q "SELECT COUNT(*) FROM conf_payments WHERE reference LIKE 'EFT-778899/%'")"
r=$(post "$ADMIN" "conference/admin/groups/$GID/add" "{\"ref\":\"$REFU\"}")
check "a paid group is closed to changes"                       'already been paid' "$r"
r=$(post "$D" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Late\",\"institution\":\"KNH\",\"country\":\"Kenya\",\"groupCode\":\"$CODE\"}")
check "a paid group's code no longer works"                     'not recognised' "$r"

cleanup
IFS='|' read -r o oa ca <<< "${SAVED:-0||}"
$MYSQL -e "UPDATE conf_settings SET open=${o:-0}, opens_at=NULLIF('$oa',''), closes_at=NULLIF('$ca','') WHERE event_id=$EV;" 2>/dev/null
rm -f "$ADMIN" "$A" "$B" "$C" "$U" "$D"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
