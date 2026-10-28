#!/usr/bin/env bash
#
# Staff sign-in protections (lib/staff-geo.php): three wrong passwords lock the account;
# staff sign in from Kenya, their counties and working hours only, unless a super admin
# approves an exception; staff passwords are stronger. Members are untouched by all of it.
#
# Location comes from Cloudflare's headers, which the laptop trusts (RESOK_LOCAL), so the
# suite sends CF-IPCountry and cf-region itself to stand anywhere it likes.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.220s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      did not want: %s\n      got: %.220s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
# login EMAIL PASSWORD [COUNTRY] [REGION] -> body ; the jar in $JAR keeps the cookie
login() {
    curl -s -c "$JAR" -b "$JAR" --max-time 20 "${API}auth/login" -H 'Content-Type: application/json' \
         ${3:+-H "CF-IPCountry: $3"} ${4:+-H "cf-region: $4"} -d "{\"email\":\"$1\",\"password\":\"$2\"}"
}
lcode() {
    curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" --max-time 20 "${API}auth/login" -H 'Content-Type: application/json' \
         ${3:+-H "CF-IPCountry: $3"} -d "{\"email\":\"$1\",\"password\":\"$2\"}"
}
as()   { local jar="$1" country="$2"; shift 2; curl -s -b "$jar" -c "$jar" --max-time 20 -H "CF-IPCountry: $country" "$@"; }
get()  { curl -s -b "$1" --max-time 20 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 20 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
setrule() { sql "INSERT INTO staff_geo_settings (name, value) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE value = '$2'"; }

PW='Correct-Horse-Battery-77'
HASH=$($PHP -r "echo password_hash('$PW', PASSWORD_DEFAULT);")
cleanup() {
    sql "DELETE FROM staff_geo_requests WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM staff_geo_events WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM staff_login_locks WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM staff_login_seen WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM staff_grants WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM staff_accounts WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'geo-%@resok.local');
         DELETE FROM auth_attempts;
         DELETE FROM users WHERE email LIKE 'geo-%@resok.local';
         DELETE FROM staff_geo_settings;"
}
SUPER=$(minted_session dev@resok.local)
get "$SUPER" staff/location >/dev/null      # creates the tables
cleanup
sql "INSERT INTO users (email,password_hash,email_verified,role) VALUES
       ('geo-staff@resok.local','$HASH',1,'member'), ('geo-hr@resok.local','$HASH',1,'member'), ('geo-member@resok.local','$HASH',1,'member');"
STAFF_ID=$(sql "SELECT id FROM users WHERE email='geo-staff@resok.local'")
HR_ID=$(sql "SELECT id FROM users WHERE email='geo-hr@resok.local'")
sql "INSERT INTO staff_accounts (user_id, full_name, active, work_counties) VALUES ($STAFF_ID, 'Geo Staff', 1, 'Kisumu'), ($HR_ID, 'Geo HR', 1, NULL);
     UPDATE staff_accounts SET manager_user_id = $HR_ID WHERE user_id = $STAFF_ID;
     INSERT INTO staff_grants (user_id, capability) VALUES ($HR_ID, 'staff.manage');"
JAR=$(mktemp)

echo "Three wrong passwords lock a staff account"
check "first wrong password" "Invalid credentials" "$(login geo-staff@resok.local wrong1 KE 'Kisumu County')"
check "second wrong password" "Invalid credentials" "$(login geo-staff@resok.local wrong2 KE 'Kisumu County')"
check "third wrong password" "Invalid credentials" "$(login geo-staff@resok.local wrong3 KE 'Kisumu County')"
check "now locked, even with the right password" "locked after 3 wrong passwords" "$(login geo-staff@resok.local "$PW" KE 'Kisumu County')"
check "the lock is in the security log" "1" "$(sql "SELECT COUNT(*) FROM security_events WHERE event_type='staff_account_locked' AND user_id=$STAFF_ID")"
check "a member is not locked after three" "Invalid credentials" "$(login geo-member@resok.local x KE; login geo-member@resok.local x KE; login geo-member@resok.local x KE; login geo-member@resok.local x KE)"
check "and still signs in" '"token"' "$(login geo-member@resok.local "$PW" KE)"
sql "DELETE FROM staff_login_locks; DELETE FROM auth_attempts;"

echo "Kenya only"
check "staff signs in from Kisumu" '"token"' "$(login geo-staff@resok.local "$PW" KE 'Kisumu County')"
check "the session works in Kenya" "Geo Staff" "$(as "$JAR" KE -H 'cf-region: Kisumu' "${API}staff/home")"
check "the same session abroad is refused" '"geoBlocked":true' "$(as "$JAR" UG "${API}staff/home")"
check "and was ended, not just refused" "401" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'CF-IPCountry: KE' "${API}staff/home")"
R=$(login geo-staff@resok.local "$PW" UG)
check "sign-in from Uganda is refused" "only be used in Kenya" "$R"
check "and names where they appear to be" "Uganda" "$R"
check "with a pass to ask for access" '"requestToken":"' "$R"
check "a member signs in from Uganda" '"token"' "$(login geo-member@resok.local "$PW" UG)"
check "the refusal is logged" "refused" "$(sql "SELECT outcome FROM staff_geo_events WHERE user_id=$STAFF_ID AND kind='abroad' LIMIT 1")"

echo "Asking from the refusal, and only a super admin approving"
TOKEN=$(printf '%s' "$R" | sed -E 's/.*"requestToken":"([^"]+)".*/\1/')
check "a forged pass is refused" "not valid" "$(post '' auth/geo-request '{"token":"abc.def","toDate":"2099-01-01","reason":"testing"}')"
TO=$(date -d '+3 day' +%F)
check "the real pass is accepted" "has been sent" "$(post '' auth/geo-request "{\"token\":\"$TOKEN\",\"toDate\":\"$TO\",\"reason\":\"Kampala workshop\"}")"
RID=$(sql "SELECT id FROM staff_geo_requests WHERE user_id=$STAFF_ID ORDER BY id DESC LIMIT 1")
check "it is for Uganda, as seen" "UG" "$(sql "SELECT country FROM staff_geo_requests WHERE id=$RID")"
check "super admins are told" "1" "$(sql "SELECT COUNT(*) > 0 FROM staff_notifications WHERE kind='location' AND dedupe_key LIKE 'geo-$RID-%'")"
HRJAR=$(minted_session geo-hr@resok.local)
check "HR (staff.manage) cannot approve" "Only a super administrator" "$(post "$HRJAR" staff/location/$RID/approve)"
MGR=$(post "$HRJAR" staff/location/$RID/approve)
check "nor can their manager" "Only a super administrator" "$MGR"
check "a decline needs a reason" "Say why" "$(post "$SUPER" staff/location/$RID/decline '{}')"
check "the super admin approves" '"ok":true' "$(post "$SUPER" staff/location/$RID/approve)"
check "now Uganda signs in" '"token"' "$(login geo-staff@resok.local "$PW" UG)"
check "but not Tanzania" "only be used in Kenya" "$(login geo-staff@resok.local "$PW" TZ)"

echo "Counties of operation"
check "Nairobi on monitor: allowed" '"token"' "$(login geo-staff@resok.local "$PW" KE 'Nairobi County')"
check "and noted" "noted" "$(sql "SELECT outcome FROM staff_geo_events WHERE user_id=$STAFF_ID AND kind='county' LIMIT 1")"
setrule county enforce
check "Nairobi on enforce: refused" "set up for Kisumu" "$(login geo-staff@resok.local "$PW" KE 'Nairobi County')"
check "Kisumu still fine" '"token"' "$(login geo-staff@resok.local "$PW" KE 'Kisumu')"
check "someone with no counties: anywhere in Kenya" '"token"' "$(login geo-hr@resok.local "$PW" KE 'Mombasa County')"
setrule county monitor

echo "Working hours"
NOW=$(date +%H)
setrule hours enforce; setrule hoursFrom "00:00"; setrule hoursTo "00:01"; setrule days 1234567
R=$(login geo-staff@resok.local "$PW" KE 'Kisumu')
check "outside the hours: refused" "can be used 12:00 am to 12:01 am" "$R"
check "with a pass to ask" '"requestToken":"' "$R"
check "super admins are exempt" '"super":true' "$(get "$SUPER" staff/home)"
setrule hoursTo "23:59"
check "inside the hours: allowed" '"token"' "$(login geo-staff@resok.local "$PW" KE 'Kisumu')"
check "settings refuse an end before the start" "start must be before" "$(post "$SUPER" staff/location/settings '{"hoursFrom":"18:00","hoursTo":"08:00"}')"
check "HR cannot change the rules" "Only a super administrator" "$(post "$HRJAR" staff/location/settings '{"hours":"off"}')"
sql "DELETE FROM staff_geo_settings"

echo "Staff portal page"
STAFFJAR=$(minted_session geo-staff@resok.local)
P=$(get "$STAFFJAR" staff/location)
check "staff see their rules" '"myCounties":["Kisumu"]' "$P"
check "and their requests" "Kampala workshop" "$P"
absent "but no queue to decide" '"canDecide":true' "$P"
check "the super admin sees the refusals" '"outcome":"refused"' "$(get "$SUPER" staff/location)"

echo "Staff passwords"
sql "UPDATE users SET reset_token='geotoken1', reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=$STAFF_ID"
check "a short staff password is refused" "at least 12 characters" "$(post '' auth/reset-password '{"token":"geotoken1","password":"Short1abc"}')"
check "one containing resok is refused" "does not contain" "$(post '' auth/reset-password '{"token":"geotoken1","password":"MyResokPass2026"}')"
check "a good one is accepted" "Password updated" "$(post '' auth/reset-password '{"token":"geotoken1","password":"Ndovu-Kubwa-Mlimani-48"}')"
sql "UPDATE users SET reset_token='geotoken2', reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email='geo-member@resok.local'"
check "a member keeps the old 8-character rule" "Password updated" "$(post '' auth/reset-password '{"token":"geotoken2","password":"Member123"}')"

cleanup
rm -f "$JAR" "$SUPER" "$HRJAR" "$STAFFJAR"
echo; echo "staff-geo: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
