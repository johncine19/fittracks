-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 14, 2026 at 07:04 AM
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

--
-- Dumping data for table `admin_audit_logs`
--

INSERT INTO `admin_audit_logs` (`log_id`, `admin_user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`) VALUES
(1, 1, 'edit', 'user', '19', '{\"email\":\"john@gmail.com\",\"role\":\"member\",\"password_changed\":false}', '2026-07-09 00:44:14'),
(2, 1, 'edit', 'plan', '4', '{\"plan_name\":\"Weekly Pack\",\"price\":\"200\"}', '2026-07-09 00:46:41'),
(3, 1, 'checkout', 'attendance', '20', NULL, '2026-07-09 03:00:48'),
(4, 1, 'checkout', 'attendance', '17', NULL, '2026-07-09 03:00:50'),
(5, 1, 'delete', 'user', '10', '{\"role\":\"member\"}', '2026-07-14 02:37:32'),
(6, 1, 'delete', 'user', '9', '{\"role\":\"member\"}', '2026-07-14 02:37:39'),
(7, 1, 'delete', 'user', '18', '{\"role\":\"member\"}', '2026-07-14 02:37:53'),
(8, 1, 'delete', 'user', '11', '{\"role\":\"member\"}', '2026-07-14 02:38:01'),
(9, 1, 'delete', 'user', '12', '{\"role\":\"trainer\"}', '2026-07-14 02:38:17'),
(10, 1, 'delete', 'user', '22', '{\"role\":\"gym_owner\"}', '2026-07-14 03:36:58'),
(11, 3, 'create', 'class', '8', '{\"class_name\":\"Yoga class\"}', '2026-07-14 03:39:07'),
(12, 3, 'create', 'class_schedule', '0', '{\"class_id\":\"8\",\"start\":\"2026-07-14T11:39\"}', '2026-07-14 03:39:23'),
(13, 1, 'delete', 'user', '23', '{\"role\":\"member\"}', '2026-07-14 03:49:07'),
(14, 1, 'delete', 'user', '3', '{\"role\":\"trainer\"}', '2026-07-14 04:39:37'),
(15, 1, 'delete', 'user', '24', '{\"role\":\"member\"}', '2026-07-14 04:39:43'),
(16, 1, 'edit', 'user', '25', '{\"email\":\"owner@example.com\",\"role\":\"gym_owner\",\"password_changed\":true}', '2026-07-14 04:50:51'),
(17, 1, 'edit', 'user', '25', '{\"email\":\"johncine.martil@nmsc.edu.ph\",\"role\":\"gym_owner\",\"password_changed\":false}', '2026-07-14 04:51:32');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` enum('all','gym_owners','trainers','members') NOT NULL DEFAULT 'all',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  `recorded_by` int UNSIGNED DEFAULT NULL,
  `gym_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
