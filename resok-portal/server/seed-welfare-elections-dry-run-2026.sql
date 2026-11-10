-- One-time draft for the 2026 welfare election dry run.
--
-- Run only after the election, nomination, staff-electorate and welfare-automation
-- schemas are applied. This seed creates a draft and five one-seat posts. It does not
-- create nominees, draw the staff roll, configure automatic welfare stages, or arm the
-- schedule. Complete those steps in election-admin after reviewing the active staff list.
-- The migration runner recognizes the seed- prefix and will not run this from Apply all.

INSERT INTO elections
  (slug, title, description, electorate, eligibility_cutoff,
   nominations_open_at, nominations_close_at, opens_at, closes_at, status, created_by)
SELECT
  'welfare-elections-dry-run-2026',
  'Welfare Elections Dry Run',
  'Dry run of the welfare election process. Active staff on the frozen roll may nominate candidates for each post.',
  'staff',
  '2026-11-20',
  '2026-11-20 07:00:00',
  '2026-11-20 17:00:00',
  '2026-11-20 17:00:00',
  '2026-11-21 17:00:00',
  'draft',
  NULL
WHERE NOT EXISTS (
  SELECT 1 FROM elections WHERE slug = 'welfare-elections-dry-run-2026'
);

INSERT INTO election_positions
  (election_id, title, description, seats, max_choices, allow_abstain, position)
SELECT e.id, 'Chairperson', NULL, 1, 1, 1, 1
FROM elections e
WHERE e.slug = 'welfare-elections-dry-run-2026'
  AND NOT EXISTS (SELECT 1 FROM election_positions p WHERE p.election_id = e.id AND p.title = 'Chairperson');

INSERT INTO election_positions
  (election_id, title, description, seats, max_choices, allow_abstain, position)
SELECT e.id, 'Vice Chairperson', NULL, 1, 1, 1, 2
FROM elections e
WHERE e.slug = 'welfare-elections-dry-run-2026'
  AND NOT EXISTS (SELECT 1 FROM election_positions p WHERE p.election_id = e.id AND p.title = 'Vice Chairperson');

INSERT INTO election_positions
  (election_id, title, description, seats, max_choices, allow_abstain, position)
SELECT e.id, 'Secretary General', NULL, 1, 1, 1, 3
FROM elections e
WHERE e.slug = 'welfare-elections-dry-run-2026'
  AND NOT EXISTS (SELECT 1 FROM election_positions p WHERE p.election_id = e.id AND p.title = 'Secretary General');

INSERT INTO election_positions
  (election_id, title, description, seats, max_choices, allow_abstain, position)
SELECT e.id, 'Vice Secretary', NULL, 1, 1, 1, 4
FROM elections e
WHERE e.slug = 'welfare-elections-dry-run-2026'
  AND NOT EXISTS (SELECT 1 FROM election_positions p WHERE p.election_id = e.id AND p.title = 'Vice Secretary');

INSERT INTO election_positions
  (election_id, title, description, seats, max_choices, allow_abstain, position)
SELECT e.id, 'Treasurer', NULL, 1, 1, 1, 5
FROM elections e
WHERE e.slug = 'welfare-elections-dry-run-2026'
  AND NOT EXISTS (SELECT 1 FROM election_positions p WHERE p.election_id = e.id AND p.title = 'Treasurer');
