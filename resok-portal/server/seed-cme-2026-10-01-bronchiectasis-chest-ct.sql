-- CME (Zoom webinar), Thursday 1 October 2026, 7 PM:
-- "Diagnosing Bronchiectasis on Chest CT Scan"
--
-- Paste the whole file into phpMyAdmin (SQL tab, resok portal database) and run it once.
-- Safe to run again: it updates the event in place and replaces its speaker list.
--
-- CPD points and the KMPDC approval reference are left NULL until confirmed; the card shows
-- no points until then. Fees are 0 ("Free to attend"): registration is an open Zoom sign-up.
--
-- Needs cpd_event_speakers and cpd_events.registration_url, which the 28 Sep webinar script
-- (or migration-event-speakers.sql) created. Both are guarded here as well.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpd_events' AND COLUMN_NAME = 'registration_url');
SET @ddl = IF(@has_col = 0,
              'ALTER TABLE cpd_events ADD COLUMN registration_url VARCHAR(500) NULL AFTER online_url',
              'SELECT 1');
PREPARE add_col FROM @ddl;
EXECUTE add_col;
DEALLOCATE PREPARE add_col;

INSERT INTO cpd_events
  (slug, title, summary, description, event_type, format, starts_at, ends_at, venue,
   registration_url, member_fee, nonmember_fee, currency, regulator, status, banner_image)
VALUES
  ('diagnosing-bronchiectasis-on-chest-ct-scan',
   'Diagnosing Bronchiectasis on Chest CT Scan',
   'How bronchiectasis is recognised on chest CT, presented by consultant radiologist Dr Maxwell Gachie. Accredited CPD CME on Zoom.',
   'A ReSoK CME on diagnosing bronchiectasis on chest CT scan.\n\nPresented by Dr Maxwell Gachie, Consultant Radiologist at Kenyatta National Hospital, and moderated by Dr Samuel Gathua, Consultant Physician and Pulmonologist.',
   'CME', 'online', '2026-10-01 19:00:00', NULL, 'Zoom',
   'https://us06web.zoom.us/webinar/register/WN_HXINn3MRQ7iwTCBt-Txorg',
   0, 0, 'KES', 'KMPDC', 'published',
   'assets/img/events/2026-10-01-bronchiectasis-chest-ct/poster-v2.jpg')
ON DUPLICATE KEY UPDATE
  title = VALUES(title), summary = VALUES(summary), description = VALUES(description),
  event_type = VALUES(event_type), format = VALUES(format), starts_at = VALUES(starts_at),
  venue = VALUES(venue), registration_url = VALUES(registration_url),
  status = VALUES(status), banner_image = VALUES(banner_image);

SET @event_id = (SELECT id FROM cpd_events WHERE slug = 'diagnosing-bronchiectasis-on-chest-ct-scan');

-- Speakers, presenter first.
DELETE FROM cpd_event_speakers WHERE event_id = @event_id;

INSERT INTO cpd_event_speakers (event_id, sort_order, role, name, headline, bio, photo) VALUES
(@event_id, 1, 'Presenter', 'Dr Maxwell Gachie',
 'Consultant Radiologist, Kenyatta National Hospital',
 NULL,
 'assets/img/events/2026-10-01-bronchiectasis-chest-ct/maxwell-gachie.jpg'),
(@event_id, 2, 'Moderator', 'Dr Samuel Gathua',
 'Consultant Physician / Pulmonologist',
 NULL,
 'assets/img/events/2026-10-01-bronchiectasis-chest-ct/samuel-gathua-v2.jpg');
