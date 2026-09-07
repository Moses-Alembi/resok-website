-- Past CPD activities.
--
-- Inserted as DRAFTS, so none of them appears on the public site until you publish it.
-- That is deliberate: the real date of each one is not recorded anywhere I can read, and
-- a wrong date on a public page is worse than no page.
--
-- BEFORE PUBLISHING, for each row:
--   1. Set the real starts_at. Every row currently carries 2025-01-01 09:00:00,
--      which is a placeholder and is not the date of anything.
--   2. Set approved_points and approval_ref from the KMPDC approval for that activity.
--      Both are left NULL here. A token cannot be collected for an activity with no
--      approval reference, which is the correct behaviour - not an oversight.
--   3. Change status to 'published'.
--
-- Easiest route: Admin -> Events & CMEs -> Edit on each row. Or edit here before running.
--
-- Safe to run more than once: the slug is unique, so a second run updates rather than
-- duplicating, and it will not overwrite dates or points you have already corrected.

INSERT INTO cpd_events (slug, title, event_type, format, starts_at, status, regulator)
VALUES
  ('previously-well-child-with-multiple-cystic-changes-in-the-lung', 'Previously well child with multiple cystic changes in the lung.', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('the-tb-and-lung-health-symposium-2025', 'The TB and Lung Health Symposium 2025', 'Symposium', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('tb-and-ipc', 'TB AND IPC', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('establishing-an-ebus-programme', 'Establishing an EBUS Programme.', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('basic-bronchoscopy-skills-workshop', 'Basic Bronchoscopy Skills Workshop', 'Workshop', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('child-and-other-difficult-paediatric-pulmonology-cases-an-adolescent-w', 'ChILD and other difficult paediatric pulmonology cases: An adolescent with frequent exacerbations of bronchiectasis.', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('interventional-pulmonology-ebus-workshop', 'Interventional Pulmonology EBUS Workshop', 'Workshop', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('obstructive-airways-disease', 'Obstructive Airways Disease', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('diagnosis-and-management-of-tb-in-children-and-adolescents', 'Diagnosis and management of TB in children and adolescents.', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC'),
  ('child-and-other-difficult-paediatric-pulmonology-cases-persistent-whee', 'ChILD and other difficult paediatric pulmonology cases: Persistent wheeze in a toddler.', 'CME', 'in_person', '2025-01-01 09:00:00', 'draft', 'KMPDC')
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  event_type = VALUES(event_type);
