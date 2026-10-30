# Postmortem: [short title, e.g. "Members could not sign in, 4 Nov"]

Write one within a week of every level 1 incident, and of any level 2 that lasted over 4
hours. One page is enough. The point is to stop it happening again, **not to find someone to
blame**: write what the system and the process allowed, not who slipped.

Copy this file, fill it in, and keep it with the incident log (not in this repository if it
names members).

---

**Incident number:** INC-____-___
**Level:** 1 / 2
**Written by:** ____ **Date:** ____

## What happened

Two or three sentences a Council member would understand.

## Who was affected

Roughly how many members, staff or visitors, and what they couldn't do.

## Timeline

Times in East Africa Time.

| Time | What happened |
|---|---|
| | Problem began |
| | First noticed (by whom, how) |
| | Response started |
| | Fixed / worked around |
| | Confirmed back to normal |

**Time to notice:** ____ **Time to fix:** ____

## Why it happened

The cause, and the cause behind that. Keep asking "why" until you reach something that can be
changed. For example: "a deploy broke sign-in" → why wasn't it caught? → "no test sign-in
after deploying" → that is the thing to change.

## What went well

What helped: an alert that fired, a runbook that worked, a quick decision.

## What to change

| Action | Owner | Due | Done |
|---|---|---|---|
| | | | |

Keep this to a few actions that will really happen, rather than a long wish list. Check the
table at the next ICT review.
