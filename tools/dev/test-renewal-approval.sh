#!/usr/bin/env bash
#
# An existing member who uploads a renewal slip must not be treated as a new applicant.
#
# Found 17 Oct 2026: an imported member (Dr Anne Irungu) uploaded a renewal slip, dropped into
# the review queue as "under review" - losing member benefits while she waited - and approving
# her sent "Welcome to the Respiratory Society of Kenya!" with a welcome letter and reset her
# renewal date to a year from that day. A new applicant must still get all of that.
set -uo pipefail
cd "$(dirname "$0")/../.."
source tools/dev/lib-session.sh

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s FAIL\n      should not contain: %s\n' "$1" "$2"; fail=$((fail+1))
    else printf '  %-62s ok\n' "$1"; pass=$((pass+1)); fi
}
q() { $MYSQL -N -e "$1"; }

PNG="tools/dev/.renewal-proof.png"
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82' > "$PNG"

cleanup() {
    q "DELETE p FROM payments p JOIN users u ON u.id = p.user_id WHERE u.email LIKE 't-renew-%@local';
       DELETE a FROM admin_actions a JOIN member_profiles mp ON mp.id = a.target_member_profile_id JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 't-renew-%@local';
       DELETE mp FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 't-renew-%@local';
       DELETE FROM users WHERE email LIKE 't-renew-%@local';" 2>/dev/null
}
cleanup
DUE=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d', strtotime('+100 days'));")
DUE_NEXT=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d', strtotime('$DUE +12 months'));")
YEAR_FROM_TODAY=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d', strtotime('+1 year'));")
q "INSERT INTO users (email, password_hash, email_verified, role) VALUES
     ('t-renew-admin@local','x',1,'admin'), ('t-renew-old@local','x',1,'member'), ('t-renew-lapsed@local','x',1,'member'), ('t-renew-new@local','x',1,'member');
   INSERT INTO member_profiles (user_id, title, first_name, surname, category, membership_status, membership_id, renewal_due)
     SELECT id, 'Dr', 'Old', 'Member', 'Ordinary Membership', 'active', 'ReSok M/T1', '$DUE' FROM users WHERE email='t-renew-old@local';
   INSERT INTO member_profiles (user_id, title, first_name, surname, category, membership_status, membership_id, renewal_due)
     SELECT id, 'Dr', 'Lapsed', 'Member', 'Ordinary Membership', 'expired', 'ReSok M/T2', '2025-01-31' FROM users WHERE email='t-renew-lapsed@local';
   INSERT INTO member_profiles (user_id, first_name, surname, category, membership_status)
     SELECT id, 'New', 'Applicant', 'Associate Membership', 'payment_required' FROM users WHERE email='t-renew-new@local';"
ADMIN=$(minted_session t-renew-admin@local)
OLD=$(minted_session t-renew-old@local); LAPSED=$(minted_session t-renew-lapsed@local); NEW=$(minted_session t-renew-new@local)
pid() { q "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email = '$1'"; }
P_OLD=$(pid t-renew-old@local); P_LAPSED=$(pid t-renew-lapsed@local); P_NEW=$(pid t-renew-new@local)
upload() { curl -s -m 20 -H "Expect:" -b "$1" -X POST "${API}payments/proof" -F amount=5000 -F "mpesaCode=$2" \
           -F type='Ordinary Membership' -F phone=0712345678 -F "proof=@$(cygpath -w "$PNG");type=image/png"; }
status() { q "SELECT membership_status FROM member_profiles WHERE id = $1"; }
due() { q "SELECT renewal_due FROM member_profiles WHERE id = $1"; }
approve() { curl -s -m 30 -b "$ADMIN" -X POST "${API}members/$1/approve" -H 'Content-Type: application/json' -d '{}'; }

echo "An existing member renews:"
upload "$OLD" TRENEWOLD1 >/dev/null
check "the slip is waiting"                                     "pending" "$(q "SELECT status FROM payments WHERE provider_reference = 'TRENEWOLD1'")"
check "the member keeps their access while it is checked"       "active" "$(status "$P_OLD")"
check "and still appears in the review queue"                   "Old" "$(curl -s -b "$ADMIN" "${API}members/review-queue")"
r=$(approve "$P_OLD")
check "approving confirms it as a renewal"                      '"renewal":true' "$r"
absent "no welcome email is attempted"                         '"welcomeEmailSent":false' "$r"
check "the slip is now paid"                                    "paid" "$(q "SELECT status FROM payments WHERE provider_reference = 'TRENEWOLD1'")"
check "the date runs a year on from the old date, not today"    "$DUE_NEXT" "$(due "$P_OLD")"
check "the membership number is unchanged"                      "ReSok M/T1" "$(q "SELECT membership_id FROM member_profiles WHERE id = $P_OLD")"
THIS_YEAR=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y');")
check "this year is now ticked as paid"                         "portal" "$(q "SELECT source FROM member_payment_years WHERE member_profile_id = $P_OLD AND year = $THIS_YEAR")"
check "the renewal is in the audit log"                         "membership_renewed" "$(q "SELECT action FROM admin_actions WHERE target_member_profile_id = $P_OLD ORDER BY id DESC LIMIT 1")"

echo "An existing member's slip is refused:"
upload "$OLD" TRENEWOLD2 >/dev/null
r=$(curl -s -m 20 -b "$ADMIN" -X POST "${API}members/$P_OLD/reject" -H 'Content-Type: application/json' -d '{"reason":"Code not found on statement"}')
check "a refused slip ticks nothing new"                        "1" "$(q "SELECT COUNT(*) FROM member_payment_years WHERE member_profile_id = $P_OLD")"
check "the slip is marked failed"                               "failed" "$(q "SELECT status FROM payments WHERE provider_reference = 'TRENEWOLD2'")"
check "but the membership is not ended"                         "active" "$(status "$P_OLD")"
check "and its date is untouched"                               "$DUE_NEXT" "$(due "$P_OLD")"

echo "A lapsed member comes back:"
upload "$LAPSED" TRENEWLAP1 >/dev/null
r=$(approve "$P_LAPSED")
check "approving is a renewal too"                              '"renewal":true' "$r"
check "they are active again"                                   "active" "$(status "$P_LAPSED")"
check "running a year from today (their old date had passed)"   "$YEAR_FROM_TODAY" "$(due "$P_LAPSED")"

echo "A new applicant is still welcomed:"
upload "$NEW" TRENEWNEW1 >/dev/null
check "a new applicant waits for review"                        "under_review" "$(status "$P_NEW")"
r=$(approve "$P_NEW")
absent "approving is not a renewal"                            '"renewal":true' "$r"
check "a welcome email is attempted"                            '"welcomeEmailSent"' "$r"
check "they are active"                                         "active" "$(status "$P_NEW")"
check "a new member's year is ticked too"                       "portal" "$(q "SELECT source FROM member_payment_years WHERE member_profile_id = $P_NEW AND year = $THIS_YEAR")"
check "and get a membership number"                             "ReSoK" "$(q "SELECT membership_id FROM member_profiles WHERE id = $P_NEW")"

cleanup
rm -f "$PNG" "$ADMIN" "$OLD" "$LAPSED" "$NEW"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
