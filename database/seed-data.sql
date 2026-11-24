-- =============================================
-- CBT ONLINE — SEED DATA (IMPORT SETELAH SCHEMA)
-- =============================================
-- Cara pakai:
-- 1. Import database/schema.sql dulu di phpMyAdmin
-- 2. Baru import file ini
-- 3. Buat file config/installed.lock (isi: installed_at=2026-11-05T00:00:00+07:00)
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------
-- 1. SUPERADMIN
-- ---------------------------------------------
INSERT INTO `superadmins` (`username`, `password`, `name`, `email`) VALUES
('admin', '$2y$10$EJGVOUHPYftlm7I0XwQXVequm.y5qDFCIu5k6SstnFHB2QwC68yCi', 'Super Administrator Platform', 'admin@cbtplatform.test');

-- ---------------------------------------------
-- 2. TENANT 1: SMA Teladan Unggulan
-- ---------------------------------------------
INSERT INTO `tenants` (`code`, `slug`, `name`, `address`, `phone`, `package`, `student_quota`, `is_active`) VALUES
('SMATEL', 'sma-teladan', 'SMA Teladan Unggulan', 'Jl. Pendidikan No. 12, Jakarta', '021-5551234', 'pro', 500, 1);
SET @t1 = LAST_INSERT_ID();

-- User Tenant 1
INSERT INTO `users` (`tenant_id`, `username`, `password`, `name`, `role`, `email`) VALUES
(@t1, 'admin_smatel', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'Bapak Hendra (Admin SMA)', 'admin', 'admin@smatel.sch.id'),
(@t1, 'guru_smatel', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'Ibu Maya (Guru Bahasa)', 'teacher', 'maya@smatel.sch.id');

-- Kelas Tenant 1
INSERT INTO `classes` (`tenant_id`, `name`, `level`) VALUES
(@t1, 'X MIPA 1', '10'),
(@t1, 'X MIPA 2', '10');
SET @c1 = LAST_INSERT_ID(); -- X MIPA 1

