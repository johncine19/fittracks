-- Multi-Gym Platform Migration Script

-- 1. Create gyms table
CREATE TABLE IF NOT EXISTS `gyms` (
  `gym_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` int UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `contact_info` varchar(100) DEFAULT NULL,
  `business_permit_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`gym_id`),
  KEY `fk_gym_owner` (`owner_user_id`),
  CONSTRAINT `fk_gym_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Modify users role enum
ALTER TABLE `users` MODIFY COLUMN `role` enum('admin','platform_admin','gym_owner','trainer','member') NOT NULL;

-- Set existing admins to platform_admin
UPDATE `users` SET `role` = 'platform_admin' WHERE `role` = 'admin';

-- Remove admin from enum
ALTER TABLE `users` MODIFY COLUMN `role` enum('platform_admin','gym_owner','trainer','member') NOT NULL;

-- 3. Modify membership_plans
ALTER TABLE `membership_plans`
ADD COLUMN `gym_id` int UNSIGNED DEFAULT NULL,
ADD COLUMN `plan_scope` enum('local','shared') NOT NULL DEFAULT 'local',
ADD CONSTRAINT `fk_mem_plan_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE;

-- 4. Create shared_plan_gyms table
CREATE TABLE IF NOT EXISTS `shared_plan_gyms` (
  `plan_id` int UNSIGNED NOT NULL,
  `gym_id` int UNSIGNED NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plan_id`, `gym_id`),
  CONSTRAINT `fk_spg_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spg_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5. Add gym_id to trainer_profiles, classes, class_schedules, attendance
ALTER TABLE `trainer_profiles`
ADD COLUMN `gym_id` int UNSIGNED DEFAULT NULL,
ADD CONSTRAINT `fk_trainer_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL;

ALTER TABLE `classes`
ADD COLUMN `gym_id` int UNSIGNED DEFAULT NULL,
ADD CONSTRAINT `fk_class_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL;

ALTER TABLE `class_schedules`
ADD COLUMN `gym_id` int UNSIGNED DEFAULT NULL,
ADD CONSTRAINT `fk_sched_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL;

ALTER TABLE `attendance`
ADD COLUMN `gym_id` int UNSIGNED DEFAULT NULL,
ADD CONSTRAINT `fk_attend_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL;

-- 6. Create workout_rules table
CREATE TABLE IF NOT EXISTS `workout_rules` (
  `rule_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `experience_level` int NOT NULL DEFAULT 1 COMMENT 'Maps to fitness_tier (1=Beginner, 2=Intermediate, 3=Advanced)',
  `biological_sex` enum('male','female','any') NOT NULL DEFAULT 'any',
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any',
  `recommended_workout_structure` text NOT NULL,
  PRIMARY KEY (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7. Create diet_rules table
CREATE TABLE IF NOT EXISTS `diet_rules` (
  `rule_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `experience_level` int NOT NULL DEFAULT 1,
  `biological_sex` enum('male','female','any') NOT NULL DEFAULT 'any',
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any',
  `macro_split` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8. Seed Baseline Rules

-- Workout Rules (Baseline examples)
INSERT INTO `workout_rules` (`experience_level`, `biological_sex`, `primary_goal`, `activity_level`, `recommended_workout_structure`) VALUES
(1, 'any', 'fat_loss', 'any', '3 days Full Body Resistance (Machine focused), 2 days Moderate Cardio (30 mins)'),
(1, 'any', 'muscle_gain', 'any', '3 days Full Body Resistance (Dumbbell/Machine), 1 day Active Recovery'),
(1, 'any', 'general_health', 'any', '2 days Full Body Resistance, 2 days Light Cardio / Yoga'),
(2, 'any', 'fat_loss', 'any', '4 days Upper/Lower Split, 2 days HIIT (20 mins)'),
(2, 'any', 'muscle_gain', 'any', '4 days Upper/Lower Split with Progressive Overload'),
(3, 'any', 'muscle_gain', 'any', '5 days Push/Pull/Legs Split, heavy compound lifts'),
(3, 'any', 'fat_loss', 'any', '5 days PPL Split, + 3 sessions fasted cardio');

-- Diet Rules (Baseline examples - macro split format: Protein% / Carbs% / Fat%)
INSERT INTO `diet_rules` (`experience_level`, `biological_sex`, `primary_goal`, `activity_level`, `macro_split`, `notes`) VALUES
(1, 'any', 'fat_loss', 'any', '35% Protein / 35% Carbs / 30% Fat', 'Maintain a 300-500 calorie deficit.'),
(1, 'any', 'muscle_gain', 'any', '30% Protein / 45% Carbs / 25% Fat', 'Maintain a 200-300 calorie surplus.'),
(1, 'any', 'general_health', 'any', '25% Protein / 45% Carbs / 30% Fat', 'Eat at maintenance calories.'),
(2, 'any', 'fat_loss', 'any', '40% Protein / 30% Carbs / 30% Fat', 'Higher protein to preserve muscle mass in a deficit.'),
(2, 'any', 'muscle_gain', 'any', '30% Protein / 50% Carbs / 20% Fat', 'Higher carbs to fuel intense training sessions.'),
(3, 'any', 'fat_loss', 'any', '40% Protein / 30% Carbs / 30% Fat', 'Aggressive deficit with refeed days.'),
(3, 'any', 'muscle_gain', 'any', '30% Protein / 50% Carbs / 20% Fat', 'Carefully tracked surplus to minimize fat gain.');

-- 9. Create announcements table
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` enum('all','gym_owners','trainers','members') NOT NULL DEFAULT 'all',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  CONSTRAINT `fk_announcement_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 10. Create member_transfers table
CREATE TABLE IF NOT EXISTS `member_transfers` (
  `transfer_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `from_gym_id` int UNSIGNED NOT NULL,
  `to_gym_id` int UNSIGNED NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  CONSTRAINT `fk_transfer_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_from_gym` FOREIGN KEY (`from_gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_to_gym` FOREIGN KEY (`to_gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
