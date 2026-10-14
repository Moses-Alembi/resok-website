-- Staff recruitment (lib/careers.php). The module creates these itself on first use; this
-- file is for phpMyAdmin. Keep it in step with careersSchema(). Safe to run again.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS careers_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(160) NOT NULL,
    department VARCHAR(120) NULL,
    location VARCHAR(120) NULL,
    employment_type VARCHAR(20) NOT NULL DEFAULT 'full_time',
    salary VARCHAR(120) NULL,
    summary VARCHAR(400) NULL,
    description MEDIUMTEXT NULL,
    requirements MEDIUMTEXT NULL,
    questions TEXT NULL,
    closes_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY careers_jobs_slug (slug),
    KEY careers_jobs_status (status, closes_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS careers_applications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    reference VARCHAR(20) NOT NULL,
    first_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(255) NULL,
    county VARCHAR(60) NULL,
    answers TEXT NULL,
    cover_note TEXT NULL,
    cv_file VARCHAR(80) NULL,
    cv_mime VARCHAR(80) NULL,
    cv_bytes INT UNSIGNED NULL,
    letter_file VARCHAR(80) NULL,
    letter_mime VARCHAR(80) NULL,
    letter_bytes INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    consent_at DATETIME NULL,
    purged_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY careers_applications_reference (reference),
    UNIQUE KEY careers_applications_once (job_id, email),
    KEY careers_applications_job (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS careers_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NULL,
    body TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY careers_notes_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS careers_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY careers_roles_user_role (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS careers_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NULL,
    application_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY careers_audit_application (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
