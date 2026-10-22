-- Extra columns for the persisted roadmap tasks. Applied by scripts/migrate.php,
-- which tolerates "duplicate column" so this file is safe to re-run.
ALTER TABLE roadmap_items ADD COLUMN week_no TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE roadmap_items ADD COLUMN task_type VARCHAR(30) NOT NULL DEFAULT 'technical';
ALTER TABLE roadmap_items ADD COLUMN title VARCHAR(180) NULL;
ALTER TABLE roadmap_items ADD COLUMN resource VARCHAR(255) NULL;