(7, 14, 8, 5, 'Goods the ambience is great', '2026-07-05 01:05:03'),
(10, 16, 9, 5, 'adadd', '2026-07-08 02:18:26');

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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `gym_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `created_by`, `class_name`, `description`, `instructor_id`, `capacity`, `created_at`, `gym_id`) VALUES
(9, NULL, 'Ultimate HIIT Burn', 'High-intensity interval training designed to burn maximum fat and calories in 45 minutes.', NULL, 30, '2026-07-14 04:19:41', 2),
(10, NULL, 'Core & Abs Crusher', 'A specialized core class focusing on the abdominal wall to reveal that six-pack.', NULL, 20, '2026-07-14 04:19:41', 2),
(11, NULL, 'Spin & Sweat Cycle', 'Intense indoor cycling class that guarantees a massive sweat session and calorie burn.', NULL, 40, '2026-07-14 04:19:41', 2),
(12, NULL, 'Zumba Fat Blast', 'Dance your way to fat loss with this high-energy cardiovascular workout.', NULL, 50, '2026-07-14 04:19:41', 2),
(13, NULL, 'Powerlifting 101', 'Learn the mechanics of the big lifts: Squat, Bench, and Deadlift for maximum strength.', NULL, 15, '2026-07-14 04:19:41', 2),
(14, NULL, 'Hypertrophy Bootcamp', 'Targeted muscle isolation exercises designed for optimal muscle growth and bodybuilding.', NULL, 25, '2026-07-14 04:19:41', 2),
(15, NULL, 'CrossFit Power', 'Explosive movements and heavy lifting to build raw power and overall strength.', NULL, 20, '2026-07-14 04:19:41', 2),
(16, NULL, 'Heavy Weights Hour', 'Guided free weight sessions for intermediate and advanced lifters focusing on muscle gain.', NULL, 20, '2026-07-14 04:19:41', 2),
(17, NULL, 'Sunrise Yoga Flow', 'Start your day with a gentle stretch and mindfulness practice for overall wellness.', NULL, 30, '2026-07-14 04:19:41', 2),
(18, NULL, 'Mobility & Balance', 'Focus on joint health, flexibility, and stability to move freely and without pain.', NULL, 20, '2026-07-14 04:19:41', 2),
(19, NULL, 'Pilates Core Wellness', 'Low-impact movements for a healthy spine, better posture, and holistic health.', NULL, 25, '2026-07-14 04:19:41', 2),
(20, NULL, 'Restorative Stretch', 'Deep stretching techniques to enhance physical endurance and daily mobility.', NULL, 20, '2026-07-14 04:19:41', 2);

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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `gym_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dietary_plans`
--

CREATE TABLE `dietary_plans` (
  `plan_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `goal` varchar(100) NOT NULL,
  `status` enum('active','completed','cancelled','draft') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dietary_plan_meals`
--

CREATE TABLE `dietary_plan_meals` (
  `meal_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `day_of_week` tinyint UNSIGNED NOT NULL COMMENT '1=Monday...7=Sunday',
  `meal_type` enum('Breakfast','Lunch','Dinner','Snack') NOT NULL,
  `food_items` text NOT NULL,
  `calories` int UNSIGNED DEFAULT '0',
  `protein_g` int UNSIGNED DEFAULT '0',
  `carbs_g` int UNSIGNED DEFAULT '0',
  `fat_g` int UNSIGNED DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `diet_rules`
--

