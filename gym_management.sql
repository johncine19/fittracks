-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 18, 2026 at 02:50 PM
-- Server version: 8.0.42
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gym_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `schedule_id` int UNSIGNED DEFAULT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `check_in_method` enum('qr_code','rfid','manual') NOT NULL,
  `recorded_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `class_id` int UNSIGNED NOT NULL,
  `class_name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `instructor_id` int UNSIGNED DEFAULT NULL,
  `capacity` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `class_bookings`
--

CREATE TABLE `class_bookings` (
  `booking_id` int UNSIGNED NOT NULL,
  `schedule_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `booking_status` enum('booked','cancelled','attended','no_show') NOT NULL DEFAULT 'booked',
  `booked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_schedules`
--

CREATE TABLE `class_schedules` (
  `schedule_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `room_location` varchar(80) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_assignments`
--

CREATE TABLE `trainer_assignments` (
  `assignment_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `assigned_date` date NOT NULL,
  `ended_date` date DEFAULT NULL,
  `status` enum('active','ended') NOT NULL DEFAULT 'active',
  `assigned_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_messages`
--

CREATE TABLE `trainer_messages` (
  `message_id` int UNSIGNED NOT NULL,
  `sender_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `message_text` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_profiles`
--

CREATE TABLE `trainer_profiles` (
  `trainer_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `bio` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dietary_restrictions`
--

CREATE TABLE `dietary_restrictions` (
  `restriction_id` int UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dietary_restrictions`
--

INSERT INTO `dietary_restrictions` (`restriction_id`, `name`, `description`) VALUES
(1, 'vegetarian', 'No meat or fish'),
(2, 'vegan', 'No animal products'),
(3, 'halal', 'Halal-compliant food only'),
(4, 'lactose_free', 'No dairy/lactose'),
(5, 'nut_allergy', 'Avoid all nuts and nut derivatives'),
(6, 'gluten_free', 'No gluten-containing ingredients');

-- --------------------------------------------------------

--
-- Table structure for table `diet_plans`
--

CREATE TABLE `diet_plans` (
  `diet_plan_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED DEFAULT NULL,
  `bmr` decimal(7,2) NOT NULL,
  `tdee` decimal(7,2) NOT NULL,
  `calorie_target` int UNSIGNED NOT NULL,
  `protein_target_g` decimal(6,2) NOT NULL,
  `carbs_target_g` decimal(6,2) NOT NULL,
  `fats_target_g` decimal(6,2) NOT NULL,
  `status` enum('system_generated','coach_reviewed','finalized') NOT NULL DEFAULT 'system_generated',
  `trainer_notes` text,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finalized_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `diet_plan_meals`
--

CREATE TABLE `diet_plan_meals` (
  `meal_id` int UNSIGNED NOT NULL,
  `diet_plan_id` int UNSIGNED NOT NULL,
  `food_id` int UNSIGNED NOT NULL,
  `meal_type` enum('breakfast','lunch','dinner','snack') NOT NULL,
  `servings` decimal(5,2) NOT NULL DEFAULT '1.00',
  `is_trainer_override` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exercises`
--

CREATE TABLE `exercises` (
  `exercise_id` int UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `muscle_group` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `food_dietary_tags`
--

CREATE TABLE `food_dietary_tags` (
  `food_id` int UNSIGNED NOT NULL,
  `restriction_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `food_items`
--

CREATE TABLE `food_items` (
  `food_id` int UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `serving_size` varchar(50) NOT NULL,
  `calories` decimal(6,2) NOT NULL,
  `protein_g` decimal(6,2) NOT NULL DEFAULT '0.00',
  `carbs_g` decimal(6,2) NOT NULL DEFAULT '0.00',
  `fats_g` decimal(6,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `memberships`
--

CREATE TABLE `memberships` (
  `membership_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','expired','cancelled','pending') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `membership_plans`
--

CREATE TABLE `membership_plans` (
  `plan_id` int UNSIGNED NOT NULL,
  `plan_name` varchar(50) NOT NULL,
  `plan_type` enum('monthly','quarterly','annual','custom') NOT NULL,
  `duration_days` int UNSIGNED NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `member_dietary_restrictions`
--

CREATE TABLE `member_dietary_restrictions` (
  `profile_id` int UNSIGNED NOT NULL,
  `restriction_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_profiles`
--

CREATE TABLE `member_profiles` (
  `profile_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `date_of_birth` date NOT NULL,
  `biological_sex` enum('male','female') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active') NOT NULL,
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('renewal_reminder','class_reminder','coach_message','milestone','system') NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` varchar(500) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int UNSIGNED NOT NULL,
  `membership_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','card','bank_transfer','online','other') NOT NULL,
  `status` enum('paid','pending','overdue','refunded') NOT NULL DEFAULT 'pending',
  `receipt_number` varchar(30) DEFAULT NULL,
  `processed_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `progress_logs`
--

CREATE TABLE `progress_logs` (
  `log_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `log_date` date NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `body_fat_percent` decimal(4,2) DEFAULT NULL,
  `chest_cm` decimal(5,2) DEFAULT NULL,
  `waist_cm` decimal(5,2) DEFAULT NULL,
  `hips_cm` decimal(5,2) DEFAULT NULL,
  `arm_cm` decimal(5,2) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_plans`
--

CREATE TABLE `training_plans` (
  `plan_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `goal` varchar(100) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_plan_exercises`
--

CREATE TABLE `training_plan_exercises` (
  `plan_exercise_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `exercise_id` int UNSIGNED NOT NULL,
  `day_of_week` tinyint UNSIGNED DEFAULT NULL,
  `sequence_order` int UNSIGNED NOT NULL DEFAULT '1',
  `sets` int UNSIGNED DEFAULT NULL,
  `reps` varchar(20) DEFAULT NULL,
  `target_weight_kg` decimal(6,2) DEFAULT NULL,
  `rest_seconds` int UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int UNSIGNED NOT NULL,
  `role` enum('admin','staff','trainer','member') NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `qr_token` varchar(64) DEFAULT NULL,
  `qr_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `fk_attendance_user` (`user_id`),
  ADD KEY `fk_attendance_schedule` (`schedule_id`),
  ADD KEY `fk_attendance_staff` (`recorded_by`),
  ADD KEY `idx_attendance_checkin` (`check_in_time`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`class_id`),
  ADD KEY `fk_class_instructor` (`instructor_id`);

--
-- Indexes for table `class_bookings`
--
ALTER TABLE `class_bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `uq_booking_unique` (`schedule_id`,`user_id`),
  ADD KEY `fk_booking_user` (`user_id`);

--
-- Indexes for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `fk_schedule_class` (`class_id`),
  ADD KEY `idx_schedule_start` (`start_datetime`);

--
-- Indexes for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `fk_assignment_coach` (`trainer_id`),
  ADD KEY `fk_assignment_member` (`member_user_id`),
  ADD KEY `fk_assignment_by` (`assigned_by`),
  ADD KEY `idx_assignment_status` (`status`);

--
-- Indexes for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `fk_message_recipient` (`recipient_id`),
  ADD KEY `idx_message_thread` (`sender_id`,`recipient_id`,`sent_at`);

--
-- Indexes for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  ADD PRIMARY KEY (`trainer_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `dietary_restrictions`
--
ALTER TABLE `dietary_restrictions`
  ADD PRIMARY KEY (`restriction_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `diet_plans`
--
ALTER TABLE `diet_plans`
  ADD PRIMARY KEY (`diet_plan_id`),
  ADD KEY `fk_dietplan_member` (`member_user_id`),
  ADD KEY `fk_dietplan_coach` (`trainer_id`),
  ADD KEY `idx_dietplan_status` (`status`);

--
-- Indexes for table `diet_plan_meals`
--
ALTER TABLE `diet_plan_meals`
  ADD PRIMARY KEY (`meal_id`),
  ADD KEY `fk_dpm_dietplan` (`diet_plan_id`),
  ADD KEY `fk_dpm_food` (`food_id`);

--
-- Indexes for table `exercises`
--
ALTER TABLE `exercises`
  ADD PRIMARY KEY (`exercise_id`);

--
-- Indexes for table `food_dietary_tags`
--
ALTER TABLE `food_dietary_tags`
  ADD PRIMARY KEY (`food_id`,`restriction_id`),
  ADD KEY `fk_fdt_restriction` (`restriction_id`);

--
-- Indexes for table `food_items`
--
ALTER TABLE `food_items`
  ADD PRIMARY KEY (`food_id`);

--
-- Indexes for table `memberships`
--
ALTER TABLE `memberships`
  ADD PRIMARY KEY (`membership_id`),
  ADD KEY `fk_membership_user` (`user_id`),
  ADD KEY `fk_membership_plan` (`plan_id`),
  ADD KEY `idx_memberships_status` (`status`);

--
-- Indexes for table `membership_plans`
--
ALTER TABLE `membership_plans`
  ADD PRIMARY KEY (`plan_id`);

--
-- Indexes for table `member_dietary_restrictions`
--
ALTER TABLE `member_dietary_restrictions`
  ADD PRIMARY KEY (`profile_id`,`restriction_id`),
  ADD KEY `fk_mdr_restriction` (`restriction_id`);

--
-- Indexes for table `member_profiles`
--
ALTER TABLE `member_profiles`
  ADD PRIMARY KEY (`profile_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notification_unread` (`user_id`,`is_read`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `fk_payment_membership` (`membership_id`),
  ADD KEY `fk_payment_staff` (`processed_by`),
  ADD KEY `idx_payments_status` (`status`);

--
-- Indexes for table `progress_logs`
--
ALTER TABLE `progress_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_progress_recorder` (`recorded_by`),
  ADD KEY `idx_progress_date` (`user_id`,`log_date`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`),
  ADD KEY `fk_setting_admin` (`updated_by`);

--
-- Indexes for table `training_plans`
--
ALTER TABLE `training_plans`
  ADD PRIMARY KEY (`plan_id`),
  ADD KEY `fk_trainingplan_member` (`member_user_id`),
  ADD KEY `fk_trainingplan_coach` (`trainer_id`);

--
-- Indexes for table `training_plan_exercises`
--
ALTER TABLE `training_plan_exercises`
  ADD PRIMARY KEY (`plan_exercise_id`),
  ADD KEY `fk_tpe_plan` (`plan_id`),
  ADD KEY `fk_tpe_exercise` (`exercise_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_bookings`
--
ALTER TABLE `class_bookings`
  MODIFY `booking_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  MODIFY `assignment_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  MODIFY `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  MODIFY `trainer_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dietary_restrictions`
--
ALTER TABLE `dietary_restrictions`
  MODIFY `restriction_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `diet_plans`
--
ALTER TABLE `diet_plans`
  MODIFY `diet_plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `diet_plan_meals`
--
ALTER TABLE `diet_plan_meals`
  MODIFY `meal_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exercises`
--
ALTER TABLE `exercises`
  MODIFY `exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `food_items`
--
ALTER TABLE `food_items`
  MODIFY `food_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `membership_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `membership_plans`
--
ALTER TABLE `membership_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `member_profiles`
--
ALTER TABLE `member_profiles`
  MODIFY `profile_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `progress_logs`
--
ALTER TABLE `progress_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_plans`
--
ALTER TABLE `training_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_plan_exercises`
--
ALTER TABLE `training_plan_exercises`
  MODIFY `plan_exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules` (`schedule_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_staff` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_class_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `class_bookings`
--
ALTER TABLE `class_bookings`
  ADD CONSTRAINT `fk_booking_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules` (`schedule_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_booking_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD CONSTRAINT `fk_schedule_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE;

--
-- Constraints for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  ADD CONSTRAINT `fk_assignment_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_assignment_coach` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assignment_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  ADD CONSTRAINT `fk_message_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_message_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  ADD CONSTRAINT `fk_coach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `diet_plans`
--
ALTER TABLE `diet_plans`
  ADD CONSTRAINT `fk_dietplan_coach` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dietplan_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `diet_plan_meals`
--
ALTER TABLE `diet_plan_meals`
  ADD CONSTRAINT `fk_dpm_dietplan` FOREIGN KEY (`diet_plan_id`) REFERENCES `diet_plans` (`diet_plan_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dpm_food` FOREIGN KEY (`food_id`) REFERENCES `food_items` (`food_id`) ON DELETE RESTRICT;

--
-- Constraints for table `food_dietary_tags`
--
ALTER TABLE `food_dietary_tags`
  ADD CONSTRAINT `fk_fdt_food` FOREIGN KEY (`food_id`) REFERENCES `food_items` (`food_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fdt_restriction` FOREIGN KEY (`restriction_id`) REFERENCES `dietary_restrictions` (`restriction_id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships`
--
ALTER TABLE `memberships`
  ADD CONSTRAINT `fk_membership_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_membership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `member_dietary_restrictions`
--
ALTER TABLE `member_dietary_restrictions`
  ADD CONSTRAINT `fk_mdr_profile` FOREIGN KEY (`profile_id`) REFERENCES `member_profiles` (`profile_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mdr_restriction` FOREIGN KEY (`restriction_id`) REFERENCES `dietary_restrictions` (`restriction_id`) ON DELETE CASCADE;

--
-- Constraints for table `member_profiles`
--
ALTER TABLE `member_profiles`
  ADD CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_membership` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`membership_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payment_staff` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `progress_logs`
--
ALTER TABLE `progress_logs`
  ADD CONSTRAINT `fk_progress_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_setting_admin` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `training_plans`
--
ALTER TABLE `training_plans`
  ADD CONSTRAINT `fk_trainingplan_coach` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_trainingplan_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `training_plan_exercises`
--
ALTER TABLE `training_plan_exercises`
  ADD CONSTRAINT `fk_tpe_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`exercise_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_tpe_plan` FOREIGN KEY (`plan_id`) REFERENCES `training_plans` (`plan_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- --------------------------------------------------------

--
-- Table structure for table `walk_in_transactions`
--

CREATE TABLE `walk_in_transactions` (
  `transaction_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `guest_name` varchar(100) NOT NULL,
  `contact_info` varchar(100) DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `visit_date` datetime NOT NULL,
  `processed_by` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `fk_walkin_staff` (`processed_by`),
  CONSTRAINT `fk_walkin_staff` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
