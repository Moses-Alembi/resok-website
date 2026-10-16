#!/usr/bin/env bash
#
# Exercises sponsors and booth lead scanning. The checks that matter are about delegates'
# data: a sponsor sees an email only when the delegate agreed at registration, sees only its
# own leads, and the public sponsor list carries no contact details.
#
# Uses spx-*@resok.local accounts, "TESTSP " categories and sponsors, removed afterwards.
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
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      must not contain: %s\n' "$1" "$2"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }

curl -s "${API}conference/sponsors" >/dev/null
curl -s "${API}conference/current" >/dev/null
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED=$(q "SELECT CONCAT_WS('|', open, COALESCE(opens_at,''), COALESCE(closes_at,'')) FROM conf_settings WHERE event_id=$EV")
cleanup() {
    $MYSQL -e "
      DELETE l FROM conf_leads l JOIN conf_sponsors s ON s.id = l.sponsor_id WHERE s.name LIKE 'TESTSP %';
      DELETE st FROM conf_sponsor_staff st JOIN conf_sponsors s ON s.id = st.sponsor_id WHERE s.name LIKE 'TESTSP %';
      DELETE FROM conf_sponsors WHERE name LIKE 'TESTSP %';
      DELETE b FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSP %';
      DELETE p FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSP %';
      DELETE r FROM conf_registrations r JOIN conf_categories c ON c.id = r.category_id WHERE c.name LIKE 'TESTSP %';
      DELETE w FROM conf_fee_windows w JOIN conf_categories c ON c.id = w.category_id WHERE c.name LIKE 'TESTSP %';
      DELETE FROM conf_categories WHERE name LIKE 'TESTSP %';
      DELETE FROM users WHERE email LIKE 'spx-%@resok.local';" 2>/dev/null
}
cleanup

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('spx-open@resok.local','$HASH',1,'member'),('spx-private@resok.local','$HASH',1,'member'),
  ('spx-booth@resok.local','$HASH',1,'member'),('spx-rival@resok.local','$HASH',1,'member');"
ADMIN=$(minted_session dev@resok.local)
OPEN=$(minted_session spx-open@resok.local)
PRIV=$(minted_session spx-private@resok.local)
BOOTH=$(minted_session spx-booth@resok.local)
RIVAL=$(minted_session spx-rival@resok.local)

post "$ADMIN" conference/admin/settings '{"open":true}' >/dev/null
post "$ADMIN" conference/admin/categories '{"name":"TESTSP Delegate","currency":"KES","windows":[{"label":"Standard","price":1000}]}' >/dev/null
CAT=$(q "SELECT id FROM conf_categories WHERE name='TESTSP Delegate'")
post "$OPEN" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Open Delegate\",\"institution\":\"KNH\",\"country\":\"Kenya\",\"shareContact\":true}" >/dev/null
post "$PRIV" conference/register "{\"categoryId\":$CAT,\"badgeName\":\"Private Delegate\",\"institution\":\"MOH\",\"country\":\"Kenya\",\"shareContact\":false}" >/dev/null
for e in spx-open spx-private; do
  RID=$(q "SELECT r.id FROM conf_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='$e@resok.local'")
  post "$ADMIN" "conference/admin/registrations/$RID/settle" "{\"method\":\"onsite\",\"reference\":\"TSP-$RID\"}" >/dev/null
