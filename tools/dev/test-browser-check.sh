#!/usr/bin/env bash
#
# Exercises "verifying your browser" (lib/proof.php, js/proof.js). Switches the check on in
# the laptop's config.local.php for the run and puts the file back afterwards, whatever
# happens. Needs node and the jsdom copy in the scratchpad for the browser-side check.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
PHP="/c/xampp/php/php.exe"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
CFG="resok-portal/public/api/config.local.php"
JSDOM_DIR="${JSDOM_DIR:-C:/Users/mssal/AppData/Local/Temp/claude/c--Users-mssal-OneDrive-Desktop-cpd-admin/925af870-6363-47f2-8fae-cbe76e5c776f/scratchpad/mmd}"
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
login() { curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' ${1:+-H "X-Resok-Proof: $1"} -X POST -d '{"email":"nobody-proof@resok.local","password":"wrong-Pass1"}' "${API}auth/login"; }
solve() { node -e '
  const src = require("fs").readFileSync("resok-portal/public/js/proof.js", "utf8");
  const solver = new Function("return " + src.match(/function solver\(challenge, bits\) \{[\s\S]*?\n  \}\n/)[0])();
  process.stdout.write(solver(process.argv[1], Number(process.argv[2])));' "$1" "$2"; }
field() { $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d[$argv[1]] ?? "";' "$1"; }

# The sign-in rate limit would answer 429 after a few runs; this is the laptop, so clear it.
$MYSQL -e "DELETE FROM auth_attempts WHERE action = 'login'" 2>/dev/null

echo "Switched off (the default):"
check "sign-in needs no proof"                                  "401" "$(login)"
r=$(curl -s "${API}proof/challenge")
check "the challenge endpoint says it is off"                   '"enabled":false' "$r"

cp "$CFG" "$CFG.prooftest"
trap 'mv -f "$CFG.prooftest" "$CFG"' EXIT
$PHP -r '$f = $argv[1]; $s = file_get_contents($f); $s = preg_replace("/return\s*\[/", "return [\n    \"proof_of_work\" => true, \"proof_of_work_bits\" => 12,", $s, 1); file_put_contents($f, $s);' "$CFG"

echo "Switched on:"
check "sign-in without proof is held"                           "428" "$(login)"
r=$(curl -s -H 'Content-Type: application/json' -X POST -d '{"email":"x@resok.local","password":"x"}' "${API}auth/login")
check "and the browser is told to verify"                       '"powRequired":true' "$r"
r=$(curl -s "${API}proof/challenge")
check "a challenge is issued"                                   '"bits":12' "$r"
CH=$(echo "$r" | field challenge)
N=$(solve "$CH" 12)
check "a solved puzzle lets sign-in through"                    "401" "$(login "$CH|$N")"
check "the same solution cannot be used twice"                  "428" "$(login "$CH|$N")"
r=$(curl -s -H "X-Resok-Proof: $CH|$N" -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/login")
check "it is refused as reused"                                 '"reason":"reused"' "$r"
CH2=$(curl -s "${API}proof/challenge" | field challenge)
r=$(curl -s -H "X-Resok-Proof: $CH2|0" -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/login")
[[ "$(solve "$CH2" 12)" == "0" ]] || check "a wrong answer is refused"   '"reason":"unsolved"' "$r"
FORGED="${CH2%.*}.00000000000000000000000000000000"
r=$(curl -s -H "X-Resok-Proof: $FORGED|$(solve "$FORGED" 12)" -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/login")
check "a forged puzzle is refused"                              '"reason":"forged"' "$r"
OLD=$($PHP -r 'require "resok-portal/public/api/lib/proof.php"; $c = require "resok-portal/public/api/config.php"; $p = "v1." . (time() - 10) . "." . bin2hex(random_bytes(16)); echo $p . "." . substr(hash_hmac("sha256", $p, proofKey($c)), 0, 32);')
r=$(curl -s -H "X-Resok-Proof: $OLD|$(solve "$OLD" 12)" -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/login")
check "an expired puzzle is refused"                            '"reason":"expired"' "$r"
r=$(curl -s -H "X-Resok-Proof: x|y|z" -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/login")
check "garbage is refused"                                      '"reason":"malformed"' "$r"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "name=Test&email=t@resok.local&subject=Hi&message=This is a test message for the form" "http://localhost:8081/contact.php")
check "the contact form is held too"                            "428" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -X POST -d '{}' "${API}auth/forgot-password")
check "and forgot-password"                                     "428" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' "${API}conference/current")
check "ordinary pages are not affected"                         "200" "$code"

echo "In a browser:"
PROOF_JS="$(pwd -W 2>/dev/null || pwd)/resok-portal/public/js/proof.js"
if [ -d "$JSDOM_DIR/node_modules/jsdom" ]; then
  r=$(cd "$JSDOM_DIR" && node -e '
    const { JSDOM } = require("jsdom");
    const fs = require("fs");
    const dom = new JSDOM("<body></body>", { url: "http://localhost:8081/resok-portal/public/js/page.html", runScripts: "outside-only" });
    const w = dom.window;
    w.fetch = (u, o) => fetch(new URL(u, w.location.href).href, o);
    w.Headers = Headers;
    const src = fs.readFileSync(process.argv[1], "utf8").replace("document.currentScript", "null");
    w.eval(src);
    w.ResokProof.fetch("../api/index.php?route=auth/login".replace("..", "/resok-portal/public"), {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ email: "nobody-proof@resok.local", password: "wrong-Pass1" }) })
      .then((res) => console.log("status " + res.status), (e) => console.log("error " + e.message));
  ' "$PROOF_JS")
  check "proof.js verifies and retries on its own"                "status 401" "$r"
else
  echo "  (jsdom not found; browser check skipped)"
fi

mv -f "$CFG.prooftest" "$CFG"; trap - EXIT
$MYSQL -e "DELETE FROM auth_attempts WHERE action = 'login'" 2>/dev/null
check "the laptop config is back as it was"                     "401" "$(login)"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
