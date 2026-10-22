-- AI career intelligence: additive only.
-- Every statement is IF NOT EXISTS / guarded, so re-running this file is safe
-- and no existing row is touched. Apply with: php scripts/migrate.php

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(190) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Provenance for a skill: a claim, a project, a certification, an assessment or
-- staff verification. `student_skills` stays the student's own list; this table
-- records *why* a skill should be believed.
CREATE TABLE IF NOT EXISTS skill_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  skill_name VARCHAR(100) NOT NULL,
  source_type ENUM('claim','project','certification','assessment','resume','staff') NOT NULL DEFAULT 'claim',
  source_ref VARCHAR(190) NULL,
  level ENUM('claimed','project','certification','assessment','verified') NOT NULL DEFAULT 'claimed',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evidence_student_skill_source (student_id, skill_name, source_type, source_ref),
  INDEX idx_evidence_student (student_id),
  CONSTRAINT fk_evidence_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Text extracted from an uploaded PDF, kept separate from the verified profile
-- until the student approves the reviewed fields.
CREATE TABLE IF NOT EXISTS resume_analyses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resume_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  extracted_text MEDIUMTEXT NULL,
  extracted_json JSON NULL,
  approved_json JSON NULL,
  review_json JSON NULL,
  status ENUM('pending','approved','failed') NOT NULL DEFAULT 'pending',
  failure_reason VARCHAR(255) NULL,
  extracted_at TIMESTAMP NULL DEFAULT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resume_analysis (resume_id),
  INDEX idx_resume_analysis_student (student_id),
  CONSTRAINT fk_resume_analysis_resume FOREIGN KEY (resume_id) REFERENCES resumes(id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_analysis_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Usage and errors only: feature, sizes, latency, outcome. Never prompt text.
CREATE TABLE IF NOT EXISTS ai_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  feature VARCHAR(60) NOT NULL,
  provider VARCHAR(60) NOT NULL,
  model VARCHAR(120) NULL,
  prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  error_code VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_logs_feature_time (feature, created_at),
  INDEX idx_ai_logs_user_time (user_id, created_at),
  CONSTRAINT fk_ai_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Text mock-interview practice.
CREATE TABLE IF NOT EXISTS practice_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  role_title VARCHAR(180) NOT NULL,
  questions_json JSON NOT NULL,
  status ENUM('active','completed') NOT NULL DEFAULT 'active',
  average_score DECIMAL(5,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_practice_student (student_id, created_at),
  CONSTRAINT fk_practice_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_practice_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practice_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  question_index TINYINT UNSIGNED NOT NULL,
  question_type VARCHAR(30) NOT NULL,
  prompt TEXT NOT NULL,
  skill VARCHAR(100) NULL,
  answer TEXT NOT NULL,
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  feedback_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_practice_answer (session_id, question_index),
  CONSTRAINT fk_practice_answer_session FOREIGN KEY (session_id) REFERENCES practice_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The roles a student is preparing for (multi-target skill-gap comparison).
CREATE TABLE IF NOT EXISTS career_targets (
  student_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, job_id),
  CONSTRAINT fk_target_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_target_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;
