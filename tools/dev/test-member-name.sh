#!/usr/bin/env bash
#
# Correcting a member's name from the admin review page (members/{id}/name). The register
# import put some names in the wrong fields, e.g. Dr Faraj "Amir Faraj Tamin" for Faraj Amir
# Tamimy. Uses its own t-name-*@local accounts.
set -uo pipefail
cd "$(dirname "$0")/../.."
source tools/dev/lib-session.sh
API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.140s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }
cleanup() {
    q "DELETE y FROM member_payment_years y JOIN member_profiles mp ON mp.id = y.member_profile_id JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 't-name-%@local';
       DELETE a FROM admin_actions a JOIN member_profiles mp ON mp.id = a.target_member_profile_id JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 't-name-%@local';
       DELETE mp FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 't-name-%@local';
       DELETE FROM users WHERE email LIKE 't-name-%@local';" 2>/dev/null
}
cleanup
q "INSERT INTO users (email, password_hash, email_verified, role) VALUES ('t-name-admin@local','x',1,'admin'), ('t-name-m@local','x',1,'member');
   INSERT INTO member_profiles (user_id, title, first_name, surname, membership_status, membership_id)
     SELECT id, 'Dr', 'Faraj', 'Amir Faraj Tamin', 'active', 'ReSok M/T9' FROM users WHERE email='t-name-m@local';"
ADMIN=$(minted_session t-name-admin@local); MEM=$(minted_session t-name-m@local)
P=$(q "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email='t-name-m@local'")
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }

r=$(post "$MEM" "members/$P/name" '{"firstName":"X","surname":"Y"}')
check "a member cannot rename records"                   'rror' "$r"
r=$(post "$ADMIN" "members/$P/name" '{"title":"Dr","firstName":"Faraj","middleName":"Amir","surname":"Tamimy"}')
check "an admin corrects the name"                       '"surname":"Tamimy"' "$r"
check "stored in the right fields"                       "Dr|Faraj|Amir|Tamimy" "$(q "SELECT CONCAT_WS('|', title, first_name, middle_name, surname) FROM member_profiles WHERE id = $P")"
check "the change is in the audit log"                   "Dr Faraj Amir Faraj Tamin -> Dr Faraj Amir Tamimy" "$(q "SELECT reason FROM admin_actions WHERE target_member_profile_id = $P AND action = 'name_corrected'")"
r=$(post "$ADMIN" "members/$P/name" '{"firstName":"Faraj","surname":""}')
check "a surname is required"                            'both needed' "$r"
r=$(post "$ADMIN" "members/$P/name" '{"firstName":"<script>","surname":"Tamimy"}')
check "markup in a name is refused"                      'letters, spaces' "$r"
r=$(post "$ADMIN" "members/$P/name" '{"firstName":["x"],"surname":"Tamimy"}')
check "a list where a name belongs is refused"           'both needed' "$r"
r=$(post "$ADMIN" "members/$P/name" "{\"firstName\":\"Wanjiru-O'Brien\",\"surname\":\"Ng'ang'a\"}")
check "hyphens and apostrophes are fine"                 "Ng'ang'a" "$r"
check "the membership number is untouched"               "ReSok M/T9" "$(q "SELECT membership_id FROM member_profiles WHERE id = $P")"

echo "Marking a year paid (payments made outside the portal):"
r=$(post "$MEM" "members/$P/years" '{"year":2026,"paidOn":"2026-02-16"}')
check "a member cannot mark years paid"                  'rror' "$r"
r=$(post "$ADMIN" "members/$P/years" '{"year":2026,"paidOn":"2026-02-16","amount":"5000","receipt":"REG-1"}')
check "an admin marks 2026 paid"                         '"year":2026' "$r"
check "with its date and source"                         '"paidOn":"2026-02-16"' "$r"
check "the year tick shows in the members list"          "2026" "$(curl -s -b "$ADMIN" "${API}members" | /c/xampp/php/php.exe -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["members"]??$d) as $m) if(($m["membershipId"]??"")==="ReSok M/T9") echo json_encode($m["yearsPaid"]);')"
r=$(post "$ADMIN" "members/$P/years" '{"year":2026}')
check "the same year cannot be recorded twice"           'already recorded' "$r"
r=$(post "$ADMIN" "members/$P/years" '{"year":2025,"paidOn":"2999-01-01"}')
check "a future payment date is refused"                 'cannot be in the future' "$r"
r=$(post "$ADMIN" "members/$P/years" '{"year":1900}')
check "an impossible year is refused"                    'Choose a year' "$r"
check "it is in the audit log"                           "2026, paid 2026-02-16, KES 5000, receipt REG-1" "$(q "SELECT reason FROM admin_actions WHERE target_member_profile_id = $P AND action = 'year_marked_paid'")"
r=$(post "$ADMIN" "members/$P/years/2026/remove" '{}')
check "a year recorded by hand can be removed"           '"years":[]' "$r"
q "INSERT INTO member_payment_years (member_profile_id, year, source) VALUES ($P, 2024, 'register')"
r=$(post "$ADMIN" "members/$P/years/2024/remove" '{}')
check "a register year cannot be removed here"           'Only a year recorded by hand' "$r"

cleanup; rm -f "$ADMIN" "$MEM"
echo; echo "$pass passed, $fail failed"; [ "$fail" -eq 0 ]
