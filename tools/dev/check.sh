#!/usr/bin/env bash
#
# Pre-upload check. Run this before every deploy.
#
# This exists because the portal API has gone down twice from things that were invisible
# without a PHP parser:
#
#   1. lib/blog.php carried an unparenthesised nested ternary. Brackets balanced, so a
#      hand-written checker passed it. PHP 8 rejects it at compile time, which took out
#      every route in the API - including the ones that never touched that file.
#   2. A route called adminLog(), which does not exist. The real function is
#      logAdminAction(). Nothing catches that by reading.
#
# "php -l" catches the first class outright. PHPStan catches the second, plus wrong
# argument counts, undefined variables and bad property access.
#
#   ./tools/dev/check.sh          syntax only, fast
#   ./tools/dev/check.sh --full   adds PHPStan and the inline-JS check
#
set -uo pipefail
cd "$(dirname "$0")/../.."

PHP=""
for candidate in php /c/xampp/php/php.exe "/c/Program Files/PHP/php.exe"; do
    if command -v "$candidate" >/dev/null 2>&1; then PHP="$candidate"; break; fi
done
if [ -z "$PHP" ]; then
    echo "No PHP found. Install XAMPP, or add php to PATH."
    exit 2
fi

FULL=0
[ "${1:-}" = "--full" ] && FULL=1

fail=0
checked=0

echo "PHP syntax  ($("$PHP" -r 'echo PHP_VERSION;'))"
echo "------------------------------------------------------------"
while IFS= read -r file; do
    checked=$((checked + 1))
    if ! out=$("$PHP" -l "$file" 2>&1); then
        echo "FAIL  $file"
        echo "$out" | sed 's/^/      /'
        fail=$((fail + 1))
    fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*')
echo "$checked file(s) parsed, $fail with errors"

if [ "$FULL" = "1" ]; then
    echo
    echo "Inline page scripts"
    echo "------------------------------------------------------------"
    if command -v node >/dev/null 2>&1; then
        # Every page in this project keeps its JS inline, so a syntax error there is
        # invisible until a browser hits it - and then only in the console.
        python - <<'PY'
import glob, io, os, re, subprocess, tempfile
bad = 0
files = 0
for path in glob.glob('*.html') + glob.glob('private/*.html') + glob.glob('resok-portal/public/*.html'):
    src = io.open(path, encoding='utf-8', errors='replace').read()
    for i, block in enumerate(re.findall(r'<script(?![^>]*\ssrc=)[^>]*>(.*?)</script>', src, re.S)):
        if not block.strip():
            continue
        files += 1
        fh = tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8')
        fh.write(block)
        fh.close()
        r = subprocess.run(['node', '--check', fh.name], capture_output=True, text=True)
        os.unlink(fh.name)
        if r.returncode:
            bad += 1
            print("FAIL  %s (block %d)" % (path, i))
            print("      " + r.stderr.strip().split('\n')[0][:150])
print("%d script block(s) checked, %d with errors" % (files, bad))
raise SystemExit(1 if bad else 0)
PY
        [ $? -ne 0 ] && fail=$((fail + 1))
    else
        echo "node not found - skipped"
    fi

    echo
    echo "PHPStan"
    echo "------------------------------------------------------------"
    # Invoked through the PHP binary found above rather than vendor/bin/phpstan, whose
    # shebang needs php on PATH - which it is not inside git-bash on Windows.
    if [ -f vendor/phpstan/phpstan/phpstan.phar ]; then
        "$PHP" vendor/phpstan/phpstan/phpstan.phar analyse --no-progress || fail=$((fail + 1))
    else
        echo "not installed - run: composer install"
    fi
fi

echo
if [ "$fail" -eq 0 ]; then
    echo "PASS - safe to upload"
    exit 0
fi
echo "$fail problem(s) - DO NOT UPLOAD"
exit 1
