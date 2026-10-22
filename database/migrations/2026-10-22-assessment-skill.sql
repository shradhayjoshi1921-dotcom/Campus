-- Skill tagging for assessment questions.
-- Additive and re-runnable: scripts/migrate.php tolerates MySQL error 1060
-- ("duplicate column"), so an install that already has the column is untouched.

ALTER TABLE assessment_questions ADD COLUMN skill VARCHAR(100) NULL;
