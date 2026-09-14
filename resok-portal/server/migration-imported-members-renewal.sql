-- Membership dates for the members imported from the register.
--
-- The accounts imported from the office's membership register on 9 September 2026 were never
-- approved through the portal, so nothing ever gave them a renewal date. On the Secretariat's
-- decision of 14 September 2026, every one of them is active until 30 November 2026 and renews
-- through the portal from then on.
--
-- A member counts as imported when the register records at least one paid year for them
-- (member_payment_years.source = 'register'). Membership numbers are not used to decide it,
-- because nothing guarantees how the import spelled them.
--
-- Safe to run again, which matters because the migration runner re-applies any file whose
-- contents change. The date only ever moves forward: a member who has renewed past
-- 30 November is not matched, and a member already at 30 November is not touched, so a
-- membership that has since lapsed is not quietly revived. Rejected records are left alone.

UPDATE member_profiles mp
SET mp.membership_status = 'active',
    mp.renewal_due = '2026-11-30'
WHERE mp.membership_status <> 'rejected'
  AND (mp.renewal_due IS NULL OR mp.renewal_due < '2026-11-30')
  AND EXISTS (SELECT 1 FROM member_payment_years y
              WHERE y.member_profile_id = mp.id AND y.source = 'register');
