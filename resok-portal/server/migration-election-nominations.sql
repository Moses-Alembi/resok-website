-- Nominations: the phase before voting.
--
-- An election now runs in two parts. Members put names forward during a nomination window;
-- the returning officer approves or rejects each one; then voting opens on the approved list.
-- Without this the candidate list could only be typed in by the officer, which is a different
-- thing from a membership nominating its own board.
--
-- Who nominated whom is recorded against the roll entry rather than the member, for the same
-- reason a ballot is: it anchors the nomination to this election's electorate, so a member
-- who was not eligible when the roll was drawn cannot be found to have nominated anybody.
--
-- The status enum is rewritten in full, every existing value included. Declaring an enum with
-- only the new values silently rewrites every row that held one of the old ones to the empty
-- string - which is exactly how this project once wiped users.role and left ICT accounts with
-- no permissions and no error. MODIFY is safe to run again; a partial list is not.

ALTER TABLE elections
  ADD COLUMN nominations_open_at DATETIME NULL AFTER eligibility_cutoff;

ALTER TABLE elections
  ADD COLUMN nominations_close_at DATETIME NULL AFTER nominations_open_at;

ALTER TABLE elections
  MODIFY COLUMN status ENUM('draft','nominations','nominations_closed','roll_published',
                            'open','closed','published','cancelled')
         NOT NULL DEFAULT 'draft';

-- Who put this candidate forward, and when. NULL for a candidate the officer entered
-- directly, which is still allowed - a nomination received on paper has to go somewhere.
ALTER TABLE election_candidates
  ADD COLUMN nominated_by_roll_id INT UNSIGNED NULL AFTER member_profile_id;

ALTER TABLE election_candidates
  ADD COLUMN nominated_at DATETIME NULL AFTER nominated_by_roll_id;

-- Whether the nominee has accepted. A name can be put forward by somebody else, and standing
-- for a board is not something that should happen to a person without their agreement.
ALTER TABLE election_candidates
  ADD COLUMN accepted_at DATETIME NULL AFTER nominated_at;

ALTER TABLE election_candidates
  ADD KEY election_candidates_nominator (nominated_by_roll_id);

-- A member may nominate one candidate per post. Without this, a single member could fill a
-- ballot with names, and the officer's list becomes a moderation queue rather than a record
-- of what the membership actually proposed.
ALTER TABLE election_candidates
  ADD UNIQUE KEY election_candidates_one_per_post (position_id, nominated_by_roll_id);
