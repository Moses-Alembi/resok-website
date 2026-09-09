-- Renewal bookkeeping on member_profiles.
--
-- last_reminder_days records the most urgent reminder band already sent, so each stage of
-- the renewal run goes out once. It existed before this file, but only because the reminder
-- cron created it on its first run with an ALTER of its own - which meant any install where
-- that cron had never run did not have the column, and anything else touching it failed.
-- Renewing a membership touches it. A column that only appears once an unrelated job has run
-- is not a schema; it is a race.
--
-- One ALTER per column on purpose: MySQL fails the whole statement if any one column already
-- exists, so combining them would mean a re-run applied none of the others. The migration
-- runner treats "duplicate column" as benign, which makes each line safe to run again.

ALTER TABLE member_profiles
  ADD COLUMN last_reminder_days INT NULL AFTER renewal_due;

-- Finds the memberships a renewal run has to look at, which is the only question this is for.
ALTER TABLE member_profiles
  ADD KEY member_profiles_renewal (membership_status, renewal_due);
