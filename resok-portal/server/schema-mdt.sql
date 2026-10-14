-- National ILD MDT case submission (lib/mdt.php). The module creates these itself on first
-- use; this file is for phpMyAdmin. Keep it in step with mdtSchema(). Safe to run again.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY mdt_roles_user_role (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_meetings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    meets_at DATETIME NOT NULL,
    venue VARCHAR(160) NULL,
    chair_name VARCHAR(160) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY mdt_meetings_when (meets_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_cases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(20) NULL,
    submitter_user_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    urgency VARCHAR(10) NOT NULL DEFAULT 'routine',
    preferred_meeting_id INT UNSIGNED NULL,
    meeting_id INT UNSIGNED NULL,
    age_years SMALLINT UNSIGNED NULL,
    sex VARCHAR(10) NULL,
    county VARCHAR(60) NULL,
    clinician_name VARCHAR(160) NULL,
    clinician_council VARCHAR(80) NULL,
    clinician_reg_no VARCHAR(40) NULL,
    facility VARCHAR(200) NULL,
    consent_basis VARCHAR(20) NULL,
    consent_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    no_identifiers_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    content MEDIUMTEXT NULL,
    decline_reason TEXT NULL,
    submitted_at DATETIME NULL,
    closed_at DATETIME NULL,
    purge_after DATE NULL,
    purged_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY mdt_cases_reference (reference),
    KEY mdt_cases_submitter (submitter_user_id),
    KEY mdt_cases_status (status),
    KEY mdt_cases_meeting (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id INT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL,
    file VARCHAR(80) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    caption VARCHAR(300) NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY mdt_files_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY mdt_messages_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_recommendations (
    case_id INT UNSIGNED NOT NULL,
    diagnosis VARCHAR(200) NULL,
    confidence VARCHAR(20) NULL,
    pattern VARCHAR(20) NULL,
    content MEDIUMTEXT NULL,
    chair_name VARCHAR(160) NULL,
    issued_by INT UNSIGNED NULL,
    issued_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mdt_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY mdt_audit_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
