-- Digital infrastructure: domain, hosting, SSL, backups, repository.
--
-- ONE table with a kind column, not five tables holding one row each.
--
-- The brief asks for separate Domain, Hosting and SSL sections, and the screens do show them
-- separately. But the organisation has one website. Five tables would mean five sets of
-- queries, five forms and five expiry checks to keep in step - to model what is really one
-- list of things that renew. One typed table gives the same screens from a quarter of the
-- code, and one expiry query that cannot miss a category because nobody remembered to add it
-- to the cron.
--
-- Nothing secret belongs here. account_ref is a username or account number, never a
-- password; credentials are phase 6 and a separate decision.
--
-- Run once in phpMyAdmin. Safe to run more than once.

CREATE TABLE IF NOT EXISTS ict_infrastructure (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  kind ENUM('domain','hosting','ssl','backup','repository','service') NOT NULL,
  name VARCHAR(160) NOT NULL,
  provider VARCHAR(160) NULL,
  -- The account this sits under: a username, customer number or reference. NOT a password.
  account_ref VARCHAR(160) NULL,
  url VARCHAR(500) NULL,

  starts_on DATE NULL,
  -- The one column the whole table exists for. A missed renewal here takes the site down.
  expires_on DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,

  cost DECIMAL(10,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  billing_cycle VARCHAR(30) NULL,

  owner_user_id INT UNSIGNED NULL,
  status ENUM('active','pending','expired','cancelled') NOT NULL DEFAULT 'active',

  -- Plan, nameservers, storage, frequency - whatever this kind of thing needs. Free text
  -- rather than thirty mostly-empty columns for the union of every provider's vocabulary.
  details TEXT NULL,
  notes TEXT NULL,

  -- For backups: when a restore was last actually tested. Knowing a backup exists is not
  -- knowing it works, and that distinction is the entire value of tracking backups at all.
  last_verified_on DATE NULL,

  -- Which alert band was last emailed, so a renewal ninety days out does not send the same
  -- warning every morning for ninety mornings. People stop reading those.
  last_alert_band VARCHAR(20) NULL,
  last_alert_on DATE NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ict_infra_kind (kind, status),
  KEY ict_infra_expiry (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
