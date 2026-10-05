-- Abstract submission (KISLHC): tables for lib/abstracts.php, lib/abstracts-review.php and
-- lib/abstracts-decisions.php.
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
    status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    error VARCHAR(500) NULL,
    text_body MEDIUMTEXT NULL,
    html_body MEDIUMTEXT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dedupe_key VARCHAR(160) NULL,
    sent_at DATETIME NULL,
    next_attempt_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_emails_dedupe (dedupe_key),
    KEY abs_emails_queue (status, id),
    KEY abs_emails_abstract (abstract_id),
    KEY abs_emails_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_reviewers (
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    tracks TEXT NULL,
    expertise VARCHAR(500) NULL,
    max_load SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(160) NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
    invited_by INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY abs_invitations_token (token_hash),
    KEY abs_invitations_event (event_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    abstract_id INT UNSIGNED NOT NULL,
    reviewer_user_id INT UNSIGNED NOT NULL,
    status ENUM('assigned','in_progress','submitted','declined','cancelled') NOT NULL DEFAULT 'assigned',
    scores TEXT NULL,
    total DECIMAL(5,2) NULL,
    comments_authors TEXT NULL,
    comments_committee TEXT NULL,
    recommendation VARCHAR(20) NULL,
    recommended_type VARCHAR(30) NULL,
    decline_reason VARCHAR(500) NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_reviews_pair (abstract_id, reviewer_user_id),
    KEY abs_reviews_reviewer (reviewer_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    template VARCHAR(40) NOT NULL,
    subject VARCHAR(250) NOT NULL,
    body TEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY abs_templates_key (event_id, template)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abs_decisions (
    abstract_id INT UNSIGNED NOT NULL,
    outcome ENUM('accept','reject','waitlist') NULL,
    presentation_type VARCHAR(30) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    recommended_outcome VARCHAR(20) NULL,
    recommended_type VARCHAR(30) NULL,
    recommended_by INT UNSIGNED NULL,
    recommended_at DATETIME NULL,
    note VARCHAR(1000) NULL,
    released_at DATETIME NULL,
    released_by INT UNSIGNED NULL,
    attendance ENUM('pending','confirmed','declined') NULL,
    attendance_at DATETIME NULL,
    PRIMARY KEY (abstract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
