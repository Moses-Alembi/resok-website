#!/usr/bin/env bash
#
# Timesheets, payroll, procurement and appraisals (lib/staff-timesheet.php, staff-payroll.php,
# staff-procurement.php, staff-appraisal.php).
#
# What matters most: nobody approves their own timesheet, purchase or payroll run; the person
# who prepared a payroll run cannot approve it, and nobody edits their own pay; pay is seen
# only by payroll and by the person it belongs to; the PAYE, NSSF, SHIF and Housing Levy sums
# match a hand-worked example; purchases need quotations by value; and an appraisal's ratings
# stay hidden from the other side until submitted.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-64s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-64s FAIL\n      wanted: %s\n      got:    %.260s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-64s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-64s FAIL\n      did not want: %s\n      got: %.260s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $d = $d[$k] ?? null; echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
get()  { curl -s -b "$1" --max-time 30 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 30 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 30 "${API}$2"; }

PW='HrTest2026x'
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW")
login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$PW\"}" >/dev/null; echo "$jar"; }

SUPER=$(minted_session dev@resok.local)
for r in staff/timesheets staff/procurement staff/appraisals staff/payroll; do get "$SUPER" "$r" >/dev/null; done   # creates the tables
cleanup() {
    local ids="SELECT id FROM users WHERE email LIKE 'hr-%@resok.local'"
    sql "DELETE l FROM staff_timesheet_lines l JOIN staff_timesheets t ON t.id = l.timesheet_id WHERE t.user_id IN ($ids);
         DELETE FROM staff_timesheets WHERE user_id IN ($ids);
         DELETE FROM staff_charge_codes WHERE code = 'TEST-HR';
         DELETE FROM staff_payroll_lines WHERE run_id IN (SELECT id FROM staff_payroll_runs WHERE period = '2099-01');
         DELETE FROM staff_pay_items WHERE user_id IN ($ids);
         DELETE FROM staff_payroll_runs WHERE period = '2099-01';
         DELETE FROM staff_payroll_settings WHERE note = 'TEST rates';
         DELETE FROM staff_pay_records WHERE user_id IN ($ids);
         DELETE i FROM staff_procurement_items i JOIN staff_procurement_requests r ON r.id = i.request_id WHERE r.requester_user_id IN ($ids);
         DELETE q FROM staff_procurement_quotes q JOIN staff_procurement_requests r ON r.id = q.request_id WHERE r.requester_user_id IN ($ids);
         DELETE g FROM staff_procurement_log g JOIN staff_procurement_requests r ON r.id = g.request_id WHERE r.requester_user_id IN ($ids);
         DELETE FROM staff_procurement_requests WHERE requester_user_id IN ($ids);
         DELETE FROM staff_suppliers WHERE name LIKE 'HRTEST %';
         DELETE o FROM staff_appraisal_objectives o JOIN staff_appraisals a ON a.id = o.appraisal_id JOIN staff_appraisal_cycles c ON c.id = a.cycle_id WHERE c.name = 'HRTEST cycle';
         DELETE p FROM staff_appraisal_competencies p JOIN staff_appraisals a ON a.id = p.appraisal_id JOIN staff_appraisal_cycles c ON c.id = a.cycle_id WHERE c.name = 'HRTEST cycle';
         DELETE n FROM staff_notifications n JOIN staff_appraisal_cycles c ON n.dedupe_key = CONCAT('ap-open:', c.id) WHERE c.name = 'HRTEST cycle';
         DELETE a FROM staff_appraisals a JOIN staff_appraisal_cycles c ON c.id = a.cycle_id WHERE c.name = 'HRTEST cycle';
         DELETE FROM staff_appraisal_cycles WHERE name = 'HRTEST cycle';
         DELETE FROM staff_notifications WHERE user_id IN ($ids);
         DELETE FROM staff_grants WHERE user_id IN ($ids);
         DELETE FROM staff_accounts WHERE user_id IN ($ids);
         DELETE FROM users WHERE email LIKE 'hr-%@resok.local';"
}
cleanup
# boss manages staff; other is outside that line; hr holds staff.manage; pay prepares payroll,
# appr approves it; fin is finance for procurement.
sql "DELETE FROM auth_attempts;
     INSERT INTO users (email,password_hash,email_verified,role) VALUES
       ('hr-boss@resok.local','$HASH',1,'member'),('hr-staff@resok.local','$HASH',1,'member'),('hr-other@resok.local','$HASH',1,'member'),
       ('hr-hr@resok.local','$HASH',1,'member'),('hr-pay@resok.local','$HASH',1,'member'),('hr-appr@resok.local','$HASH',1,'member'),('hr-fin@resok.local','$HASH',1,'member');
     INSERT INTO staff_accounts (user_id, full_name) SELECT id, CONCAT('HR ', SUBSTRING_INDEX(SUBSTRING_INDEX(email,'@',1),'-',-1)) FROM users WHERE email LIKE 'hr-%@resok.local';"
