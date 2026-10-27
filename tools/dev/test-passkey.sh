#!/usr/bin/env bash
#
# Passkeys as the second factor (api/lib/passkey.php), against a software authenticator
# (passkey-authenticator.php) standing in for a phone.
#
# What matters most: a passkey can be added only by someone holding the current app code;
# sign-in with it still needs the password first; the device must have verified the person
# (UV); a signature for another website, a reused challenge, a wrong key, a stranger's key or a
# counter going backwards are all refused; removing one needs the password; and turning
# two-factor off takes the passkeys with it.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
ORIGIN="http://localhost:8081"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
DIR="$(cd "$(dirname "$0")" && pwd)"
AUTH="$PHP $(cygpath -w "$DIR/passkey-authenticator.php")"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.240s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
json() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $d = $d[$k] ?? null; echo is_scalar($d) ? $d : json_encode($d);' "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
post() { curl -s -b "$1" -X POST --max-time 20 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
TMP=$(mktemp -d); W() { cygpath -w "$1"; }

PW='PasskeyTest2026'
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW")
# Separate statements: on a fresh database user_passkeys does not exist until first use, and
# one failing statement would stop the rest of the batch.
sql "DELETE p FROM user_passkeys p JOIN users u ON u.id = p.user_id WHERE u.email LIKE 'pk-%@resok.local'"
sql "DELETE FROM users WHERE email LIKE 'pk-%@resok.local'; DELETE FROM auth_attempts;
     INSERT INTO users (email,password_hash,email_verified,role) VALUES ('pk-admin@resok.local','$HASH',1,'admin'),('pk-other@resok.local','$HASH',1,'admin');"
UID1=$(sql "SELECT id FROM users WHERE email='pk-admin@resok.local'")
UID2=$(sql "SELECT id FROM users WHERE email='pk-other@resok.local'")

# Two-factor on for both accounts, with a known secret, the way enrolment stores it.
SECRET=JBSWY3DPEHPK3PXP
CFG=$(cygpath -w "$DIR/../../resok-portal/public/api")
ENC=$($PHP -r 'chdir($argv[1]); $c = require "config.php"; require "lib/crypto.php"; echo cryptoEncrypt($c, $argv[2]);' "$CFG" "$SECRET")
sql "UPDATE users SET mfa_enabled=1, mfa_secret='$ENC', mfa_enrolled_at=NOW() WHERE id IN ($UID1,$UID2)"
code() { $PHP -r 'chdir($argv[1]); require "lib/mfa.php"; echo mfaCodeAt($argv[2], intdiv(time(), 30));' "$CFG" "$SECRET"; }

login() {   # login <email> <jar> -> prints the password step's response
    curl -s -c "$2" -X POST "${API}auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$PW\"}"
}
# A real session for the admin, through password + app code.
JAR=$TMP/admin.jar
CH=$(login pk-admin@resok.local "$JAR" | json challenge)
curl -s -c "$JAR" -X POST "${API}auth/mfa/verify" -H 'Content-Type: application/json' -d "{\"challenge\":\"$CH\",\"code\":\"$(code)\"}" >/dev/null

echo "Adding a passkey"
check "a wrong app code is refused" "code was not correct" "$(post "$JAR" auth/passkey/register/options '{"code":"000000"}')"
OPTS=$(post "$JAR" auth/passkey/register/options "{\"code\":\"$(code)\"}")
check "the right code gives the device a challenge" '"challenge"' "$OPTS"
check "bound to localhost on the laptop" '"id":"localhost"' "$OPTS"
CRED=$($AUTH create "$(W $TMP/key.json)" "$OPTS" "$ORIGIN" 69 "evil.example")
check "a key made for another website is refused" "not made for this site" "$(post "$JAR" auth/passkey/register/verify "{\"name\":\"x\",\"credential\":$CRED}")"
OPTS=$(post "$JAR" auth/passkey/register/options "{\"code\":\"$(code)\"}")
CRED=$($AUTH create "$(W $TMP/key.json)" "$OPTS" "$ORIGIN" 65)
check "a device that did not check the person is refused" "did not confirm it was you" "$(post "$JAR" auth/passkey/register/verify "{\"name\":\"x\",\"credential\":$CRED}")"
OPTS=$(post "$JAR" auth/passkey/register/options "{\"code\":\"$(code)\"}")
CRED=$($AUTH create "$(W $TMP/key.json)" "$OPTS" "https://evil.example")
check "a response from another origin is refused" "not made for this site" "$(post "$JAR" auth/passkey/register/verify "{\"name\":\"x\",\"credential\":$CRED}")"
OPTS=$(post "$JAR" auth/passkey/register/options "{\"code\":\"$(code)\"}")
CRED=$($AUTH create "$(W $TMP/key.json)" "$OPTS" "$ORIGIN")
R=$(post "$JAR" auth/passkey/register/verify "{\"name\":\"Test phone\",\"credential\":$CRED}")
check "a proper face/fingerprint passkey is added" '"name":"Test phone"' "$R"
check "the same challenge cannot be used twice" "too long or was already used" "$(post "$JAR" auth/passkey/register/verify "{\"name\":\"again\",\"credential\":$CRED}")"
check "adding is in the security log" "passkey_added" "$(sql "SELECT event_type FROM security_events WHERE user_id=$UID1 AND event_type='passkey_added' LIMIT 1")"
check "a session with no app code cannot add one" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "${API}auth/passkey/register/options" -H 'Content-Type: application/json' -d '{}')"

