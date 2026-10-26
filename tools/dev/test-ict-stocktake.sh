#!/usr/bin/env bash
#
# Exercises the asset stock-take (lib/ict-assets.php): start one, tick assets as their
# stickers are scanned, see the ticks in the register, finish, and get the list of what was
# not seen.
#
# What matters most: a tick is recorded once however often a sticker is scanned; the register
# shows which rows are ticked; retired, disposed and lost items do not count against the total;
# only one stock-take is open at a time; and finishing lists every unseen asset without
# changing any asset's status.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      did not want: %s\n      got: %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $d = $d[$k] ?? null; echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }

SUPER=$(minted_session dev@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }

get "$SUPER" ict/stocktake >/dev/null   # creates the tables on a fresh database
sql "DELETE FROM ict_stocktake_scans; DELETE FROM ict_stocktakes;
DELETE FROM users WHERE email = 'st-member@resok.local';
INSERT INTO users (email,password_hash,email_verified,role) VALUES ('st-member@resok.local','x',1,'member');"
MEMBER=$(minted_session st-member@resok.local)

# Three assets to work with, and one retired one that should not count.
A1=$(sql "SELECT id FROM ict_assets WHERE status NOT IN ('retired','disposed','lost') ORDER BY id LIMIT 1")
A2=$(sql "SELECT id FROM ict_assets WHERE status NOT IN ('retired','disposed','lost') ORDER BY id LIMIT 1 OFFSET 1")
T1=$(sql "SELECT asset_tag FROM ict_assets WHERE id=$A1")
RID=$(sql "SELECT id FROM ict_assets WHERE status NOT IN ('retired','disposed','lost') ORDER BY id DESC LIMIT 1")
RSTATUS=$(sql "SELECT status FROM ict_assets WHERE id=$RID")
sql "UPDATE ict_assets SET status='retired' WHERE id=$RID"
EXPECTED=$(sql "SELECT COUNT(*) FROM ict_assets WHERE status NOT IN ('retired','disposed','lost')")

echo "Who can use it"
check "anonymous is refused" "401" "$(curl -s -o /dev/null -w '%{http_code}' "${API}ict/stocktake")"
check "a member without ICT rights is refused" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$MEMBER" "${API}ict/stocktake")"
check "nor can they start one" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$MEMBER" -X POST -H 'Content-Type: application/json' -d '{}' "${API}ict/stocktake")"

echo "Starting"
check "ticking with nothing open is refused" "No stock-take is open" "$(post "$SUPER" ict/stocktake/verify "{\"assetId\":$A1}")"
R=$(post "$SUPER" ict/stocktake '{"name":"Test count"}')
check "a stock-take starts" '"open":true' "$R"
check "it counts the assets expected" "\"expected\":$EXPECTED" "$R"
check "retired assets do not count" "\"seen\":0" "$R"
check "a second one cannot be opened" "already open" "$(post "$SUPER" ict/stocktake '{"name":"Again"}')"
check "the register shows an unticked row" '"verifiedAt":null' "$(get "$SUPER" ict/assets)"

echo "Ticking"
R=$(post "$SUPER" ict/stocktake/verify "{\"assetId\":$A1}")
check "a scan ticks the asset" '"already":false' "$R"
check "and the count goes up" '"seen":1' "$R"
R=$(post "$SUPER" ict/stocktake/verify "{\"assetId\":$A1}")
check "scanning it again does not count twice" '"already":true' "$R"
check "still one seen" '"seen":1' "$R"
check "an unknown asset is refused" "not on the register" "$(post "$SUPER" ict/stocktake/verify '{"assetId":99999999}')"
post "$SUPER" ict/stocktake/verify "{\"assetId\":$A2}" >/dev/null
LIST=$(get "$SUPER" "ict/assets&search=$T1")
check "the register shows the tick" '"verifiedAt":"20' "$LIST"
R=$(post "$SUPER" ict/stocktake/verify "{\"assetId\":$RID}")
check "a retired item that turns up is ticked as extra" '"extra":1' "$R"
check "without counting towards the total" '"seen":2' "$R"

echo "Untick"
check "a wrong scan can be unticked" '"seen":1' "$(post "$SUPER" ict/stocktake/unverify "{\"assetId\":$A2}")"
post "$SUPER" ict/stocktake/verify "{\"assetId\":$A2}" >/dev/null

echo "Finishing"
BEFORE=$(sql "SELECT status FROM ict_assets WHERE id=$(sql "SELECT id FROM ict_assets WHERE status NOT IN ('retired','disposed','lost') ORDER BY id LIMIT 1 OFFSET 2")")
R=$(post "$SUPER" ict/stocktake/finish)
check "finishing gives the report" '"open":false' "$R"
check "with the total frozen" "\"expected\":$EXPECTED" "$R"
MISSING=$(echo "$R" | $PHP -r 'echo count(json_decode(stream_get_contents(STDIN), true)["report"]["missing"]);')
check "every unseen asset is listed" "$((EXPECTED - 2))" "$MISSING"
absent "a ticked asset is not listed as missing" "\"assetTag\":\"$T1\"" "$R"
check "nothing is marked lost automatically" "$BEFORE" "$(sql "SELECT status FROM ict_assets WHERE id=$(sql "SELECT id FROM ict_assets WHERE status NOT IN ('retired','disposed','lost') ORDER BY id LIMIT 1 OFFSET 2")")"
check "after finishing, scans no longer tick" "No stock-take is open" "$(post "$SUPER" ict/stocktake/verify "{\"assetId\":$A1}")"
absent "the register drops the Seen column" "verifiedAt" "$(get "$SUPER" ict/assets)"
ID=$(echo "$R" | json report.id)
check "the report can be opened again later" "Test count" "$(get "$SUPER" ict/stocktake/$ID)"
check "and appears in the history" "Test count" "$(get "$SUPER" ict/stocktake)"
check "it is in the ICT audit log" "stocktake_finished" "$(sql "SELECT action FROM ict_audit WHERE action LIKE 'stocktake%' ORDER BY id DESC LIMIT 1")"

# Put things back.
sql "UPDATE ict_assets SET status='$RSTATUS' WHERE id=$RID;
DELETE FROM ict_stocktake_scans; DELETE FROM ict_stocktakes; DELETE FROM users WHERE email = 'st-member@resok.local';"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
