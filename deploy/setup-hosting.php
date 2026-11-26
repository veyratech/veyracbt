<?php
/**
 * CBT Online — One-Time Hosting Setup Script
 *
 * Jalankan SEKALI setelah git clone di cPanel:
 *   https://domain-kamu.com/cbtonline/deploy/setup-hosting.php
 *
 * Script ini akan:
 *  1. Cek apakah .env sudah ada → jika tidak, buat dari template
 *  2. Set permission folder writable
 *  3. Cek koneksi database
 *  4. Tampilkan status deploy
 *
 * ⚠️ HAPUS FILE INI SETELAH SELESAI (keamanan!)
 */

$messages = [];
$errors = [];
$success = false;

// ── 1. Cek & buat .env jika belum ada ──
$envFile = __DIR__ . '/../.env';
$envExample = __DIR__ . '/../.env.example';

if (file_exists($envFile)) {
    $messages[] = ['ok', '.env sudah ada — tidak perlu dibuat ulang.'];
} else {
    if (file_exists($envExample)) {
        $envContent = file_get_contents($envExample);
        // Ganti placeholder nilai lokal dengan template produksi
        $envContent = str_replace([
            'CBT_DB_HOST=127.0.0.1',
            'CBT_DB_NAME=cbtonline_db',
            'CBT_DB_USER=root',
            'CBT_DB_PASS=',
        ], [
            'CBT_DB_HOST=localhost',
            'CBT_DB_NAME=GANTI_DENGAN_NAMA_DB_CPanel',
            'CBT_DB_USER=GANTI_DENGAN_USER_DB_CPanel',
            'CBT_DB_PASS=GANTI_DENGAN_PASSWORD_DB',
        ], $envContent);

        $result = file_put_contents($envFile, $envContent);
        if ($result !== false) {
            $messages[] = ['ok', '.env dibuat dari template. EDIT file .env dan ganti kredensial DB cPanel!'];
        } else {
            $errors[] = 'Gagal membuat .env — cek permission folder root.';
        }
    } else {
        $errors[] = '.env.example tidak ditemukan!';
    }
}

// ── 2. Set permission folder writable ──
$writableDirs = [
    __DIR__ . '/../.sessions',
    __DIR__ . '/../storage/rate_limits',
    __DIR__ . '/../assets/uploads',
    __DIR__ . '/../assets/uploads/logos',
    __DIR__ . '/../assets/uploads/students',
    __DIR__ . '/../assets/uploads/webcam',
];

foreach ($writableDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        $errors[] = "Folder $dir TIDAK writable — set permission 755 di cPanel File Manager.";
    } else {
        $messages[] = ['ok', basename(dirname($dir)) . '/' . basename($dir) . ' — writable ✓'];
    }
}

// ── 3. Cek koneksi database ──
$envConfig = [];
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $envConfig[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
        }
    }
}

$dbHost = $envConfig['CBT_DB_HOST'] ?? 'localhost';
$dbName = $envConfig['CBT_DB_NAME'] ?? '';
$dbUser = $envConfig['CBT_DB_USER'] ?? '';
$dbPass = $envConfig['CBT_DB_PASS'] ?? '';

if (strpos($dbName, 'GANTI_') === 0 || strpos($dbUser, 'GANTI_') === 0) {
    $errors[] = 'Kredensial DB di .env masih placeholder (GANTI_...) — edit .env dulu!';
} else {
    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $messages[] = ['ok', "Koneksi database '{$dbName}' berhasil ✓"];

        // Cek tabel
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            $messages[] = ['ok', "Ditemukan " . count($tables) . " tabel di database."];
        } else {
            $messages[] = ['warn', "Database kosong — jalankan install.php untuk membuat tabel & seed data."];
        }
    } catch (PDOException $e) {
        $errors[] = "Koneksi database gagal: " . $e->getMessage();
    }
}

// ── 4. Cek PHP version ──
$phpVer = PHP_VERSION;
$phpMajor = (int)explode('.', $phpVer)[0];
$phpMinor = (int)explode('.', $phpVer)[1];
if ($phpMajor >= 8 && $phpMinor >= 1) {
    $messages[] = ['ok', "PHP version {$phpVer} ✓"];
} else {
    $errors[] = "PHP version {$phpVer} terlalu lama — minimal PHP 8.1. Ganti di cPanel → Select PHP Version.";
}

// ── 5. Cek ekstensi PHP wajib ──
$requiredExtensions = ['pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $messages[] = ['ok', "Ekstensi {$ext} terpasang ✓"];
    } else {
        $errors[] = "Ekstensi PHP {$ext} TIDAK terpasang! Aktifkan di cPanel → Select PHP Version.";
    }
}

$success = empty($errors);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Hosting | CBT Online</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; border-radius: 16px; max-width: 640px; width: 100%; padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }
        .badge-ok { background: #ecfdf5; color: #065f46; }
        .badge-err { background: #fef2f2; color: #991b1b; }
        h1 { font-size: 22px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }
        p.sub { font-size: 13px; color: #64748b; margin-bottom: 20px; }
        .msg { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .msg-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .msg-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .msg-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .msg::before { content: "✓"; font-weight: 700; }
        .msg-warn::before { content: "⚠"; }
        .msg-err::before { content: "✗"; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 24px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; text-align: center; }
        .btn-primary { background: #059669; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; margin-left: 8px; }
        .warning-box { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px; font-size: 12.5px; color: #92400e; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge <?= $success ? 'badge-ok' : 'badge-err' ?>">
            <?= $success ? 'Setup Berhasil' : 'Masih Ada Masalah' ?>
        </span>
        <h1>Cek Setup Hosting</h1>
        <p class="sub">Hasil verifikasi deployment CBT Online ke server cPanel.</p>

        <?php foreach ($messages as $msg): ?>
            <div class="msg msg-<?= $msg[0] ?>"><?= htmlspecialchars($msg[1]) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $err): ?>
            <div class="msg msg-err"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="../install.php" class="btn btn-primary">Jalankan Installer Database &rarr;</a>
                <a href="../" class="btn btn-primary" style="background:#3b82f6;">Buka Aplikasi &rarr;</a>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <strong>⚠️ Keamanan:</strong> Hapus file <code>deploy/setup-hosting.php</code> setelah setup selesai.
            File ini berisi informasi diagnostik yang tidak perlu ada di produksi.
        </div>
    </div>
</body>
</html>
