-- Webinar, 28 September 2026, 7 PM (Zoom):
-- "Infectious Lung Disorders: Spectrum of Surgical Treatment Options"
--
-- Paste the whole file into phpMyAdmin (SQL tab, resok portal database) and run it once.
-- Safe to run again: it updates the event in place and replaces its speaker list.
--
-- Published straight away, since the webinar is two days out. CPD points and the KMPDC
-- approval reference are left NULL until they are confirmed - the page then says "CPD
-- points pending accreditation" rather than claiming a number nobody has approved. Fill
-- them in from Admin -> Events & CMEs once KMPDC confirms.
--
-- Fees are 0 (the card reads "Free to attend"), because registration is an open Zoom
-- sign-up. Change member_fee / nonmember_fee below if there is a charge.

-- 1. The two schema additions this needs. Both are guarded, so a database that already
--    has them (from migration-event-speakers.sql) is left alone.
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

-- 2. The event.
INSERT INTO cpd_events
  (slug, title, summary, description, event_type, format, starts_at, ends_at, venue,
   registration_url, member_fee, nonmember_fee, currency, regulator, status, banner_image)
VALUES
  ('infectious-lung-disorders-surgical-treatment-options',
   'Infectious Lung Disorders: Spectrum of Surgical Treatment Options',
   'The range of surgical treatment options for infectious lung disorders, presented by thoracic surgeon Prof. Brig. Dr. Asif Asghar. Accredited CPD webinar on Zoom.',
   'A ReSoK webinar on infectious lung disorders and the spectrum of surgical treatment options available for them.

Presented by Prof. Brig. Dr. Asif Asghar, with Dr. Joseph Mutuku Mutie as panelist and Dr. Jacqueline Wanjiku Kagima moderating.',
   'Webinar', 'online', '2026-09-28 19:00:00', NULL, 'Zoom',
   'https://us06web.zoom.us/webinar/register/WN_h7igw1KZQDGkSSSG75xw2w',
   0, 0, 'KES', 'KMPDC', 'published',
   'assets/img/events/2026-09-28-infectious-lung-disorders/poster.jpg')
ON DUPLICATE KEY UPDATE
  title = VALUES(title), summary = VALUES(summary), description = VALUES(description),
  event_type = VALUES(event_type), format = VALUES(format), starts_at = VALUES(starts_at),
  venue = VALUES(venue), registration_url = VALUES(registration_url),
  status = VALUES(status), banner_image = VALUES(banner_image);

SET @event_id = (SELECT id FROM cpd_events WHERE slug = 'infectious-lung-disorders-surgical-treatment-options');

-- 3. Speakers, presenter first.
DELETE FROM cpd_event_speakers WHERE event_id = @event_id;

INSERT INTO cpd_event_speakers (event_id, sort_order, role, name, headline, bio, photo) VALUES
(@event_id, 1, 'Presenter', 'Prof. Brig. Dr. Asif Asghar',
 'Consultant Thoracic Surgeon, PAF Hospital, Islamabad',
 'Prof. Brig. Dr. Asif Asghar is a thoracic surgeon from Pakistan with over 14 years of specialized experience in the diagnosis and surgical management of complex chest conditions. He holds FCPS qualifications in General Surgery (1999) and Thoracic Surgery (2008), has advanced international training in VATS and Uniportal VATS, and has served as a CPSP-accredited supervisor and examiner since 2018.\n\nHe is Head of Department at PAF Hospital, Islamabad, and Consultant Thoracic Surgeon at Kulsum International Hospital, Islamabad. His expertise includes minimally invasive lung and chest surgery, lung resections, thoracic malignancies, and pleural, mediastinal and chest-wall procedures, along with training surgeons in minimally invasive techniques.\n\nProf. Asghar is currently in Nairobi and welcomes collaboration with hospitals and specialists on joint case management, developing Uniportal VATS services, demonstration surgeries, training and mentorship, CME sessions, and building sustainable thoracic surgery programmes.',
 'assets/img/events/2026-09-28-infectious-lung-disorders/asif-asghar.jpg'),
(@event_id, 2, 'Panelist', 'Dr. Joseph Mutuku Mutie',
 'Cardiothoracic & Vascular Surgeon',
 'Dr. Joseph Mutuku Mutie is a Kenyan Cardiothoracic and Vascular Surgeon with specialized expertise in advanced thoracic surgery, minimally invasive surgery, complex airway reconstruction, and aortic surgery.\n\nHe holds an MBChB and a Master of Medicine in Thoracic and Cardiovascular Surgery from the University of Nairobi. His postgraduate research focused on operative mortality among patients with thoracic aortic aneurysm and dissection at Kenyatta National Hospital.\n\nDr. Mutie has completed specialized fellowship training in tubeless uniportal thoracic surgery, developing advanced expertise in minimally invasive thoracic procedures. His professional training has included exposure to leading thoracic surgery centres in China, including experience in uniportal VATS.\n\nHis career is distinguished by several significant milestones in cardiothoracic surgery. He was part of the surgical team that performed Kenya’s first minimally invasive esophagectomy, an important milestone in the advancement of minimally invasive esophageal surgery in the country.\n\nHe has also performed the first tubeless tracheal reconstruction under spontaneous ventilation in Africa, pioneering an advanced non-intubated approach to complex airway reconstruction. In addition, Dr. Mutie has extensive experience in aortic arch replacement surgery, managing complex aortic conditions requiring advanced cardiothoracic intervention.\n\nHis clinical interests encompass tubeless uniportal thoracic surgery, minimally invasive esophageal surgery, tracheal and airway reconstruction, complex aortic surgery, aortic arch replacement, and advanced cardiothoracic surgical techniques.\n\nThrough his specialized training, international exposure, and experience with complex surgical procedures, Dr. Mutie continues to contribute to the advancement of modern cardiothoracic and minimally invasive thoracic surgery in Kenya and across Africa.',
 'assets/img/events/2026-09-28-infectious-lung-disorders/joseph-mutie.jpg'),
(@event_id, 3, 'Moderator', 'Jacqueline Wanjiku Kagima, MD, PhD',
 'Honourable Secretary & Director of Trainings, Respiratory Society of Kenya',
 NULL,
 'assets/img/events/2026-09-28-infectious-lung-disorders/wanjiku-kagima.jpg');
