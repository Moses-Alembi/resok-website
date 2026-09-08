-- ICT operations: roles, capabilities and the ICT audit trail.
--
-- Phase 1 of extending the Super Admin into an ICT operations centre. Nothing here changes
-- how any existing account behaves.
--
-- Run once in phpMyAdmin. Safe to run more than once.

-- ---------------------------------------------------------------------------------------
-- A third role.
--
-- Until now role was ENUM('member','admin'), so the only way to give an ICT officer access
-- to anything administrative was to make them an admin - which hands them every member's
-- national ID number, every payment record, and the power to approve or reject a
-- membership. An ICT officer needs none of that, and under the Kenya Data Protection Act
-- granting it is a decision that would have to be defended.
--
-- Adding a value to an ENUM is non-breaking: every existing row keeps the role it has, and
-- requireAdmin() still tests for 'admin' exactly, so an 'ict' account is refused everywhere
-- an admin is required.
-- ---------------------------------------------------------------------------------------
-- IMPORTANT: users.role is declared in schema.sql, schema-ict.sql and schema-blog.sql,
-- and all three must list the SAME complete set. MySQL does not reject a row holding a
-- value a redefined enum no longer has - it rewrites it to the empty string, silently.
-- That is how every ICT account lost its role and, with it, every permission it held.
-- Adding a role means adding it to all three.
ALTER TABLE users MODIFY COLUMN role ENUM('member','author','editor','content_manager','analytics_manager','ict','admin') NOT NULL DEFAULT 'member';

-- ---------------------------------------------------------------------------------------
-- Capabilities, as rows rather than columns.
--
-- Eighteen boolean columns would mean a schema migration every time a nineteenth is needed.
-- A row per grant makes that an INSERT, and makes "what can this person do" a single query
-- that is also the answer to "who can do this".
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_capabilities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  capability VARCHAR(40) NOT NULL,
  granted_by INT UNSIGNED NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Granting the same capability twice is a no-op rather than a duplicate row.
  UNIQUE KEY ict_capabilities_unique (user_id, capability),
  KEY ict_capabilities_cap (capability),
  CONSTRAINT ict_capabilities_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- The ICT audit trail.
--
-- Kept separate from admin_actions rather than widening it. admin_actions.target is
-- target_member_profile_id - an integer that means one specific thing, and that table is
-- evidence about who touched member data. An ICT action targets an asset, a domain or a
-- credential, so it needs a polymorphic target and before/after values.
--
-- Append-only. Nothing in the application ever updates or deletes a row here; on a host
-- where the database user can be restricted, withhold UPDATE and DELETE on this table.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id VARCHAR(60) NULL,
  summary VARCHAR(300) NULL,
  -- Only the fields that changed, as JSON. Enough to answer "what did it say before" without
  -- storing a copy of every record on every edit.
  before_json TEXT NULL,
  after_json TEXT NULL,
  -- Salted hash, never an address: enough to tell two sessions apart, not enough to be
  -- personal data in its own right. Same approach as security_events.
  client_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ict_audit_time (created_at),
  KEY ict_audit_actor (actor_user_id, created_at),
  KEY ict_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