-- Siswa Tenant 1
INSERT INTO `students` (`tenant_id`, `class_id`, `nis`, `name`, `password`, `gender`) VALUES
(@t1, @c1, '2024001', 'Ahmad Fauzi',     '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'L'),
(@t1, @c1, '2024002', 'Siti Nurhaliza',   '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'P'),
(@t1, @c1, '2024003', 'Budi Santoso',     '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'L');

-- Mapel & Bank Soal Tenant 1
INSERT INTO `subjects` (`tenant_id`, `code`, `name`) VALUES
(@t1, 'BINDO-X', 'Bahasa Indonesia');
SET @s1 = LAST_INSERT_ID();

INSERT INTO `question_banks` (`tenant_id`, `subject_id`, `title`, `total_questions`) VALUES
(@t1, @s1, 'Simulasi Asesmen Bahasa Indonesia', 5);
SET @b1 = LAST_INSERT_ID();

-- Soal Tenant 1
INSERT INTO `questions` (`tenant_id`, `bank_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `option_e`, `correct_answer`, `points`) VALUES
(@t1, @b1, 'pg', 'Ide pokok atau gagasan utama dalam sebuah paragraf biasanya dapat ditemukan pada bagian...', 'Kalimat penjelas', 'Kalimat utama', 'Kalimat tanya', 'Kalimat penutup saja', 'Daftar pustaka', 'B', 20),
(@t1, @b1, 'pg', 'Teks yang berisi laporan mengenai hasil pengamatan atau peninjauan yang sistematis disebut...', 'Teks Eksposisi', 'Teks Anekdot', 'Teks Laporan Hasil Observasi', 'Teks Negosiasi', 'Teks Narasi Fiktif', 'C', 20),
(@t1, @b1, 'pg', 'Konjungsi yang menyatakan hubungan sebab-akibat di bawah ini adalah...', 'Sehingga dan karena', 'Tetapi dan namun', 'Atau dan serta', 'Meskipun dan walaupun', 'Ketika dan saat', 'A', 20),
(@t1, @b1, 'pg', 'Berikut ini yang termasuk ciri-ciri kalimat efektif adalah...', 'Menggunakan banyak kata kiasan ambigu', 'Memiliki subjek dan predikat yang jelas serta hemat kata', 'Struktur kalimat sangat panjang dan berbelit-belit', 'Bermakna ganda agar pembaca bebas menafsirkan', 'Tidak terikat kaidah ejaan PUEBI', 'B', 20),
(@t1, @b1, 'pg', 'Bagian penutup dari sebuah teks eksposisi yang berisi simpulan atau penegasan kembali disebut...', 'Tesis', 'Rangkaian Argumen', 'Reorientasi / Penegasan Ulang', 'Koda', 'Orientasi', 'C', 20);

-- Ujian Tenant 1
INSERT INTO `exams` (`tenant_id`, `bank_id`, `class_id`, `title`, `duration_minutes`, `token`, `start_time`, `end_time`, `randomize_questions`, `randomize_options`, `show_score_immediately`, `max_violations`, `is_active`) VALUES
(@t1, @b1, @c1, 'Penilaian Harian Bahasa Indonesia', 45, 'SMATEL1', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, 0, 1, 3, 1);

-- ---------------------------------------------
-- 3. TENANT 2: SMK Nusantara Teknologi
-- ---------------------------------------------
INSERT INTO `tenants` (`code`, `slug`, `name`, `address`, `phone`, `package`, `student_quota`, `is_active`) VALUES
('SMKNUS', 'smk-nusantara', 'SMK Nusantara Teknologi', 'Jl. Industri Kreatif Kav 7, Bandung', '022-7778899', 'enterprise', 800, 1);
SET @t2 = LAST_INSERT_ID();

-- User Tenant 2
INSERT INTO `users` (`tenant_id`, `username`, `password`, `name`, `role`, `email`) VALUES
(@t2, 'admin_smknus', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'Pak Doni (Admin SMK)', 'admin', 'doni@smknusantara.sch.id');

-- Kelas Tenant 2
INSERT INTO `classes` (`tenant_id`, `name`, `level`) VALUES
(@t2, 'XII RPL 1', '12');
SET @c2 = LAST_INSERT_ID();

-- Siswa Tenant 2
INSERT INTO `students` (`tenant_id`, `class_id`, `nis`, `name`, `password`, `gender`) VALUES
(@t2, @c2, '2024101', 'Rizky Pratama', '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'L'),
(@t2, @c2, '2024102', 'Dewi Lestari',  '$2y$10$/c34mCPnz9SnmXuECv/O9OywCqi1hwX5t2czGVNDcb8I2EXR9fRTi', 'P');

-- Mapel & Bank Soal Tenant 2
INSERT INTO `subjects` (`tenant_id`, `code`, `name`) VALUES
(@t2, 'RPL-WEB', 'Pemrograman Web & Basis Data');
SET @s2 = LAST_INSERT_ID();

INSERT INTO `question_banks` (`tenant_id`, `subject_id`, `title`, `total_questions`) VALUES
(@t2, @s2, 'Ujian Kompetensi Kejuruan Web', 5);
SET @b2 = LAST_INSERT_ID();

-- Soal Tenant 2
INSERT INTO `questions` (`tenant_id`, `bank_id`, `question_type`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `option_e`, `correct_answer`, `points`) VALUES
(@t2, @b2, 'pg', 'Ekstensi file skrip server-side PHP yang standar adalah...', '.html', '.php', '.js', '.css', '.sql', 'B', 20),
(@t2, @b2, 'pg', 'Perintah SQL untuk mengambil seluruh data dari tabel `users` adalah...', 'FETCH ALL FROM users;', 'GET * FROM users;', 'SELECT * FROM users;', 'RETRIEVE users;', 'QUERY users;', 'C', 20),
(@t2, @b2, 'pg', 'Di PHP, fungsi PDO yang aman untuk mencegah kerentanan SQL Injection menggunakan...', 'Prepared Statement ($pdo->prepare)', 'eval()', 'mysql_query() tanpa filter', 'echo $_GET["id"]', 'file_get_contents()', 'A', 20),
(@t2, @b2, 'pg', 'Metode HTTP yang paling tepat digunakan untuk mengirimkan data sensitif seperti password saat login adalah...', 'GET', 'HEAD', 'POST', 'OPTIONS', 'TRACE', 'C', 20),
(@t2, @b2, 'pg', 'Dalam konsep Multi-Tenant SaaS, isolasi data berbasis satu database bersama menggunakan pemisah kolom...', 'tenant_id', 'server_ip', 'disk_size', 'mac_address', 'cpu_core', 'A', 20);

-- Ujian Tenant 2
INSERT INTO `exams` (`tenant_id`, `bank_id`, `class_id`, `title`, `duration_minutes`, `token`, `start_time`, `end_time`, `randomize_questions`, `randomize_options`, `show_score_immediately`, `max_violations`, `is_active`) VALUES
(@t2, @b2, @c2, 'Kuis Kejuruan Pemrograman Web', 40, 'WEBRPL', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, 0, 1, 3, 1);

SET FOREIGN_KEY_CHECKS=1;

-- =============================================
-- SELESAI
-- =============================================
-- Kredensial Demo:
-- Superadmin:   admin / admin123
-- SMA Teladan:  admin_smatel / password  |  Siswa NIS 2024001 / password
-- SMK Nusantara: admin_smknus / password |  Siswa NIS 2024101 / password
-- Token Ujian: SMATEL1 (SMA) / WEBRPL (SMK)
-- =============================================
