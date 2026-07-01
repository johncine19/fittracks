-- Change date_of_birth to age in member_profiles

ALTER TABLE member_profiles ADD COLUMN age INT UNSIGNED NOT NULL DEFAULT 30 AFTER weight_kg;

-- Calculate age for existing records if any
UPDATE member_profiles SET age = TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) WHERE date_of_birth IS NOT NULL;

ALTER TABLE member_profiles DROP COLUMN date_of_birth;
