-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 01, 2026 at 04:32 AM
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
-- Database: `fittrack_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_audit_logs`
--

CREATE TABLE `admin_audit_logs` (
  `log_id` int UNSIGNED NOT NULL,
  `admin_user_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `details` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `class_name`, `description`, `instructor_id`, `capacity`, `created_at`) VALUES
(1, 'Leg days', 'leg ngani', 3, 10, '2026-06-25 01:17:14'),
(2, 'muscle', 'muscle ngani', 3, 12, '2026-06-26 05:54:32');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `class_schedules`
--

INSERT INTO `class_schedules` (`schedule_id`, `class_id`, `room_location`, `start_datetime`, `end_datetime`, `created_at`) VALUES
(1, 1, 'Studio a', '2026-06-25 09:17:00', '2026-06-26 09:17:00', '2026-06-25 01:17:47'),
(2, 1, 'Studio a', '2026-06-25 09:31:00', '2026-06-26 09:31:00', '2026-06-25 01:31:57'),
(3, 2, 'studio1', '2026-06-26 13:54:00', '2026-06-27 13:55:00', '2026-06-26 05:55:06');

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `user_id` int UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL
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

--
-- Dumping data for table `exercises`
--

INSERT INTO `exercises` (`exercise_id`, `name`, `category`, `muscle_group`, `description`) VALUES
(1, 'Squat', 'strength', 'legs', 'Compound lower-body lift.'),
(2, 'Bench press', 'strength', 'chest', 'Horizontal push movement.'),
(3, 'Deadlift', 'strength', 'posterior chain', 'Hip hinge strength movement.'),
(4, 'Lat pulldown', 'strength', 'back', 'Vertical pull movement.'),
(5, 'Plank', 'core', 'abdominals', 'Anti-extension core hold.'),
(6, 'Treadmill intervals', 'cardio', 'full body', 'Alternating work and recovery intervals.'),
(7, 'Overhead press', 'strength', 'shoulders', 'Vertical push for shoulder strength.'),
(8, 'Barbell row', 'strength', 'back', 'Horizontal pull for upper back.'),
(9, 'Leg press', 'strength', 'legs', 'Machine-based quad and glute work.'),
(10, 'Dumbbell lunges', 'strength', 'legs', 'Unilateral lower-body strength.'),
(11, 'Bicep curls', 'strength', 'arms', 'Isolation curl for biceps.'),
(12, 'Tricep pushdown', 'strength', 'arms', 'Cable pushdown for triceps.'),
(13, 'Lateral raise', 'strength', 'shoulders', 'Isolation work for side delts.'),
(14, 'Russian twists', 'core', 'obliques', 'Rotational core exercise.'),
(15, 'Hanging leg raise', 'core', 'abdominals', 'Lower-ab focused core movement.'),
(16, 'Dead bug', 'core', 'abdominals', 'Core stability with limb coordination.'),
(17, 'Stationary bike', 'cardio', 'legs', 'Low-impact steady or interval cycling.'),
(18, 'Rowing machine', 'cardio', 'full body', 'Full-body cardio with pull drive.'),
(19, 'Jump rope', 'cardio', 'full body', 'High-intensity footwork and conditioning.'),
(20, 'Stair climber', 'cardio', 'legs', 'Continuous stepping cardio.');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `membership_plans`
--

INSERT INTO `membership_plans` (`plan_id`, `plan_name`, `plan_type`, `duration_days`, `price`, `description`, `is_active`, `created_at`) VALUES
(1, 'Monthly Starter', 'monthly', 30, 1200.00, 'Gym access with standard class booking.', 1, '2026-06-24 23:48:32'),
(2, 'Quarterly Plus', 'quarterly', 90, 3200.00, 'Best for consistent members and coaching add-ons.', 1, '2026-06-24 23:48:32'),
(3, 'Annual Elite', 'annual', 365, 12000.00, 'Full-year access with preferred class scheduling.', 1, '2026-06-24 23:48:32');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `type`, `title`, `message`, `is_read`, `created_at`) VALUES
(1, 3, 'coach_message', 'New message', 'Johncine Martil: hello', 0, '2026-06-27 04:33:35'),
(2, 1, 'system', 'Test notification', 'Notifications are working.', 0, '2026-07-01 02:25:31');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
-- Table structure for table `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `schema_migrations`
--

INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES
(1, '0001_add_email_verification.sql', '2026-07-01 01:01:32'),
(2, '0002_nullable_training_plan_trainer.sql', '2026-07-01 02:05:57');

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

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES
('activity_extra_active', '1.9', 'Activity multiplier: extra active.', NULL, '2026-06-26 06:05:13'),
('activity_lightly_active', '1.375', 'Activity multiplier: lightly active.', NULL, '2026-06-26 06:05:13'),
('activity_moderately_active', '1.55', 'Activity multiplier: moderately active.', NULL, '2026-06-26 06:05:13'),
('activity_sedentary', '1.2', 'Activity multiplier: sedentary.', NULL, '2026-06-26 06:05:13'),
('activity_very_active', '1.725', 'Activity multiplier: very active.', NULL, '2026-06-26 06:05:13'),
('bmr_age_factor', '5', 'BMR multiplier for age in years.', NULL, '2026-06-26 06:05:13'),
('bmr_female_adjustment', '-161', 'BMR sex adjustment for female members.', NULL, '2026-06-26 06:05:13'),
('bmr_height_factor', '6.25', 'BMR multiplier for height in centimeters.', NULL, '2026-06-26 06:05:13'),
('bmr_male_adjustment', '5', 'BMR sex adjustment for male members.', NULL, '2026-06-26 06:05:13'),
('bmr_weight_factor', '10', 'BMR multiplier for weight in kilograms.', NULL, '2026-06-26 06:05:13'),
('goal_fat_loss_adjustment', '-0.15', 'Calorie adjustment for fat loss.', NULL, '2026-06-26 06:05:13'),
('goal_general_health_adjustment', '0', 'Calorie adjustment for general health.', NULL, '2026-06-26 06:05:13'),
('goal_maintenance_adjustment', '0', 'Calorie adjustment for maintenance.', NULL, '2026-06-26 06:05:13'),
('goal_muscle_gain_adjustment', '0.10', 'Calorie adjustment for muscle gain.', NULL, '2026-06-26 06:05:13'),
('macro_default_carbs', '0.45', 'Default carb calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_default_fats', '0.30', 'Default fat calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_default_protein', '0.25', 'Default protein calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_fat_loss_carbs', '0.35', 'Fat loss carb calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_fat_loss_fats', '0.30', 'Fat loss fat calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_fat_loss_protein', '0.35', 'Fat loss protein calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_muscle_gain_carbs', '0.45', 'Muscle gain carb calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_muscle_gain_fats', '0.25', 'Muscle gain fat calorie split.', NULL, '2026-06-26 06:05:13'),
('macro_muscle_gain_protein', '0.30', 'Muscle gain protein calorie split.', NULL, '2026-06-26 06:05:13');

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

--
-- Dumping data for table `trainer_profiles`
--

INSERT INTO `trainer_profiles` (`trainer_id`, `user_id`, `specialization`, `bio`, `created_at`) VALUES
(1, 3, 'Strengthening', '', '2026-06-25 01:16:38');

-- --------------------------------------------------------

--
-- Table structure for table `training_plans`
--

CREATE TABLE `training_plans` (
  `plan_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED DEFAULT NULL,
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
  `email_verified_at` datetime DEFAULT NULL,
  `qr_token` varchar(64) DEFAULT NULL,
  `qr_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `profile_picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `password_hash`, `phone`, `status`, `email_verified_at`, `qr_token`, `qr_expires_at`, `created_at`, `updated_at`, `profile_picture`) VALUES
(1, 'admin', 'System', 'Admin', 'admin@fittrack.com', '$2y$10$/CPq8sX.y8nIzDv8zqSz1edUSANAC8Y2UBV9HhrENCFCDi2.ddOSW', '09703736380', 'active', '2026-07-01 09:01:32', NULL, NULL, '2026-06-24 23:48:32', '2026-07-01 01:01:32', 'profile_1_1782348581.png'),
(3, 'trainer', 'Johncine', 'Uchiha', 'janicemartil2@gmail.com', '$2y$10$Ve0RbacrTnrmIF1z4RkK..W0qIMyHCG0f7MyJTU9pCQTeIHKrqOTS', '', 'active', '2026-07-01 09:01:32', NULL, NULL, '2026-06-25 01:16:37', '2026-07-01 01:01:32', 'profile_3_1782452603.png'),
(7, 'member', 'Johncine', 'Saavedra', 'johncinemartil596@gmail.com', '$2y$10$1yES1XHIYquCGcPGr48zM.7wWPWuyV84QM.WqJ/viNEHEaxxQZOJO', '09070284462', 'active', '2026-07-01 09:49:05', NULL, NULL, '2026-07-01 01:48:41', '2026-07-01 01:49:05', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `walk_in_transactions`
--

CREATE TABLE `walk_in_transactions` (
  `transaction_id` int UNSIGNED NOT NULL,
  `guest_name` varchar(100) NOT NULL,
  `contact_info` varchar(100) DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `visit_date` datetime NOT NULL,
  `processed_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `walk_in_transactions`
--

INSERT INTO `walk_in_transactions` (`transaction_id`, `guest_name`, `contact_info`, `amount_paid`, `visit_date`, `processed_by`) VALUES
(1, 'johncine', '09703736380', 100.00, '2026-06-25 07:51:15', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_admin_audit_created` (`created_at`),
  ADD KEY `idx_admin_audit_entity` (`entity_type`,`entity_id`);

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
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_email_verification_token` (`token`);

--
-- Indexes for table `exercises`
--
ALTER TABLE `exercises`
  ADD PRIMARY KEY (`exercise_id`);

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
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`);

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
-- Indexes for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `migration` (`migration`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`),
  ADD KEY `fk_setting_admin` (`updated_by`);

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
-- Indexes for table `walk_in_transactions`
--
ALTER TABLE `walk_in_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `fk_walkin_staff` (`processed_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `class_bookings`
--
ALTER TABLE `class_bookings`
  MODIFY `booking_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `exercises`
--
ALTER TABLE `exercises`
  MODIFY `exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `membership_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `membership_plans`
--
ALTER TABLE `membership_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `member_profiles`
--
ALTER TABLE `member_profiles`
  MODIFY `profile_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- AUTO_INCREMENT for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  MODIFY `assignment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  MODIFY `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  MODIFY `trainer_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `walk_in_transactions`
--
ALTER TABLE `walk_in_transactions`
  MODIFY `transaction_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
-- Constraints for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships`
--
ALTER TABLE `memberships`
  ADD CONSTRAINT `fk_membership_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_membership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

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

--
-- Constraints for table `walk_in_transactions`
--
ALTER TABLE `walk_in_transactions`
  ADD CONSTRAINT `fk_walkin_staff` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
