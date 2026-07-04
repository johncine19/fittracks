-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 03, 2026 at 05:09 AM
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

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `user_id`, `schedule_id`, `check_in_time`, `check_out_time`, `check_in_method`, `recorded_by`) VALUES
(3, 8, NULL, '2026-07-01 17:01:32', '2026-07-01 18:37:19', 'qr_code', 1),
(4, 8, NULL, '2026-07-01 17:54:14', '2026-07-02 05:54:20', 'manual', 1),
(5, 8, NULL, '2026-07-02 06:03:40', '2026-07-02 06:10:22', 'qr_code', 1),
(6, 8, NULL, '2026-07-02 21:28:01', '2026-07-02 21:39:02', 'qr_code', 1),
(7, 9, NULL, '2026-07-02 21:45:56', '2026-07-02 21:47:35', 'qr_code', 1),
(8, 10, NULL, '2026-07-02 21:51:36', '2026-07-02 21:52:02', 'qr_code', 1),
(9, 10, NULL, '2026-07-02 21:54:56', '2026-07-02 22:00:28', 'qr_code', 1),
(10, 11, NULL, '2026-07-02 22:02:54', '2026-07-02 22:04:44', 'qr_code', 1),
(11, 8, NULL, '2026-07-03 06:26:56', '2026-07-03 06:28:14', 'qr_code', 1);

-- --------------------------------------------------------

--
-- Table structure for table `checkout_ratings`
--

CREATE TABLE `checkout_ratings` (
  `rating_id` int UNSIGNED NOT NULL,
  `attendance_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `rating` tinyint UNSIGNED NOT NULL,
  `comment` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `checkout_ratings`
--

INSERT INTO `checkout_ratings` (`rating_id`, `attendance_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(1, 6, 8, 5, 'Smooth Transaction', '2026-07-02 13:39:02'),
(2, 7, 9, 5, 'The gym is on fire, ill be coming again!', '2026-07-02 13:47:35'),
(3, 8, 10, 5, 'Nice!', '2026-07-02 13:52:02'),
(4, 10, 11, 5, 'goods', '2026-07-02 14:04:58'),
(5, 11, 8, 5, 'gagi ganda', '2026-07-02 22:28:14');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `class_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `class_name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `instructor_id` int UNSIGNED DEFAULT NULL,
  `capacity` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `created_by`, `class_name`, `description`, `instructor_id`, `capacity`, `created_at`) VALUES
(1, 3, 'Leg days', 'leg ngani', 3, 10, '2026-06-25 01:17:14'),
(2, 3, 'muscle', 'muscle ngani', 3, 12, '2026-06-26 05:54:32'),
(3, 3, 'muscle', 'muscle ngani', 3, 10, '2026-07-02 22:41:21'),
(5, 3, 'Yoga', 'yoga ngani', 3, 10, '2026-07-02 23:26:13');

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
(4, 5, 'studio', '2026-07-03 07:26:00', '2026-07-04 07:26:00', '2026-07-02 23:26:38');

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
-- Table structure for table `exercise_completions`
--

