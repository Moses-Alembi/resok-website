-- Find a Specialist (lib/directory.php). The module creates this itself on first use; this file
-- is for phpMyAdmin. Keep it in step with directorySchema(). Safe to run again.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS directory_listings (
    member_profile_id INT UNSIGNED NOT NULL,
    listed TINYINT(1) NOT NULL DEFAULT 0,
    category VARCHAR(40) NULL,
    specialties VARCHAR(400) NULL,
    locations TEXT NULL,
    counties VARCHAR(300) NULL,
    public_phone VARCHAR(30) NULL,
    public_email VARCHAR(190) NULL,
    website VARCHAR(300) NULL,
    bio VARCHAR(800) NULL,
    languages VARCHAR(200) NULL,
    accepting_referrals TINYINT(1) NOT NULL DEFAULT 1,
    show_photo TINYINT(1) NOT NULL DEFAULT 0,
    hidden_by_admin TINYINT(1) NOT NULL DEFAULT 0,
    hidden_reason VARCHAR(300) NULL,
    hidden_by INT UNSIGNED NULL,
    consent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (member_profile_id),
    KEY directory_listings_visible (listed, hidden_by_admin, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
