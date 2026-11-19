# Welfare elections: confirm one ballot position per nominee

Staff may nominate the same colleague for several positions. Before voting, each
nominee with multiple eligible candidacies chooses one position. A super
administrator acting as returning officer records the choice and how the nominee
confirmed it. The system keeps the other nominations and totals as history.

## Prepare and deploy

1. Apply `migration-2026-11-19-welfare-nomination-records.sql` if it has not already
   been applied. Then apply
   `resok-portal/server/migration-2026-11-19-welfare-position-review.sql`.
   The new migration creates current choices and confirmation-history tables. It is
   rerunnable and does not change election dates, statuses, rolls, candidates,
   nominations or ballots.
2. Deploy the matching application files together. Upload the new helper
   `resok-portal/public/api/lib/election-position-review.php` before the updated
   `elections.php` that requires it. The other runtime files are
   `api/index.php`, `api/lib/welfare.php`, `api/lib/migrate.php`,
   `election-admin.html`, `elections.html` and `js/welfare-admin.js`, all below
   `resok-portal/public/`.
3. The cPanel deployment excludes `resok-portal/server/`. Upload the SQL separately
   before applying it from the migration runner, or import it through phpMyAdmin.
   Deployment of PHP alone cannot create the review schema.
4. Before voting opens, use **Correct the details** to ensure voting starts strictly
   after nominations close. Publish enough review time for nominees to confirm
   their positions. Existing dates are never rewritten automatically.
5. Keep the minute-by-minute welfare cron job running. Do not disarm or redraw the
   voter roll merely to apply this update.

## Returning officer procedure

1. Open `election-admin?for=staff`, select the election and find **Confirm ballot
   positions**. During nominations this identifies colleagues put forward for more
   than one post and shows each post's nomination total.
2. After nominations have actually closed, contact each affected nominee and ask
   which one position they will contest. A **Nominations closed** status set early
   does not bypass the scheduled closing time.
3. Select that position, record a confirmation note, and choose **Record chosen
   position**. Include how and when the nominee confirmed their choice. There is
   no automatic choice based on nomination totals and no new acceptance/decline
   action in the staff portal.
4. The other candidacies display **Not contesting this position**. Their original
   candidate records, nominations and nomination totals remain intact. This is not
   disqualification or deletion.
5. Resolve every outstanding choice and candidate shortage. If a selected position
   leaves another post without enough candidates, revise the pre-voting timetable
   and follow the agreed nomination procedure. The system does not fill the vacancy
   automatically.
6. Once the review is ready and the voting opening time arrives, automatic opening
   can proceed. Manual **Open voting** uses the same server checks.

If the scheduled opening time passes with outstanding issues, the election stays
at **Nominations closed** and the automatic schedule reports the reason. Nomination
closure is committed, so the review remains usable. If the whole voting window
expires before opening, correct the timetable before proceeding; no result is
invented.

## Records and enforcement

`election_staff_position_choices` stores the selected post, the administrator,
confirmation time and note, and a snapshot of the candidate's eligible positions.
`election_staff_position_choice_events` appends each changed confirmation, including
the previous choice and candidate snapshot. Identical retries do not duplicate an
event.

Before opening, changes to a nominee's eligible candidacies invalidate the old
confirmation and require review again. This includes a change that leaves only
one eligible post after a previously recorded choice. Once voting opens,
confirmation controls and candidate, position and timetable edits lock. Ballot
construction, vote validation and result declaration use the confirmed candidacy.

Recorded staff nominations cannot be removed through the candidate/post delete
action. Nominations and tallies are retained when another post is selected.

The administrator review API is `GET`/`POST`
`admin/elections/{id}/position-review`. Recording requires a positive nominee and
position ID, a confirmation note, and verified super-administrator access. Ordinary
staff cannot make administrative confirmations or retrieve the review.

## Existing opened elections and recovery

An election that has already opened retains its original ballot. This update does
not resolve its multiple-position candidacies or rewrite its votes. The returning
officer must handle any such case under the procedure agreed for that election.

If the review schema is unavailable, new staff voting cannot open. The admin
screen reports the unavailable review instead of claiming readiness. Board/member
elections retain their separate rules.

Keep both review tables and all confirmation events during recovery. An older
application version does not understand recorded exclusions: reverting to it can
restore candidates to posts they did not choose. After choices are recorded,
restore the complete reviewed application bundle rather than mixing versions.

## Verification

The following use synthetic accounts and the disposable test MariaDB server on
`127.0.0.1:33317`; they do not load the portal database configuration:

- `php tools/dev/test-welfare-position-review.php`
- `php tools/dev/test-welfare-nominations.php`
- `php tools/dev/test-welfare.php`

The DOM checks exercise the actual admin renderers without a browser:

- `node tools/dev/test-election-position-review.cjs`
- `node tools/dev/test-election-admin-nominations.cjs`

Also rehearse two-post nominations, a confirmed choice, an empty remaining post,
automatic opening, and the final ballot in the target environment before launch.
