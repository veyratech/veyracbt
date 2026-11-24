-- Skema Database CBT Online Multi-Tenant (PHP Native)
-- Engine: InnoDB, Charset: utf8mb4
-- NOTE: Database HARUS sudah dibuat sebelumnya di cPanel → MySQL Databases.
-- File ini hanya membuat tabel-tabel saja, tidak membuat database baru.

-- 1. Tabel Master Tenant (Sekolah / Lembaga)
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `slug` VARCHAR(60) NOT NULL UNIQUE,
    `subdomain` VARCHAR(60) NULL,
    `name` VARCHAR(150) NOT NULL,
    `logo_url` VARCHAR(255) NULL,
    `logo` VARCHAR(500) NULL,
    `address` TEXT NULL,
    `phone` VARCHAR(30) NULL,
    `package` VARCHAR(50) NOT NULL DEFAULT 'pro',
    `student_quota` INT NOT NULL DEFAULT 500,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabel Super Admin (Pengelola Platform)
CREATE TABLE IF NOT EXISTS `superadmins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NULL,
    `totp_secret` VARCHAR(64) NULL,
    `totp_verified` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- 3. Tabel User Tenant (Admin Sekolah & Guru)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'teacher', 'proctor') NOT NULL DEFAULT 'admin',
    `email` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NULL,
    `totp_secret` VARCHAR(64) NULL,
    `totp_verified` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_tenant_username` (`tenant_id`, `username`),
    INDEX `idx_tenant_users` (`tenant_id`),
    CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Tabel Kelas per Tenant
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `level` VARCHAR(20) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_classes_tenant` (`tenant_id`),
    CONSTRAINT `fk_classes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Tabel Siswa per Tenant
CREATE TABLE IF NOT EXISTS `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `class_id` INT NULL,
    `nis` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL,
    `gender` ENUM('L', 'P') NOT NULL DEFAULT 'L',
    `photo` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NULL,
    `totp_secret` VARCHAR(64) NULL,
    `totp_verified` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_tenant_student_nis` (`tenant_id`, `nis`),
    INDEX `idx_students_tenant` (`tenant_id`),
    INDEX `idx_students_class` (`class_id`),
    CONSTRAINT `fk_students_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. Tabel Mata Pelajaran per Tenant
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `code` VARCHAR(20) NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_subjects_tenant` (`tenant_id`),
    CONSTRAINT `fk_subjects_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. Tabel Bank Soal
CREATE TABLE IF NOT EXISTS `question_banks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `total_questions` INT NOT NULL DEFAULT 0,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_banks_tenant` (`tenant_id`),
    CONSTRAINT `fk_banks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_banks_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Butir Soal & Pilihan Ganda (A-E)
CREATE TABLE IF NOT EXISTS `questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `bank_id` INT NOT NULL,
    `question_type` ENUM('pg', 'essay') NOT NULL DEFAULT 'pg',
    `question_text` TEXT NOT NULL,
    `question_image` VARCHAR(255) NULL,
    `option_a` TEXT NOT NULL,
    `option_b` TEXT NOT NULL,
    `option_c` TEXT NOT NULL,
    `option_d` TEXT NOT NULL,
    `option_e` TEXT NULL,
    `correct_answer` CHAR(1) NOT NULL,
    `points` INT NOT NULL DEFAULT 1,
    `explanation` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_questions_tenant` (`tenant_id`),
    INDEX `idx_questions_bank` (`bank_id`),
    CONSTRAINT `fk_questions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_questions_bank` FOREIGN KEY (`bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. Tabel Jadwal Ujian
CREATE TABLE IF NOT EXISTS `exams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `bank_id` INT NOT NULL,
    `class_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `duration_minutes` INT NOT NULL DEFAULT 60,
    `token` VARCHAR(10) NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `randomize_questions` TINYINT(1) NOT NULL DEFAULT 1,
    `randomize_options` TINYINT(1) NOT NULL DEFAULT 0,
    `question_count` INT NULL DEFAULT NULL COMMENT '0/NULL = semua soal; >0 = ambil N soal acak dari bank',
    `show_score_immediately` TINYINT(1) NOT NULL DEFAULT 1,
    `max_violations` INT NOT NULL DEFAULT 3,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_exams_tenant` (`tenant_id`),
    CONSTRAINT `fk_exams_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exams_bank` FOREIGN KEY (`bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. Tabel Sesi Ujian Peserta
CREATE TABLE IF NOT EXISTS `exam_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `exam_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `finish_time` DATETIME NULL,
    `question_order` TEXT NOT NULL,
    `total_violations` INT NOT NULL DEFAULT 0,
    `status` ENUM('in_progress', 'completed', 'blocked') NOT NULL DEFAULT 'in_progress',
    `score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `extra_time_minutes` INT NOT NULL DEFAULT 0,
    `last_activity` DATETIME NULL,
    `last_webcam_frame` VARCHAR(255) NULL,
    `last_webcam_at` DATETIME NULL,
    `webcam_status` ENUM('active', 'denied', 'disabled') NOT NULL DEFAULT 'disabled',
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_session` (`tenant_id`, `exam_id`, `student_id`),
    INDEX `idx_sessions_tenant` (`tenant_id`),
    INDEX `idx_sessions_exam` (`exam_id`),
    CONSTRAINT `fk_sessions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. Tabel Jawaban Siswa per Soal (Autosave Realtime)
CREATE TABLE IF NOT EXISTS `exam_answers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `session_id` INT NOT NULL,
    `question_id` INT NOT NULL,
    `student_answer` CHAR(1) NULL,
    `answer_text` TEXT NULL,
    `is_doubt` TINYINT(1) NOT NULL DEFAULT 0,
    `is_correct` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL=belum dinilai(essay), 0=salah, 1=benar',
    `essay_score` DECIMAL(6,2) NULL COMMENT 'Poin essay yang diberikan guru',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_session_question` (`session_id`, `question_id`),
    INDEX `idx_answers_session` (`session_id`),
    CONSTRAINT `fk_answers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_session` FOREIGN KEY (`session_id`) REFERENCES `exam_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 12. Tabel Audit Log Pengawas & Pelanggaran
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `session_id` INT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `message` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_logs_tenant` (`tenant_id`),
    CONSTRAINT `fk_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 13. Tabel Broadcast Pengawas ke Siswa (Pengumuman Real-time)
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
) ENGINE=InnoDB;

-- 14. Tabel Riwayat Latihan Siswa (mode latihan tanpa token)
CREATE TABLE IF NOT EXISTS `practice_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `bank_id` INT NOT NULL,
    `total_questions` INT NOT NULL DEFAULT 0,
    `correct_count` INT NOT NULL DEFAULT 0,
    `wrong_count` INT NOT NULL DEFAULT 0,
    `blank_count` INT NOT NULL DEFAULT 0,
    `score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_practice_tenant` (`tenant_id`),
    INDEX `idx_practice_student` (`student_id`),
    INDEX `idx_practice_bank` (`bank_id`),
    CONSTRAINT `fk_practice_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_practice_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_practice_bank` FOREIGN KEY (`bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
