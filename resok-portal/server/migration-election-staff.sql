-- Staff welfare elections on the same election system (26 Oct 2026).
--
-- An election now says who its electorate is: the membership, as before, or the Society's
-- staff. Everything that makes an election trustworthy - the frozen roll, the one-act ballot,
-- the secret vote, the count - is the same code for both; only who is on the roll and who may
-- stand differ.
--
-- Staff have portal accounts but, mostly, no member record, so a staff roll entry and a staff
-- nominee are identified by their user account instead. One person, one vote per election is
-- then enforced on the user, which holds for members too (one account per member).

ALTER TABLE elections
  ADD COLUMN electorate ENUM('members','staff') NOT NULL DEFAULT 'members' AFTER description;

-- A staff voter has no member record.
ALTER TABLE election_roll
  MODIFY member_profile_id INT UNSIGNED NULL;

-- The double-voting guard, on the account. NULL member ids do not collide in the old key, so
-- staff rolls need this one.
ALTER TABLE election_roll
  ADD UNIQUE KEY election_roll_user (election_id, user_id);

-- A staff nominee, identified by account: they accept in the portal like a member does.
ALTER TABLE election_candidates
  ADD COLUMN nominee_user_id INT UNSIGNED NULL AFTER member_profile_id;
