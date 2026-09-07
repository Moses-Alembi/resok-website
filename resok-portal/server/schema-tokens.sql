-- Attendance and KMPDC token distribution.
--
-- Three tables, and the shape of them matters:
--
-- event_attendees is deliberately NOT keyed on member_profile_id. Non-members attend these
-- events, earn the same KMPDC points, and until now could not be recorded at all - the old
-- event_registrations table had member_profile_id NOT NULL. An attendee here is a person,
-- with the member link filled in only when there is one.
--
-- cpd_tokens holds the codes generated on the KMPDC portal. They are encrypted at rest: a
-- table of unissued CPD tokens is worth stealing, and it is exactly the shape of data
-- lib/crypto.php exists for.
--
-- token_access_codes is the six-digit code emailed to a collector. Stored hashed, because a
-- readable one in the database is a way into somebody else's token.
--
-- Run once in phpMyAdmin. Safe to run more than once.

CREATE TABLE IF NOT EXISTS event_attendees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,

  -- Present for members, NULL for everyone else. Both kinds of attendee are otherwise
  -- treated identically, which is the whole point of recording it this way.
  member_profile_id INT UNSIGNED NULL,

  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  -- What a KMPDC return is keyed on. Not always known at sign-in, so it is nullable and
  -- the admin screen reports who is missing one.
  kmpdc_number VARCHAR(60) NULL,
  phone VARCHAR(30) NULL,

  channel ENUM('in_person','online','unknown') NOT NULL DEFAULT 'unknown',
  minutes_attended SMALLINT UNSIGNED NULL,
  attended TINYINT(1) NOT NULL DEFAULT 0,
  attendance_method ENUM('register','zoom_report','venue_code','manual') NULL,
  confirmed_at DATETIME NULL,
  confirmed_by INT UNSIGNED NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- One row per person per event. A hybrid attendee who is in the room and also signed in
  -- on Zoom is one attendee, not two, and must not be able to collect two tokens.
  UNIQUE KEY event_attendees_unique (event_id, email),
  KEY event_attendees_event (event_id, attended),
  KEY event_attendees_member (member_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpd_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,

  -- Encrypted when a data_encryption_key is configured; stored as typed otherwise, the same
  -- rule the rest of the system follows. Wide enough for the encrypted form.
  token_value VARCHAR(255) NOT NULL,
  -- Last four characters of the plaintext, kept readable so an admin can tell two tokens
  -- apart on screen without the system ever having to decrypt and display the whole thing.
  token_hint VARCHAR(8) NULL,

  status ENUM('unissued','assigned','collected','void') NOT NULL DEFAULT 'unissued',
  attendee_id INT UNSIGNED NULL,
  assigned_at DATETIME NULL,
  collected_at DATETIME NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- One token per attendee, enforced by the database rather than by remembering to check.
  UNIQUE KEY cpd_tokens_attendee (attendee_id),
  KEY cpd_tokens_event (event_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_access_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendee_id INT UNSIGNED NOT NULL,

  -- Hashed. A readable code in the database is a way into someone else's token.
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  -- Counted so a six-digit code cannot be worked through by guessing.
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY token_access_attendee (attendee_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
