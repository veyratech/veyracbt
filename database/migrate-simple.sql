-- =============================================
-- MIGRASI DATABASE CBT ONLINE
-- Jalankan via phpMyAdmin → Tab SQL → Paste semua → Go
-- Versi: kompatibel MySQL 5.7+ dan 8.x
-- =============================================

-- 1. Tabel baru exam_broadcasts (aman dijalankan berulang)
CREATE TABLE IF NOT EXISTS `exam_broadcasts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `exam_id` INT NOT NULL,
    `session_id` INT NULL COMMENT 'NULL = siaran ke seluruh peserta',
    `message` TEXT NOT NULL,
    `sender_name` VARCHAR(100) NOT NULL DEFAULT 'Pengawas Ruang',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_broadcast_tenant_exam` (`tenant_id`, `exam_id`),
    INDEX `idx_broadcast_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Kolom baru exam_sessions
ALTER TABLE `exam_sessions` ADD COLUMN `extra_time_minutes` INT NOT NULL DEFAULT 0 AFTER `max_violations`;
ALTER TABLE `exam_sessions` ADD COLUMN `last_webcam_frame` VARCHAR(500) NULL AFTER `last_activity`;
ALTER TABLE `exam_sessions` ADD COLUMN `last_webcam_at` DATETIME NULL AFTER `last_webcam_frame`;
ALTER TABLE `exam_sessions` ADD COLUMN `webcam_status` ENUM('active','denied','disabled') NULL AFTER `last_webcam_at`;

-- 3. Kolom baru students
ALTER TABLE `students` ADD COLUMN `photo` VARCHAR(255) NULL AFTER `gender`;

-- 4. Index baru (abaikan error "Duplicate key name" — artinya index sudah ada, aman)
ALTER TABLE `exam_sessions` ADD INDEX `idx_es_tenant_status` (`tenant_id`, `status`);
ALTER TABLE `exam_sessions` ADD INDEX `idx_es_exam` (`exam_id`);
ALTER TABLE `exam_sessions` ADD INDEX `idx_es_student` (`student_id`);
ALTER TABLE `exam_sessions` ADD INDEX `idx_es_last_activity` (`last_activity`);
ALTER TABLE `exam_answers` ADD INDEX `idx_ea_session` (`session_id`);
ALTER TABLE `exam_answers` ADD INDEX `idx_ea_question` (`question_id`);
ALTER TABLE `students` ADD INDEX `idx_st_tenant_class` (`tenant_id`, `class_id`);
ALTER TABLE `students` ADD INDEX `idx_st_nis` (`tenant_id`, `nis`);
ALTER TABLE `activity_logs` ADD INDEX `idx_al_tenant_time` (`tenant_id`, `created_at`);
ALTER TABLE `activity_logs` ADD INDEX `idx_al_event` (`event_type`);
ALTER TABLE `questions` ADD INDEX `idx_q_bank` (`bank_id`, `tenant_id`);
ALTER TABLE `exams` ADD INDEX `idx_ex_tenant_active` (`tenant_id`, `is_active`);
ALTER TABLE `exams` ADD INDEX `idx_ex_time` (`start_time`, `end_time`);
ALTER TABLE `users` ADD INDEX `idx_u_tenant_user` (`tenant_id`, `username`);
