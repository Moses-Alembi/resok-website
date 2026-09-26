-- Speakers and a public registration link for events.
--
-- cpd_event_speakers: any number of speakers per event, in a set order (presenter first),
-- each with a role, name, one-line headline, bio and photo. A table rather than a column so
-- the order and count are free, and so the public events page can show them as people.
--
-- registration_url: a public sign-up page such as a Zoom webinar registration link. Unlike
-- online_url - the joining link, which is never shown publicly - this one is meant to be
-- clicked by anyone, so the event card's Register button goes straight to it.
--
-- Safe to run more than once: the migration runner treats "already exists" as done.

CREATE TABLE IF NOT EXISTS cpd_event_speakers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  role VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  headline VARCHAR(200) NULL,
  bio TEXT NULL,
  photo VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY cpd_event_speakers_event (event_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cpd_events
  ADD COLUMN registration_url VARCHAR(500) NULL AFTER online_url;
