-- ICT assets, assignments and maintenance.
--
-- The largest ICT module, and the one replacing the most spreadsheets: what equipment the
-- organisation owns, who is holding each piece, and what has been done to it.
--
-- Two shapes matter here.
--
-- Assignments are append-only history, not a field on the asset. "Who has this laptop" is
-- the newest open row, and every previous holder stays on the record. A current_holder
-- column would answer the first question and destroy the answer to "who had it when it was
-- damaged" - which is the question that actually gets asked.
--
-- An assignee is a person, not necessarily a user account. Cleaners, drivers and interns are
-- issued equipment and will never have a portal login, so the name and department are stored
-- directly, with a user link filled in only when there is one.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS ict_assets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- What is printed on the sticker and encoded in the QR code, e.g. ICT-LAP-0042. Unique,
  -- because it is how a physical object is matched to this row during an audit.
  asset_tag VARCHAR(40) NOT NULL,

  category ENUM('laptop','desktop','monitor','tablet','phone','printer','scanner',
                'projector','server','router','switch','access_point','ups',
                'cctv','storage','accessory','other') NOT NULL DEFAULT 'other',
  name VARCHAR(160) NOT NULL,
  manufacturer VARCHAR(120) NULL,
  model VARCHAR(120) NULL,
  serial_number VARCHAR(120) NULL,
  specifications TEXT NULL,

  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  supplier VARCHAR(160) NULL,
  -- The date, not the term. A term needs the purchase date to mean anything, and the date
  -- is what an expiry check can actually use.
  warranty_expires_on DATE NULL,

  `condition` ENUM('new','good','fair','poor','damaged') NOT NULL DEFAULT 'good',
  status ENUM('available','assigned','maintenance','damaged','lost','retired','disposed')
         NOT NULL DEFAULT 'available',

  location VARCHAR(160) NULL,
  department VARCHAR(120) NULL,
  notes TEXT NULL,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY ict_assets_tag (asset_tag),
  KEY ict_assets_status (status, category),
  KEY ict_assets_serial (serial_number),
  KEY ict_assets_warranty (warranty_expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Assignment history.
--
-- One row per handover. The open row - returned_at IS NULL - is who holds it now. Rows are
-- never edited to reassign; a return closes one and a new assignment opens another, so the
-- chain of custody stays intact.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,

  -- Filled in when the holder has a portal account; the name and department are recorded
  -- either way, because most people issued equipment never will.
  holder_user_id INT UNSIGNED NULL,
  holder_name VARCHAR(160) NOT NULL,
  holder_email VARCHAR(190) NULL,
  department VARCHAR(120) NULL,

  assigned_at DATETIME NOT NULL,
  assigned_by INT UNSIGNED NULL,
  condition_out ENUM('new','good','fair','poor','damaged') NOT NULL DEFAULT 'good',
  handover_notes TEXT NULL,

  returned_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  condition_in ENUM('new','good','fair','poor','damaged') NULL,
  return_notes TEXT NULL,

  PRIMARY KEY (id),
  KEY ict_assignments_asset (asset_id, returned_at),
  KEY ict_assignments_holder (holder_user_id),
  KEY ict_assignments_email (holder_email),
  CONSTRAINT ict_assignments_asset_fk FOREIGN KEY (asset_id)
    REFERENCES ict_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Maintenance and repair history.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_maintenance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,

  performed_on DATE NOT NULL,
  kind ENUM('preventive','repair','upgrade','inspection','decommission')
       NOT NULL DEFAULT 'repair',

  problem_reported TEXT NULL,
  diagnosis TEXT NULL,
  work_done TEXT NULL,
  parts_used TEXT NULL,

  technician VARCHAR(160) NULL,
  vendor VARCHAR(160) NULL,
  cost DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',

  result ENUM('resolved','unresolved','replaced','written_off') NOT NULL DEFAULT 'resolved',
  -- Drives the maintenance-due reminder, on the same cron as the infrastructure expiries.
  next_due_on DATE NULL,
  notes TEXT NULL,

  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ict_maintenance_asset (asset_id, performed_on),
  KEY ict_maintenance_due (next_due_on),
  CONSTRAINT ict_maintenance_asset_fk FOREIGN KEY (asset_id)
    REFERENCES ict_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
