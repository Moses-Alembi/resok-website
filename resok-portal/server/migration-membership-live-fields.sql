ALTER TABLE member_profiles
  ADD COLUMN profession VARCHAR(160) NULL AFTER division,
  ADD COLUMN specialization VARCHAR(160) NULL AFTER profession,
  ADD COLUMN institution VARCHAR(190) NULL AFTER specialization,
  ADD COLUMN physical_address VARCHAR(255) NULL AFTER institution,
  ADD COLUMN payer_type ENUM('Individual', 'Organization') NOT NULL DEFAULT 'Individual' AFTER physical_address;
