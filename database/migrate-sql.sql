-- =============================================
-- MIGRASI DATABASE CBT ONLINE (versi aman tanpa error)
-- Jalankan via phpMyAdmin → Tab SQL → Paste seluruh isi file ini → Go
-- =============================================

-- 1. TABEL BARU
CREATE TABLE IF NOT EXISTS `exam_broadcasts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `exam_id` INT NOT NULL,
    `session_id` INT NULL COMMENT 'NULL = siaran ke seluruh peserta',
    `message` TEXT NOT NULL,
    `sender_name` VARCHAR(100) NOT NULL DEFAULT 'Pengawas Ruang',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_broadcast_tenant_exam` (`tenant_id`, `exam_id`),
    INDEX `idx_broadcast_session` (`session_id`),
    CONSTRAINT `fk_broadcast_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_broadcast_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. TAMBAH KOLOM Baru (hanya jika belum ada)
-- exam_sessions.extra_time_minutes
SET @dbname = DATABASE(), @tbl = 'exam_sessions', @col = 'extra_time_minutes';
SET @sql_add = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE `',@tbl,'` ADD COLUMN `',@col,'` INT NOT NULL DEFAULT 0 AFTER `max_violations`'), 'SELECT 1');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- exam_sessions.last_webcam_frame
SET @col = 'last_webcam_frame';
SET @sql_add = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE `',@tbl,'` ADD COLUMN `',@col,'` VARCHAR(500) NULL AFTER `last_activity`'), 'SELECT 1');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- exam_sessions.last_webcam_at
SET @col = 'last_webcam_at';
SET @sql_add = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE `',@tbl,'` ADD COLUMN `',@col,'` DATETIME NULL AFTER `last_webcam_frame`'), 'SELECT 1');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- exam_sessions.webcam_status
SET @col = 'webcam_status';
SET @sql_add = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE `',@tbl,'` ADD COLUMN `',@col,'` ENUM(''active'',''denied'',''disabled'') NULL AFTER `last_webcam_at`'), 'SELECT 1');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- students.photo
SET @tbl = 'students', @col = 'photo';
SET @sql_add = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE `',@tbl,'` ADD COLUMN `',@col,'` VARCHAR(255) NULL AFTER `gender`'), 'SELECT 1');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. TAMBAH INDEX (hanya jika belum ada)
-- Helper: satu per satu dengan pengecekan INFORMATION_SCHEMA
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_sessions' AND index_name='idx_es_tenant_status')=0,
  'ALTER TABLE `exam_sessions` ADD INDEX `idx_es_tenant_status` (`tenant_id`, `status`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_sessions' AND index_name='idx_es_exam')=0,
  'ALTER TABLE `exam_sessions` ADD INDEX `idx_es_exam` (`exam_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_sessions' AND index_name='idx_es_student')=0,
  'ALTER TABLE `exam_sessions` ADD INDEX `idx_es_student` (`student_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_sessions' AND index_name='idx_es_last_activity')=0,
  'ALTER TABLE `exam_sessions` ADD INDEX `idx_es_last_activity` (`last_activity`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_answers' AND index_name='idx_ea_session')=0,
  'ALTER TABLE `exam_answers` ADD INDEX `idx_ea_session` (`session_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exam_answers' AND index_name='idx_ea_question')=0,
  'ALTER TABLE `exam_answers` ADD INDEX `idx_ea_question` (`question_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='students' AND index_name='idx_st_tenant_class')=0,
  'ALTER TABLE `students` ADD INDEX `idx_st_tenant_class` (`tenant_id`, `class_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='students' AND index_name='idx_st_nis')=0,
  'ALTER TABLE `students` ADD INDEX `idx_st_nis` (`tenant_id`, `nis`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='activity_logs' AND index_name='idx_al_tenant_time')=0,
  'ALTER TABLE `activity_logs` ADD INDEX `idx_al_tenant_time` (`tenant_id`, `created_at`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='activity_logs' AND index_name='idx_al_event')=0,
  'ALTER TABLE `activity_logs` ADD INDEX `idx_al_event` (`event_type`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='questions' AND index_name='idx_q_bank')=0,
  'ALTER TABLE `questions` ADD INDEX `idx_q_bank` (`bank_id`, `tenant_id`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exams' AND index_name='idx_ex_tenant_active')=0,
  'ALTER TABLE `exams` ADD INDEX `idx_ex_tenant_active` (`tenant_id`, `is_active`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='exams' AND index_name='idx_ex_time')=0,
  'ALTER TABLE `exams` ADD INDEX `idx_ex_time` (`start_time`, `end_time`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='idx_u_tenant_user')=0,
  'ALTER TABLE `users` ADD INDEX `idx_u_tenant_user` (`tenant_id`, `username`)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'Migrasi selesai — semua tabel & index sudah sesuai.' AS status;
