-- Final roster target: 34 staff voters. Preserve metadata for elections that have opened.
-- This does not create accounts, change staff numbers, or rewrite a frozen electoral roll.
ALTER TABLE welfare_settings ALTER COLUMN staff_target SET DEFAULT 34;

UPDATE welfare_settings w
JOIN elections e ON e.id=w.election_id
SET w.staff_target=34
WHERE e.opened_at IS NULL;