uid() { sql "SELECT id FROM users WHERE email='hr-$1@resok.local'"; }
BOSS=$(uid boss); STAFF=$(uid staff); OTHER=$(uid other); HR=$(uid hr); PAY=$(uid pay); APPR=$(uid appr); FIN=$(uid fin)
sql "UPDATE staff_accounts SET manager_user_id=$BOSS WHERE user_id=$STAFF;
     INSERT INTO staff_grants (user_id, capability) VALUES ($HR,'staff.manage'),($PAY,'payroll.manage'),($PAY,'payroll.approve'),($APPR,'payroll.approve'),($FIN,'procurement.approve');
     INSERT INTO staff_charge_codes (code, name, kind) VALUES ('TEST-HR', 'Test project', 'project');"
J_BOSS=$(login hr-boss@resok.local); J_STAFF=$(login hr-staff@resok.local); J_OTHER=$(login hr-other@resok.local)
J_HR=$(login hr-hr@resok.local); J_PAY=$(login hr-pay@resok.local); J_APPR=$(login hr-appr@resok.local); J_FIN=$(login hr-fin@resok.local)
CODE=$(sql "SELECT id FROM staff_charge_codes WHERE code='TEST-HR'")
WEEK=$($PHP -r "date_default_timezone_set('Africa/Nairobi'); echo date('Y-m-d', strtotime('monday this week'));")

echo "Timesheets"
check "hours must be quarter hours" "quarter hours" "$(post "$J_STAFF" staff/timesheets/save "{\"week\":\"$WEEK\",\"lines\":[{\"codeId\":$CODE,\"hours\":[0.3,0,0,0,0,0,0]}]}")"
check "no day over 24 hours" "more than 24" "$(post "$J_STAFF" staff/timesheets/save "{\"week\":\"$WEEK\",\"lines\":[{\"codeId\":$CODE,\"hours\":[20,0,0,0,0,0,0]},{\"codeId\":$CODE,\"hours\":[5,0,0,0,0,0,0]}]}")"
check "a week starts on a Monday" "starts on a Monday" "$(post "$J_STAFF" staff/timesheets/save '{"week":"2026-10-28","lines":[]}')"
R=$(post "$J_STAFF" staff/timesheets/save "{\"week\":\"$WEEK\",\"lines\":[{\"codeId\":$CODE,\"hours\":[8,7.5,8,8,6,0,0],\"note\":\"Clinic\"}],\"submit\":true}")
check "staff submit a week" '"status":"submitted"' "$R"
check "with the hours totalled" '"totalMinutes":2250' "$R"
TS=$(echo "$R" | json sheet.id)
check "the manager is told" "submitted a timesheet" "$(sql "SELECT title FROM staff_notifications WHERE user_id=$BOSS ORDER BY id DESC LIMIT 1")"
check "nobody approves their own" "own timesheet" "$(post "$J_STAFF" staff/timesheets/$TS/approve)"
check "a colleague outside the line cannot approve" "manager" "$(post "$J_OTHER" staff/timesheets/$TS/approve)"
check "nor see it" "404" "$(code "$J_OTHER" staff/timesheets/$TS)"
check "the manager's queue has it" "\"id\":$TS" "$(get "$J_BOSS" staff/timesheets/approvals)"
check "returning needs a reason" "what needs correcting" "$(post "$J_BOSS" staff/timesheets/$TS/return)"
check "the manager approves" '"status":"approved"' "$(post "$J_BOSS" staff/timesheets/$TS/approve)"
check "an approved week is locked" "approved and locked" "$(post "$J_STAFF" staff/timesheets/save "{\"week\":\"$WEEK\",\"lines\":[]}")"
check "only HR reopens it" "Only HR" "$(post "$J_BOSS" staff/timesheets/$TS/reopen '{"reason":"x"}')"
MONTH=${WEEK:0:7}
check "the manager's monthly report shows approved hours" '"code":"TEST-HR"' "$(get "$J_BOSS" "staff/timesheets/report&month=$MONTH")"
absent "and nobody outside the line" "HR other" "$(get "$J_BOSS" "staff/timesheets/report&month=$MONTH")"
check "HR reopens with a reason" '"ok":true' "$(post "$J_HR" staff/timesheets/$TS/reopen '{"reason":"Wrong project"}')"
check "and the owner can correct it again" '"status":"returned"' "$(post "$J_STAFF" staff/timesheets/save "{\"week\":\"$WEEK\",\"lines\":[{\"codeId\":$CODE,\"hours\":[8,8,8,8,8,0,0]}]}")"
check "plain staff cannot add project codes" "kept by HR and finance" "$(post "$J_STAFF" staff/timesheets/codes '{"code":"X1","name":"x"}')"

