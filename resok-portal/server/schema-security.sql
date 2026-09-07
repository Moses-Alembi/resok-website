-- Security tables: rate limiting and the security event log.
--
-- The API creates these itself on first use, but only if the database user holds CREATE.
-- On shared hosting it often does not, and the code deliberately degrades quietly rather
-- than breaking authentication - which means rate limiting can be switched off without
-- anyone noticing. Import this once and that cannot happen.
--
-- Run it in phpMyAdmin against the portal database. It is safe to run more than once.

CREATE TABLE IF NOT EXISTS auth_attempts (
  scope_key CHAR(64) NOT NULL,
  action VARCHAR(40) NOT NULL DEFAULT 'login',
  failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  first_failure_at DATETIME NOT NULL,
  last_failure_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  PRIMARY KEY (scope_key),
  KEY auth_attempts_locked (locked_until),
  KEY auth_attempts_action (action, last_failure_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records that something happened and roughly where, never who: a salted client hash, no
-- address and no email. That is what lets it be kept without becoming personal data.
CREATE TABLE IF NOT EXISTS security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(40) NOT NULL,
  action VARCHAR(40) NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  client_hash CHAR(64) NULL,
  user_id INT UNSIGNED NULL,
  detail VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY security_events_time (created_at),
  KEY security_events_type (event_type, created_at),
  KEY security_events_severity (severity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Two-factor columns. Added at runtime by mfaEnsureColumns(), which needs ALTER; the same
-- reasoning applies. MySQL has no ADD COLUMN IF NOT EXISTS, so a second run reports
-- "duplicate column" - that error is safe to ignore.
ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN mfa_enrolled_at DATETIME NULL;
ALTER TABLE users ADD COLUMN mfa_recovery TEXT NULL;

-- ---------------------------------------------------------------------------------------
-- Column widths for encryption at rest.
--
-- RUN THIS BEFORE setting data_encryption_key. Encrypting a value makes it longer - AES-GCM
-- adds an IV and an authentication tag, then base64 expands the result - so a 32-character
-- two-factor secret becomes about 87 characters and a 40-character ID number about 99.
-- Written into a column too narrow, MySQL truncates it without complaint and the value is
-- gone: an unreadable ID number, or a member who can no longer pass their second factor.
-- ---------------------------------------------------------------------------------------
ALTER TABLE users MODIFY COLUMN mfa_secret VARCHAR(255) NULL;
ALTER TABLE member_profiles MODIFY COLUMN id_number VARCHAR(255) NULL;
