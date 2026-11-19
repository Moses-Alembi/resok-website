# Welfare nominations: an existing candidate must remain selectable

For the one-position-per-nominee review before voting, also follow
[welfare-position-review.md](welfare-position-review.md).

The previous staff nomination handler rejected a person who already had a candidate
entry for that post. This stopped other staff recording their own nomination of that
person and displayed the error shown in the reported screenshot.

The fix stores each staff member's final nomination separately from candidate entries.
Several staff may nominate the same person; the ballot still contains that person once.
Submitting the same choice again succeeds without duplicating the nomination. A staff
member cannot change their nomination to somebody else for the same post.

## Deployment order

1. Back up the election database through the normal hosting process.
2. Upload and apply
   `resok-portal/server/migration-2026-11-19-welfare-nomination-records.sql`.
   It creates the nomination records table and copies the known original staff
   nominators from existing candidate records. It does not redraw the voter roll,
   reset nominations or ballots, change election dates, or change election status.
   Reapplying the file is safe.
3. Deploy `resok-portal/public/api/lib/elections.php`,
   `resok-portal/public/api/lib/migrate.php`, and
   `resok-portal/public/elections.html` and `resok-portal/public/election-admin.html`
   from the same patch.
4. While nominations are open, use two eligible test accounts in a rehearsal election
   to nominate the same colleague for the same post. Both should receive a green
   confirmation. Confirm the candidate appears once in the administrator's candidate
   list and both nominations exist in `election_staff_nominations`.
5. Retry the same choice, then try changing that account's choice. The retry should
   succeed; the changed choice should be refused as final.
6. As super administrator, open `election-admin?for=staff`, select the election and
   find **Nomination tally**. Both staff nominations should count toward the same
   candidate's total. Use **Refresh nomination tally** for a new reading.

## Nomination tally and vote tally

The super-admin nomination tally counts distinct recorded nominators per candidate,
including known original nominators from the older candidate records. An original
nominator already present in the new nomination records counts once. A repeat
submission of the same nomination does not increase the total.

Submissions rejected by the previous duplicate-candidate check were never recorded.
Those staff must submit again while nominations are open to have their nomination
included; the tally cannot reconstruct failed submissions.

The nominee list labels the original nominator separately from the total. An
administrator adding a candidate without a recorded staff nomination contributes
zero nominations. If the nomination-records migration is missing, totals are shown
as unavailable instead of zero. Counts are excluded from voter-facing nomination
responses and from board-election rows.

**Vote tally** reads submitted voting ballots. Nomination totals remain visible in
the staff election's **Nomination tally** after nominations close and voting begins.

The current cPanel deployment excludes `resok-portal/server/`. Upload the new SQL file
separately to that directory before applying it from the migration runner, or import
it through phpMyAdmin. Deploying the PHP file alone is insufficient: it deliberately
refuses staff nomination writes when the new table is missing.

If the host caches PHP with OPcache and timestamp checks are disabled, refresh that
cache through the hosting controls after uploading the updated PHP file.

## Scope and verification

Existing candidate status, original nominator, candidate details, and ballots are
preserved. Additional nominations do not create duplicate candidate entries or repeat
the nominee notification. Staff cannot revive a withdrawn or disqualified candidate.
Board/member elections keep their existing nomination rules.

`php tools/dev/test-welfare-nominations.php` exercises this flow using synthetic
accounts and a separate disposable MariaDB instance on `127.0.0.1:33317`. It never
loads portal configuration or connects to the normal portal database.

Existing nominations whose original roll references were already orphaned by an
earlier roll redraw cannot be reconstructed by this migration. Preserve those
records and investigate them separately; do not redraw the roll to apply this fix.

If the application code must be rolled back, keep `election_staff_nominations`
and its records. Dropping the table would discard the additional nominators captured
after this update. The previous PHP code will again reject nominations of an existing
candidate until the fix is restored.
