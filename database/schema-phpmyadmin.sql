-- =====================================================================
-- VEYRA CBT ONLINE MULTI-TENANT - COMPLETE SCHEMA & SEED DATA (cPanel)
-- Engine: InnoDB, Charset: utf8mb4
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Bersihkan tabel lama (urutan anak -> induk agar aman dari FK)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `practice_sessions`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `exam_answers`;
DROP TABLE IF EXISTS `exam_sessions`;
DROP TABLE IF EXISTS `exams`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `question_banks`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `classes`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `superadmins`;
DROP TABLE IF EXISTS `tenants`;

-- ---------------------------------------------------------------------
-- 2. Tabel Master Tenant (Sekolah / Lembaga) & Superadmin
-- ---------------------------------------------------------------------
CREATE TABLE `tenants` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `superadmins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NULL,
    `totp_secret` VARCHAR(64) NULL,
    `totp_verified` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Tabel User Tenant, Kelas, Siswa & Mata Pelajaran
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `level` VARCHAR(20) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_classes_tenant` (`tenant_id`),
    CONSTRAINT `fk_classes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `class_id` INT NULL,
    `nis` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL,
    `plain_password` VARCHAR(100) NULL,
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
    INDEX `idx_st_tenant_class` (`tenant_id`, `class_id`),
    CONSTRAINT `fk_students_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `code` VARCHAR(20) NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_subjects_tenant` (`tenant_id`),
    CONSTRAINT `fk_subjects_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Tabel Bank Soal, Butir Soal & Jadwal Ujian
