-- ReSoK board elections: the election, its posts, its candidates and its electoral roll.
--
-- The ballot itself is deliberately not in this file. How a vote is stored is the one
-- decision that cannot be changed once real votes exist, and it belongs in its own migration
-- applied when that decision is settled. Everything here is the same either way.
--
-- Three things shape it.
--
-- The roll is frozen, not computed. Eligibility is decided once, at a stated cutoff, and
-- copied into election_roll as rows. A live query against membership standing would mean the
-- electorate changed while voting was open - somebody renewing on the Tuesday would join it,
-- somebody lapsing on the Wednesday would leave, and the number of people entitled to vote
-- would depend on when you asked. A frozen roll can also be published and challenged before
-- voting opens, which is what makes a disputed result answerable.
--
-- The roll stores the member's name and number as they were at the cutoff. A member who
-- later changes their name, or whose record is corrected, must not change what the roll for
-- a past election says.
--
-- Nothing here can be edited once voting opens. The application enforces that, and the
-- opened_at timestamp is what it checks against: an election whose candidates could change
-- mid-vote is not an election.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS elections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(90) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,

  -- The date membership standing is judged on. Stored rather than assumed, because the roll
  -- has to be reproducible: anyone should be able to ask "who was eligible, and why" and get
  -- the same answer next year.
  eligibility_cutoff DATE NOT NULL,

  opens_at DATETIME NOT NULL,
  closes_at DATETIME NOT NULL,

  -- draft: being set up, nothing published.
  -- roll_published: the roll is visible for members to check before voting opens.
  -- open: voting is live. Nothing about the election may be edited from here on.
  -- closed: voting has ended; results may be counted.
  -- published: the result is final and visible.
  -- cancelled: abandoned. Kept rather than deleted - an election that was called and called
  --            off is part of the record.
  status ENUM('draft','roll_published','open','closed','published','cancelled')
         NOT NULL DEFAULT 'draft',

  -- Set once, when voting actually opens, and never cleared. Every edit guard compares
  -- against this rather than against the status, because a status can be moved back.
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  results_published_at DATETIME NULL,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY elections_slug (slug),
  KEY elections_status (status, opens_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Posts being contested.
--
-- seats is how many people are elected to the post, and max_choices how many a voter may
-- mark. They are separate numbers on purpose: three seats does not always mean three votes
-- each, and a society that wants members to pick two of five for three seats can say so.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS election_positions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  election_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,

  seats TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_choices TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- Whether a voter may return a ballot for this post marking nobody. Abstention is a
  -- position, and recording it is not the same as a voter skipping the post by accident.
  allow_abstain TINYINT(1) NOT NULL DEFAULT 1,

  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY election_positions_election (election_id, position),
  CONSTRAINT election_positions_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Candidates.
--
-- A candidate is linked to a member where there is one, but the name and details are stored
-- here too. The link answers "is this candidate a member in good standing"; the stored copy
-- is what appears on the ballot and in the result, and must not change if the member record
-- is later edited.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS election_candidates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  position_id INT UNSIGNED NOT NULL,
  member_profile_id INT UNSIGNED NULL,

  name VARCHAR(160) NOT NULL,
  membership_id VARCHAR(80) NULL,
  headline VARCHAR(255) NULL,
  manifesto TEXT NULL,
  photo VARCHAR(255) NULL,

  -- withdrawn candidates stay on the record. A name that was on the ballot when voting
  -- opened cannot be removed from the history of that election.
  status ENUM('nominated','approved','withdrawn','disqualified') NOT NULL DEFAULT 'nominated',
  withdrawn_reason VARCHAR(255) NULL,

  -- The order candidates appear in. Randomised per election rather than alphabetical,
  -- because being first on a ballot paper is worth votes.
  ballot_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY election_candidates_position (position_id, ballot_order),
  KEY election_candidates_member (member_profile_id),
  CONSTRAINT election_candidates_position_fk FOREIGN KEY (position_id)
    REFERENCES election_positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- The electoral roll.
--
-- One row per eligible member, written once when the roll is drawn and not recomputed.
--
-- voted_at records that somebody voted. It records nothing about what they voted, and in a
-- secret ballot it is the only trace a voter leaves on their own row - which is what makes
-- "one member, one vote" enforceable without knowing anyone's choice.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS election_roll (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  election_id INT UNSIGNED NOT NULL,
  member_profile_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,

  -- As they were at the cutoff. A later correction to the member record must not change what
  -- this roll says about a past election.
  name VARCHAR(160) NOT NULL,
  membership_id VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  -- Why this person was eligible: the standing that put them on the roll. Kept so a
  -- challenge can be answered with the reason rather than a re-derivation.
  standing_at_cutoff VARCHAR(40) NULL,

  voted_at DATETIME NULL,
  -- How the vote was cast, for the audit trail. Never what was cast.
  voted_via ENUM('portal','proxy','in_person') NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- One entry per member per election. This is the constraint that makes double voting a
  -- database error rather than a matter of the application remembering to check.
  UNIQUE KEY election_roll_unique (election_id, member_profile_id),
  KEY election_roll_voted (election_id, voted_at),
  CONSTRAINT election_roll_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
