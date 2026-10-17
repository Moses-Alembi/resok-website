-- 2026 payments from the office register that the September import did not carry: the
-- register's 2026 entries were not in the imported file. Added as Moses confirms them.
--
-- INSERT IGNORE on (member, year): a year already recorded (by the portal, by the admin
-- "Mark year as paid" control, or an earlier run) is left as it is. Safe to apply repeatedly.

-- 17 Oct 2026: Dr Valentine Wangui Kei-Muriithi (ReSok M/50) paid on 16.02.2026.
INSERT IGNORE INTO member_payment_years (member_profile_id, year, paid_on, source, note)
SELECT mp.id, 2026, '2026-02-16', 'manual', 'Office register: paid 16.02.2026'
FROM member_profiles mp
WHERE mp.membership_id = 'ReSok M/50';
