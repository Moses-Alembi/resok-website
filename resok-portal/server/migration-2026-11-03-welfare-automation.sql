-- Explicitly armed welfare elections. Existing elections are never armed automatically.
CREATE TABLE IF NOT EXISTS welfare_settings (
  election_id INT UNSIGNED NOT NULL PRIMARY KEY,
  staff_target INT UNSIGNED NOT NULL DEFAULT 32,
  armed_at DATETIME NULL,
  configured_by INT UNSIGNED NULL,
  last_error VARCHAR(500) NULL,
  CONSTRAINT welfare_settings_election_fk FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Additional voters get ordinary portal accounts, never staff or administrative access.
CREATE TABLE IF NOT EXISTS welfare_guests (
  election_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  added_by INT UNSIGNED NULL,
  PRIMARY KEY (election_id, user_id),
  CONSTRAINT welfare_guests_election_fk FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
  CONSTRAINT welfare_guests_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS welfare_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  election_id INT UNSIGNED NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY welfare_events_recent (election_id, id),
  CONSTRAINT welfare_events_election_fk FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
