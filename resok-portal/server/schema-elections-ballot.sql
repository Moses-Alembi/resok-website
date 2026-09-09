-- The ballot.
--
-- Separate from schema-elections.sql because this is the decision that cannot be revisited:
-- once real votes exist in this shape, they cannot be made secret retrospectively.
--
-- ReSoK's choice is a recorded ballot. Each vote is stored against the roll entry that cast
-- it, and only a super administrator can read it. The role is held by ICT rather than by an
-- officer connected to the board, which is what makes it a returning-officer function rather
-- than a participant reading their own election.
--
-- What that buys: a disputed vote can be traced, a member who says the system lost their
-- ballot can be answered, and turnout and tallies reconcile against named rows.
--
-- What it costs, stated plainly because it cannot be undone later: a member's choice is
-- retrievable by somebody. Every retrieval of identifiable voting data is therefore written
-- to the ICT audit log, so the question "who looked at how people voted" has an answer.
--
-- Three rules the schema enforces rather than trusting the application to remember:
--
--   A voter cannot vote for the same candidate twice in the same post.
--   A ballot row cannot exist without a roll entry, so only eligible members can be in here.
--   Deleting an election takes its ballots with it; nothing is orphaned into a state where
--   votes exist for an election nobody can name.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS election_ballots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  election_id INT UNSIGNED NOT NULL,
  position_id INT UNSIGNED NOT NULL,

  -- Who cast it. The roll entry rather than the member, so a ballot is anchored to this
  -- election's electorate: a member's later record changes cannot move a vote between
  -- elections or make one appear in an election they were not eligible for.
  roll_id INT UNSIGNED NOT NULL,

  -- NULL together with is_abstain = 1 is a deliberate abstention. A voter who returns a
  -- ballot marking nobody has done something different from a voter who never voted, and a
  -- society that counts abstentions towards quorum needs to tell them apart.
  candidate_id INT UNSIGNED NULL,
  is_abstain TINYINT(1) NOT NULL DEFAULT 0,

  cast_at DATETIME NOT NULL,
  -- Kept for the audit trail, not for identification: a hash, never a raw address, so the
  -- log can show two ballots came from one place without storing where anybody was.
  client_hash CHAR(64) NULL,

  PRIMARY KEY (id),

  -- One vote per candidate per post per voter. In a post with several seats a voter may
  -- mark several candidates, which is several rows; marking the same candidate twice is a
  -- database error rather than something the application has to catch.
  UNIQUE KEY election_ballots_once (roll_id, position_id, candidate_id),

  KEY election_ballots_count (election_id, position_id, candidate_id),
  KEY election_ballots_roll (roll_id),

  CONSTRAINT election_ballots_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE,
  CONSTRAINT election_ballots_position_fk FOREIGN KEY (position_id)
    REFERENCES election_positions(id) ON DELETE CASCADE,
  CONSTRAINT election_ballots_roll_fk FOREIGN KEY (roll_id)
    REFERENCES election_roll(id) ON DELETE CASCADE,
  CONSTRAINT election_ballots_candidate_fk FOREIGN KEY (candidate_id)
    REFERENCES election_candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- The declared result.
--
-- Written when a super administrator declares the result, and never recomputed on read. A
-- tally that is recalculated every time it is displayed can change after the fact - a
-- candidate disqualified next week would silently rewrite last week's declared result. The
-- count that was declared is the count that stands, and a recount is a new row.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS election_results (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  election_id INT UNSIGNED NOT NULL,
  position_id INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NULL,

  candidate_name VARCHAR(160) NOT NULL,
  votes INT UNSIGNED NOT NULL DEFAULT 0,
  is_abstain TINYINT(1) NOT NULL DEFAULT 0,
  elected TINYINT(1) NOT NULL DEFAULT 0,

  -- Which count this was. A recount is a new declaration, not an edit of the old one.
  declaration SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  declared_by INT UNSIGNED NULL,
  declared_at DATETIME NOT NULL,
  note VARCHAR(255) NULL,

  PRIMARY KEY (id),
  KEY election_results_position (election_id, position_id, declaration),
  CONSTRAINT election_results_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