-- ---------------------------------------------------------------------
CREATE TABLE `question_banks` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `bank_id` INT NOT NULL,
    `question_type` ENUM('pg', 'essay') NOT NULL DEFAULT 'pg',
    `question_text` TEXT NOT NULL,
    `question_image` VARCHAR(255) NULL,
    `question_audio` VARCHAR(255) NULL,
    `audio_play_limit` INT NOT NULL DEFAULT 0,
    `question_video` VARCHAR(500) NULL,
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
    INDEX `idx_q_bank` (`bank_id`, `tenant_id`),
    CONSTRAINT `fk_questions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_questions_bank` FOREIGN KEY (`bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exams` (
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
    `question_count` INT NULL DEFAULT NULL,
    `show_score_immediately` TINYINT(1) NOT NULL DEFAULT 1,
    `max_violations` INT NOT NULL DEFAULT 3,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_exams_tenant` (`tenant_id`),
    INDEX `idx_ex_tenant_active` (`tenant_id`, `is_active`),
    INDEX `idx_ex_time` (`start_time`, `end_time`),
    CONSTRAINT `fk_exams_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exams_bank` FOREIGN KEY (`bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Tabel Sesi Ujian, Jawaban, Log Aktivitas & Latihan
-- ---------------------------------------------------------------------
CREATE TABLE `exam_sessions` (
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
    `last_webcam_frame` VARCHAR(500) NULL,
    `last_webcam_at` DATETIME NULL,
    `webcam_status` ENUM('active', 'denied', 'disabled') NOT NULL DEFAULT 'disabled',
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_session` (`tenant_id`, `exam_id`, `student_id`),
    INDEX `idx_sessions_tenant` (`tenant_id`),
    INDEX `idx_sessions_exam` (`exam_id`),
    INDEX `idx_es_tenant_status` (`tenant_id`, `status`),
    INDEX `idx_es_student` (`student_id`),
    INDEX `idx_es_last_activity` (`last_activity`),
    CONSTRAINT `fk_sessions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_answers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `session_id` INT NOT NULL,
    `question_id` INT NOT NULL,
    `student_answer` CHAR(1) NULL,
    `answer_text` TEXT NULL,
    `is_doubt` TINYINT(1) NOT NULL DEFAULT 0,
    `is_correct` TINYINT(1) NULL DEFAULT NULL,
    `essay_score` DECIMAL(6,2) NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_session_question` (`session_id`, `question_id`),
    INDEX `idx_answers_session` (`session_id`),
    INDEX `idx_ea_question` (`question_id`),
    CONSTRAINT `fk_answers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_session` FOREIGN KEY (`session_id`) REFERENCES `exam_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `session_id` INT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `message` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_logs_tenant` (`tenant_id`),
    INDEX `idx_al_tenant_time` (`tenant_id`, `created_at`),
    INDEX `idx_al_event` (`event_type`),
    CONSTRAINT `fk_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_broadcasts` (
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

CREATE TABLE `practice_sessions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Seed Data Awal (Superadmin & Tenant Awal)
-- ---------------------------------------------------------------------
INSERT INTO `superadmins` (`id`, `username`, `password`, `name`, `email`) VALUES
(1, 'admin', '$2y$10$EJGVOUHPYftlm7I0XwQXVequm.y5qDFCIu5k6SstnFHB2QwC68yCi', 'Super Administrator Platform', 'admin@veyraeducate.test');

INSERT INTO `tenants` (`id`, `code`, `slug`, `name`, `address`, `phone`, `package`, `student_quota`, `is_active`) VALUES
(1, 'SMATEL', 'sma-teladan', 'SMA Teladan Unggulan', 'Jl. Pendidikan No. 12, Jakarta', '021-5551234', 'pro', 500, 1);

INSERT INTO `users` (`id`, `tenant_id`, `username`, `password`, `name`, `role`, `email`) VALUES
(1, 1, 'admin_smatel', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'Bapak Hendra (Admin SMA)', 'admin', 'admin@smatel.sch.id'),
(2, 1, 'guru_smatel', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'Ibu Maya (Guru Bahasa)', 'teacher', 'maya@smatel.sch.id');

INSERT INTO `classes` (`id`, `tenant_id`, `name`, `level`) VALUES
(1, 1, 'X MIPA 1', '10'),
(2, 1, 'X MIPA 2', '10');

INSERT INTO `students` (`id`, `tenant_id`, `class_id`, `nis`, `name`, `password`, `plain_password`, `gender`) VALUES
(1, 1, 1, '2024001', 'Ahmad Fauzi', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'password', 'L'),
(2, 1, 1, '2024002', 'Siti Nurhaliza', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'password', 'P'),
(3, 1, 1, '2024003', 'Budi Santoso', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'password', 'L');

INSERT INTO `subjects` (`id`, `tenant_id`, `code`, `name`) VALUES
(1, 1, 'BINDO-X', 'Bahasa Indonesia');

INSERT INTO `question_banks` (`id`, `tenant_id`, `subject_id`, `title`, `total_questions`) VALUES
(1, 1, 1, 'Simulasi Asesmen Bahasa Indonesia', 5);

INSERT INTO `questions` (`id`, `tenant_id`, `bank_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `option_e`, `correct_answer`, `points`) VALUES
(1, 1, 1, 'pg', 'Ide pokok atau gagasan utama dalam sebuah paragraf biasanya dapat ditemukan pada bagian...', 'Kalimat penjelas', 'Kalimat utama', 'Kalimat tanya', 'Kalimat penutup saja', 'Daftar pustaka', 'B', 20),
(2, 1, 1, 'pg', 'Teks yang berisi laporan mengenai hasil pengamatan atau peninjauan yang sistematis disebut...', 'Teks Eksposisi', 'Teks Anekdot', 'Teks Laporan Hasil Observasi', 'Teks Negosiasi', 'Teks Narasi Fiktif', 'C', 20),
(3, 1, 1, 'pg', 'Konjungsi yang menyatakan hubungan sebab-akibat di bawah ini adalah...', 'Sehingga dan karena', 'Tetapi dan namun', 'Atau dan serta', 'Meskipun dan walaupun', 'Ketika dan saat', 'A', 20),
(4, 1, 1, 'pg', 'Berikut ini yang termasuk ciri-ciri kalimat efektif adalah...', 'Menggunakan banyak kata kiasan ambigu', 'Memiliki subjek dan predikat yang jelas serta hemat kata', 'Struktur kalimat sangat panjang dan berbelit-belit', 'Bermakna ganda agar pembaca bebas menafsirkan', 'Tidak terikat kaidah ejaan PUEBI', 'B', 20),
(5, 1, 1, 'pg', 'Bagian penutup dari sebuah teks eksposisi yang berisi simpulan atau penegasan kembali disebut...', 'Tesis', 'Rangkaian Argumen', 'Reorientasi / Penegasan Ulang', 'Koda', 'Orientasi', 'C', 20);

INSERT INTO `exams` (`id`, `tenant_id`, `bank_id`, `class_id`, `title`, `duration_minutes`, `token`, `start_time`, `end_time`, `randomize_questions`, `randomize_options`, `show_score_immediately`, `max_violations`, `is_active`) VALUES
(1, 1, 1, 1, 'Penilaian Harian Bahasa Indonesia', 45, 'SMATEL1', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1, 0, 1, 3, 1);

SET FOREIGN_KEY_CHECKS = 1;