echo "Payroll: who sees pay"
check "ordinary staff cannot open payroll" "403" "$(code "$J_STAFF" staff/payroll)"
check "nor pay records" "403" "$(code "$J_STAFF" staff/payroll/records)"
check "HR (staff.manage) cannot either" "403" "$(code "$J_HR" staff/payroll)"
check "an approver cannot see pay records" "403" "$(code "$J_APPR" staff/payroll/records)"
check "the payroll officer can" '"people"' "$(get "$J_PAY" staff/payroll/records)"
check "nobody edits their own pay record" "own pay record" "$(post "$J_PAY" staff/payroll/records/$PAY '{"basic":999999}')"
check "a KRA PIN must look like one" "A123456789B" "$(post "$J_PAY" staff/payroll/records/$STAFF '{"basic":100000,"paymentMethod":"bank","bankName":"KCB","bankAccount":"1234567890","kraPin":"123"}')"
check "a pay record is saved" '"ok":true' "$(post "$J_PAY" staff/payroll/records/$STAFF '{"basic":100000,"paymentMethod":"bank","bankName":"KCB","bankBranch":"Moi Avenue","bankAccount":"1234567890","kraPin":"A123456789B"}')"
check "the account number is not stored in plain text" "1" "$(sql "SELECT bank_account <> '1234567890' FROM staff_pay_records WHERE user_id=$STAFF")"
check "the log says pay changed, not the amount" "pay record created" "$(sql "SELECT summary FROM staff_audit WHERE action='pay_record_saved' ORDER BY id DESC LIMIT 1")"
absent "and never the salary" "100000" "$(sql "SELECT summary FROM staff_audit WHERE action='pay_record_saved' ORDER BY id DESC LIMIT 1")"
post "$J_PAY" staff/payroll/records/$BOSS '{"basic":30000,"paymentMethod":"mpesa","mpesaPhone":"0712345678","items":[{"kind":"earning_taxable","label":"House allowance","amount":5000}]}' >/dev/null

