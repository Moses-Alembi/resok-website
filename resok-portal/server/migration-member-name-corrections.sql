-- Names the register import put in the wrong fields, corrected as Moses points them out.
--
-- Each UPDATE matches the record exactly as imported (membership number and the wrong name),
-- so it changes nothing once corrected, nothing on a record someone has since fixed through
-- the admin "Edit name" button, and nothing on any other member. Safe to apply repeatedly.

-- 17 Oct 2026: Dr Faraj Amir Tamimy, imported as first "Faraj", surname "Amir Faraj Tamin".
UPDATE member_profiles
SET first_name = 'Faraj', middle_name = 'Amir', surname = 'Tamimy'
WHERE membership_id = 'ReSok M/44' AND first_name = 'Faraj' AND surname = 'Amir Faraj Tamin';
