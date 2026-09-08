-- The issue book: acknowledgement of equipment handovers.
--
-- ict_assignments already records every handover. What a paper issue book has that the
-- table did not is the signature - the reason the book is evidence rather than a note.
-- These columns record that acknowledgement.
--
-- Two ways of acknowledging, because ReSoK issues equipment to people who have no portal
-- login. A cleaner or a driver signs a printed form, which is filed and referenced here;
-- somebody with an account can confirm in the portal instead. Both are recorded the same
-- way, with acknowledged_via saying which, so a report can tell a signature on file from a
-- click without treating either as the other.
--
-- No issue reference column. The reference printed on a form is derived from the row's id
-- and the year it was issued (ISS-2026-0042), so it cannot drift out of step with the row
-- it names, and the 172 imported assignments get one without a backfill.
--
-- One ALTER per column on purpose: MySQL fails a whole statement if any one column already
-- exists, so combining them would mean a re-run applied none of the others. The migration
-- runner treats "duplicate column" as benign, which makes each line safe to run again.

ALTER TABLE ict_assignments
  ADD COLUMN acknowledged_at DATETIME NULL AFTER handover_notes;

ALTER TABLE ict_assignments
  ADD COLUMN acknowledged_via ENUM('portal','paper') NULL AFTER acknowledged_at;

-- Where the signed form is filed, or who witnessed it. Free text because the answer is a
-- filing location - "Signed copy in the ICT folder, witnessed by B. Agesa" - and no
-- enumeration survives contact with how offices actually store paper.
ALTER TABLE ict_assignments
  ADD COLUMN acknowledgement_ref VARCHAR(200) NULL AFTER acknowledged_via;

ALTER TABLE ict_assignments
  ADD COLUMN acknowledged_by INT UNSIGNED NULL AFTER acknowledgement_ref;

-- Finds the unacknowledged handovers, which is the only question this index is for.
ALTER TABLE ict_assignments
  ADD KEY ict_assignments_ack (acknowledged_at, returned_at);
