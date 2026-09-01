

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 1,
  verification_token VARCHAR(128) NULL,
  reset_token VARCHAR(128) NULL,
  reset_expires DATETIME NULL,
  role ENUM('member', 'admin') NOT NULL DEFAULT 'member',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email),
  KEY users_reset_token_idx (reset_token),
  KEY users_verification_token_idx (verification_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(50) NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  surname VARCHAR(100) NOT NULL,
  country VARCHAR(100) NULL,
  county VARCHAR(100) NULL,
  division VARCHAR(100) NULL,
  profession VARCHAR(160) NULL,
  specialization VARCHAR(160) NULL,
  institution VARCHAR(190) NULL,
  physical_address VARCHAR(255) NULL,
  payer_type ENUM('Individual', 'Organization') NOT NULL DEFAULT 'Individual',
  category VARCHAR(160) NULL,
  id_type VARCHAR(40) NULL,
  id_number VARCHAR(100) NULL,
  mobile VARCHAR(30) NOT NULL,
  profile_image VARCHAR(255) NULL,
  membership_status ENUM('payment_required', 'under_review', 'active', 'rejected', 'expired') NOT NULL DEFAULT 'payment_required',
  membership_id VARCHAR(80) NULL,
  cpd_points INT NOT NULL DEFAULT 0,
  renewal_due DATE NULL,
  review_reason TEXT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY member_profiles_user_unique (user_id),
  UNIQUE KEY member_profiles_membership_unique (membership_id),
  CONSTRAINT member_profiles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  member_profile_id INT UNSIGNED NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  method VARCHAR(80) NOT NULL DEFAULT 'M-Pesa Paybill',
  payment_type VARCHAR(120) NOT NULL DEFAULT 'Membership Registration',
  phone VARCHAR(30) NULL,
  status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
  reference VARCHAR(120) NOT NULL,
  provider_reference VARCHAR(120) NULL,
  proof_filename VARCHAR(255) NULL,
  proof_original_name VARCHAR(255) NULL,
  proof_mime_type VARCHAR(120) NULL,
  proof_file_size INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payments_reference_unique (reference),
  UNIQUE KEY payments_provider_reference_unique (provider_reference),
  KEY payments_user_idx (user_id),
  KEY payments_member_profile_idx (member_profile_id),
  CONSTRAINT payments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT payments_member_profile_fk FOREIGN KEY (member_profile_id) REFERENCES member_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also created lazily by the PHP API (ensureCpdTable/ensureAuditTable in index.php)
-- if this script hasn't been re-run against an existing database.
CREATE TABLE IF NOT EXISTS cpd_activities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_profile_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  points INT NOT NULL DEFAULT 0,
  occurred_on DATE NULL,
  added_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY cpd_activities_member_idx (member_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_profile_id INT UNSIGNED NOT NULL,
  event_id VARCHAR(60) NOT NULL,
  event_title VARCHAR(160) NOT NULL,
  cpd_points INT NOT NULL DEFAULT 0,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY event_registrations_unique (member_profile_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_actions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target_member_profile_id INT UNSIGNED NULL,
  reason TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY admin_actions_target_idx (target_member_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
