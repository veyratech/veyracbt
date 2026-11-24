-- =============================================
-- CBT Online Update Patch v1.0 -- SQL Section
-- Jalankan melalui phpMyAdmin / MySQL CLI
-- =============================================

CREATE TABLE IF NOT EXISTS `exam_broadcasts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL,
  `exam_id` INT NOT NULL,
  `session_id` INT NULL DEFAULT NULL COMMENT 'NULL = siaran ke seluruh peserta',
  `message` TEXT NOT NULL,
  `sender_name` VARCHAR(100) NOT NULL DEFAULT 'Pengawas Ruang',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_broadcast_tenant_exam` (`tenant_id`, `exam_id`),
  INDEX `idx_broadcast_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_tenant_status (tenant_id, status);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_exam        (exam_id);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_student     (student_id);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_last_activity (last_activity);
ALTER TABLE exam_answers  ADD INDEX IF NOT EXISTS idx_ea_session (session_id);
ALTER TABLE exam_answers  ADD INDEX IF NOT EXISTS idx_ea_question (question_id);
ALTER TABLE students      ADD INDEX IF NOT EXISTS idx_st_tenant_class (tenant_id, class_id);
ALTER TABLE students      ADD INDEX IF NOT EXISTS idx_st_nis (tenant_id, nis);
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_al_tenant_time (tenant_id, created_at);
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_al_event (event_type);
ALTER TABLE questions     ADD INDEX IF NOT EXISTS idx_q_bank (bank_id, tenant_id);
ALTER TABLE exams         ADD INDEX IF NOT EXISTS idx_ex_tenant_active (tenant_id, is_active);
ALTER TABLE exams         ADD INDEX IF NOT EXISTS idx_ex_time (start_time, end_time);
ALTER TABLE users         ADD INDEX IF NOT EXISTS idx_u_tenant_user (tenant_id, username);