CREATE TABLE `diet_rules` (
  `rule_id` int UNSIGNED NOT NULL,
  `experience_level` int NOT NULL DEFAULT '1',
  `biological_sex` enum('male','female','any') NOT NULL DEFAULT 'any',
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any',
  `macro_split` varchar(255) NOT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `diet_rules`
--

INSERT INTO `diet_rules` (`rule_id`, `experience_level`, `biological_sex`, `primary_goal`, `activity_level`, `macro_split`, `notes`) VALUES
(1, 1, 'any', 'fat_loss', 'any', '35% Protein / 35% Carbs / 30% Fat', 'Maintain a 300-500 calorie deficit.'),
(2, 1, 'any', 'muscle_gain', 'any', '30% Protein / 45% Carbs / 25% Fat', 'Maintain a 200-300 calorie surplus.'),
(3, 1, 'any', 'general_health', 'any', '25% Protein / 45% Carbs / 30% Fat', 'Eat at maintenance calories.'),
(4, 2, 'any', 'fat_loss', 'any', '40% Protein / 30% Carbs / 30% Fat', 'Higher protein to preserve muscle mass in a deficit.'),
(5, 2, 'any', 'muscle_gain', 'any', '30% Protein / 50% Carbs / 20% Fat', 'Higher carbs to fuel intense training sessions.'),
(6, 3, 'any', 'fat_loss', 'any', '40% Protein / 30% Carbs / 30% Fat', 'Aggressive deficit with refeed days.'),
(7, 3, 'any', 'muscle_gain', 'any', '30% Protein / 50% Carbs / 20% Fat', 'Carefully tracked surplus to minimize fat gain.');

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
  `description` varchar(255) DEFAULT NULL,
  `difficulty_level` int NOT NULL DEFAULT '1' COMMENT '1=Starter, 2=Intermediate, 3=Advanced'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `exercises`
--

INSERT INTO `exercises` (`exercise_id`, `name`, `category`, `muscle_group`, `description`, `difficulty_level`) VALUES
(1, 'Squat', 'strength', 'legs', 'Compound lower-body lift.', 2),
(2, 'Bench press', 'strength', 'chest', 'Horizontal push movement.', 2),
(3, 'Deadlift', 'strength', 'posterior chain', 'Hip hinge strength movement.', 2),
(4, 'Lat pulldown', 'strength', 'back', 'Vertical pull movement.', 1),
(5, 'Plank', 'core', 'abdominals', 'Anti-extension core hold.', 1),
(6, 'Treadmill intervals', 'cardio', 'full body', 'Alternating work and recovery intervals.', 1),
(7, 'Overhead press', 'strength', 'shoulders', 'Vertical push for shoulder strength.', 2),
(8, 'Barbell row', 'strength', 'back', 'Horizontal pull for upper back.', 2),
(9, 'Leg press', 'strength', 'legs', 'Machine-based quad and glute work.', 1),
(10, 'Dumbbell lunges', 'strength', 'legs', 'Unilateral lower-body strength.', 1),
(11, 'Bicep curls', 'strength', 'arms', 'Isolation curl for biceps.', 1),
(12, 'Tricep pushdown', 'strength', 'arms', 'Cable pushdown for triceps.', 1),
(13, 'Lateral raise', 'strength', 'shoulders', 'Isolation work for side delts.', 1),
(14, 'Russian twists', 'core', 'obliques', 'Rotational core exercise.', 1),
(15, 'Hanging leg raise', 'core', 'abdominals', 'Lower-ab focused core movement.', 1),
(16, 'Dead bug', 'core', 'abdominals', 'Core stability with limb coordination.', 1),
(17, 'Stationary bike', 'cardio', 'legs', 'Low-impact steady or interval cycling.', 1),
(18, 'Rowing machine', 'cardio', 'full body', 'Full-body cardio with pull drive.', 1),
(19, 'Jump rope', 'cardio', 'full body', 'High-intensity footwork and conditioning.', 1),
(20, 'Stair climber', 'cardio', 'legs', 'Continuous stepping cardio.', 1);

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

-- --------------------------------------------------------

--
-- Table structure for table `gyms`
--

CREATE TABLE `gyms` (
  `gym_id` int UNSIGNED NOT NULL,
  `owner_user_id` int UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `contact_info` varchar(100) DEFAULT NULL,
  `business_permit_url` varchar(255) DEFAULT NULL,
  `valid_id_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gyms`
--

INSERT INTO `gyms` (`gym_id`, `owner_user_id`, `name`, `address`, `contact_info`, `business_permit_url`, `status`, `created_at`, `updated_at`) VALUES
(2, 25, 'Elite Fitness Center', '123 Fit Street', '09703736380', 'permit_6a55c0d2a45f9.png', 'approved', '2026-07-14 04:19:41', '2026-07-14 04:53:38');

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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT '5.00',
  `gym_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `membership_plans`
--

INSERT INTO `membership_plans` (`plan_id`, `plan_name`, `plan_type`, `duration_days`, `price`, `description`, `is_active`, `created_at`, `commission_rate`, `gym_id`) VALUES
(1, 'Monthly Starter', 'monthly', 30, 1200.00, 'Gym access with standard class booking.', 1, '2026-06-24 23:48:32', 40.00, NULL),
(2, 'Quarterly Plus', 'quarterly', 90, 3200.00, 'Best for consistent members and coaching add-ons.', 1, '2026-06-24 23:48:32', 40.00, NULL),
(3, 'Annual Elite', 'annual', 365, 12000.00, 'Full-year access with preferred class scheduling.', 1, '2026-06-24 23:48:32', 40.00, NULL),
(4, 'Weekly Pack', 'custom', 7, 200.00, 'Weekly subscription', 1, '2026-07-02 23:02:41', 40.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `member_profiles`
--

CREATE TABLE `member_profiles` (
  `profile_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `neck_cm` decimal(5,2) DEFAULT NULL,
  `waist_cm` decimal(5,2) DEFAULT NULL,
  `hip_cm` decimal(5,2) DEFAULT NULL,
  `age` int UNSIGNED NOT NULL DEFAULT '30',
  `biological_sex` enum('male','female') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active') NOT NULL,
  `primary_goal` varchar(255) NOT NULL,
  `dietary_restrictions` varchar(255) DEFAULT 'none',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fitness_tier` int DEFAULT '1',
  `completed_weeks` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_transfers`
--

CREATE TABLE `member_transfers` (
  `transfer_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `from_gym_id` int UNSIGNED NOT NULL,
  `to_gym_id` int UNSIGNED NOT NULL,
  `status` enum('pending_current_gym','pending_receiving_gym','approved','rejected') NOT NULL DEFAULT 'pending_current_gym',
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL
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
  `reference_id` int UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `type`, `title`, `message`, `reference_id`, `is_read`, `created_at`) VALUES
(2, 1, 'system', 'Test notification', 'Notifications are working.', NULL, 1, '2026-07-01 02:25:31'),
(35, 1, 'system', 'New Subscription Request', 'John Uchiha requested a Weekly Pack membership. Payment method: CASH.', NULL, 1, '2026-07-04 03:28:08'),
(92, 1, 'system', 'New Subscription Request', 'Laison Clinton requested a Weekly Pack membership. Payment method: CASH.', NULL, 1, '2026-07-07 01:08:25'),
(96, 1, 'system', 'New Subscription Request', 'Laison Clinton requested a Monthly Starter membership. Payment method: CASH.', NULL, 1, '2026-07-07 01:22:39'),
(99, 1, 'system', 'Trainer Appointment Request', 'Ichigo Kurosaki has requested an appointment.', NULL, 1, '2026-07-08 00:30:37'),
(101, 1, 'system', 'Trainer Appointment Request', 'Ichigo Kurosaki has requested an appointment.', NULL, 1, '2026-07-08 00:35:00'),
(102, 1, 'system', 'Trainer Appointment Request', 'Ichigo Kurosaki has requested an appointment.', NULL, 1, '2026-07-08 00:35:03'),
(106, 1, 'system', 'Appointment Rejected', 'Trainer John rejected the appointment request. Reason: Im not available right now', NULL, 1, '2026-07-08 00:36:26'),
(107, 1, 'system', 'Trainer Appointment Request', 'Ichigo Kurosaki has requested an appointment.', NULL, 1, '2026-07-08 00:58:03'),
(114, 1, 'system', 'Appointment Rejected', 'Trainer John rejected the appointment request. Reason: im not at feeling well', NULL, 1, '2026-07-08 06:06:45'),
(116, 1, 'system', 'New Subscription Request', 'adad haha requested a Weekly Pack membership. Payment method: CASH.', NULL, 1, '2026-07-08 08:24:13'),
(126, 1, 'system', 'Trainer Appointment Request', 'John Cine has requested an appointment.', NULL, 1, '2026-07-08 08:59:55'),
(127, 1, 'system', 'Trainer Appointment Request', 'John Cine has requested an appointment.', NULL, 1, '2026-07-08 09:12:12'),
(142, 1, 'system', 'Appointment Rejected', 'Trainer John rejected the appointment request.', NULL, 1, '2026-07-08 10:05:01'),
(145, 1, 'system', 'New Subscription Request', 'Sister loyi requested a Weekly Pack membership. Payment method: CASH.', NULL, 1, '2026-07-09 06:55:08');

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
('at_risk_inactivity_days', '3', 'Days of inactivity before sending at-risk notification', NULL, '2026-07-09 02:03:35'),
('at_risk_notification_cooldown', '14', 'Cooldown in days between consecutive at-risk notifications', NULL, '2026-07-09 02:03:36'),
('engagement_weight_attendance', '40', 'Weight (%) of attendance in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_classes', '20', 'Weight (%) of class bookings in the engagement score', NULL, '2026-07-09 01:47:49'),
('engagement_weight_consistency', '20', 'Weight (%) of weekly consistency in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_progress', '10', 'Weight (%) of progress logs in the engagement score', NULL, '2026-07-01 08:23:45'),
('engagement_weight_workouts', '10', 'Weight (%) of daily completed workouts in the engagement score', NULL, '2026-07-09 01:47:49'),
('last_at_risk_scan_date', '2026-07-14', NULL, NULL, '2026-07-14 02:05:10'),
('last_at_risk_settings_update', '2000-01-01 00:00:00', 'Timestamp when at-risk settings were last modified (15-day cooldown)', NULL, '2026-07-09 02:03:36');

-- --------------------------------------------------------

--
-- Table structure for table `trainer_assignments`
--

CREATE TABLE `trainer_assignments` (
  `assignment_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `member_user_id` int UNSIGNED NOT NULL,
  `assigned_date` datetime NOT NULL,
  `ended_date` datetime DEFAULT NULL,
  `status` enum('pending_admin','pending_trainer','active','rejected','ended') NOT NULL DEFAULT 'pending_admin',
  `assigned_by` int UNSIGNED DEFAULT NULL,
  `rejection_reason` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_commissions`
--

CREATE TABLE `trainer_commissions` (
  `commission_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `payment_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `gym_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `status` enum('active','completed','archived','draft') NOT NULL DEFAULT 'draft',
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
  `role` enum('platform_admin','gym_owner','trainer','member') NOT NULL,
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
(1, 'platform_admin', 'FitTrack', 'Admin', 'admin@fittrack.com', '$2y$10$/CPq8sX.y8nIzDv8zqSz1edUSANAC8Y2UBV9HhrENCFCDi2.ddOSW', '09703736380', 'active', '2026-07-01 09:01:32', NULL, NULL, '2026-06-24 23:48:32', '2026-07-14 01:49:23', 'profile_1_1782348581.png', NULL, NULL),
(25, 'gym_owner', 'Gym', 'Owner', 'johncine.martil@nmsc.edu.ph', '$2y$10$o0Y6OryORCuml7/EDWQ77O6EugeHOidS0n8IROsFzAzQQTLNp2YO6', '09703736380', 'active', '2026-07-14 12:52:11', NULL, NULL, '2026-07-14 04:19:41', '2026-07-14 04:52:11', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `walk_in_transactions`
--

CREATE TABLE `walk_in_transactions` (
  `transaction_id` int UNSIGNED NOT NULL,
  `guest_name` varchar(100) NOT NULL,
  `contact_info` varchar(100) DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','gcash') NOT NULL DEFAULT 'cash',
  `visit_date` datetime NOT NULL,
  `processed_by` int UNSIGNED DEFAULT NULL,
  `converted_to_member_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `walk_in_transactions`
--

INSERT INTO `walk_in_transactions` (`transaction_id`, `guest_name`, `contact_info`, `amount_paid`, `payment_method`, `visit_date`, `processed_by`, `converted_to_member_id`) VALUES
(1, 'johncine', '09703736380', 100.00, 'cash', '2026-06-25 07:51:15', 1, NULL),
(2, 'Janice Martil', '09703736380', 15.00, 'cash', '2026-07-01 17:56:03', 1, NULL),
(3, 'Sister loyi', '09703736380', 20.00, 'cash', '2026-07-04 12:57:18', 1, NULL),
(4, 'Ano jay', 'N/A', 100.00, 'cash', '2026-07-07 08:05:17', 1, NULL),
(5, 'Lara', 'N/A', 100.00, 'cash', '2026-07-07 08:39:46', 1, NULL),
(6, 'adad haha', 'N/A', 100.00, 'cash', '2026-07-08 10:21:14', 1, NULL),
(7, 'John Cine', '09070284462', 30.00, 'cash', '2026-07-08 18:14:03', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `workout_rules`
--

CREATE TABLE `workout_rules` (
  `rule_id` int UNSIGNED NOT NULL,
  `experience_level` int NOT NULL DEFAULT '1' COMMENT 'Maps to fitness_tier (1=Beginner, 2=Intermediate, 3=Advanced)',
  `biological_sex` enum('male','female','any') NOT NULL DEFAULT 'any',
  `primary_goal` enum('fat_loss','muscle_gain','maintenance','general_health') NOT NULL,
  `activity_level` enum('sedentary','lightly_active','moderately_active','very_active','extra_active','any') NOT NULL DEFAULT 'any',
  `recommended_workout_structure` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `workout_rules`
--

INSERT INTO `workout_rules` (`rule_id`, `experience_level`, `biological_sex`, `primary_goal`, `activity_level`, `recommended_workout_structure`) VALUES
(1, 1, 'any', 'fat_loss', 'any', '3 days Full Body Resistance (Machine focused), 2 days Moderate Cardio (30 mins)'),
(2, 1, 'any', 'muscle_gain', 'any', '3 days Full Body Resistance (Dumbbell/Machine), 1 day Active Recovery'),
(3, 1, 'any', 'general_health', 'any', '2 days Full Body Resistance, 2 days Light Cardio / Yoga'),
(4, 2, 'any', 'fat_loss', 'any', '4 days Upper/Lower Split, 2 days HIIT (20 mins)'),
(5, 2, 'any', 'muscle_gain', 'any', '4 days Upper/Lower Split with Progressive Overload'),
(6, 3, 'any', 'muscle_gain', 'any', '5 days Push/Pull/Legs Split, heavy compound lifts'),
(7, 3, 'any', 'fat_loss', 'any', '5 days PPL Split, + 3 sessions fasted cardio');

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
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `fk_announcement_author` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `fk_attendance_user` (`user_id`),
  ADD KEY `fk_attendance_schedule` (`schedule_id`),
  ADD KEY `fk_attendance_staff` (`recorded_by`),
  ADD KEY `idx_attendance_checkin` (`check_in_time`),
  ADD KEY `fk_attend_gym` (`gym_id`);

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
  ADD KEY `fk_class_instructor` (`instructor_id`),
  ADD KEY `fk_class_gym` (`gym_id`);

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
  ADD KEY `idx_schedule_start` (`start_datetime`),
  ADD KEY `fk_sched_gym` (`gym_id`);

--
-- Indexes for table `dietary_plans`
--
ALTER TABLE `dietary_plans`
  ADD PRIMARY KEY (`plan_id`),
  ADD KEY `fk_diet_plan_member` (`member_user_id`),
  ADD KEY `fk_diet_plan_trainer` (`trainer_id`);

--
-- Indexes for table `dietary_plan_meals`
--
ALTER TABLE `dietary_plan_meals`
  ADD PRIMARY KEY (`meal_id`),
  ADD KEY `fk_diet_meal_plan` (`plan_id`);

--
-- Indexes for table `diet_rules`
--
ALTER TABLE `diet_rules`
  ADD PRIMARY KEY (`rule_id`);

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
-- Indexes for table `gyms`
--
ALTER TABLE `gyms`
  ADD PRIMARY KEY (`gym_id`),
  ADD KEY `fk_gym_owner` (`owner_user_id`);

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
  ADD PRIMARY KEY (`plan_id`),
  ADD KEY `fk_mem_plan_gym` (`gym_id`);

--
-- Indexes for table `member_profiles`
--
ALTER TABLE `member_profiles`
  ADD PRIMARY KEY (`profile_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `member_transfers`
--
ALTER TABLE `member_transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `fk_transfer_user` (`user_id`),
  ADD KEY `fk_transfer_from_gym` (`from_gym_id`),
  ADD KEY `fk_transfer_to_gym` (`to_gym_id`);

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
-- Indexes for table `trainer_commissions`
--
ALTER TABLE `trainer_commissions`
  ADD PRIMARY KEY (`commission_id`),
  ADD KEY `trainer_id` (`trainer_id`),
  ADD KEY `payment_id` (`payment_id`);

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
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `fk_trainer_gym` (`gym_id`);

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
-- Indexes for table `workout_rules`
--
ALTER TABLE `workout_rules`
  ADD PRIMARY KEY (`rule_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `checkout_ratings`
--
ALTER TABLE `checkout_ratings`
  MODIFY `rating_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `class_bookings`
--
ALTER TABLE `class_bookings`
  MODIFY `booking_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `dietary_plans`
--
ALTER TABLE `dietary_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dietary_plan_meals`
--
ALTER TABLE `dietary_plan_meals`
  MODIFY `meal_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `diet_rules`
--
ALTER TABLE `diet_rules`
  MODIFY `rule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `exercises`
--
ALTER TABLE `exercises`
  MODIFY `exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `exercise_completions`
--
ALTER TABLE `exercise_completions`
  MODIFY `completion_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `gyms`
--
ALTER TABLE `gyms`
  MODIFY `gym_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `membership_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `membership_plans`
--
ALTER TABLE `membership_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `member_profiles`
--
ALTER TABLE `member_profiles`
  MODIFY `profile_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `member_transfers`
--
ALTER TABLE `member_transfers`
  MODIFY `transfer_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=156;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `progress_logs`
--
ALTER TABLE `progress_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `trainer_assignments`
--
ALTER TABLE `trainer_assignments`
  MODIFY `assignment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `trainer_commissions`
--
ALTER TABLE `trainer_commissions`
  MODIFY `commission_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `trainer_messages`
--
ALTER TABLE `trainer_messages`
  MODIFY `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `trainer_profiles`
--
ALTER TABLE `trainer_profiles`
  MODIFY `trainer_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `training_plans`
--
ALTER TABLE `training_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `training_plan_exercises`
--
ALTER TABLE `training_plan_exercises`
  MODIFY `plan_exercise_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=644;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `walk_in_transactions`
--
ALTER TABLE `walk_in_transactions`
  MODIFY `transaction_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `workout_rules`
--
ALTER TABLE `workout_rules`
  MODIFY `rule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announcement_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attend_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules` (`schedule_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_staff` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_class_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL,
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
  ADD CONSTRAINT `fk_sched_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_schedule_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE;

--
-- Constraints for table `dietary_plans`
--
ALTER TABLE `dietary_plans`
  ADD CONSTRAINT `fk_diet_plan_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_diet_plan_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE CASCADE;

--
-- Constraints for table `dietary_plan_meals`
--
ALTER TABLE `dietary_plan_meals`
  ADD CONSTRAINT `fk_diet_meal_plan` FOREIGN KEY (`plan_id`) REFERENCES `dietary_plans` (`plan_id`) ON DELETE CASCADE;

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
-- Constraints for table `gyms`
--
ALTER TABLE `gyms`
  ADD CONSTRAINT `fk_gym_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships`
--
ALTER TABLE `memberships`
  ADD CONSTRAINT `fk_membership_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_membership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `membership_plans`
--
ALTER TABLE `membership_plans`
  ADD CONSTRAINT `fk_mem_plan_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE;

--
-- Constraints for table `member_profiles`
--
ALTER TABLE `member_profiles`
  ADD CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `member_transfers`
--
ALTER TABLE `member_transfers`
  ADD CONSTRAINT `fk_transfer_from_gym` FOREIGN KEY (`from_gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_transfer_to_gym` FOREIGN KEY (`to_gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_transfer_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

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
-- Constraints for table `trainer_commissions`
--
ALTER TABLE `trainer_commissions`
  ADD CONSTRAINT `trainer_commissions_ibfk_1` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trainer_commissions_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `fk_coach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_trainer_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE SET NULL;

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

-- --------------------------------------------------------

--
-- Table structure for table `gym_members`
--
CREATE TABLE IF NOT EXISTS `gym_members` (
  `user_id` int UNSIGNED NOT NULL,
  `gym_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for table `gym_members`
--
ALTER TABLE `gym_members`
  ADD PRIMARY KEY (`user_id`,`gym_id`),
  ADD KEY `fk_gym_members_gym` (`gym_id`);

--
-- Constraints for table `gym_members`
--
ALTER TABLE `gym_members`
  ADD CONSTRAINT `fk_gym_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gym_members_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE;

--
-- Constraints for table `checkout_ratings`
--
ALTER TABLE `checkout_ratings`
  ADD CONSTRAINT `fk_checkout_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checkout_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
