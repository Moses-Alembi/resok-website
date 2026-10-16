-- Membership dates for the members imported from the register.
--
-- History. The accounts imported from the office's membership register on 9 September 2026
-- were never approved through the portal, so nothing gave them a renewal date. On the
-- Secretariat's decision of 14 September 2026 this file set every one of them active until
-- 30 November 2026.
--
-- 16 October 2026: Moses asked for those dates to be removed while he checks the database
-- and supplies each member's real expiry. A single shared date treated a member who paid for
-- 2026 the same as one who last paid in 2023. This file now clears the shared date instead.
-- A member with no date is "active, with no renewal date recorded": they keep every benefit,
-- receive no renewal reminders and never lapse (see membershipStanding()).
--
-- Only members still on exactly 30 November 2026 are cleared. Anyone who has since renewed
-- through the portal has a later date of their own, and keeps it.
--
-- Once only. The migration runner re-applies any file whose contents change, and pressing
-- "apply" again after Moses has entered the real dates must not wipe them, including any
-- that genuinely are 30 November 2026. The marker row below records that the clear has
-- happened; the UPDATE does nothing once it exists.
--
-- A member counts as imported when the register records at least one paid year for them
-- (member_payment_years.source = 'register'). Rejected records are left alone.

CREATE TABLE IF NOT EXISTS migration_once (
  name VARCHAR(100) NOT NULL,
  ran_at DATETIME NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE member_profiles mp
SET mp.renewal_due = NULL
WHERE mp.membership_status <> 'rejected'
  AND mp.renewal_due = '2026-11-30'
  AND EXISTS (SELECT 1 FROM member_payment_years y
              WHERE y.member_profile_id = mp.id AND y.source = 'register')
  AND NOT EXISTS (SELECT 1 FROM migration_once m WHERE m.name = 'clear-imported-renewal-dates');

INSERT IGNORE INTO migration_once (name, ran_at) VALUES ('clear-imported-renewal-dates', NOW());