echo "Payroll: the run"
R=$(post "$J_PAY" staff/payroll/runs '{"period":"2099-01"}')
RUN=$(echo "$R" | json run.id)
check "a run is prepared" '"status":"draft"' "$R"
check "one run per month" "already a draft run" "$(post "$J_PAY" staff/payroll/runs '{"period":"2099-01"}')"
L=$(get "$J_PAY" staff/payroll/runs/$RUN)
# Basic 100,000: NSSF 6% of 100,000 = 6,000; SHIF 2.75% = 2,750; Housing Levy 1.5% = 1,500;
# taxable 100,000 - 6,000 - 2,750 - 1,500 = 89,750; tax 2,400 + 2,083.25 + 30% x 57,417 = 21,708.35;
# less relief 2,400 = PAYE 19,308.35; net 100,000 - 6,000 - 2,750 - 1,500 - 19,308.35 = 70,441.65.
check "NSSF on 100,000 is 6,000" '"nssf":6000' "$L"
check "SHIF is 2,750" '"shif":2750' "$L"
check "PAYE is 19,308.35" '"paye":19308.35' "$L"
check "net pay is 70,441.65" '"net":70441.65' "$L"
check "allowances are taxed: gross 35,000 for the boss" '"gross":35000' "$L"
check "the run shows the account's last digits" '7890"' "$L"
absent "but never the full number" '1234567890' "$L"
check "the payment file waits for approval" "approved run" "$(get "$J_PAY" "staff/payroll/runs/$RUN/export&type=bank")"
check "unconfirmed rates block submission" "Confirm the statutory rates" "$(post "$J_PAY" staff/payroll/runs/$RUN/submit)"
check "rates need a note of where they were checked" "Note where" "$(post "$J_PAY" staff/payroll/settings '{"effectiveFrom":"2099-01","rates":{"personalRelief":2400,"shifRate":2.75,"shifMinimum":300,"housingLevyRate":1.5,"housingLevyEmployerRate":1.5,"nssfRate":6,"nssfLowerLimit":9000,"nssfUpperLimit":108000,"pensionCap":30000,"shifAndLevyDeductible":true,"payeBands":[[24000,10],[8333,25],[467667,30],[300000,32.5],[null,35]]}}')"
post "$J_PAY" staff/payroll/settings '{"effectiveFrom":"2099-01","note":"TEST rates","rates":{"personalRelief":2400,"shifRate":2.75,"shifMinimum":300,"housingLevyRate":1.5,"housingLevyEmployerRate":1.5,"nssfRate":6,"nssfLowerLimit":9000,"nssfUpperLimit":108000,"pensionCap":30000,"shifAndLevyDeductible":true,"payeBands":[[24000,10],[8333,25],[467667,30],[300000,32.5],[null,35]]}}' >/dev/null
check "confirmed rates apply to the run's month" '"ratesConfirmed":true' "$(get "$J_PAY" staff/payroll/runs/$RUN)"
check "nobody adds pay items for themselves" "for themselves" "$(post "$J_PAY" staff/payroll/runs/$RUN/items "{\"userId\":$PAY,\"kind\":\"earning_taxable\",\"label\":\"Bonus\",\"amount\":1}")"
check "a one-off deduction is added" '"ok":true' "$(post "$J_PAY" staff/payroll/runs/$RUN/items "{\"userId\":$STAFF,\"kind\":\"deduction\",\"label\":\"Salary advance\",\"amount\":10000}")"
check "and comes off net pay" '"net":60441.65' "$(get "$J_PAY" staff/payroll/runs/$RUN)"
check "the run is submitted" '"status":"submitted"' "$(post "$J_PAY" staff/payroll/runs/$RUN/submit)"
check "staff cannot see a payslip before approval" "404" "$(code "$J_STAFF" staff/payroll/mine/$RUN)"
check "the approver is told" "ready for approval" "$(sql "SELECT title FROM staff_notifications WHERE user_id=$APPR ORDER BY id DESC LIMIT 1")"
check "the preparer cannot approve" "someone else must approve" "$(post "$J_PAY" staff/payroll/runs/$RUN/approve)"
check "an approver approves" '"status":"approved"' "$(post "$J_APPR" staff/payroll/runs/$RUN/approve)"
check "approved runs cannot be changed" "Only a draft" "$(post "$J_PAY" staff/payroll/runs/$RUN/recalculate)"
check "staff see their own payslip" '"net":60441.65' "$(get "$J_STAFF" staff/payroll/mine/$RUN)"
absent "the payslip masks the account" '1234567890' "$(get "$J_STAFF" staff/payroll/mine/$RUN)"
check "a colleague cannot see it" "404" "$(code "$J_OTHER" staff/payroll/mine/$RUN)"
check "staff are told their payslip is ready" "payslip for January 2099" "$(sql "SELECT title FROM staff_notifications WHERE user_id=$STAFF ORDER BY id DESC LIMIT 1")"
check "the bank file has the full account" "1234567890" "$(get "$J_PAY" "staff/payroll/runs/$RUN/export&type=bank")"
check "and the M-Pesa number" "0712345678" "$(get "$J_PAY" "staff/payroll/runs/$RUN/export&type=bank")"
check "the statutory summary totals PAYE" "PAYE (KRA)" "$(get "$J_APPR" "staff/payroll/runs/$RUN/export&type=statutory")"
check "only a super admin voids" "super administrator" "$(post "$J_APPR" staff/payroll/runs/$RUN/void '{"reason":"x"}')"

