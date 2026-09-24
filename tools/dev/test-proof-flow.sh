#!/usr/bin/env bash
#
# The payment-proof flow against the running local API.
#
# The claim: an uploaded proof is a claim, not money received. It stays pending (so the
# member gets no "PAID" receipt for an image they chose) until an admin approves the member,
# which confirms it; rejecting the member marks it failed. Tested by making the requests.
set -uo pipefail
cd "$(dirname "$0")/../.."
source tools/dev/lib-session.sh

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
pass=0; fail=0
check() {  # check <description> <expected-substring> <actual>
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.110s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

# A 1x1 PNG to upload as "proof".
PNG=$(mktemp --suffix=.png)
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82' > "$PNG"

make_member() {  # make_member <email> -> profile id
    $MYSQL -e "DELETE FROM users WHERE email='$1';
      INSERT INTO users (email, password_hash, email_verified, role) VALUES ('$1', 'x', 1, 'member');
      INSERT INTO member_profiles (user_id, first_name, surname, category, membership_status)
        SELECT id, 'Proof', 'Tester', 'Associate Membership', 'payment_required' FROM users WHERE email='$1';" >/dev/null
    $MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='$1';"
}
upload() {  # upload <jar> <mpesa code>
    curl -s -m 20 -H "Expect:" -b "$1" -X POST "${API}payments/proof" -F amount=2000 -F "mpesaCode=$2" \
         -F type='Associate Membership' -F phone=0712345678 -F "proof=@$(cygpath -w "$PNG");type=image/png"
}
pay_status() { $MYSQL -N -e "SELECT status FROM payments WHERE provider_reference='$1';"; }
member_status() { $MYSQL -N -e "SELECT membership_status FROM member_profiles WHERE id=$1;"; }

$MYSQL -e "DELETE FROM users WHERE email='t-proof-admin@local';
  INSERT INTO users (email, password_hash, email_verified, role) VALUES ('t-proof-admin@local','x',1,'admin');" >/dev/null
ADMIN=$(minted_session t-proof-admin@local)

echo "Upload, then approve:"
P1=$(make_member t-proof-a@local); M1=$(minted_session t-proof-a@local)
check "the proof is accepted"                          'under admin review' "$(upload "$M1" TPROOFAAA1)"
check "it is stored as pending, not paid"              'pending'            "$(pay_status TPROOFAAA1)"
check "the member is under review"                     'under_review'       "$(member_status "$P1")"
check "the member's own list shows it pending"         '"status":"pending"' "$(curl -s -m 10 -b "$M1" "${API}payments")"
check "the admin list shows it as awaiting"            '"pendingTotal":2000' "$(curl -s -m 10 -b "$ADMIN" "${API}members")"
check "the same M-Pesa code cannot be sent twice"      'already been submitted' "$(upload "$M1" TPROOFAAA1)"
curl -s -m 20 -b "$ADMIN" -X POST "${API}members/$P1/approve" -H 'Content-Type: application/json' -d '{}' >/dev/null
check "approving the member confirms the proof"        'paid'               "$(pay_status TPROOFAAA1)"
check "and makes the member active"                    'active'             "$(member_status "$P1")"

echo
echo "Upload, then reject:"
P2=$(make_member t-proof-b@local); M2=$(minted_session t-proof-b@local)
upload "$M2" TPROOFBBB2 >/dev/null
curl -s -m 20 -b "$ADMIN" -X POST "${API}members/$P2/reject" -H 'Content-Type: application/json' -d '{"reason":"Unreadable screenshot"}' >/dev/null
check "rejecting the member marks the proof failed"    'failed'             "$(pay_status TPROOFBBB2)"
check "and the member is rejected"                     'rejected'           "$(member_status "$P2")"
check "a fresh proof can be sent after rejection"      'under admin review' "$(upload "$M2" TPROOFBBB3)"

echo
echo "No proof, no approval:"
P3=$(make_member t-proof-c@local)
# Only meaningful where approving without payment is switched off (the production default);
# this laptop's config.local.php turns it on for development.
if grep -qE "allow_approve_without_payment'\s*=>\s*true" resok-portal/public/api/config.local.php 2>/dev/null; then
    echo "  (skipped: allow_approve_without_payment is on in config.local.php)"
else
    check "approving someone with nothing submitted fails" 'required before approval' "$(curl -s -m 20 -b "$ADMIN" -X POST "${API}members/$P3/approve" -H 'Content-Type: application/json' -d '{}')"
fi

$MYSQL -e "DELETE FROM users WHERE email IN ('t-proof-a@local','t-proof-b@local','t-proof-c@local','t-proof-admin@local');" >/dev/null
rm -f "$PNG" "$ADMIN" "$M1" "$M2"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
