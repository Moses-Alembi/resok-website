#!/usr/bin/env bash
#
# Server-side session control and the staff two-factor gate.
#
# The claims: a logged-out session is dead even if someone kept a copy of the cookie; a
# password reset ends every session the account had; a demotion takes effect on the next
# request, not when the token expires; a staff account without two-factor reaches the
# enrolment routes and nothing else; and member uploads cannot be fetched by URL.
#
# Run from the repository root:  ./tools/dev/test-sessions.sh
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
SITE="http://localhost:8081"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
CFG="resok-portal/public/api/config.php"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('sess-member@resok.local','sess-admin@resok.local','sess-staff@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('sess-member@resok.local','$HASH',1,'member'),
 ('sess-admin@resok.local','$HASH',1,'admin'),
 ('sess-staff@resok.local','$HASH',1,'admin');" >/dev/null
MEMBER_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='sess-member@resok.local';")
ADMIN_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='sess-admin@resok.local';")
STAFF_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='sess-staff@resok.local';")
# sess-admin stands in for an enrolled admin: the gate reads only mfa_enabled.
$MYSQL -e "UPDATE users SET mfa_enabled = 1 WHERE id = $ADMIN_ID;" 2>/dev/null

login() {   # login <jar> <email> [password]
    curl -s -c "$1" -X POST "${API}auth/login" -H 'Content-Type: application/json' \
      -d "{\"email\":\"$2\",\"password\":\"${3:-TestPass123}\"}"
}
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -c "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 15 "${API}$2"; }

# A session cookie signed the way the API signs one. extra is merged into the payload.
mint() {   # mint <userId> <email> <role> [extra-json]
    local token jar
    token=$(ID="$1" EM="$2" RO="$3" EX="${4:-{\}}" CFG="$CFG" $PHP -r '
        $c = require getenv("CFG");
        $b = fn($d) => rtrim(strtr(base64_encode($d), "+/", "-_"), "=");
        $p = array_merge(["userId" => (int)getenv("ID"), "email" => getenv("EM"), "role" => getenv("RO"),
                          "exp" => time() + 1800, "seen" => time(), "iat" => time(), "jti" => bin2hex(random_bytes(16))], json_decode(getenv("EX"), true) ?: []);
        $body = $b(json_encode($p));
        echo $body . "." . $b(hash_hmac("sha256", $body, $c["jwt_secret"], true));' 2>/dev/null)
    jar=$(mktemp)
    printf 'localhost\tFALSE\t/\tFALSE\t0\tresok_token\t%s\n' "$token" > "$jar"
    echo "$jar"
}

echo "Logout ends the session on the server:"
A=$(mktemp); COPY=$(mktemp)
login "$A" sess-member@resok.local >/dev/null
cp "$A" "$COPY"
check "signed in"                                          '200' "$(code "$A" auth/mfa/status)"
check "a session carries its own id"                       '1' \
      "$(grep -c resok_token "$A")"
post "$A" auth/logout >/dev/null
check "a copy of the logged-out cookie is refused"         '"reason":"revoked"' "$(get "$COPY" auth/mfa/status)"
B=$(mktemp); login "$B" sess-member@resok.local >/dev/null
check "a fresh login still works"                          '200' "$(code "$B" auth/mfa/status)"

echo
echo "A password reset ends every session:"
C=$(mktemp); login "$C" sess-member@resok.local >/dev/null
check "second device signed in"                            '200' "$(code "$C" auth/mfa/status)"
sleep 1   # a session minted in the same second as the reset counts as before it
$MYSQL -e "UPDATE users SET reset_token='sesstesttoken', reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=$MEMBER_ID;"
RESET=$(curl -s -X POST "${API}auth/reset-password" -H 'Content-Type: application/json' \
        -d '{"token":"sesstesttoken","password":"NewPass456"}')
check "the reset succeeds"                                 'Password updated' "$RESET"
check "the session from before it is ended"                '"reason":"revoked"' "$(get "$B" auth/mfa/status)"
check "so is the one on the other device"                  '"reason":"revoked"' "$(get "$C" auth/mfa/status)"
sleep 1
D=$(mktemp); login "$D" sess-member@resok.local NewPass456 >/dev/null
check "logging in with the new password works"             '200' "$(code "$D" auth/mfa/status)"

echo
echo "The role is read live, not from the token:"
ADM=$(mint "$ADMIN_ID" sess-admin@resok.local admin)
check "an admin reaches the member list"                     '200' "$(code "$ADM" members)"
$MYSQL -e "UPDATE users SET role='member' WHERE id=$ADMIN_ID;"
check "after demotion the same cookie is refused"          '403' "$(code "$ADM" members)"
$MYSQL -e "UPDATE users SET role='admin' WHERE id=$ADMIN_ID;"
FORGED=$(mint "$MEMBER_ID" sess-member@resok.local admin)
check "a member token claiming admin gets nothing"         '403' "$(code "$FORGED" members)"
LEGACY=$(mint "$MEMBER_ID" sess-member@resok.local member '{"iat":null,"jti":null}')
check "a pre-revocation token without iat is ended too"    '"reason":"revoked"' "$(get "$LEGACY" auth/mfa/status)"
GONE=$(mint 999999999 nobody@resok.local admin)
check "a token for a deleted account is refused"           '"reason":"revoked"' "$(get "$GONE" auth/mfa/status)"

echo
echo "Staff two-factor gate:"
ENFORCED=$(CFG="$CFG" $PHP -r '$c = require getenv("CFG"); echo empty($c["require_staff_mfa"]) ? "no" : "yes";' 2>/dev/null)
if [[ "$ENFORCED" != "yes" ]]; then
    echo "  require_staff_mfa is off in config.local.php - gate checks skipped."
    echo "  Set it to true and rerun this suite to test the gate."
else
    S=$(mktemp)
    LOGIN=$(login "$S" sess-staff@resok.local)
    check "login tells the page setup is owed"             '"mfaSetupRequired":true' "$LOGIN"
    check "admin routes are refused"                       '"mfaSetupRequired":true' "$(get "$S" members)"
    check "the status route is allowed"                    '"enabled":false' "$(get "$S" auth/mfa/status)"
    SETUP=$(post "$S" auth/mfa/setup)
    check "setup is allowed"                               '"secret"' "$SETUP"
    SECRET=$(echo "$SETUP" | $PHP -r 'preg_match("/\"secret\":\"([A-Z2-7]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
    TOTP=$($PHP -r 'require "resok-portal/public/api/lib/mfa.php"; echo mfaCodeAt($argv[1], intdiv(time(), 30));' "$SECRET" 2>/dev/null)
    check "enabling is allowed"                            '"enabled":true' "$(post "$S" auth/mfa/enable "{\"code\":\"$TOTP\"}")"
    check "once enrolled, admin routes open"               '200' "$(code "$S" members)"
    MEMBERPAGE=$(mktemp); login "$MEMBERPAGE" sess-member@resok.local NewPass456 >/dev/null
    check "members are never asked"                        '200' "$(code "$MEMBERPAGE" auth/mfa/status)"
fi

echo
echo "Uploads cannot be fetched by URL:"
PROOF=$(ls resok-portal/uploads/Payment_Proof 2>/dev/null | head -1)
if [[ -n "$PROOF" ]]; then
    check "a payment proof URL is refused"                 '403' \
          "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/resok-portal/uploads/Payment_Proof/$PROOF")"
else
    echo "  no payment proof on disk to try - skipped"
fi
check "the folder itself is refused"                       '403' \
      "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/resok-portal/uploads/")"

$MYSQL -e "DELETE FROM users WHERE email IN ('sess-member@resok.local','sess-admin@resok.local','sess-staff@resok.local');
           DELETE FROM session_revocations WHERE user_id IN ($MEMBER_ID,$ADMIN_ID,$STAFF_ID);" >/dev/null 2>&1

echo
echo "$pass passed, $fail failed"
[[ $fail -eq 0 ]]
