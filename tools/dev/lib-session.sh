# Sourced by the test suites. Mints a signed session cookie for an existing account, the
# same way the API signs one, so a suite can act as the local super admin without knowing
# (or hard-coding) that account's personal password. Hard-coded passwords here broke the
# election and ICT suites the moment the developer changed theirs - every officer step then
# failed with "Missing token", which reads like an API fault rather than a stale password.
#
#   JAR=$(minted_session dev@resok.local)       # cookie jar for curl -b
minted_session() {
    local email="$1" id token jar cfg
    cfg="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/resok-portal/public/api/config.php"
    id=$(/c/xampp/mysql/bin/mysql.exe -u root resok_portal -N -e "SELECT id FROM users WHERE email='$email';")
    token=$(ID="$id" EM="$email" CFG="$cfg" /c/xampp/php/php.exe -r '
        $c = require getenv("CFG");
        $role = trim((string)shell_exec("/c/xampp/mysql/bin/mysql.exe -u root resok_portal -N -e \"SELECT role FROM users WHERE id=" . (int)getenv("ID") . "\""));
        $b = fn($d) => rtrim(strtr(base64_encode($d), "+/", "-_"), "=");
        $body = $b(json_encode(["userId" => (int)getenv("ID"), "email" => getenv("EM"),
                                "role" => $role ?: "admin", "exp" => time() + 1800, "seen" => time()]));
        echo $body . "." . $b(hash_hmac("sha256", $body, $c["jwt_secret"], true));' 2>/dev/null)
    jar=$(mktemp)
    printf 'localhost\tFALSE\t/\tFALSE\t0\tresok_token\t%s\n' "$token" > "$jar"
    echo "$jar"
}
