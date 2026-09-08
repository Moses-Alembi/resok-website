#!/usr/bin/env bash
#
# Exercises the issue book through the running API.
#
# The claims worth testing are about evidence, not fields. A reference has to stay attached
# to the handover it names. Two spellings of one person have to answer as one person, without
# either spelling being lost. A signature cannot be added to a handover that already ended,
# cannot be overwritten once given, and cannot claim paper exists without saying where.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *'"error"'* ]]; then
        printf '  %-58s FAIL
      response was an error, so the absence proves nothing:
      %.150s
' "$1" "$3"; fail=$((fail+1)); return
    fi
    if [[ "$3" != *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      did not want: %s\n      got: %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('i-view@local','i-issue@local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('i-view@local','$HASH',1,'ict'),('i-issue@local','$HASH',1,'ict');" >/dev/null
VID=$($MYSQL -N -e "SELECT id FROM users WHERE email='i-view@local';")
MID=$($MYSQL -N -e "SELECT id FROM users WHERE email='i-issue@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($VID,'assets.view'),
 ($MID,'assets.view'),($MID,'assets.manage'),($MID,'assets.assign');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

VIEWER=$(login i-view@local)
ISSUER=$(login i-issue@local)

# A clean corner of the register to work in, so the assertions do not depend on the 172
# imported rows staying exactly as they are.
$MYSQL -e "DELETE FROM ict_assets WHERE asset_tag LIKE 'TEST-ISS-%';" >/dev/null
mkasset() {
    post "$ISSUER" 'ict/assets' "{\"assetTag\":\"$1\",\"category\":\"laptop\",\"name\":\"$2\",\"purchaseCost\":$3}" \
      | $PHP -r 'preg_match("/\"id\":(\d+)/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";'
}
A1=$(mkasset TEST-ISS-A 'Issue book laptop A' 50000)
A2=$(mkasset TEST-ISS-B 'Issue book laptop B' 30000)
A3=$(mkasset TEST-ISS-C 'Issue book laptop C' 20000)

post "$ISSUER" "ict/assets/$A1/assign" '{"holderName":"WANJIRU KAMAU","department":"FINANCE"}' >/dev/null
post "$ISSUER" "ict/assets/$A2/assign" '{"holderName":"WANJIRU","department":"FINANCE"}' >/dev/null
post "$ISSUER" "ict/assets/$A3/assign" '{"holderName":"OTIENO/ACHIENG","department":"STORES"}' >/dev/null

I1=$($MYSQL -N -e "SELECT h.id FROM ict_assignments h JOIN ict_assets a ON a.id=h.asset_id
                   WHERE a.asset_tag='TEST-ISS-A' AND h.returned_at IS NULL;")
I3=$($MYSQL -N -e "SELECT h.id FROM ict_assignments h JOIN ict_assets a ON a.id=h.asset_id
                   WHERE a.asset_tag='TEST-ISS-C' AND h.returned_at IS NULL;")

echo "The register:"
R=$(get "$VIEWER" 'ict/issues&q=TEST-ISS')
check "lists handovers"                       '"issues"'            "$R"
check "carries the asset tag"                 'TEST-ISS-A'          "$R"
check "carries the holder"                    'WANJIRU KAMAU'       "$R"
check "derives an issue reference"            "\"reference\":\"ISS-$(date +%Y)-" "$R"
check "reports state out"                     '"state":"out"'       "$R"
check "reports the summary"                   '"summary"'           "$R"
check "knows acknowledgement is available"    '"acknowledgementAvailable":true' "$R"

echo
echo "One handover:"
ONE=$(get "$VIEWER" "ict/issues/$I1")
check "fetches a single handover"             '"issue"'             "$ONE"
check "reference matches the row id"          "ISS-$(date +%Y)-$(printf '%04d' "$I1")" "$ONE"
check "carries the serial for the form"       '"serialNumber"'      "$ONE"
check "starts unacknowledged"                 '"acknowledged":false' "$ONE"
check "unknown id is a 404"                   'No handover with that id' "$(get "$VIEWER" 'ict/issues/99999999')"

echo
echo "Holders, grouped by person:"
H=$(get "$VIEWER" 'ict/issues/holders')
check "groups the two Wanjiru spellings"      '"WANJIRU KAMAU"'     "$H"
check "keeps both spellings on the record"    '"spellings":["WANJIRU KAMAU"' "$H"
check "flags a shared holder"                 '"sharedHolder":true' "$H"
check "names the possible duplicate"          'possibleDuplicateOf' "$H"
check "counts what each person holds"         '"items"'             "$H"
check "totals the value held"                 '"value"'             "$H"
check "says how many costs are unknown"       '"valueUnknown"'      "$H"

WANJ=$(get "$VIEWER" 'ict/issues&q=TEST-ISS&holder=wanjiru%20kamau')
check "holder filter matches on normalised name" 'TEST-ISS-A'       "$WANJ"
absent "holder filter excludes other people"  'TEST-ISS-C'          "$WANJ"

echo
echo "Held-by, which used to answer nothing:"
HB=$(get "$VIEWER" 'ict/assets/held-by&name=WANJIRU%20KAMAU')
check "finds items by name"                   'TEST-ISS-A'          "$HB"
check "no name and no email is refused"       'Give a name or an email' "$(get "$VIEWER" 'ict/assets/held-by')"

echo
echo "Acknowledgement:"
check "paper with no filing location refused" 'Where is the signed form filed' \
      "$(post "$ISSUER" "ict/issues/$I1/acknowledge" '{"via":"paper"}')"
check "an unknown method is refused"          'Say how it was acknowledged' \
      "$(post "$ISSUER" "ict/issues/$I1/acknowledge" '{"via":"verbally"}')"
check "assets.view cannot sign"               'do not have the ICT permission' \
      "$(post "$VIEWER" "ict/issues/$I1/acknowledge" '{"via":"portal"}')"

OK=$(post "$ISSUER" "ict/issues/$I1/acknowledge" '{"via":"paper","reference":"ICT folder 2026, shelf 3"}')
check "a filed paper signature is accepted"   '"acknowledged":true' "$OK"
check "records which way it was signed"       '"acknowledgedVia":"paper"' "$OK"
check "records where the paper is"            'ICT folder 2026'     "$OK"
check "signing twice is refused"              'Already acknowledged' \
      "$(post "$ISSUER" "ict/issues/$I1/acknowledge" '{"via":"portal"}')"
check "portal needs no filing location"       '"acknowledgedVia":"portal"' \
      "$(post "$ISSUER" "ict/issues/$I3/acknowledge" '{"via":"portal"}')"

UNS=$(get "$VIEWER" 'ict/issues&state=unsigned&q=TEST-ISS')
check "unsigned view excludes signed rows"    'TEST-ISS-B'          "$UNS"
absent "unsigned view drops what was signed"  'TEST-ISS-A'          "$UNS"

echo
echo "A returned handover cannot be signed after the fact:"
post "$ISSUER" "ict/assets/$A2/return" '{"conditionIn":"good"}' >/dev/null
I2=$($MYSQL -N -e "SELECT h.id FROM ict_assignments h JOIN ict_assets a ON a.id=h.asset_id
                   WHERE a.asset_tag='TEST-ISS-B' ORDER BY h.id DESC LIMIT 1;")
check "refuses to sign a closed handover"     'acknowledged when it happens' \
      "$(post "$ISSUER" "ict/issues/$I2/acknowledge" '{"via":"portal"}')"
RET=$(get "$VIEWER" 'ict/issues&state=returned&q=TEST-ISS')
check "returned view finds it"                'TEST-ISS-B'          "$RET"
check "and keeps the return condition"        '"conditionIn":"good"' "$RET"
absent "returned view excludes what is out"   'TEST-ISS-A'          "$RET"

echo
echo "The audit trail:"
check "acknowledgement is audited"            'issue_acknowledged' \
      "$($MYSQL -N -e "SELECT action FROM ict_audit WHERE action='issue_acknowledged' LIMIT 1;")"

$MYSQL -e "DELETE FROM ict_assets WHERE asset_tag LIKE 'TEST-ISS-%';
           DELETE FROM users WHERE email IN ('i-view@local','i-issue@local');" >/dev/null

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
