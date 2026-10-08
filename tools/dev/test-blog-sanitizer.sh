#!/usr/bin/env bash
#
# The blog's HTML filter (blogSanitizeHtml) against known script-injection tricks. Every
# output is checked for a surviving on*/style attribute or a javascript:/data: URL, and a
# few ordinary inputs must come through unchanged.
#
# Until 9 Oct 2026 an attribute written straight after a quoted value with no space,
# <a href="x"onclick="...">, passed the filter; so did nothing else here, but that one runs.
#
# Run from the repository root:  ./tools/dev/test-blog-sanitizer.sh
set -uo pipefail
PAYLOADS=$(mktemp)
cat > "$PAYLOADS" <<'LIST'
<img src=x onerror=alert(1)>
<img/onerror=alert(1) src=x>
<a href="jav&#x09;ascript:alert(1)">x</a>
<a href="javascript:alert(1)">x</a>
<a href=" javascript:alert(1)">x</a>
<svg onload=alert(1)>
<div><scr<script>ipt>alert(1)</script></div>
<img src="x" onerror	=alert(1)>
<a href="https://ok" onmouseover=alert(1)//>x</a>
<img src="data:image/svg+xml;base64,PHN2Zz4=">
<div title="x" onclick=alert(1)>x</div>
<a href="x"onclick="alert(1)">x</a>
<a href="x"/onclick=alert(1)>x</a>
<a href='x'onmouseover='a()'>x</a>
<p title="a"style="position:fixed">x</p>
<a href="x" ONCLICK = "a()">x</a>
<a title="x>" onclick="alert(1)">x</a>
<img src="a.png" alt="one" onerror="x">
<a href="x" title='it"s' onclick="a()">x</a>
<A HREF="x" OnMouseOver="a()">x</A>
<a title="x"href="javascript:alert(1)">x</a>
<img alt="a"src="javascript:alert(1)">
<a href="JaVaScRiPt:alert(1)">x</a>
<a href="&#106;avascript:alert(1)">x</a>
<p>Click here onion=fine to read</p>
LIST
/c/xampp/php/php.exe -r '
require "resok-portal/public/api/lib/blog.php";
$pass = 0; $fail = 0;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $in) {
    $out = blogSanitizeHtml($in);
    $danger = preg_match("/<[^>]*[\s\"\x27\/](on[a-z]+|style)\s*=/i", $out) || preg_match("/(javascript|vbscript|data):/i", $out);
    printf("  %-56s %s\n", substr($in, 0, 56), $danger ? "FAIL -> $out" : "ok");
    $danger ? $fail++ : $pass++;
}
$keep = [
    "<p>Plain <strong>bold</strong> and <a href=\"https://resok.org\" title=\"Home\">link</a></p>",
    "<p>Click here onion=fine to read</p>",
    "<img src=\"a.png\" alt=\"one onerror=x\">",
];
foreach ($keep as $in) {
    $same = blogSanitizeHtml($in) === $in;
    printf("  %-56s %s\n", "unchanged: " . substr($in, 0, 45), $same ? "ok" : "FAIL -> " . blogSanitizeHtml($in));
    $same ? $pass++ : $fail++;
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
' "$PAYLOADS"
