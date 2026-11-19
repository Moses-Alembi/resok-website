-- Staff nominations are individual acts; the ballot contains each candidate only once.
-- Apply before deploying the matching elections.php change. Existing candidates, votes,
-- roll entries, election dates and statuses are preserved. Safe to apply more than once.
CREATE TABLE IF NOT EXISTS election_staff_nominations (
  position_id INT UNSIGNED NOT NULL,
  nominator_user_id INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  nominated_at DATETIME NOT NULL,
  PRIMARY KEY (position_id, nominator_user_id),
  KEY election_staff_nominations_candidate (candidate_id),
  CONSTRAINT election_staff_nominations_position_fk FOREIGN KEY (position_id)
    REFERENCES election_positions(id) ON DELETE CASCADE,
  CONSTRAINT election_staff_nominations_user_fk FOREIGN KEY (nominator_user_id)
    REFERENCES users(id),
  CONSTRAINT election_staff_nominations_candidate_fk FOREIGN KEY (candidate_id)
    REFERENCES election_candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the first nominators of candidates created by the previous implementation.
-- Stable account IDs keep their final nomination attached if roll IDs later change.
INSERT IGNORE INTO election_staff_nominations
  (position_id, nominator_user_id, candidate_id, nominated_at)
SELECT c.position_id, r.user_id, c.id, COALESCE(c.nominated_at, c.created_at)
FROM election_candidates c
JOIN election_positions p ON p.id = c.position_id
JOIN elections e ON e.id = p.election_id AND e.electorate = 'staff'
JOIN election_roll r ON r.id = c.nominated_by_roll_id AND r.election_id = e.id
ORDER BY c.id;
