# Changing the database structure

## How it works now

- Every table and column change is a `.sql` file in `resok-portal/server/`.
- The **Threat Assessment** page (`security.html`, super admins only) lists those files and
  applies them with one button.
- Each applied file is recorded in the `schema_migrations` table: its name, a checksum of its
  contents, who applied it and when. That table is the database's version history. It answers
  "which changes does this database have?" for live and for the laptop.
- If a file changes after it was applied, its checksum no longer matches and the page shows it
  as **changed**. That is a warning, not something to apply again without thinking.
- Files run in the order listed in `migrateOrder()` in `resok-portal/public/api/lib/migrate.php`.
  Files not on that list run afterwards, alphabetically.

## Rules

1. **Never edit a file that has already been applied on live.** Make the change in a new file.
   Editing an old one makes live and the laptop drift apart without anyone noticing.
2. **Name new files with the date first:**
   `migration-2026-11-04-what-it-does.sql`. The date gives the order at a glance and keeps
   files in sequence alphabetically. (Older files predate this rule. Leave their names alone,
   because renaming would make them look unapplied.)
3. **If a file depends on another, add it to `migrateOrder()`** after the file it needs.
4. **Only add, don't remove**, in the same deploy as the code change. Add new columns and
   tables first. Remove old ones in a later file, once no deployed code uses them. That way
   rolling the code back ([rollback.md](rollback.md)) still works against the new database.
5. **Write files so they can run twice.** Use `CREATE TABLE IF NOT EXISTS`. The runner already
   skips "column already exists" and "table already exists" errors.
6. **Keep one-off data fixes and deletions out of `resok-portal/server/`.** Every file there is
   offered for applying, so a delete script placed there could be run by accident. Run such
   scripts by hand in phpMyAdmin, after taking a backup.
7. **Back up before applying on live**: cPanel → phpMyAdmin → Export, or cPanel → Backup.

## Applying a change on live

1. Deploy the code (cPanel → Git Version Control → Deploy HEAD Commit).
2. Back up the database.
3. Open Threat Assessment and apply the new file. Read the result: "skipped" lines are normal,
   errors are not.
4. Sign in and use the feature that needed the change.

## To check

`.cpanel.yml` excludes `resok-portal/server/` from the deploy, yet the Threat Assessment page
reads its files from that folder on the server. **[TO CHECK on live: does Threat Assessment
list the newest files, e.g. `migration-election-staff.sql`?]** If not, new `.sql` files have
to be uploaded to that folder by hand in File Manager until the deploy is changed to copy them
somewhere that isn't public.
