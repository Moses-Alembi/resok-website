-- One welfare ballot position per nominee, confirmed during the review interval.
-- These tables preserve administrative confirmations and their history. Applying this
-- migration never redraws a roll or changes candidates, nominations, ballots or dates.
-- Safe to apply again. Apply after the staff-election and nomination-record migrations.
CREATE TABLE IF NOT EXISTS election_staff_position_choices (
  election_id INT UNSIGNED NOT NULL,
  nominee_user_id INT UNSIGNED NOT NULL,
  position_id INT UNSIGNED NOT NULL,
  candidate_set_hash CHAR(64) NOT NULL,
  candidate_set_json TEXT NOT NULL,
  confirmed_by INT UNSIGNED NOT NULL,
  confirmed_at DATETIME NOT NULL,
  note TEXT NOT NULL,
  PRIMARY KEY (election_id, nominee_user_id),
  KEY election_staff_position_choices_position (position_id),
  CONSTRAINT election_staff_position_choices_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE,
  CONSTRAINT election_staff_position_choices_nominee_fk FOREIGN KEY (nominee_user_id)
    REFERENCES users(id),
  CONSTRAINT election_staff_position_choices_position_fk FOREIGN KEY (position_id)
    REFERENCES election_positions(id) ON DELETE CASCADE,
  CONSTRAINT election_staff_position_choices_actor_fk FOREIGN KEY (confirmed_by)
    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application code only appends events. Position IDs are historical values rather
-- than cascading references, so removing a pre-poll post cannot erase this evidence.
CREATE TABLE IF NOT EXISTS election_staff_position_choice_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  election_id INT UNSIGNED NOT NULL,
  nominee_user_id INT UNSIGNED NOT NULL,
  previous_position_id INT UNSIGNED NULL,
  new_position_id INT UNSIGNED NOT NULL,
  previous_candidate_set_hash CHAR(64) NULL,
  candidate_set_hash CHAR(64) NOT NULL,
  previous_candidate_set_json TEXT NULL,
  candidate_set_json TEXT NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  confirmation_note TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY election_staff_position_choice_events_nominee (election_id, nominee_user_id, id),
  CONSTRAINT election_staff_position_choice_events_election_fk FOREIGN KEY (election_id)
    REFERENCES elections(id) ON DELETE CASCADE,
  CONSTRAINT election_staff_position_choice_events_nominee_fk FOREIGN KEY (nominee_user_id)
    REFERENCES users(id),
  CONSTRAINT election_staff_position_choice_events_actor_fk FOREIGN KEY (actor_id)
    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