echo "Procurement"
R=$(post "$J_STAFF" staff/procurement/new/save "{\"title\":\"Peak flow meters\",\"chargeCodeId\":$CODE,\"justification\":\"Kitui clinic\",\"items\":[{\"description\":\"Peak flow meter\",\"quantity\":20,\"unit\":\"pcs\",\"unitCost\":3000}],\"submit\":true}")
PR=$(echo "$R" | json request.id)
check "a request is submitted to the line manager" '"status":"submitted"' "$R"
check "and numbered" '"ref":"PR-' "$R"
check "with its total" '"total":60000' "$R"
check "the requester cannot approve it" "own request" "$(post "$J_STAFF" staff/procurement/$PR/approve)"
check "finance waits for the line manager" "line manager" "$(post "$J_FIN" staff/procurement/$PR/approve)"
check "a colleague cannot see it" "404" "$(code "$J_OTHER" staff/procurement/$PR)"
check "the manager approves" '"status":"manager_approved"' "$(post "$J_BOSS" staff/procurement/$PR/approve)"
check "then finance" '"status":"approved"' "$(post "$J_FIN" staff/procurement/$PR/approve)"
check "60,000 needs three quotations" "needs 3 quotations" "$(post "$J_FIN" staff/procurement/$PR/order)"
sql "INSERT INTO staff_suppliers (name, active) VALUES ('HRTEST Medisel',1),('HRTEST Surgipharm',1),('HRTEST Pharmaken',1)"
for s in "Medisel:58000" "Surgipharm:61000" "Pharmaken:59500"; do
    SID=$(sql "SELECT id FROM staff_suppliers WHERE name='HRTEST ${s%%:*}'")
    post "$J_FIN" staff/procurement/$PR/quote "{\"supplierId\":$SID,\"amount\":${s##*:}}" >/dev/null
done
Q_HIGH=$(sql "SELECT q.id FROM staff_procurement_quotes q JOIN staff_suppliers s ON s.id=q.supplier_id WHERE q.request_id=$PR AND s.name='HRTEST Surgipharm'")
Q_LOW=$(sql "SELECT q.id FROM staff_procurement_quotes q JOIN staff_suppliers s ON s.id=q.supplier_id WHERE q.request_id=$PR AND s.name='HRTEST Medisel'")
check "staff cannot record quotations" "finance" "$(post "$J_STAFF" staff/procurement/$PR/quote '{"supplierId":1,"amount":1}')"
check "choosing a dearer quote needs a reason" "not the lowest" "$(post "$J_FIN" staff/procurement/$PR/order "{\"quoteId\":$Q_HIGH}")"
R=$(post "$J_FIN" staff/procurement/$PR/order "{\"quoteId\":$Q_LOW}")
check "the order is issued" '"status":"ordered"' "$R"
check "with an LPO number" '"lpoNumber":"LPO-' "$R"
check "whoever issued the order cannot confirm delivery" "does not confirm delivery" "$(post "$J_FIN" staff/procurement/$PR/receive '{"note":"ok"}')"
check "delivery needs a note" "Note what arrived" "$(post "$J_STAFF" staff/procurement/$PR/receive)"
check "the requester confirms delivery" '"status":"received"' "$(post "$J_STAFF" staff/procurement/$PR/receive '{"note":"All 20 delivered"}')"
check "finance closes it with the payment reference" '"status":"closed"' "$(post "$J_FIN" staff/procurement/$PR/close '{"paymentRef":"EFT 4471"}')"
check "the timeline has every step" '"action":"closed"' "$(get "$J_STAFF" staff/procurement/$PR)"
R=$(post "$J_OTHER" staff/procurement/new/save "{\"title\":\"Projector\",\"chargeCodeId\":$CODE,\"justification\":\"Training\",\"items\":[{\"description\":\"Projector\",\"quantity\":1,\"unitCost\":45000}],\"submit\":true}")
check "with no line manager it goes straight to finance" '"status":"manager_approved"' "$R"

