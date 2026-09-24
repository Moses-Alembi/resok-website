#!/usr/bin/env bash
#
# Exercises the STK push safety rules against the running local API. STK is switched off
# locally (and M-Pesa credentials are absent), which is exactly the state to test: nothing
# may be charged, and a forged "paid" callback must not mark anything paid.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
pass=0; fail=0
check() {  # check <description> <expected-substring> <actual>
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.110s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
status_of() { $MYSQL -N -e "SELECT status FROM payments WHERE reference='$1';"; }

USER_ID=$($MYSQL -N -e "SELECT id FROM users ORDER BY id LIMIT 1;")
seed() {  # seed <reference> <checkout-id>
    $MYSQL -e "DELETE FROM payments WHERE reference='$1';
      INSERT INTO payments (user_id, amount, currency, method, payment_type, status, reference, provider_reference)
      VALUES ($USER_ID, 5000, 'KES', 'M-Pesa STK Push', 'Ordinary Membership', 'pending', '$1', '$2');" >/dev/null
}
callback() {  # callback <route> <checkout-id> <result-code>
    curl -s -m 20 -X POST "${API}$1" -H 'Content-Type: application/json' -d "{\"Body\":{\"stkCallback\":{
      \"MerchantRequestID\":\"x\",\"CheckoutRequestID\":\"$2\",\"ResultCode\":$3,\"ResultDesc\":\"forged\",
      \"CallbackMetadata\":{\"Item\":[{\"Name\":\"Amount\",\"Value\":5000},{\"Name\":\"MpesaReceiptNumber\",\"Value\":\"FAKE123456\"}]}}}}"
}

echo "STK stays switched off:"
check "payment page is told STK is off"           '"stkEnabled":false' "$(curl -s -m 10 "${API}payment-instructions")"
check "a direct STK request is refused"           'not available yet'  "$(curl -s -m 10 -X POST "${API}payments/stk-push" -H 'Content-Type: application/json' -d '{"amount":1,"phone":"0712345678","type":"Ordinary Membership"}')"

echo
echo "A forged 'paid' callback (the public URL anyone can POST to):"
seed T-STK-FORGE-1 ws_CO_test_forge_1
callback 'payments/stk/callback' ws_CO_test_forge_1 0 >/dev/null
check "new path: payment is NOT marked paid"      'pending' "$(status_of T-STK-FORGE-1)"
seed T-STK-FORGE-2 ws_CO_test_forge_2
callback 'payments/mpesa/callback' ws_CO_test_forge_2 0 >/dev/null
check "old path: payment is NOT marked paid"      'pending' "$(status_of T-STK-FORGE-2)"
check "fake receipt was not stored"               '0' "$($MYSQL -N -e "SELECT COUNT(*) FROM payments WHERE provider_reference='FAKE123456';")"

echo
echo "Housekeeping paths still behave:"
seed T-STK-CANCEL ws_CO_test_cancel
callback 'payments/stk/callback' ws_CO_test_cancel 1032 >/dev/null
check "a cancelled prompt is marked failed"       'failed' "$(status_of T-STK-CANCEL)"
check "garbage body is ignored, not an error"     'Ignored' "$(curl -s -m 10 -X POST "${API}payments/stk/callback" -H 'Content-Type: application/json' -d '{"hello":1}')"
check "unknown checkout id is ignored"            'Already processed' "$(callback 'payments/stk/callback' ws_CO_nobody 0)"

$MYSQL -e "DELETE FROM payments WHERE reference IN ('T-STK-FORGE-1','T-STK-FORGE-2','T-STK-CANCEL');" >/dev/null
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
