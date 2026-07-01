-- Adds walk-in to member conversion tracking.
-- When a walk-in customer agrees to become a member, the admin creates
-- their account and links it here so historical walk-in data is preserved.

ALTER TABLE `walk_in_transactions`
  ADD COLUMN `converted_to_member_id` INT UNSIGNED NULL DEFAULT NULL AFTER `processed_by`,
  ADD CONSTRAINT `fk_walkin_member` FOREIGN KEY (`converted_to_member_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
