"""Checks that pages only call portal helpers that actually exist, and agree on the version.

Written after login broke with "window.ResokPortal.screeningFields is not a function". Two
separate mistakes produced that, and both are checkable:

  1. A page called a helper the deployed portal-state.js did not have. Guarding with
     `window.ResokPortal ? ... : {}` did not help - the object exists in the old copy too,
     so an outdated file sails straight past that test and dies on the missing method.

  2. portal-state.js was edited without its ?v= cache-busting string changing, so browsers
     kept serving the copy they already had. The version is part of the change, not an
     afterthought.

Run from the repository root, via tools/dev/check.sh.
"""
import glob
import io
import os
import re
import sys

STATE = 'resok-portal/public/js/portal-state.js'


def pages():
    return sorted(glob.glob('resok-portal/public/*.html') + glob.glob('*.html'))


def exported_names():
    """Names on the window.ResokPortal export object."""
    if not os.path.exists(STATE):
        print("FAIL  %s is missing" % STATE)
        return None
    src = io.open(STATE, encoding='utf-8', errors='replace').read()
    block = re.search(r'window\.ResokPortal\s*=\s*\{(.*?)\n\s*\};', src, re.S)
    if not block:
        print("FAIL  could not find the window.ResokPortal export block in %s" % STATE)
        return None

    names = set()
    for line in block.group(1).split(','):
        name = line.strip().split(':')[0].strip()
        if re.fullmatch(r'[A-Za-z_$][\w$]*', name):
            names.add(name)
    return names


def main():
    exported = exported_names()
    if exported is None:
        return 1

    problems = 0

    used = {}
    for path in pages():
        src = io.open(path, encoding='utf-8', errors='replace').read()
        for name in re.findall(r'ResokPortal\.([A-Za-z_$][\w$]*)', src):
            used.setdefault(name, set()).add(path)

    for name, where in sorted(used.items()):
        if name not in exported:
            print("FAIL  ResokPortal.%s is called but never exported" % name)
            for w in sorted(where):
                print("      %s" % w)
            problems += 1

    # Every page has to ask for the same build, or one of them gets a different one.
    versions = set()
    for path in pages():
        src = io.open(path, encoding='utf-8', errors='replace').read()
        versions.update(re.findall(r'portal-state\.js\?v=([^"\']+)', src))
    if len(versions) > 1:
        print("FAIL  pages disagree about the portal-state.js version: %s"
              % ', '.join(sorted(versions)))
        problems += 1

    print("%d helper(s) exported, %d referenced, %d problem(s)"
          % (len(exported), len(used), problems))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
