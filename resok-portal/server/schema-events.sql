-- Events: CMEs, webinars, conferences and workshops.
--
-- Replaces eventCatalog() in api/index.php, which held three events hardcoded in PHP - so
-- adding one meant editing code and redeploying, and the public site and the member portal
-- drifted into advertising different events. One row here now feeds both, and later the
-- attendance register and the KMPDC token assignment.
--
-- Run once in phpMyAdmin against the portal database. Safe to run more than once.

CREATE TABLE IF NOT EXISTS cpd_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Stable, URL-safe name. Used in links, so it must not change once published.
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(200) NOT NULL,
  summary VARCHAR(500) NULL,
  description TEXT NULL,

  event_type ENUM('CME','Webinar','Conference','Workshop','Symposium') NOT NULL DEFAULT 'CME',
  format ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'online',

  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  venue VARCHAR(200) NULL,

  -- The joining link. Never returned by the public endpoint - it goes only to people who
  -- have registered, otherwise the paid session is one search result away from being free.
  online_url VARCHAR(500) NULL,

  -- Whole shillings. Non-members usually pay more, and they are a real audience here:
  -- they attend, they earn KMPDC points, and they are the warmest membership leads there are.
  member_fee INT UNSIGNED NOT NULL DEFAULT 0,
  nonmember_fee INT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  capacity INT UNSIGNED NULL,

  -- KMPDC accreditation. Without an approval reference an event may still be published,
  -- but it is shown as "points pending approval" rather than promising credit that cannot
  -- be delivered. Points are decimal because half points exist.
  regulator VARCHAR(40) NOT NULL DEFAULT 'KMPDC',
  approval_ref VARCHAR(80) NULL,
  approved_points DECIMAL(4,1) NULL,

  -- Share of the session that must be attended before a token is released. Zoom reports
  -- give exact minutes, so this is a fact that can be defended in an audit rather than a
  -- judgement call.
  min_attendance_pct TINYINT UNSIGNED NOT NULL DEFAULT 80,

  status ENUM('draft','published','closed','cancelled') NOT NULL DEFAULT 'draft',
  banner_image VARCHAR(255) NULL,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY cpd_events_slug (slug),
  KEY cpd_events_listing (status, starts_at),
  KEY cpd_events_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
