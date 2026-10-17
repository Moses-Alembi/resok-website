-- Portal payments count towards the years a member has paid for.
--
-- 17 October 2026: the year ticks on the admin members list come from member_payment_years,
-- which only the office register filled. A member who paid through the portal (Ms Isabel
-- Heinel, ReSoK0002, KES 2,000 in 2026) showed a dash for 2026. Membership runs by calendar
-- year, so a confirmed portal payment counts for the year it was paid in.
--
-- From now on the portal records the year when a payment is confirmed
-- (membershipRecordPortalYears in lib/membership.php). This fills in payments confirmed
-- before that. Safe to repeat: INSERT IGNORE, and a year the register already holds for that
-- member is left as the register has it.

INSERT IGNORE INTO member_payment_years (member_profile_id, year, amount, currency, paid_on, receipt, method, source, note)
SELECT p.member_profile_id, YEAR(p.created_at), p.amount, COALESCE(p.currency, 'KES'), DATE(p.created_at),
       LEFT(COALESCE(NULLIF(p.provider_reference, ''), p.reference), 120), LEFT(p.method, 60), 'portal', 'Paid through the portal'
FROM payments p
JOIN member_profiles mp ON mp.id = p.member_profile_id
WHERE p.status = 'paid' AND p.member_profile_id IS NOT NULL
ORDER BY p.created_at;
