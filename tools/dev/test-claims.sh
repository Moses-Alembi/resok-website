#!/usr/bin/env bash
#
# Exercises claim emails for members imported from the register.
#
# The claims worth testing are about not losing a real member's record. An imported member
# has to be able to get in without registering again - a second registration would sit beside
# the real record with no membership number and ask a paid-up member to pay. The renewal date
# has to reach 30 November 2026 without ever pulling a later date back. And a batch has to
# pick up only the accounts nobody can sign in to, never a member who already has.
#
# Uses its own claim-*@resok.local accounts and removes them afterwards. No mail server is
# configured locally, so every send fails here by design; that exercises the failure record.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
MIGRATION="resok-portal/server/migration-imported-members-renewal.sql"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
q() { $MYSQL -N -e "$1"; }

cleanup() {
    $MYSQL -e "DELETE c FROM member_claim_emails c JOIN users u ON u.id = c.user_id WHERE u.email LIKE 'claim-%@resok.local';" 2>/dev/null
    $MYSQL -e "DELETE FROM users WHERE email LIKE 'claim-%@resok.local';" >/dev/null
}
cleanup

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
# An unguessable hash nobody knows the password to, as the import left them.
LOCKED=$($PHP -r "echo password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);")

$MYSQL -e "
INSERT INTO users (email, password_hash, email_verified, role) VALUES
 ('claim-admin@resok.local',   '$HASH',   1, 'admin'),
 ('claim-new@resok.local',     '$LOCKED', 0, 'member'),
 ('claim-later@resok.local',   '$LOCKED', 0, 'member'),
 ('claim-rejected@resok.local','$LOCKED', 0, 'member'),
 ('claim-done@resok.local',    '$HASH',   1, 'member');" >/dev/null

profile() {  # email, renewal_due (SQL literal), status, membership number
    $MYSQL -e "INSERT INTO member_profiles (user_id, first_name, surname, mobile, membership_status, membership_id, renewal_due)
               SELECT id, 'Claim', 'Tester', '+254700000000', '$3', '$4', $2 FROM users WHERE email = '$1';
               INSERT INTO member_payment_years (member_profile_id, year, source)
               SELECT mp.id, 2025, 'register' FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email = '$1';" >/dev/null
}
profile claim-new@resok.local      NULL          expired  'ReSok M/9001'
profile claim-later@resok.local    "'2027-03-31'" active   'ReSok M/9002'
profile claim-rejected@resok.local NULL          rejected 'ReSok M/9003'
profile claim-done@resok.local     NULL          expired  'ReSok M/9004'

echo "Renewal date for imported members:"
$MYSQL < "$MIGRATION"
row() { q "SELECT CONCAT(mp.membership_status, '|', COALESCE(mp.renewal_due, 'NULL')) FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email = '$1';"; }
check "an undated imported member becomes active to 30 Nov"  'active|2026-11-30' "$(row claim-new@resok.local)"
check "a later renewal date is never pulled back"            'active|2027-03-31' "$(row claim-later@resok.local)"
check "a rejected record is left alone"                      'rejected|NULL'     "$(row claim-rejected@resok.local)"
$MYSQL < "$MIGRATION"
check "running it twice changes nothing"                     'active|2026-11-30' "$(row claim-new@resok.local)"

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -X POST "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

ADMIN=$(login claim-admin@resok.local TestPass123)
MEMBER=$(login claim-done@resok.local TestPass123)

echo "Who may use it:"
check "a member cannot read the claim summary"   'Admin access required' "$(get "$MEMBER" 'members/claims')"
check "a member cannot send a batch"             'Admin access required' "$(post "$MEMBER" 'members/claims/send' '{}')"

echo "Who counts as unclaimed:"
# Other unverified accounts may exist in this database; count only ours so the test is stable.
ours() { q "SELECT COUNT(*) FROM users u JOIN member_profiles mp ON mp.user_id = u.id WHERE u.role='member' AND u.email_verified = 0 AND u.email LIKE 'claim-%@resok.local';"; }
check "the three locked test accounts are unclaimed"          '3' "$(ours)"
check "the summary answers"                                    '"unclaimed":' "$(get "$ADMIN" 'members/claims')"
check "a member who has signed in is not offered a claim link" 'already claimed' \
  "$(post "$ADMIN" "members/$(q "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email='claim-done@resok.local';")/claim-link" '{}')"

echo "Sending (no mail server here, so every send fails and is recorded):"
NEW_PID=$(q "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email='claim-new@resok.local';")
ONE=$(post "$ADMIN" "members/$NEW_PID/claim-link" '{}')
check "a single send reports the failure rather than hiding it" '"sent":false' "$ONE"
check "the failure is recorded against the member"              '1' \
  "$(q "SELECT COUNT(*) FROM member_claim_emails c JOIN users u ON u.id = c.user_id WHERE u.email='claim-new@resok.local' AND c.send_error IS NOT NULL;")"
check "a token was issued for 30 days"                          '30' \
  "$(q "SELECT DATEDIFF(reset_expires, NOW()) FROM users WHERE email='claim-new@resok.local';")"

BATCH=$(post "$ADMIN" 'members/claims/send' '{"limit":25}')
check "a batch runs and reports"                                '"summary":' "$BATCH"
check "a batch does not retry an address that already failed"   'not retried' \
  "$([[ "$BATCH" == *'claim-new@resok.local'* ]] && echo 'RETRIED' || echo 'not retried')"
check "a batch picks up the never-emailed account"              'claim-later@resok.local' "$BATCH"
check "the batch logged its run"                                '1' \
  "$(q "SELECT COUNT(*) > 0 FROM admin_actions WHERE action = 'claim_emails_sent';")"

echo "Claiming with the emailed token:"
TOKEN=$(q "SELECT reset_token FROM users WHERE email='claim-new@resok.local';")
check "the claim link sets a password"       'Password updated' \
  "$(curl -s -X POST "${API}auth/reset-password" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\",\"password\":\"Claimed123\"}")"
check "claiming verifies the address"        '1' "$(q "SELECT email_verified FROM users WHERE email='claim-new@resok.local';")"
CLAIMED=$(login claim-new@resok.local Claimed123)
ME=$(get "$CLAIMED" 'members/me')
check "the member can sign in"               '"email":"claim-new@resok.local"' "$ME"
check "they keep their membership number"    '"membershipId":"ReSok M' "$ME"
check "  - the same number, not a new one"   'M/9001' "$(echo "$ME" | sed 's#\\/#/#g')"
check "they are active, not asked to pay"    '"membershipStatus":"active"' "$ME"
check "their paid years are still attached"  '1' \
  "$(q "SELECT COUNT(*) FROM member_payment_years y JOIN member_profiles mp ON mp.id = y.member_profile_id JOIN users u ON u.id = mp.user_id WHERE u.email='claim-new@resok.local';")"
check "no second record was created"         '1' "$(q "SELECT COUNT(*) FROM users WHERE email='claim-new@resok.local';")"
check "the token cannot be used twice"       'Invalid or expired' \
  "$(curl -s -X POST "${API}auth/reset-password" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\",\"password\":\"Another123\"}")"
check "they no longer count as unclaimed"    '2' "$(ours)"

cleanup
rm -f "$ADMIN" "$MEMBER" "$CLAIMED"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
