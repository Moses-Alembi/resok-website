# Automatic welfare elections

This implementation uses the existing recorded ballot. The authenticated live screen
shows aggregate candidate totals and turnout, never individual choices, staff numbers,
addresses or nominators. The feed refreshes every five seconds; it is a results feed,
not a camera/video broadcast.

## Prepare and deploy

1. Deploy the PHP and portal changes. Apply the new
   `resok-portal/server/migration-2026-11-03-welfare-automation.sql` through the migration
   runner or phpMyAdmin. Existing migration files were not changed.
   **The current cPanel deploy excludes `resok-portal/server/`: upload this migration
   separately before expecting the migration runner to list it.**
   Also apply `migration-2026-11-03-welfare-staff-total-34.sql` for the final 34-person target.
   Existing migrations remain unchanged. Upload this new file separately on cPanel too.
   An already-armed roll needs to be disarmed and armed again with all 34 staff ready;
   this update does not rewrite a frozen roll or any election that has opened.
2. Create/select a staff welfare election in `election-admin`. Set the contested posts,
   seats, maximum choices and all four dates. Times are Africa/Nairobi.
3. In **Automatic welfare election**, select **Set up automatic election**.
4. Reconcile the real employee roster. There must be exactly **34 active employees with
   distinct employee numbers and activated accounts**. Paste spreadsheet rows as
   `employee number<TAB>full name<TAB>email`. Header rows should be removed.
5. Account imports do not send mail. Use the existing staff invite action for employees,
   or ask each new employee to use **Forgot password** with their own address. Completing
   that mailbox link activates the account. Existing passwords and roles are preserved.
6. Once every account is ready, choose **Arm automatic schedule**. This snapshots the
   voter roll. Imports/redrawing are refused while armed; disarm before corrections.
   The total is exactly **34 staff voters**; no additional voter accounts are included.
   Nothing can change the electorate after voting has opened.
7. Add a cPanel cron job every minute:

   ```text
   * * * * * php /home/resokorg/public_html/resok-portal/public/api/cron/welfare.php
   ```

   Confirm the PHP binary and document root for the hosting account. The job is CLI-only.
   Authenticated election requests and the live feed also advance due stages, but cron is
   what keeps the schedule progressing when nobody has the portal open.

## What happens automatically

- Nominations open and close on schedule. Welfare nominations are final, require no
  acceptance, cannot be declined, and are approved automatically after automatic setup.
  `accepted_at` is not forged to represent consent the nominee never supplied.
- Voting opens when due, provided each post has sufficient eligible candidates. The
  roster and ballot then lock. Missing candidates pause the transition with an error.
- One account casts one final ballot. The election lock serializes voting, closure and
  declaration, and the voter lock prevents duplicate submissions.
- Candidate totals and turnout are visible to every authenticated portal account through
  a common portal panel and `election-live`. Seeing totals does not confer voting rights.
- At closing, the result is declared and published automatically. Retries do not produce
  repeated declarations. A tie remains closed and flagged: decide a welfare tie/runoff
  policy before polling; the system does not invent a winner.
- Staff on the roll can access authentication and election routes outside working hours
  during the election window and for 24 hours after closing to read results. Other staff
  work routes keep their existing hours policy. Country/county access rules still apply.

## Verification

`php tools/dev/test-welfare.php` runs integration checks against a **separate disposable
MariaDB instance on 127.0.0.1:33317**, using synthetic people only. It never loads
`config.php` or connects to the normal portal database. The instance must be initialized
with the test password specified in that script. Each run creates and drops its own
randomly named `resok_welfare_test_*` database.

The tests cover roster readiness, authorization, automatic nominations and stages,
concurrent duplicate submissions, a ballot waiting during closure, multi-seat counting,
privacy of the live response, automatic publication, ties, and board-election regression.
