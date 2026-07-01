-- Allow system-generated workout plans without an assigned trainer.
ALTER TABLE `training_plans` DROP FOREIGN KEY `fk_trainingplan_coach`;
ALTER TABLE `training_plans` MODIFY `trainer_id` int UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `training_plans`
  ADD CONSTRAINT `fk_trainingplan_coach` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE CASCADE;
