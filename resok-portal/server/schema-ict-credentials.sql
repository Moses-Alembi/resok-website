-- ICT credential register.
--
-- Deliberately NOT a password vault. There is no column here that holds a secret, and there
-- is not meant to be one.
--
-- The reasoning, recorded because it will be questioned later: the encryption key would have
-- to live in config.local.php on the same server as the database it protects. That trade is
-- proportionate for a national ID number. It is not proportionate for the domain registrar
-- login - compromise one internet-facing PHP application and an attacker takes the encrypted
-- vault and the key together, and with them the domain, the hosting, the database and the
-- email in a single motion.
--
-- Scattered across notebooks and WhatsApp is bad, but it is bad in a distributed way. One
-- key on the most exposed machine the organisation owns concentrates the risk rather than
-- reducing it. The secrets belong in a dedicated password manager whose whole threat model
-- is built for this; what was actually missing was knowing which accounts exist at all.
--
-- So this answers: what accounts do we have, who owns each one, is two-factor on, when was
-- the password last changed, and where is it kept. That is the part nobody had.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS ict_credentials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  name VARCHAR(160) NOT NULL,
  kind ENUM('domain','hosting','server','database','email','cms','repository',
            'api_key','ssh_key','payment','social','service','other')
       NOT NULL DEFAULT 'service',
  provider VARCHAR(160) NULL,

  -- The username or account number. A login name is not a secret - it is on every invoice
  -- and in every support email - and without it the register cannot say which account it
  -- means. The password is not here and never will be.
  account_identifier VARCHAR(190) NULL,
  console_url VARCHAR(500) NULL,

  -- Where the actual secret is kept. A link into Bitwarden, 1Password or whatever is in use,
  -- so this register is one click from the credential without ever holding it.
  vault_url VARCHAR(500) NULL,
  vault_reference VARCHAR(160) NULL,

  -- Who is responsible, and who to ask when they are unreachable. The second one is the
  -- column that matters at 2am on a Sunday.
  owner_user_id INT UNSIGNED NULL,
  owner_name VARCHAR(160) NULL,
  backup_contact VARCHAR(160) NULL,

  -- Whether two-factor is switched on for the account itself. Recorded because "which of our
  -- critical accounts still has no second factor" is a real question the register can answer
  -- without holding a single password.
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mfa_notes VARCHAR(200) NULL,

  criticality ENUM('critical','high','normal') NOT NULL DEFAULT 'normal',

  last_rotated_on DATE NULL,
  -- Zero means no rotation schedule. next_rotation_on is derived, not typed, so it cannot
  -- disagree with the interval.
  rotation_months TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_rotation_on DATE NULL,

  status ENUM('active','retired') NOT NULL DEFAULT 'active',
  notes TEXT NULL,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ict_credentials_kind (kind, status),
  KEY ict_credentials_rotation (next_rotation_on),
  KEY ict_credentials_criticality (criticality, mfa_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Who went to fetch which credential.
--
-- The register holds no secrets, so reading it is not sensitive. Following the link to the
-- vault is - that is the moment somebody goes to collect a real password. Recording it gives
-- the audit trail the brief asked for without the portal ever holding the credential.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_credential_access (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  reason VARCHAR(200) NULL,
  client_hash CHAR(64) NULL,
  accessed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ict_credential_access_cred (credential_id, accessed_at),
  KEY ict_credential_access_user (user_id, accessed_at),
  CONSTRAINT ict_credential_access_fk FOREIGN KEY (credential_id)
    REFERENCES ict_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
