#!/usr/bin/env bash
#
# Exercises the helpdesk through the running API.
#
# The claims worth testing are about who sees what. Anyone signed in can raise a ticket and
# read their own; nobody else can read it; internal notes between ICT staff are not shown to
# the requester; and the requester can chase their ticket without being able to change its
# priority.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL\n      wanted: %s\n      got:    %.120s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-58s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-58s FAIL (should not contain "%s")\n' "$1" "$2"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('t-staff@local','t-user@local','t-other@local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('t-staff@local','$HASH',1,'ict'),
 ('t-user@local','$HASH',1,'member'),
 ('t-other@local','$HASH',1,'member');" >/dev/null
SID=$($MYSQL -N -e "SELECT id FROM users WHERE email='t-staff@local';")
$MYSQL -e "INSERT IGNORE INTO ict_capabilities (user_id,capability) VALUES
 ($SID,'tickets.view'),($SID,'tickets.manage');" >/dev/null

login() { local jar; jar=$(mktemp); curl -s -c "$jar" -X POST "${API}auth/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$jar"; }
get()  { curl -s -b "$1" --max-time 10 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }
patch(){ curl -s -b "$1" -X PATCH --max-time 10 "${API}$2" -H 'Content-Type: application/json' -d "$3"; }

STAFF=$(login t-staff@local)
USER=$(login t-user@local)
OTHER=$(login t-other@local)

echo "Anyone signed in can raise one:"
check "an ordinary member can raise a ticket" '"reference"' \
      "$(post "$USER" 'ict/tickets' '{"subject":"TestSuite laptop will not charge","category":"hardware","priority":"high","description":"It stopped overnight."}')"
check "a subject is the only requirement"     '"reference"' \
      "$(post "$USER" 'ict/tickets' '{"subject":"TestSuite second ticket"}')"
check "no subject is refused"                 'in a few words' "$(post "$USER" 'ict/tickets' '{}')"
check "defaults are applied"                  '"category":"other"' "$(get "$USER" 'ict/tickets&search=TestSuite')"

TID=$($MYSQL -N -e "SELECT id FROM ict_tickets WHERE subject='TestSuite laptop will not charge' LIMIT 1;")

echo
echo "Who can read it:"
check "the requester can"            'will not charge' "$(get "$USER" "ict/tickets/$TID")"
check "ICT staff can"                'will not charge' "$(get "$STAFF" "ict/tickets/$TID")"
check "an unrelated member CANNOT"   'not yours'       "$(get "$OTHER" "ict/tickets/$TID")"
check "a member sees only their own" 'TestSuite laptop' "$(get "$USER" 'ict/tickets')"
absent "and not other people's"      'TestSuite third'  "$(post "$OTHER" 'ict/tickets' '{\"subject\":\"TestSuite third\"}' >/dev/null; get \"$USER\" 'ict/tickets')"

echo
echo "Working it:"
check "staff can assign"             '"status":"assigned"' \
      "$(patch "$STAFF" "ict/tickets/$TID" "{\"assigneeUserId\":$SID}")"
check "assigning records first response" '"firstResponseAt"' "$(get "$STAFF" "ict/tickets/$TID")"
check "the workflow narrates itself"  'Assigned to'  "$(get "$STAFF" "ict/tickets/$TID")"
check "staff can change priority"     '"priority":"urgent"' \
      "$(patch "$STAFF" "ict/tickets/$TID" '{"priority":"urgent"}')"
check "staff can resolve"             '"status":"resolved"' \
      "$(patch "$STAFF" "ict/tickets/$TID" '{"status":"resolved","resolution":"Charger replaced"}')"
check "resolving stamps the time"     '"resolvedAt"' "$(get "$STAFF" "ict/tickets/$TID")"
check "reopening clears it"           '"resolvedAt":null' \
      "$(patch "$STAFF" "ict/tickets/$TID" '{"status":"in_progress"}')"

echo
echo "What a requester may and may not do:"
check "can add a comment"             '"reference"' \
      "$(patch "$USER" "ict/tickets/$TID" '{"comment":"Any update on this?"}')"
check "cannot change priority"        '"priority":"urgent"' \
      "$(patch "$USER" "ict/tickets/$TID" '{"priority":"low","comment":"still waiting"}' >/dev/null; get "$STAFF" "ict/tickets/$TID")"
check "an empty update is refused"    'Add a comment' "$(patch "$USER" "ict/tickets/$TID" '{}')"
check "a stranger cannot comment"     'tickets.manage' "$(patch "$OTHER" "ict/tickets/$TID" '{"comment":"hello"}')"

echo
echo "Internal notes stay internal:"
patch "$STAFF" "ict/tickets/$TID" '{"comment":"Battery is out of warranty - do not tell them yet","internal":true}' >/dev/null
check "staff see the internal note"   'out of warranty' "$(get "$STAFF" "ict/tickets/$TID")"
absent "the requester does NOT"       'out of warranty' "$(get "$USER" "ict/tickets/$TID")"
check "but still sees public replies" 'Any update on this' "$(get "$USER" "ict/tickets/$TID")"

echo
echo "The queue:"
check "staff see a summary"           '"open"'   "$(get "$STAFF" 'ict/tickets')"
check "a member gets no summary"      '"summary":null' "$(get "$USER" 'ict/tickets')"
check "assignees are listed"          '"assignees"' "$(get "$STAFF" 'ict/tickets/assignees')"
check "a member cannot list assignees" 'tickets.manage' "$(get "$USER" 'ict/tickets/assignees')"

echo
echo "Audit:"
check "raising logged"                'ticket_raised'  "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='ticket';")"
check "updates logged"                'ticket_updated' "$($MYSQL -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM ict_audit WHERE target_type='ticket';")"

$MYSQL -e "DELETE FROM ict_tickets WHERE subject LIKE 'TestSuite%';
DELETE FROM users WHERE email IN ('t-staff@local','t-user@local','t-other@local');" >/dev/null
rm -f "$STAFF" "$USER" "$OTHER"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
