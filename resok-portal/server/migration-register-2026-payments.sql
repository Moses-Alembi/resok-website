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

-- 17 Oct 2026: Dr Beverly Owino (ReSoK M/106) paid KES 5,000 on 19.01.2026. She joined after
-- the register was exported for the September import, so she may have no portal account at
-- all. Each step only does its part if it is missing: an account (unclaimed, like the other
-- imported members - she sets her password through a claim link), a profile, then the year.
-- The password field holds a marker no password can match, so nobody can sign in to the
-- account until she claims it.
INSERT INTO users (email, password_hash, email_verified, role)
SELECT 'yongobowino@gmail.com', '!unclaimed-imported', 0, 'member'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(email) = 'yongobowino@gmail.com')
  AND NOT EXISTS (SELECT 1 FROM member_profiles WHERE membership_id IN ('ReSoK M/106', 'ReSok M/106'));

INSERT INTO member_profiles (user_id, title, first_name, surname, profession, specialization, institution, mobile, payer_type, membership_status, membership_id)
SELECT u.id, 'Dr', 'Beverly', 'Owino', 'Respiratory Fellow', 'Respiratory', 'NHS Ayrshire & Arran', '0723964882', 'Individual', 'active', 'ReSoK M/106'
FROM users u
WHERE LOWER(u.email) = 'yongobowino@gmail.com'
  AND NOT EXISTS (SELECT 1 FROM member_profiles mp WHERE mp.user_id = u.id)
  AND NOT EXISTS (SELECT 1 FROM member_profiles mp WHERE mp.membership_id IN ('ReSoK M/106', 'ReSok M/106'));

INSERT IGNORE INTO member_payment_years (member_profile_id, year, amount, paid_on, source, note)
SELECT mp.id, 2026, 5000.00, '2026-01-19', 'manual', 'Office register: paid 19.01.2026'
FROM member_profiles mp
WHERE mp.membership_id IN ('ReSoK M/106', 'ReSok M/106');

-- Expiry is 12 months from the date paid (Moses, 17 Oct 2026): Valentine 2027-02-16,
-- Beverly 2027-01-19. Taken from the payment recorded above; only ever moves a date later.
UPDATE member_profiles mp
JOIN member_payment_years y ON y.member_profile_id = mp.id AND y.year = 2026 AND y.paid_on IS NOT NULL
SET mp.renewal_due = DATE_ADD(y.paid_on, INTERVAL 12 MONTH),
    mp.membership_status = IF(mp.membership_status = 'expired', 'active', mp.membership_status)
WHERE mp.membership_id IN ('ReSok M/50', 'ReSoK M/106', 'ReSok M/106')
  AND (mp.renewal_due IS NULL OR mp.renewal_due < DATE_ADD(y.paid_on, INTERVAL 12 MONTH));
