-- Which years each member has paid for.
--
-- One row per member per year, not one column per year. The register this comes from is a
-- spreadsheet with a sheet for each year, and the obvious translation - paid_2024, paid_2025,
-- paid_2026 - would mean an ALTER TABLE every January, a schema that grows without end, and
-- every query naming years it was written before. Rows make adding a year an insert, and a
-- question like "who has paid for the last three years" one query instead of three columns
-- spliced together.
--
-- The amount is nullable on purpose. Appearing on a year's sheet is itself the record that
-- somebody was a paid member that year; several of the older rows carry no figure, and a
-- zero would state something the register does not say.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

CREATE TABLE IF NOT EXISTS member_payment_years (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_profile_id INT UNSIGNED NOT NULL,

  -- The membership year, not the date of payment. Somebody who pays for 2026 in December
  -- 2025 has paid for 2026, and that is the question this table answers.
  year SMALLINT UNSIGNED NOT NULL,

  amount DECIMAL(10,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  paid_on DATE NULL,
  receipt VARCHAR(120) NULL,
  method VARCHAR(60) NULL,

  -- Where the row came from. 'register' is the historical spreadsheet, which nobody can now
  -- re-derive; 'portal' is a payment confirmed through the system. Worth keeping separate,
  -- because only one of the two has anything behind it that can be checked.
  source ENUM('register','portal','manual') NOT NULL DEFAULT 'register',
  note VARCHAR(255) NULL,

  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- A member pays for a given year once. A second payment for the same year corrects the
  -- first rather than sitting beside it.
  UNIQUE KEY member_payment_years_unique (member_profile_id, year),
  KEY member_payment_years_year (year),
  CONSTRAINT member_payment_years_profile_fk FOREIGN KEY (member_profile_id)
    REFERENCES member_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
