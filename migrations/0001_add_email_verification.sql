-- Adds email verification tracking for self-registered members.
-- Existing users are backfilled as verified (they pre-date this feature,
-- and accounts created directly by admins/staff are trusted at creation
-- time — see migration 0002).

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL AFTER `status`;

UPDATE `users` SET `email_verified_at` = NOW() WHERE `email_verified_at` IS NULL;

CREATE TABLE `email_verifications` (
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_email_verification_token` (`token`),
  CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
