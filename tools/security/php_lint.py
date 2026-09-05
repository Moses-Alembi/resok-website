"""Catch PHP constructs that are fatal at compile time but leave brackets balanced.

My bracket checker passed a file that PHP refuses to compile, and that took the whole API
down. This looks for the specific class it missed - chiefly the unparenthesised nested
ternary, which PHP 8 rejects outright rather than warning about.
"""
import glob
import io
import os
import re
import sys

ROOT = sys.argv[1]

files = []
for pattern in ('**/*.php',):
    files += glob.glob(os.path.join(ROOT, pattern), recursive=True)
files = sorted(f for f in files if 'node_modules' not in f.replace('\\', '/'))


def strip_noise(text: str) -> str:
    """Blank out strings and comments so their contents cannot look like code."""
    out, i, n = [], 0, len(text)
    while i < n:
        c = text[i]
        if c in ('"', "'"):
            quote = c
            i += 1
            while i < n and text[i] != quote:
                if text[i] == '\\':
                    i += 1
                i += 1
            i += 1
            out.append('""')
        elif text.startswith('//', i) or text.startswith('#', i):
            while i < n and text[i] != '\n':
                i += 1
        elif text.startswith('/*', i):
            j = text.find('*/', i + 2)
            i = (j + 2) if j >= 0 else n
        else:
            out.append(c)
            i += 1
    return ''.join(out)


problems = 0
for path in files:
    raw = io.open(path, encoding='utf-8', errors='replace').read()
    code = strip_noise(raw)
    for lineno, line in enumerate(code.split('\n'), 1):
        # Two ? and two : on one statement, with no parentheses wrapping the inner one.
        if line.count('?') >= 2 and line.count(':') >= 2 and '?:' not in line:
            candidate = re.sub(r'\([^()]*\)', '()', line)          # collapse balanced groups
            if re.search(r'\?[^?:]*:[^?:]*\?[^?:]*:', candidate):
                shown = raw.split('\n')[lineno - 1].strip()
                print("%s:%d  unparenthesised nested ternary (fatal in PHP 8)" % (path.replace('\\', '/'), lineno))
                print("      %s" % shown[:110])
                problems += 1

print()
print("%d PHP files scanned, %d problem(s)" % (len(files), problems))
sys.exit(1 if problems else 0)