CREATE TABLE `exercise_completions` (
  `completion_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `exercise_id` int UNSIGNED NOT NULL,
  `completed_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `exercise_completions`
--

INSERT INTO `exercise_completions` (`completion_id`, `user_id`, `plan_id`, `exercise_id`, `completed_date`) VALUES
(2, 9, 6, 2, '2026-07-02'),
(1, 9, 6, 16, '2026-07-02'),
(3, 9, 6, 19, '2026-07-02'),
(4, 9, 6, 20, '2026-07-02');

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
(3, 'Annual Elite', 'annual', 365, 12000.00, 'Full-year access with preferred class scheduling.', 1, '2026-06-24 23:48:32'),
(4, 'Weekly Pack', 'custom', 7, 100.00, 'Weekly subscription', 1, '2026-07-02 23:02:41');

-- --------------------------------------------------------

--
-- Table structure for table `member_profiles`
--

CREATE TABLE `member_profiles` (
  `profile_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `age` int UNSIGNED NOT NULL DEFAULT '30',
  `biological_sex` enum('male','female') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active') NOT NULL,
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fitness_tier` int DEFAULT '1',
  `completed_weeks` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `member_profiles`
--

INSERT INTO `member_profiles` (`profile_id`, `user_id`, `height_cm`, `weight_kg`, `age`, `biological_sex`, `activity_level`, `primary_goal`, `created_at`, `updated_at`, `fitness_tier`, `completed_weeks`) VALUES
(3, 8, 111.00, 55.00, 21, 'male', 'lightly_active', 'muscle_gain', '2026-07-01 07:39:33', '2026-07-02 23:19:32', 1, 0),
(5, 9, 110.00, 56.00, 24, 'male', 'moderately_active', 'general_health', '2026-07-02 13:45:21', '2026-07-02 13:45:21', 1, 0),
(6, 10, 160.00, 80.00, 28, 'male', 'very_active', 'fat_loss', '2026-07-02 13:50:58', '2026-07-02 13:50:58', 1, 0),
(7, 11, 145.00, 58.00, 21, 'female', 'lightly_active', 'fat_loss', '2026-07-02 14:02:05', '2026-07-02 14:02:05', 1, 0);

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
  `reference_id` int UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `type`, `title`, `message`, `reference_id`, `is_read`, `created_at`) VALUES
(1, 3, 'coach_message', 'New message', 'Johncine Martil: hello', NULL, 1, '2026-06-27 04:33:35'),
(2, 1, 'system', 'Test notification', 'Notifications are working.', NULL, 1, '2026-07-01 02:25:31'),
(3, 8, 'system', 'Welcome to FITTRACKS', 'Your profile is set up and your personalised workout plan is ready.', NULL, 1, '2026-07-01 07:39:33'),
(4, 8, 'system', 'Workout plan updated', 'Your workout plan was recalculated from your updated physical profile.', NULL, 1, '2026-07-01 08:54:24'),
(5, 8, 'system', 'Workout plan recalculated', 'Your weekly exercise schedule has been refreshed based on your current profile.', NULL, 1, '2026-07-01 09:07:54'),
(6, 8, 'system', 'Trainer assigned', 'You have been assigned to Johncine Uchiha.', NULL, 1, '2026-07-01 22:22:08'),
(7, 3, 'system', 'New client assigned', 'John Uchiha has been assigned to you.', NULL, 1, '2026-07-01 22:22:08'),
(8, 8, 'coach_message', 'Message from your trainer', 'Johncine Uchiha: hello', NULL, 1, '2026-07-01 22:40:23'),
(9, 8, 'system', 'New workout plan', 'Johncine Uchiha generated a personalised workout plan for you.', NULL, 1, '2026-07-01 22:43:34'),
(10, 8, 'coach_message', 'New message from Johncine Uchiha', 'You have 4 new messages.', NULL, 1, '2026-07-01 23:04:03'),
(11, 8, 'coach_message', 'New message from Johncine Uchiha', 'You have 4 new messages.', NULL, 1, '2026-07-01 23:04:03'),
(12, 8, 'coach_message', 'New message from Johncine Uchiha', 'You have 4 new messages.', NULL, 1, '2026-07-01 23:04:03'),
(13, 3, 'coach_message', 'New message from John Uchiha', 'hi', NULL, 1, '2026-07-01 22:57:29'),
(14, 8, 'coach_message', 'New message from Johncine Uchiha', 'You have 4 new messages.', NULL, 1, '2026-07-01 23:04:03'),
(15, 8, 'system', 'Workout plan recalculated', 'Your weekly exercise schedule has been refreshed based on your current profile.', NULL, 1, '2026-07-02 13:28:23'),
(16, 9, 'system', 'Welcome to FITTRACKS', 'Your profile is set up and your personalised workout plan is ready.', NULL, 1, '2026-07-02 13:45:21'),
(17, 10, 'system', 'Welcome to FITTRACKS', 'Your profile is set up and your personalised workout plan is ready.', NULL, 1, '2026-07-02 13:50:58'),
(18, 11, 'system', 'Welcome to FITTRACKS', 'Your profile is set up and your personalised workout plan is ready.', NULL, 1, '2026-07-02 14:02:05'),
(19, 8, 'system', 'Workout plan updated', 'Your workout plan was refreshed after logging new progress.', NULL, 1, '2026-07-02 23:19:32'),
(20, 8, 'milestone', 'Progress logged', 'Nice work — your latest measurements were saved.', NULL, 1, '2026-07-02 23:19:32'),
(21, 3, 'coach_message', 'New message from John Uchiha', 'hello can you check my progress', 8, 0, '2026-07-03 01:33:08'),
(22, 8, 'system', 'Training plan created', 'Johncine Uchiha created a training plan: Personalize Training Plan.', NULL, 1, '2026-07-03 01:43:54');

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
  `payment_method` enum('cash','card','bank_transfer','online','other','gcash') NOT NULL,
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

--
-- Dumping data for table `progress_logs`
--

INSERT INTO `progress_logs` (`log_id`, `user_id`, `log_date`, `weight_kg`, `body_fat_percent`, `chest_cm`, `waist_cm`, `hips_cm`, `arm_cm`, `photo_url`, `notes`, `recorded_by`, `created_at`) VALUES
(1, 8, '2026-07-03', 55.00, 10.00, 20.00, 23.00, NULL, 10.00, NULL, 'trial log', 8, '2026-07-02 23:19:31');

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
('engagement_weight_attendance', '40', 'Weight (%) of attendance in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_classes', '30', 'Weight (%) of class bookings in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_consistency', '20', 'Weight (%) of weekly consistency in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_progress', '10', 'Weight (%) of progress logs in the engagement score', NULL, '2026-07-01 08:23:45'),
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

--
-- Dumping data for table `trainer_assignments`
--

INSERT INTO `trainer_assignments` (`assignment_id`, `trainer_id`, `member_user_id`, `assigned_date`, `ended_date`, `status`, `assigned_by`) VALUES
(2, 1, 8, '2026-07-02', NULL, 'active', 1);

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

--
-- Dumping data for table `trainer_messages`
--

INSERT INTO `trainer_messages` (`message_id`, `sender_id`, `recipient_id`, `message_text`, `is_read`, `sent_at`) VALUES
(4, 3, 8, 'hello', 1, '2026-07-01 22:40:23'),
(5, 3, 8, 'hello?', 1, '2026-07-01 22:53:48'),
(6, 3, 8, 'hello', 1, '2026-07-01 22:54:03'),
(7, 3, 8, 'hello', 1, '2026-07-01 22:56:24'),
(8, 8, 3, 'hi', 1, '2026-07-01 22:57:29'),
(9, 3, 8, 'hahaahha\r\nhahahah', 1, '2026-07-01 23:03:52'),
(10, 3, 8, 'adad', 1, '2026-07-01 23:03:54'),
(11, 3, 8, 'adadad2aad', 1, '2026-07-01 23:03:58'),
(12, 3, 8, 'ggg', 1, '2026-07-01 23:04:03'),
(13, 8, 3, 'hello can you check my progress', 1, '2026-07-03 01:33:08');

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

--
-- Dumping data for table `training_plans`
--

INSERT INTO `training_plans` (`plan_id`, `member_user_id`, `trainer_id`, `title`, `goal`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(6, 9, NULL, 'Personalised Workout Plan', 'general_health', '2026-07-02', NULL, 'active', '2026-07-02 13:45:21', '2026-07-02 13:45:21'),
(7, 10, NULL, 'Personalised Workout Plan', 'fat_loss', '2026-07-02', NULL, 'active', '2026-07-02 13:50:58', '2026-07-02 13:50:58'),
(8, 11, NULL, 'Personalised Workout Plan', 'fat_loss', '2026-07-02', NULL, 'active', '2026-07-02 14:02:05', '2026-07-02 14:02:05'),
(9, 8, NULL, 'Personalised Workout Plan', 'muscle_gain', '2026-07-03', NULL, 'active', '2026-07-02 23:19:32', '2026-07-02 23:19:32'),
(10, 8, 1, 'Personalize Training Plan', 'muscle gain', '2026-07-03', '2026-07-10', 'active', '2026-07-03 01:43:54', '2026-07-03 01:43:54');

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

--
-- Dumping data for table `training_plan_exercises`
--

INSERT INTO `training_plan_exercises` (`plan_exercise_id`, `plan_id`, `exercise_id`, `day_of_week`, `sequence_order`, `sets`, `reps`, `target_weight_kg`, `rest_seconds`, `notes`) VALUES
(59, 6, 13, 1, 1, 3, '10-12', NULL, 60, NULL),
(60, 6, 4, 1, 2, 3, '10-12', NULL, 60, NULL),
(61, 6, 5, 1, 3, 3, '10-12', NULL, 60, NULL),
(62, 6, 19, 1, 4, 3, '15 mins', NULL, 45, NULL),
(63, 6, 17, 2, 1, 3, '15 mins', NULL, 45, NULL),
(64, 6, 19, 2, 2, 3, '15 mins', NULL, 45, NULL),
(65, 6, 12, 2, 3, 3, '10-12', NULL, 60, NULL),
(66, 6, 4, 2, 4, 3, '10-12', NULL, 60, NULL),
(67, 6, 16, 4, 1, 3, '10-12', NULL, 60, NULL),
(68, 6, 2, 4, 2, 3, '10-12', NULL, 60, NULL),
(69, 6, 19, 4, 3, 3, '15 mins', NULL, 45, NULL),
(70, 6, 20, 4, 4, 3, '15 mins', NULL, 45, NULL),
(71, 6, 9, 5, 1, 3, '10-12', NULL, 60, NULL),
(72, 6, 5, 5, 2, 3, '10-12', NULL, 60, NULL),
(73, 6, 3, 5, 3, 3, '10-12', NULL, 60, NULL),
(74, 6, 11, 5, 4, 3, '10-12', NULL, 60, NULL),
(75, 6, 15, 5, 5, 3, '10-12', NULL, 60, NULL),
(76, 7, 6, 1, 1, 3, '15 mins', NULL, 45, NULL),
(77, 7, 17, 1, 2, 3, '15 mins', NULL, 45, NULL),
(78, 7, 18, 1, 3, 3, '15 mins', NULL, 45, NULL),
(79, 7, 19, 1, 4, 3, '15 mins', NULL, 45, NULL),
(80, 7, 6, 2, 1, 3, '15 mins', NULL, 45, NULL),
(81, 7, 17, 2, 2, 3, '15 mins', NULL, 45, NULL),
(82, 7, 18, 2, 3, 3, '15 mins', NULL, 45, NULL),
(83, 7, 6, 3, 1, 3, '15 mins', NULL, 45, NULL),
(84, 7, 17, 3, 2, 3, '15 mins', NULL, 45, NULL),
(85, 7, 18, 3, 3, 3, '15 mins', NULL, 45, NULL),
(86, 7, 19, 3, 4, 3, '15 mins', NULL, 45, NULL),
(87, 7, 20, 3, 5, 3, '15 mins', NULL, 45, NULL),
(88, 7, 6, 5, 1, 3, '15 mins', NULL, 45, NULL),
(89, 7, 17, 5, 2, 3, '15 mins', NULL, 45, NULL),
(90, 7, 18, 5, 3, 3, '15 mins', NULL, 45, NULL),
(91, 7, 19, 5, 4, 3, '15 mins', NULL, 45, NULL),
(92, 7, 6, 6, 1, 3, '15 mins', NULL, 45, NULL),
(93, 7, 17, 6, 2, 3, '15 mins', NULL, 45, NULL),
(94, 7, 18, 6, 3, 3, '15 mins', NULL, 45, NULL),
(95, 8, 6, 1, 1, 3, '15 mins', NULL, 45, NULL),
(96, 8, 17, 1, 2, 3, '15 mins', NULL, 45, NULL),
(97, 8, 18, 1, 3, 3, '15 mins', NULL, 45, NULL),
(98, 8, 19, 1, 4, 3, '15 mins', NULL, 45, NULL),
(99, 8, 6, 3, 1, 3, '15 mins', NULL, 45, NULL),
(100, 8, 17, 3, 2, 3, '15 mins', NULL, 45, NULL),
(101, 8, 18, 3, 3, 3, '15 mins', NULL, 45, NULL),
(102, 8, 6, 5, 1, 3, '15 mins', NULL, 45, NULL),
(103, 8, 17, 5, 2, 3, '15 mins', NULL, 45, NULL),
(104, 8, 18, 5, 3, 3, '15 mins', NULL, 45, NULL),
(105, 9, 1, 1, 1, 4, '8-10', NULL, 90, NULL),
(106, 9, 2, 1, 2, 4, '8-10', NULL, 90, NULL),
(107, 9, 3, 1, 3, 4, '8-10', NULL, 90, NULL),
(108, 9, 4, 1, 4, 4, '8-10', NULL, 90, NULL),
(109, 9, 7, 1, 5, 4, '8-10', NULL, 90, NULL),
(110, 9, 1, 3, 1, 4, '8-10', NULL, 90, NULL),
(111, 9, 2, 3, 2, 4, '8-10', NULL, 90, NULL),
(112, 9, 3, 3, 3, 4, '8-10', NULL, 90, NULL),
(113, 9, 1, 5, 1, 4, '8-10', NULL, 90, NULL),
(114, 9, 2, 5, 2, 4, '8-10', NULL, 90, NULL),
(115, 9, 3, 5, 3, 4, '8-10', NULL, 90, NULL),
(116, 9, 4, 5, 4, 4, '8-10', NULL, 90, NULL),
(117, 9, 7, 5, 5, 4, '8-10', NULL, 90, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int UNSIGNED NOT NULL,
  `role` enum('admin','trainer','member') NOT NULL,
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
  `profile_picture` varchar(255) DEFAULT NULL,
  `engagement_score` tinyint UNSIGNED DEFAULT NULL,
  `engagement_computed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `password_hash`, `phone`, `status`, `email_verified_at`, `qr_token`, `qr_expires_at`, `created_at`, `updated_at`, `profile_picture`, `engagement_score`, `engagement_computed_at`) VALUES
(1, 'admin', 'System', 'Admin', 'admin@fittrack.com', '$2y$10$/CPq8sX.y8nIzDv8zqSz1edUSANAC8Y2UBV9HhrENCFCDi2.ddOSW', '09703736380', 'active', '2026-07-01 09:01:32', NULL, NULL, '2026-06-24 23:48:32', '2026-07-01 01:01:32', 'profile_1_1782348581.png', NULL, NULL),
(3, 'trainer', 'Johncine', 'Uchiha', 'janicemartil2@gmail.com', '$2y$10$/cryoTHsqH5Vy3p5ZDsrT.eJxD/SuBCZRSliI8Gk5O5npRaF9GkBu', '09070284462', 'active', '2026-07-01 09:01:32', NULL, NULL, '2026-06-25 01:16:37', '2026-07-01 22:19:36', 'profile_3_1782452603.png', NULL, NULL),
(8, 'member', 'John', 'Uchiha', 'johncinemartil596@gmail.com', '$2y$10$EP/VetyymgyFKjmQsLsvm.lDJORTGmnb.9hmrZm1vvBM5D1Dk/eSG', '09070284462', 'active', '2026-07-01 15:38:50', '6fd89212fb60133bd8fc672006790727', '2026-07-03 02:51:35', '2026-07-01 07:38:36', '2026-07-03 00:46:35', 'profile_8_c3daad53111ca5cd.jpg', 18, '2026-07-03 06:24:11'),
(9, 'member', 'fahhh', 'Graar', 'fah@gmail.com', '$2y$10$nBMQ0tn9bgOtHOzMDIfF9uFAwPJ4/Z9o0XyZasl3YUoWW2r/J58Sy', '09703736380', 'active', '2026-07-02 21:44:24', NULL, NULL, '2026-07-02 13:44:24', '2026-07-02 13:45:56', NULL, 0, '2026-07-02 21:45:21'),
(10, 'member', 'Brother', 'Louie', 'brother@gmail.com', '$2y$10$P117MPGhM9bDKq4emyBh5OSVzUMLdESHJN.BM.vIZIRjTKQg4dluC', '09703736380', 'active', '2026-07-02 21:49:57', NULL, NULL, '2026-07-02 13:49:57', '2026-07-02 13:54:56', NULL, 0, '2026-07-02 21:50:58'),
(11, 'member', 'Sister', 'loyi', 'sister@gmail.com', '$2y$10$0pZhs9GINumP6SO7UenvPutVnNDq8NCkwpYeBvdmtNjAWahWKa7FC', '09703736380', 'active', '2026-07-02 21:54:17', NULL, NULL, '2026-07-02 13:54:17', '2026-07-02 14:05:41', 'profile_11_010a927246af957a.jpg', 0, '2026-07-02 22:02:05');

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
  `processed_by` int UNSIGNED DEFAULT NULL,
  `converted_to_member_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `walk_in_transactions`
--

INSERT INTO `walk_in_transactions` (`transaction_id`, `guest_name`, `contact_info`, `amount_paid`, `visit_date`, `processed_by`, `converted_to_member_id`) VALUES
(1, 'johncine', '09703736380', 100.00, '2026-06-25 07:51:15', 1, NULL),
(2, 'hahaahh', '09703736380', 15.00, '2026-07-01 17:56:03', 1, NULL);

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
-- Indexes for table `checkout_ratings`
--
ALTER TABLE `checkout_ratings`
  ADD PRIMARY KEY (`rating_id`),
  ADD UNIQUE KEY `uq_attendance` (`attendance_id`);

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
-- Indexes for table `exercise_completions`
--
ALTER TABLE `exercise_completions`
  ADD PRIMARY KEY (`completion_id`),
  ADD UNIQUE KEY `unique_daily_completion` (`user_id`,`plan_id`,`exercise_id`,`completed_date`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `exercise_id` (`exercise_id`);

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
  ADD KEY `fk_walkin_staff` (`processed_by`),
  ADD KEY `fk_walkin_member` (`converted_to_member_id`);

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
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `checkout_ratings`
--
ALTER TABLE `checkout_ratings`
  MODIFY `rating_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `class_bookings`
--
ALTER TABLE `class_bookings`
  MODIFY `booking_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `exercises`
--
ALTER TABLE `exercises`
  MODIFY `exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `exercise_completions`
--
ALTER TABLE `exercise_completions`
  MODIFY `completion_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `membership_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `membership_plans`
--
ALTER TABLE `membership_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `member_profiles`
--
ALTER TABLE `member_profiles`
  MODIFY `profile_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `progress_logs`
--
ALTER TABLE `progress_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  MODIFY `assignment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  MODIFY `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  MODIFY `trainer_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `training_plans`
--
ALTER TABLE `training_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `training_plan_exercises`
--
ALTER TABLE `training_plan_exercises`
  MODIFY `plan_exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `walk_in_transactions`
--
ALTER TABLE `walk_in_transactions`
  MODIFY `transaction_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- Constraints for table `exercise_completions`
--
ALTER TABLE `exercise_completions`
  ADD CONSTRAINT `exercise_completions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exercise_completions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `training_plans` (`plan_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exercise_completions_ibfk_3` FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`exercise_id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `fk_walkin_member` FOREIGN KEY (`converted_to_member_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_walkin_staff` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
