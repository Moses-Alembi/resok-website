-- Software and licences.
--
-- What the organisation pays for, when it renews, how many seats there are and who is using
-- them. Reuses the renewal banding from ict_infrastructure, so an expiring licence reads the
-- same way as an expiring domain.
--
-- Two decisions carried over from earlier modules:
--
--   A licence key is a secret, so it is not stored here. Same reasoning as the credential
--   register: the key would sit on the same server as the key protecting it. There is a
--   vault link instead.
--
--   Seats are append-only history, like asset assignments. Who has a seat now is the row
--   with no release date, and the person who had it last year stays on the record - which is
--   what makes "we are paying for ten and using four" answerable.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS ict_licenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  name VARCHAR(160) NOT NULL,
  vendor VARCHAR(160) NULL,
  kind ENUM('subscription','perpetual','open_source','trial','oem') NOT NULL DEFAULT 'subscription',
  category VARCHAR(80) NULL,

  -- Where the key or account is kept. Not the key itself.
  vault_url VARCHAR(500) NULL,
  vault_reference VARCHAR(160) NULL,
  console_url VARCHAR(500) NULL,
  account_identifier VARCHAR(190) NULL,

  -- Zero means unlimited or not seat-based, which is true of most open-source and some
  -- site licences. Used seats are counted from the seat table, never stored here, so the
  -- two cannot disagree.
  seats_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  purchased_on DATE NULL,
  expires_on DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  cost DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  billing_cycle VARCHAR(30) NULL,

  owner_user_id INT UNSIGNED NULL,
  owner_name VARCHAR(160) NULL,
  status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  notes TEXT NULL,

  -- Which alert band was last emailed, so a renewal ninety days out does not send the same
  -- warning every morning. Same approach as ict_infrastructure.
  last_alert_band VARCHAR(20) NULL,
  last_alert_on DATE NULL,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ict_licenses_status (status, expires_on),
  KEY ict_licenses_expiry (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Seat allocation.
--
-- Append-only, like asset assignments. A released seat keeps its row, so the question
-- "who had this last year" survives, and so does "are we paying for seats nobody uses".
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_license_seats (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id INT UNSIGNED NOT NULL,

  holder_user_id INT UNSIGNED NULL,
  holder_name VARCHAR(160) NOT NULL,
  holder_email VARCHAR(190) NULL,
  department VARCHAR(120) NULL,

  assigned_at DATETIME NOT NULL,
  assigned_by INT UNSIGNED NULL,
  released_at DATETIME NULL,
  released_by INT UNSIGNED NULL,
  notes VARCHAR(300) NULL,

  PRIMARY KEY (id),
  KEY ict_license_seats_license (license_id, released_at),
  KEY ict_license_seats_email (holder_email),
  CONSTRAINT ict_license_seats_fk FOREIGN KEY (license_id)
    REFERENCES ict_licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