echo "Adding with a recovery code instead of the app"
REC=$($PHP -r 'chdir($argv[1]); require "lib/mfa.php"; $r = mfaGenerateRecoveryCodes(); echo json_encode($r);' "$CFG")
RHASH=$(echo "$REC" | json hashed); RCODE=$(echo "$REC" | $PHP -r 'echo json_decode(stream_get_contents(STDIN), true)["plain"][0];')
sql "UPDATE users SET mfa_recovery='$RHASH' WHERE id=$UID1"
check "a recovery code is accepted in place of the app code" '"challenge"' "$(post "$JAR" auth/passkey/register/options "{\"code\":\"$RCODE\"}")"
check "and is used up: the same one is refused" "code was not correct" "$(post "$JAR" auth/passkey/register/options "{\"code\":\"$RCODE\"}")"

echo "Signing in with it"
J2=$TMP/s2.jar
R=$(login pk-admin@resok.local "$J2")
check "after the password, the page is told a passkey exists" '"passkey":true' "$R"
CH=$(echo "$R" | json challenge)
check "without the password step there is nothing to sign" "expired" "$(post "" auth/passkey/login/options '{"challenge":"forged.token"}')"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
check "the device gets a sign-in challenge for its key" '"allowCredentials"' "$LO"
A=$($AUTH get "$(W $TMP/key.json)" "$LO" "$ORIGIN" 1)
check "a signature without user verification is refused" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
A=$($AUTH get "$(W $TMP/key.json)" "$LO" "https://evil.example")
check "a signature from another website is refused" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
A=$($AUTH get "$(W $TMP/key.json)" "$LO" "$ORIGIN" 5 0 "evil.example")
check "a signature for another site's id is refused" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
$AUTH create "$(W $TMP/other.json)" '{"publicKey":{"challenge":"x","rp":{"id":"localhost"}}}' "$ORIGIN" >/dev/null
A=$($AUTH get "$(W $TMP/other.json)" "$LO" "$ORIGIN")
check "a key that is not on this account is refused" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
A=$($AUTH get "$(W $TMP/key.json)" "$LO" "$ORIGIN" 5 7)
R=$(curl -s -c "$J2" -X POST "${API}auth/passkey/login/verify" -H 'Content-Type: application/json' -d "{\"challenge\":\"$CH\",\"credential\":$A}")
check "the real device, face checked: signed in" '"usedPasskey":true' "$R"
check "with a session cookie" "resok_token" "$(cat "$J2")"
check "the same signed answer cannot be replayed" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
LO=$(post "" auth/passkey/login/options "{\"challenge\":\"$CH\"}")
A=$($AUTH get "$(W $TMP/key.json)" "$LO" "$ORIGIN" 5 3)
check "a counter going backwards (a copied key) is refused" "not accepted" "$(post "" auth/passkey/login/verify "{\"challenge\":\"$CH\",\"credential\":$A}")"
check "failures are in the security log" "passkey_failed" "$(sql "SELECT event_type FROM security_events WHERE user_id=$UID1 AND event_type='passkey_failed' LIMIT 1")"

echo "Another account"
CH2=$(login pk-other@resok.local "$TMP/o.jar" | json challenge)
check "an account with no passkey is not offered one" "no face or fingerprint" "$(post "" auth/passkey/login/options "{\"challenge\":\"$CH2\"}")"

echo "Removing and turning two-factor off"
PID=$(sql "SELECT id FROM user_passkeys WHERE user_id=$UID1 LIMIT 1")
check "removing needs the password" "password was not correct" "$(post "$JAR" auth/passkey/$PID/delete '{"password":"wrong"}')"
check "nobody can remove another account's passkey" "No such passkey" "$(post "$TMP/o.jar" auth/passkey/$PID/delete "{\"password\":\"$PW\"}" ; curl -s -c "$TMP/o2.jar" -X POST "${API}auth/mfa/verify" -H 'Content-Type: application/json' -d "{\"challenge\":\"$CH2\",\"code\":\"$(code)\"}" >/dev/null; post "$TMP/o2.jar" auth/passkey/$PID/delete "{\"password\":\"$PW\"}")"
OPTS=$(post "$JAR" auth/passkey/register/options "{\"code\":\"$(code)\"}")
CRED=$($AUTH create "$(W $TMP/key2.json)" "$OPTS" "$ORIGIN")
post "$JAR" auth/passkey/register/verify "{\"name\":\"Laptop\",\"credential\":$CRED}" >/dev/null
check "two passkeys on the account" "2" "$(sql "SELECT COUNT(*) FROM user_passkeys WHERE user_id=$UID1")"
post "$JAR" auth/mfa/disable "{\"password\":\"$PW\"}" >/dev/null
check "turning two-factor off removes them all" "0" "$(sql "SELECT COUNT(*) FROM user_passkeys WHERE user_id=$UID1")"

sql "DELETE p FROM user_passkeys p JOIN users u ON u.id = p.user_id WHERE u.email LIKE 'pk-%@resok.local'"
sql "DELETE FROM users WHERE email LIKE 'pk-%@resok.local'; DELETE FROM auth_attempts;"
rm -rf "$TMP"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
