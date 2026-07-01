-- Add cached engagement score columns to users table

ALTER TABLE users
  ADD COLUMN engagement_score TINYINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN engagement_computed_at DATETIME NULL DEFAULT NULL;