done
CODE_OPEN=$(get "$OPEN" conference/current | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["me"]["registration"]["badge"]["code"] ?? "";')
CODE_PRIV=$(get "$PRIV" conference/current | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["me"]["registration"]["badge"]["code"] ?? "";')

echo "Sponsors:"
r=$(post "$OPEN" conference/admin/sponsors '{"name":"TESTSP Pharma"}')
check "a delegate cannot add sponsors"                          'Only conference administrators' "$r"
r=$(post "$ADMIN" conference/admin/sponsors '{"name":"TESTSP Pharma","tier":"gold","booth":"B12","contactEmail":"sales@pharma.test","website":"https://pharma.test"}')
check "an administrator adds a sponsor"                         'TESTSP Pharma' "$r"
post "$ADMIN" conference/admin/sponsors '{"name":"TESTSP Rival","tier":"exhibitor"}' >/dev/null
SP=$(q "SELECT id FROM conf_sponsors WHERE name='TESTSP Pharma'")
SP2=$(q "SELECT id FROM conf_sponsors WHERE name='TESTSP Rival'")
r=$(curl -s -b "$ADMIN" -F "logo=@resok-portal/public/assets/img/logo.png;type=image/png" "${API}conference/admin/sponsors/$SP/logo")
check "a logo is uploaded"                                      "sponsors/$SP/logo" "$r"
r=$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "${API}conference/sponsors/$SP/logo")
check "the logo is served publicly"                             '200 image/png' "$r"
r=$(curl -s "${API}conference/sponsors")
check "the public list shows sponsors by tier"                  '"tierLabel":"Gold"' "$r"
absent "the public list carries no contact email"              'sales@pharma.test' "$r"

echo "Lead scanning:"
r=$(get "$BOOTH" conference/leads)
check "an account with no sponsor cannot scan leads"           'not registered as staff' "$r"
r=$(post "$ADMIN" "conference/admin/sponsors/$SP/staff" '{"email":"spx-booth@resok.local"}')
check "booth staff are added by portal email"                  'spx-booth@resok.local' "$r"
post "$ADMIN" "conference/admin/sponsors/$SP2/staff" '{"email":"spx-rival@resok.local"}' >/dev/null
r=$(post "$BOOTH" conference/leads/scan "{\"sponsorId\":$SP,\"code\":\"$CODE_OPEN\"}")
check "a consenting delegate's email reaches the sponsor"       'spx-open@resok.local' "$r"
r=$(post "$BOOTH" conference/leads/scan "{\"sponsorId\":$SP,\"code\":\"$CODE_PRIV\"}")
check "a delegate who did not consent is saved by name"        'Private Delegate' "$r"
absent "and their email is withheld"                           'spx-private@resok.local' "$r"
r=$(post "$BOOTH" conference/leads/scan "{\"sponsorId\":$SP,\"code\":\"$CODE_OPEN\",\"note\":\"Wants a demo\"}")
check "scanning again adds a note, not a second lead"           'Wants a demo' "$r"
check "one lead per delegate"                                   "2" "$(q "SELECT COUNT(*) FROM conf_leads WHERE sponsor_id=$SP")"
r=$(post "$BOOTH" conference/leads/scan "{\"sponsorId\":$SP,\"code\":\"RSK-NOTABADGE0000\"}")
check "a code that is not a badge is refused"                   'not an active badge' "$r"
r=$(post "$BOOTH" conference/leads/scan "{\"sponsorId\":$SP2,\"code\":\"$CODE_OPEN\"}")
check "staff cannot scan for another sponsor"                   'do not scan for that sponsor' "$r"
r=$(get "$RIVAL" "conference/leads&sponsor=$SP")
check "a rival sponsor cannot read these leads"                 'do not scan for that sponsor' "$r"
r=$(curl -s -b "$BOOTH" "${API}conference/leads/export&sponsor=$SP")
check "the sponsor downloads its leads"                         'Open Delegate' "$r"
absent "the download withholds the private email"              'spx-private@resok.local' "$r"

cleanup
IFS='|' read -r o oa ca <<< "${SAVED:-0||}"
$MYSQL -e "UPDATE conf_settings SET open=${o:-0}, opens_at=NULLIF('$oa',''), closes_at=NULLIF('$ca','') WHERE event_id=$EV;" 2>/dev/null
rm -f "$ADMIN" "$OPEN" "$PRIV" "$BOOTH" "$RIVAL"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
