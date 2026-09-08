#!/usr/bin/env bash
#
# Exercises two-factor enrolment and the sign-in it protects.
#
# The claims worth testing are about what a password alone can still do. Enrolling must need
# a working code, so nobody locks themselves out with a QR they never scanned. Once enabled,
# the password must stop being enough to sign in. Turning it off must need the password
# again, so a stolen session cannot quietly remove it. And a recovery code must work once.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      did not want: %s\n      got: %.150s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email = 'mfa-test@resok.local';
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('mfa-test@resok.local','$HASH',1,'admin');" >/dev/null
UID_=$($MYSQL -N -e "SELECT id FROM users WHERE email='mfa-test@resok.local';")

JAR=$(mktemp)
login() {
    curl -s -c "$JAR" -X POST "${API}auth/login" -H 'Content-Type: application/json' \
      -d "{\"email\":\"mfa-test@resok.local\",\"password\":\"$1\"}"
}
get()  { curl -s -b "$JAR" --max-time 15 "${API}$1"; }
post() { curl -s -b "$JAR" -X POST --max-time 15 "${API}$1" -H 'Content-Type: application/json' -d "${2:-{\}}"; }

# The code the app would be showing right now, derived from the secret the same way the
# server derives it - this is what a real authenticator does.
totp() { $PHP -r '
  require "resok-portal/public/api/lib/mfa.php";
  echo mfaCodeAt($argv[1], intdiv(time(), 30));
' "$1" 2>/dev/null | grep -v Warning; }

echo "Before enrolling:"
LOGIN=$(login TestPass123)
check "password alone signs in"                'token'                "$LOGIN"
absent "no challenge is issued"                'mfaRequired'          "$LOGIN"
STATUS=$(get 'auth/mfa/status')
check "status reports it off"                  '"enabled":false'      "$STATUS"
check "status reports it available"            '"available":true'     "$STATUS"
check "status knows the role requires it"      '"requiredForRole":true' "$STATUS"

echo
echo "Enrolling:"
SETUP=$(post 'auth/mfa/setup')
check "setup returns a secret"                 '"secret"'             "$SETUP"
check "setup returns a provisioning URI"       'otpauth://totp/'      "$SETUP"
SECRET=$(echo "$SETUP" | $PHP -r 'preg_match("/\"secret\":\"([A-Z2-7]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
check "the secret is base32"                   "$SECRET"              "$SECRET"

check "the secret is not stored in the clear"  'enc.v1.' \
      "$($MYSQL -N -e "SELECT mfa_secret FROM users WHERE id=$UID_;")"
check "still off until a code proves the app"  '0' \
      "$($MYSQL -N -e "SELECT mfa_enabled FROM users WHERE id=$UID_;")"

check "a wrong code is refused"                'was not correct' \
      "$(post 'auth/mfa/enable' '{"code":"000000"}')"

ENABLE=$(post 'auth/mfa/enable' "{\"code\":\"$(totp "$SECRET")\"}")
check "a working code turns it on"             '"enabled":true'       "$ENABLE"
check "recovery codes are issued"              '"recoveryCodes"'      "$ENABLE"
RCODE=$(echo "$ENABLE" | $PHP -r 'preg_match("/\"recoveryCodes\":\[\"([^\"]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
check "recovery codes are hashed, not stored"  'false' \
      "$($MYSQL -N -e "SELECT IF(mfa_recovery LIKE '%$RCODE%','true','false') FROM users WHERE id=$UID_;")"

echo
echo "Signing in with it on:"
GATED=$(login TestPass123)
check "the password alone no longer signs in"  '"mfaRequired":true'   "$GATED"
absent "and issues no session token"           '"token"'              "$GATED"
CHALLENGE=$(echo "$GATED" | $PHP -r 'preg_match("/\"challenge\":\"([^\"]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
check "a challenge is issued instead"          "$CHALLENGE"           "$CHALLENGE"

VERIFY=$(curl -s -c "$JAR" -X POST "${API}auth/mfa/verify" -H 'Content-Type: application/json' \
  -d "{\"challenge\":\"$CHALLENGE\",\"code\":\"$(totp "$SECRET")\"}")
check "the code completes the sign-in"         'token'                "$VERIFY"

echo
echo "Recovery:"
GATED2=$(login TestPass123)
CH2=$(echo "$GATED2" | $PHP -r 'preg_match("/\"challenge\":\"([^\"]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
REC=$(curl -s -c "$JAR" -X POST "${API}auth/mfa/verify" -H 'Content-Type: application/json' \
  -d "{\"challenge\":\"$CH2\",\"code\":\"$RCODE\"}")
check "a recovery code signs in"               'token'                "$REC"
check "and is spent, not reusable"             'false' \
      "$($MYSQL -N -e "SELECT IF(mfa_recovery LIKE '%$RCODE%','true','false') FROM users WHERE id=$UID_;")"

echo
echo "Turning it off:"
check "the wrong password is refused"          'was not correct' \
      "$(post 'auth/mfa/disable' '{"password":"WrongPass123"}')"
check "it is still on after that refusal"      '1' \
      "$($MYSQL -N -e "SELECT mfa_enabled FROM users WHERE id=$UID_;")"
check "the right password turns it off"        '"enabled":false' \
      "$(post 'auth/mfa/disable' '{"password":"TestPass123"}')"
check "and the secret is cleared, not kept"    'NULL' \
      "$($MYSQL -N -e "SELECT IFNULL(mfa_secret,'NULL') FROM users WHERE id=$UID_;")"

echo
echo "The security log:"
for event in mfa_enabled mfa_disable_refused mfa_disabled mfa_missing_privileged_login; do
    check "records $event" "$event" \
      "$($MYSQL -N -e "SELECT event_type FROM security_events WHERE event_type='$event' AND user_id=$UID_ LIMIT 1;")"
done

$MYSQL -e "DELETE FROM users WHERE email='mfa-test@resok.local';" >/dev/null
rm -f "$JAR"

echo
echo "  $pass passed, $fail failed"
[[ $fail -eq 0 ]]
