-- Abstract submission (KISLHC): tables for lib/abstracts.php.
--
-- The module creates these itself on first use; this file is for the admin Migrations page
-- or phpMyAdmin when the database user cannot CREATE. Keep it in step with abstractsSchema().
-- Safe to re-run: every statement is CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS abs_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    name VARCHAR(200) NOT NULL,
    ref_prefix VARCHAR(20) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Nairobi',
    starts_on DATE NULL,
    ends_on DATE NULL,
    venue VARCHAR(200) NULL,
    call_opens_at DATETIME NULL,
    call_closes_at DATETIME NULL,
    review_deadline DATETIME NULL,
    settings TEXT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_events_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_tracks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY abs_tracks_event (event_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_profiles (
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(30) NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    affiliation VARCHAR(200) NOT NULL,
    country VARCHAR(60) NOT NULL,
    orcid VARCHAR(19) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_abstracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    submitter_user_id INT UNSIGNED NOT NULL,
    reference VARCHAR(40) NULL,
    track_id INT UNSIGNED NULL,
    title VARCHAR(400) NOT NULL DEFAULT '',
    body MEDIUMTEXT NULL,
    sections TEXT NULL,
    keywords TEXT NULL,
    preferred_type VARCHAR(30) NULL,
    final_type VARCHAR(30) NULL,
    status ENUM('draft','submitted','under_review','decision_pending','accepted',
                'waitlisted','rejected','withdrawn','confirmed') NOT NULL DEFAULT 'draft',
    declarations TEXT NULL,
    reopened_until DATETIME NULL,
    submitted_at DATETIME NULL,
    withdrawn_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_abstracts_reference (reference),
    KEY abs_abstracts_event (event_id, status),
    KEY abs_abstracts_submitter (submitter_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_authors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    abstract_id INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    affiliation VARCHAR(200) NOT NULL DEFAULT '',
    country VARCHAR(60) NULL,
    is_presenting TINYINT(1) NOT NULL DEFAULT 0,
    is_corresponding TINYINT(1) NOT NULL DEFAULT 0,
    user_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY abs_authors_abstract (abstract_id, sort_order),
    KEY abs_authors_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    abstract_id INT UNSIGNED NOT NULL,
    snapshot MEDIUMTEXT NOT NULL,
    saved_by INT UNSIGNED NULL,
    saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY abs_versions_abstract (abstract_id, saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('reviewer','track_chair','programme_chair','administrator') NOT NULL,
    track_id INT UNSIGNED NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_roles_unique (event_id, user_id, role, track_id),
    KEY abs_roles_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NULL,
    abstract_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    detail TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY abs_audit_abstract (abstract_id, created_at),
    KEY abs_audit_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_emails (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NULL,
    abstract_id INT UNSIGNED NULL,
    template VARCHAR(40) NOT NULL,
    to_email VARCHAR(190) NOT NULL,
    subject VARCHAR(300) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY abs_emails_abstract (abstract_id),
    KEY abs_emails_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
