CREATE TABLE IF NOT EXISTS exercise_completions (
    completion_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NOT NULL,
    completed_date DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES training_plans(plan_id) ON DELETE CASCADE,
    FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id) ON DELETE CASCADE,
    UNIQUE KEY unique_daily_completion (user_id, plan_id, exercise_id, completed_date)
);

ALTER TABLE member_profiles 
ADD COLUMN fitness_tier INT DEFAULT 1,
ADD COLUMN completed_weeks INT DEFAULT 0;
