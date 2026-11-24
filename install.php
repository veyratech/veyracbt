<?php
/**
 * Auto-Installer & Database Seeder CBT Online Multi-Tenant
 */

// Uncomment only for development debugging; disabled in production
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

$config = require __DIR__ . '/config/database.php';
$installed = false;
$error = null;
$messages = [];

$isCli = (php_sapi_name() === 'cli');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$lockFile = __DIR__ . '/config/installed.lock';

// Kunci installer: hanya bisa dijalankan sekali via web.
// Untuk instalasi ulang: hapus file config/installed.lock atau jalankan via CLI.
if (!$isCli && file_exists($lockFile)) {
    http_response_code(403);
    die('Installer dikunci. Aplikasi sudah terinstal. Untuk instalasi ulang, hapus file config/installed.lock terlebih dahulu.');
}

if ($isPost || isset($_GET['auto']) || $isCli) {
    try {
        // 1. Koneksi awal ke MySQL Server (langsung ke database target)
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        $messages[] = "Berhasil terhubung ke database '{$config['database']}' di {$config['host']}.";

        // 2. Eksekusi schema (hanya tabel, tanpa CREATE DATABASE / USE)
        $sqlSchema = file_get_contents(__DIR__ . '/database/schema.sql');
        $pdo->exec($sqlSchema);
        $messages[] = "Seluruh tabel berhasil dibuat di database '{$config['database']}'.";

        // 3. Cek apakah seeder sudah pernah dijalankan
        $chkAdmin = $pdo->query("SELECT COUNT(*) FROM superadmins")->fetchColumn();
        if ($chkAdmin == 0) {
            // Seed Superadmin
            $passAdmin = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO superadmins (username, password, name, email) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', $passAdmin, 'Super Administrator Platform', 'admin@cbtplatform.test']);
            $messages[] = "Superadmin dibuat (username: <b>admin</b>, password: <b>admin123</b>).";

            // Default password untuk user & siswa demo
            $defaultPass = password_hash('password', PASSWORD_BCRYPT);

            // --- SEED TENANT 1: SMA Teladan ---
            $stmt = $pdo->prepare("INSERT INTO tenants (code, slug, name, address, phone, package, student_quota, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute(['SMATEL', 'sma-teladan', 'SMA Teladan Unggulan', 'Jl. Pendidikan No. 12, Jakarta', '021-5551234', 'pro', 500, 1]);
            $tenant1Id = (int)$pdo->lastInsertId();

            // Admin & Guru Tenant 1
            $stmt = $pdo->prepare("INSERT INTO users (tenant_id, username, password, name, role, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant1Id, 'admin_smatel', $defaultPass, 'Bapak Hendra (Admin SMA)', 'admin', 'admin@smatel.sch.id']);
            $stmt->execute([$tenant1Id, 'guru_smatel', $defaultPass, 'Ibu Maya (Guru Bahasa)', 'teacher', 'maya@smatel.sch.id']);

            // Kelas Tenant 1
            $stmt = $pdo->prepare("INSERT INTO classes (tenant_id, name, level) VALUES (?, ?, ?)");
            $stmt->execute([$tenant1Id, 'X MIPA 1', '10']);
            $class1Id = (int)$pdo->lastInsertId();
            $stmt->execute([$tenant1Id, 'X MIPA 2', '10']);

            // Siswa Tenant 1
            $stmt = $pdo->prepare("INSERT INTO students (tenant_id, class_id, nis, name, password, gender) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant1Id, $class1Id, '2024001', 'Ahmad Fauzi', $defaultPass, 'L']);
            $stmt->execute([$tenant1Id, $class1Id, '2024002', 'Siti Nurhaliza', $defaultPass, 'P']);
            $stmt->execute([$tenant1Id, $class1Id, '2024003', 'Budi Santoso', $defaultPass, 'L']);

            // Mapel & Bank Soal Tenant 1
            $stmt = $pdo->prepare("INSERT INTO subjects (tenant_id, code, name) VALUES (?, ?, ?)");
            $stmt->execute([$tenant1Id, 'BINDO-X', 'Bahasa Indonesia']);
            $subject1Id = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO question_banks (tenant_id, subject_id, title, total_questions) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tenant1Id, $subject1Id, 'Simulasi Asesmen Bahasa Indonesia', 5]);
            $bank1Id = (int)$pdo->lastInsertId();

            // Soal Pilihan Ganda Tenant 1
            $questionsT1 = [
                [
                    'text' => 'Ide pokok atau gagasan utama dalam sebuah paragraf biasanya dapat ditemukan pada bagian...',
                    'a' => 'Kalimat penjelas',
                    'b' => 'Kalimat utama',
                    'c' => 'Kalimat tanya',
                    'd' => 'Kalimat penutup saja',
                    'e' => 'Daftar pustaka',
                    'correct' => 'B'
                ],
                [
                    'text' => 'Teks yang berisi laporan mengenai hasil pengamatan atau peninjauan yang sistematis disebut...',
                    'a' => 'Teks Eksposisi',
                    'b' => 'Teks Anekdot',
                    'c' => 'Teks Laporan Hasil Observasi',
                    'd' => 'Teks Negosiasi',
                    'e' => 'Teks Narasi Fiktif',
                    'correct' => 'C'
                ],
                [
                    'text' => 'Konjungsi yang menyatakan hubungan sebab-akibat di bawah ini adalah...',
                    'a' => 'Sehingga dan karena',
                    'b' => 'Tetapi dan namun',
                    'c' => 'Atau dan serta',
                    'd' => 'Meskipun dan walaupun',
                    'e' => 'Ketika dan saat',
                    'correct' => 'A'
                ],
                [
                    'text' => 'Berikut ini yang termasuk ciri-ciri kalimat efektif adalah...',
                    'a' => 'Menggunakan banyak kata kiasan ambigu',
                    'b' => 'Memiliki subjek dan predikat yang jelas serta hemat kata',
                    'c' => 'Struktur kalimat sangat panjang dan berbelit-belit',
                    'd' => 'Bermakna ganda agar pembaca bebas menafsirkan',
                    'e' => 'Tidak terikat kaidah ejaan PUEBI',
                    'correct' => 'B'
                ],
                [
                    'text' => 'Bagian penutup dari sebuah teks eksposisi yang berisi simpulan atau penegasan kembali disebut...',
                    'a' => 'Tesis',
                    'b' => 'Rangkaian Argumen',
                    'c' => 'Reorientasi / Penegasan Ulang',
                    'd' => 'Koda',
                    'e' => 'Orientasi',
                    'correct' => 'C'
                ]
            ];

            $stmtQ = $pdo->prepare("INSERT INTO questions (tenant_id, bank_id, question_text, option_a, option_b, option_c, option_d, option_e, correct_answer, points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 20)");
            foreach ($questionsT1 as $q) {
                $stmtQ->execute([$tenant1Id, $bank1Id, $q['text'], $q['a'], $q['b'], $q['c'], $q['d'], $q['e'], $q['correct']]);
            }

            // Ujian Aktif Tenant 1
            $now = date('Y-m-d H:i:s');
            $nextWeek = date('Y-m-d H:i:s', strtotime('+7 days'));
            $stmt = $pdo->prepare("INSERT INTO exams (tenant_id, bank_id, class_id, title, duration_minutes, token, start_time, end_time, randomize_questions, randomize_options, show_score_immediately, max_violations, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant1Id, $bank1Id, $class1Id, 'Penilaian Harian Bahasa Indonesia', 45, 'SMATEL1', $now, $nextWeek, 1, 0, 1, 3, 1]);


            // --- SEED TENANT 2: SMK Nusantara ---
            $stmt = $pdo->prepare("INSERT INTO tenants (code, slug, name, address, phone, package, student_quota, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute(['SMKNUS', 'smk-nusantara', 'SMK Nusantara Teknologi', 'Jl. Industri Kreatif Kav 7, Bandung', '022-7778899', 'enterprise', 800, 1]);
            $tenant2Id = (int)$pdo->lastInsertId();

            // Admin Tenant 2
            $stmt = $pdo->prepare("INSERT INTO users (tenant_id, username, password, name, role, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant2Id, 'admin_smknus', $defaultPass, 'Pak Doni (Admin SMK)', 'admin', 'doni@smknusantara.sch.id']);

            // Kelas Tenant 2
            $stmt = $pdo->prepare("INSERT INTO classes (tenant_id, name, level) VALUES (?, ?, ?)");
            $stmt->execute([$tenant2Id, 'XII RPL 1', '12']);
            $class2Id = (int)$pdo->lastInsertId();

            // Siswa Tenant 2
            $stmt = $pdo->prepare("INSERT INTO students (tenant_id, class_id, nis, name, password, gender) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant2Id, $class2Id, '2024101', 'Rizky Pratama', $defaultPass, 'L']);
            $stmt->execute([$tenant2Id, $class2Id, '2024102', 'Dewi Lestari', $defaultPass, 'P']);

            // Mapel & Bank Soal Tenant 2
            $stmt = $pdo->prepare("INSERT INTO subjects (tenant_id, code, name) VALUES (?, ?, ?)");
            $stmt->execute([$tenant2Id, 'RPL-WEB', 'Pemrograman Web & Basis Data']);
            $subject2Id = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO question_banks (tenant_id, subject_id, title, total_questions) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tenant2Id, $subject2Id, 'Ujian Kompetensi Kejuruan Web', 5]);
            $bank2Id = (int)$pdo->lastInsertId();

            // Soal Pilihan Ganda Tenant 2
            $questionsT2 = [
                [
                    'text' => 'Ekstensi file skrip server-side PHP yang standar adalah...',
                    'a' => '.html',
                    'b' => '.php',
                    'c' => '.js',
                    'd' => '.css',
                    'e' => '.sql',
                    'correct' => 'B'
                ],
                [
                    'text' => 'Perintah SQL untuk mengambil seluruh data dari tabel `users` adalah...',
                    'a' => 'FETCH ALL FROM users;',
                    'b' => 'GET * FROM users;',
                    'c' => 'SELECT * FROM users;',
                    'd' => 'RETRIEVE users;',
                    'e' => 'QUERY users;',
                    'correct' => 'C'
                ],
                [
                    'text' => 'Di PHP, fungsi PDO yang aman untuk mencegah kerentanan SQL Injection menggunakan...',
                    'a' => 'Prepared Statement ($pdo->prepare)',
                    'b' => 'eval()',
                    'c' => 'mysql_query() tanpa filter',
                    'd' => 'echo $_GET["id"]',
                    'e' => 'file_get_contents()',
                    'correct' => 'A'
                ],
                [
                    'text' => 'Metode HTTP yang paling tepat digunakan untuk mengirimkan data sensitif seperti password saat login adalah...',
                    'a' => 'GET',
                    'b' => 'HEAD',
                    'c' => 'POST',
                    'd' => 'OPTIONS',
                    'e' => 'TRACE',
                    'correct' => 'C'
                ],
                [
                    'text' => 'Dalam konsep Multi-Tenant SaaS, isolasi data berbasis satu database bersama menggunakan pemisah kolom...',
                    'a' => 'tenant_id',
                    'b' => 'server_ip',
                    'c' => 'disk_size',
                    'd' => 'mac_address',
                    'e' => 'cpu_core',
                    'correct' => 'A'
                ]
            ];

            foreach ($questionsT2 as $q) {
                $stmtQ->execute([$tenant2Id, $bank2Id, $q['text'], $q['a'], $q['b'], $q['c'], $q['d'], $q['e'], $q['correct']]);
            }

            // Ujian Aktif Tenant 2
            $stmt = $pdo->prepare("INSERT INTO exams (tenant_id, bank_id, class_id, title, duration_minutes, token, start_time, end_time, randomize_questions, randomize_options, show_score_immediately, max_violations, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant2Id, $bank2Id, $class2Id, 'Kuis Kejuruan Pemrograman Web', 40, 'WEBRPL', $now, $nextWeek, 1, 0, 1, 3, 1]);

            $messages[] = "Data demo Multi-Tenant (2 Sekolah, Kelas, Siswa, Soal, & Jadwal Ujian) berhasil di-seed!";
        } else {
            $messages[] = "Database sudah berisi data sebelumnya.";
        }

        $installed = true;

        // Pasang kunci agar installer tidak bisa dieksekusi ulang via web
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, "installed_at=" . date('c') . "\n");
        }
        $messages[] = "Installer dikunci (config/installed.lock).";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Installer | CBT Online Multi-Tenant</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --secondary: #06b6d4;
            --success: #10b981;
            --danger: #ef4444;
            --dark: #0f172a;
            --card-bg: #ffffff;
            --body-bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            max-width: 720px;
            width: 100%;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .header {
            text-align: center;
            margin-bottom: 28px;
        }
        .badge {
            display: inline-block;
            background: #e0e7ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 6px 14px;
            border-radius: 9999px;
            margin-bottom: 12px;
        }
        h1 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        p.subtitle {
            color: var(--text-muted);
            font-size: 15px;
        }
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .step-list {
            list-style: none;
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .step-item {
            background: #f1f5f9;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #334155;
        }
        .step-item::before {
            content: "✓";
            color: #10b981;
            font-weight: bold;
            font-size: 16px;
        }
        .card-demo {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            margin-top: 24px;
        }
        .card-demo h3 {
            font-size: 16px;
            color: #0f172a;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .grid-accounts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
        }
        .account-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
        }
        .account-box strong {
            color: #1e293b;
            display: block;
            margin-bottom: 4px;
        }
        .account-box code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            color: #e11d48;
            font-family: monospace;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff;
            padding: 14px 28px;
            font-weight: 700;
            font-size: 15px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(79, 70, 229, 0.45);
        }
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            box-shadow: none;
            margin-top: 10px;
        }
        .btn-outline:hover {
            background: #eef2ff;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span class="badge">PHP Native • Multi-Tenant</span>
            <h1>Instalasi & Seeder CBT Online</h1>
            <p class="subtitle">Platform Ujian Online Multi-Sekolah / Lembaga Berbasis PHP Native & MySQL</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>Gagal Melakukan Instalasi:</strong><br>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($installed): ?>
            <div class="alert alert-success">
                <strong>Selamat! Database dan Sistem Siap Digunakan.</strong>
                <p>Seluruh tabel dan data demonstrasi berhasil disiapkan di database <code><?= htmlspecialchars($config['database']) ?></code>.</p>
            </div>

            <ul class="step-list">
                <?php foreach ($messages as $msg): ?>
                    <li class="step-item"><?= $msg ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="card-demo">
                <h3>Kredensial Akun Percobaan (Demo)</h3>
                <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px; margin-bottom: 16px; font-size: 13px; color: #92400e;">
                    ⚠️ <strong>Penting:</strong> Ganti semua kata sandi di atas sebelum digunakan di lingkungan produksi.
                </div>
                <div class="grid-accounts">
                    <div class="account-box">
                        <strong>🛡️ Superadmin Platform</strong>
                        URL: <a href="superadmin/login.php">superadmin/login.php</a><br>
                        User: <code>admin</code> | Pass: <code>admin123</code>
                    </div>
                    <div class="account-box">
                        <strong>🏫 Tenant 1: SMA Teladan</strong>
                        Kode Sekolah: <code>SMATEL</code><br>
                        Admin: <code>admin_smatel</code> / <code>password</code><br>
                        Siswa: NIS <code>2024001</code> / <code>password</code><br>
                        Token Ujian: <code>SMATEL1</code>
                    </div>
                    <div class="account-box">
                        <strong>🏫 Tenant 2: SMK Nusantara</strong>
                        Kode Sekolah: <code>SMKNUS</code><br>
                        Admin: <code>admin_smknus</code> / <code>password</code><br>
                        Siswa: NIS <code>2024101</code> / <code>password</code><br>
                        Token Ujian: <code>WEBRPL</code>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px;">
                <a href="index.php" class="btn">Masuk ke Halaman Utama / Login Siswa & Guru &rarr;</a>
                <a href="superadmin/login.php" class="btn btn-outline">Buka Portal Superadmin &rarr;</a>
            </div>

        <?php else: ?>
            <div class="alert alert-success">
                Konfigurasi Database Terdeteksi:<br>
                Host: <b><?= htmlspecialchars($config['host']) ?></b> | Database: <b><?= htmlspecialchars($config['database']) ?></b> | User: <b><?= htmlspecialchars($config['username']) ?></b>
            </div>
            <p style="font-size: 14px; color: #475569; margin-bottom: 24px; line-height: 1.6;">
                Klik tombol di bawah untuk membuat database <code>cbtonline_db</code>, mengeksekusi tabel multi-tenant, dan mengisi data sampel (Superadmin, 2 Sekolah, Kelas, Siswa, Bank Soal, dan Ujian Aktif).
            </p>
            <form method="POST">
                <button type="submit" class="btn">Mulai Instalasi Otomatis Sekarang</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
