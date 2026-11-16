-- Mark staff accounts that may only use the staff welfare election.
-- Runtime setup also adds this column when staff tables are first loaded.
ALTER TABLE staff_accounts
  ADD COLUMN election_only TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
