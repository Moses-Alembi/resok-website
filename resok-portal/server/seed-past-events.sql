-- Past CPD activities, with their real dates.
--
-- Inserted as DRAFTS. Two things are still missing from every row, and both are facts
-- nobody here can supply:
--
--   approved_points  - the CPD points KMPDC approved for the activity
--   approval_ref     - the KMPDC approval reference
--
-- They are left NULL rather than guessed. An invented approval reference on a public
-- page is a false accreditation claim. While they are NULL the collect-token button
-- does not appear, which is correct: there is nothing to collect until KMPDC has
-- approved the activity and tokens exist for it.
--
-- Fill both in, then change status to published. Admin -> Events & CMEs -> Edit.
--
-- Re-running this file resets the title, type and DATE of each row - so it corrects the
-- placeholder dates from the first version. It leaves points, approval_ref and status
-- alone, so anything you have filled in by hand survives.

INSERT INTO cpd_events (slug, title, event_type, format, starts_at, status, regulator)
VALUES
  ('previously-well-child-with-multiple-cystic-changes-in-the-lung', 'Previously well child with multiple cystic changes in the lung.', 'CME', 'in_person', '2025-10-28 09:00:00', 'draft', 'KMPDC'),
  ('the-tb-and-lung-health-symposium-2025', 'The TB and Lung Health Symposium 2025', 'Symposium', 'in_person', '2025-09-30 09:00:00', 'draft', 'KMPDC'),
  ('tb-and-ipc', 'TB AND IPC', 'CME', 'in_person', '2025-08-28 09:00:00', 'draft', 'KMPDC'),
  ('establishing-an-ebus-programme', 'Establishing an EBUS Programme.', 'CME', 'in_person', '2025-08-05 09:00:00', 'draft', 'KMPDC'),
  ('basic-bronchoscopy-skills-workshop', 'Basic Bronchoscopy Skills Workshop', 'Workshop', 'in_person', '2025-08-06 09:00:00', 'draft', 'KMPDC'),
  ('child-and-other-difficult-paediatric-pulmonology-cases-an-adolescent-w', 'ChILD and other difficult paediatric pulmonology cases: An adolescent with frequent exacerbations of bronchiectasis.', 'CME', 'in_person', '2025-07-30 09:00:00', 'draft', 'KMPDC'),
  ('interventional-pulmonology-ebus-workshop', 'Interventional Pulmonology EBUS Workshop', 'Workshop', 'in_person', '2025-08-07 09:00:00', 'draft', 'KMPDC'),
  ('obstructive-airways-disease', 'Obstructive Airways Disease', 'CME', 'in_person', '2025-04-12 09:00:00', 'draft', 'KMPDC'),
  ('diagnosis-and-management-of-tb-in-children-and-adolescents', 'Diagnosis and management of TB in children and adolescents.', 'CME', 'in_person', '2025-07-08 09:00:00', 'draft', 'KMPDC'),
  ('child-and-other-difficult-paediatric-pulmonology-cases-persistent-whee', 'ChILD and other difficult paediatric pulmonology cases: Persistent wheeze in a toddler.', 'CME', 'in_person', '2025-06-24 09:00:00', 'draft', 'KMPDC')
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  event_type = VALUES(event_type),
  starts_at = VALUES(starts_at);
