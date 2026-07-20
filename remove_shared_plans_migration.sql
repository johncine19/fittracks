-- Run this script on your live database to remove the shared membership plans feature

-- Drop obsolete tables
DROP TABLE IF EXISTS `shared_plan_gyms`;
DROP TABLE IF EXISTS `gym_share_payouts`;

-- Drop the plan_scope column from membership_plans
ALTER TABLE `membership_plans` DROP COLUMN `plan_scope`;
