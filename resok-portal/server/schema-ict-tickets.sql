-- ICT helpdesk.
--
-- Tickets, the conversation on them, and the timestamps that make response and resolution
-- time measurable rather than a matter of opinion.
--
-- Anyone signed in can raise a ticket and see their own; ICT staff see and work all of them.
-- That is the point of a helpdesk - if raising one is harder than sending a WhatsApp message
-- it will not be used, and a helpdesk nobody uses is worse than no helpdesk because it looks
-- like coverage.
--
-- The four timestamps are separate on purpose. created_at to first_response_at is how long
-- somebody waited to hear anything, which is what people actually complain about;
-- created_at to resolved_at is how long the problem lasted. Averaging one number would hide
-- both.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS ict_tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- What gets quoted in a corridor conversation. Sequential per year so the number itself
  -- says roughly when it was raised.
  reference VARCHAR(24) NOT NULL,

  -- The requester may be any signed-in user; the name and email are kept directly as well,
  -- so a ticket still reads correctly after an account is closed.
  requester_user_id INT UNSIGNED NULL,
  requester_name VARCHAR(160) NOT NULL,
  requester_email VARCHAR(190) NULL,
  department VARCHAR(120) NULL,

  category ENUM('hardware','software','network','email','internet','printer',
                'account','website','security','other') NOT NULL DEFAULT 'other',
  subject VARCHAR(200) NOT NULL,
  description TEXT NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',

  -- Set when the problem is about a specific piece of equipment, so the asset's history and
  -- its faults are one join apart.
  asset_id INT UNSIGNED NULL,

  assignee_user_id INT UNSIGNED NULL,
  status ENUM('new','assigned','in_progress','waiting','resolved','closed')
         NOT NULL DEFAULT 'new',
  resolution TEXT NULL,

  created_at DATETIME NOT NULL,
  -- The first time ICT said anything back. Separate from resolution because waiting in
  -- silence is the part people remember.
  first_response_at DATETIME NULL,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY ict_tickets_reference (reference),
  KEY ict_tickets_queue (status, priority, created_at),
  KEY ict_tickets_requester (requester_user_id, created_at),
  KEY ict_tickets_assignee (assignee_user_id, status),
  KEY ict_tickets_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- The conversation.
--
-- is_internal separates a note between ICT staff from a reply the requester sees. Without
-- that split people either say nothing useful in writing, or say it somewhere the ticket
-- cannot keep it.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ict_ticket_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,

  author_user_id INT UNSIGNED NULL,
  author_name VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  -- Set on the entries written by the workflow itself - assigned, resolved, reopened - so
  -- the thread reads as one story rather than needing a separate history panel beside it.
  is_system TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL,

  PRIMARY KEY (id),
  KEY ict_ticket_comments_ticket (ticket_id, created_at),
  CONSTRAINT ict_ticket_comments_fk FOREIGN KEY (ticket_id)
    REFERENCES ict_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
