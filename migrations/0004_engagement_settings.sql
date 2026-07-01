-- Adds engagement weighting and threshold configuration to system_settings
-- Deletes unused nutrition and BMR constants

DELETE FROM system_settings WHERE setting_key LIKE 'bmr_%'
   OR setting_key LIKE 'macro_%'
   OR setting_key LIKE 'activity_%'
   OR setting_key LIKE 'goal_%';

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
  ('engagement_weight_attendance', '40', 'Max points for attendance frequency (30 days).'),
  ('engagement_weight_classes', '30', 'Max points for class participation (30 days).'),
  ('engagement_weight_consistency', '20', 'Max points for weekly consistency (30 days).'),
  ('engagement_weight_progress', '10', 'Max points for progress log updates (60 days).'),
  ('engagement_threshold_high', '75', 'Score at or above = Highly Engaged.'),
  ('engagement_threshold_moderate', '40', 'Score at or above = Moderately Engaged. Below = At-Risk.');
