-- ========================================================
-- FitTracks TiDB / MySQL Migration: Gym Pause & Maintenance Mode System
-- Target: Live TiDB Cloud Serverless / MySQL 8.0+
-- Generated: 2026-10-23
-- Safe & Idempotent
-- ========================================================

-- 1. Create system_settings table if it does not already exist
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `fk_setting_admin` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Modify system_settings.setting_value to TEXT (safe online ALTER in TiDB)
ALTER TABLE `system_settings` MODIFY COLUMN `setting_value` TEXT NOT NULL;

-- 3. Add reference_id to notifications if not exists
-- Note: TiDB supports ADD COLUMN IF NOT EXISTS
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `reference_id` INT UNSIGNED DEFAULT NULL AFTER `message`;

-- 4. Add index for fast gym-scoped notification queries if not exists
-- (If running on older MySQL without IF NOT EXISTS on index, ignore error if duplicate)
ALTER TABLE `notifications` ADD INDEX IF NOT EXISTS `idx_notif_reference` (`reference_id`);