echo "Appraisals"
CY=$(post "$J_HR" staff/appraisals/cycles '{"name":"HRTEST cycle","periodStart":"2026-01-01","periodEnd":"2026-12-31"}' | json id)
check "plain staff cannot run cycles" "run by HR" "$(post "$J_STAFF" staff/appraisals/cycles/$CY/open)"
check "HR opens the cycle" '"ok":true' "$(post "$J_HR" staff/appraisals/cycles/$CY/open)"
AP=$(sql "SELECT id FROM staff_appraisals WHERE cycle_id=$CY AND user_id=$STAFF")
check "staff get an appraisal with their manager as appraiser" "$BOSS" "$(sql "SELECT appraiser_user_id FROM staff_appraisals WHERE id=$AP")"
check "a colleague cannot see it" "404" "$(code "$J_OTHER" staff/appraisals/$AP)"
check "weights must add up to 100" "add up to 100" "$(post "$J_STAFF" staff/appraisals/$AP/objectives '{"submit":true,"objectives":[{"objective":"Train 200 health workers","measure":"Registers","weight":60},{"objective":"Run 4 clinics","measure":"Reports","weight":30}]}')"
check "objectives go to the appraiser" '"stage":"objectives_submitted"' "$(post "$J_STAFF" staff/appraisals/$AP/objectives '{"submit":true,"objectives":[{"objective":"Train 200 health workers","measure":"Registers","weight":60},{"objective":"Run 4 clinics","measure":"Reports","weight":40}]}')"
check "the appraisee cannot agree their own" "Only the appraiser" "$(post "$J_STAFF" staff/appraisals/$AP/agree)"
check "the appraiser agrees" '"stage":"self_assessment"' "$(post "$J_BOSS" staff/appraisals/$AP/agree)"
SELF='{"objectives":[{"rating":5,"comment":"Trained 230"},{"rating":4,"comment":"4 clinics"}],"competencies":{"quality":4,"communication":4,"teamwork":5,"initiative":4,"reliability":4,"integrity":5},"comment":"Good year"}'
post "$J_STAFF" staff/appraisals/$AP/self "$SELF" >/dev/null
check "a draft self-assessment is hidden from the appraiser" '"selfRating":null' "$(get "$J_BOSS" staff/appraisals/$AP)"
check "self-assessment is submitted" '"stage":"manager_review"' "$(post "$J_STAFF" staff/appraisals/$AP/self "${SELF%\}},\"submit\":true}")"
check "now the appraiser sees it" '"selfRating":5' "$(get "$J_BOSS" staff/appraisals/$AP)"
REVIEW='{"objectives":[{"rating":4},{"rating":3}],"competencies":{"quality":4,"communication":3,"teamwork":4,"initiative":4,"reliability":3,"integrity":5},"comment":"Solid","trainingNeeds":"Spirometry refresher"}'
post "$J_BOSS" staff/appraisals/$AP/review "$REVIEW" >/dev/null
check "a draft review is hidden from the appraisee" '"managerRating":null' "$(get "$J_STAFF" staff/appraisals/$AP)"
# objectives (4x60 + 3x40)/100 = 3.6; competencies 23/6 = 3.8333; 0.7 x 3.6 + 0.3 x 3.8333 = 3.67
check "the review is submitted with a score" '"score":3.67' "$(post "$J_BOSS" staff/appraisals/$AP/review "${REVIEW%\}},\"submit\":true}")"
check "the appraisee now sees it" '"rating":"Exceeds expectations"' "$(get "$J_STAFF" staff/appraisals/$AP)"
check "disagreeing needs a comment" "what you disagree" "$(post "$J_STAFF" staff/appraisals/$AP/acknowledge '{"agrees":false}')"
check "acknowledging completes it" '"stage":"completed"' "$(post "$J_STAFF" staff/appraisals/$AP/acknowledge '{"agrees":true}')"
check "HR sees the cycle with the result" '"rating":"Exceeds expectations"' "$(get "$J_HR" staff/appraisals/cycles/$CY)"
check "and its training needs in the CSV" "Spirometry refresher" "$(get "$J_HR" "staff/appraisals/cycles/$CY&csv=1")"

echo "Home"
post "$J_STAFF" staff/procurement/new/save "{\"title\":\"Toner\",\"chargeCodeId\":$CODE,\"justification\":\"Printer\",\"items\":[{\"description\":\"Toner\",\"quantity\":1,\"unitCost\":9000}],\"submit\":true}" >/dev/null
check "the manager's home shows a purchase to decide" '"procurement":1' "$(get "$J_BOSS" staff/home)"

cleanup
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
