<?php
/**
 * Registrasi Lembaga — Form Ringkas (Hanya Nama + Password)
 */
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/session-init.php';
require_once __DIR__ . '/core/Tenant.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
Helper::checkMaintenance();

if (!Helper::platformConfig('allow_registration', true)) {
    Helper::setFlash('warning', 'Pendaftaran sekolah mitra baru saat ini ditutup oleh Superadmin. Silakan hubungi pihak manajemen platform.');
    Helper::redirect('index.php');
    exit;
}

if (Auth::check()) {
    $role = Auth::role();
    if ($role === 'superadmin')  { Helper::redirect('superadmin/tenants.php'); exit; }
    if (in_array($role, ['admin', 'teacher'])) { Helper::redirect('admin/index.php'); exit; }
    Helper::redirect('student/dashboard.php'); exit;
}

$error = '';
$flash = Helper::getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();

    $name   = trim($_POST['name'] ?? '');
    $pass   = trim($_POST['password'] ?? '');
    $email  = trim($_POST['email'] ?? '');

    // Validasi
    if (empty($name)) {
        $error = 'Nama sekolah / lembaga wajib diisi.';
    } elseif (strlen($pass) < 6) {
        $error = 'Kata sandi minimal 6 karakter.';
    } else {
        // Generate slug & subdomain otomatis dari nama
        $slug       = strtolower(preg_replace('/[^a-z0-9\u00C0-\u024F]+/i', '-', trim($name)));
        $slug       = preg_replace('/-+/', '-', $slug);
        $slug       = rtrim($slug, '-');

        $subdomain  = strtolower(preg_replace('/[^a-z0-9-]/', '', substr($slug, 0, 20)));
        $subdomain  = rtrim($subdomain, '-');

        // Code: 4 huruf pertama + random 4 digit
        $codePrefix = strtoupper(substr($slug, 0, 4));
        $code       = $codePrefix . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Cek duplikat
        $existing = Database::fetch(
            "SELECT id, code, slug, subdomain FROM tenants WHERE slug = ? OR subdomain = ? OR code = ? LIMIT 1",
            [$slug, $subdomain, $code]
        );
        if ($existing) {
            // Tambah suffix angka agar unik
            $suffix = random_int(100, 999);
            $slug      .= "-{$suffix}";
            $subdomain .= "-{$suffix}";
            $code      .= "{$suffix}";
        }

        // Insert tenant
        $tenantId = Database::insert('tenants', [
            'name'          => $name,
            'code'          => $code,
            'slug'          => $slug,
            'subdomain'     => $subdomain,
            'package'       => 'pro',
            'student_quota' => 500,
            'is_active'     => 1,
        ]);

        // Buat admin otomatis
        $adminUsername = $slug;
        Database::insert('users', [
            'tenant_id' => $tenantId,
            'username'  => $adminUsername,
            'password'  => password_hash($pass, PASSWORD_DEFAULT),
            'name'      => "Admin {$name}",
            'email'     => $email ?: "{$adminUsername}@cbt.local",
            'role'      => 'admin',
            'is_active' => 1,
        ]);

        Helper::setFlash('success',
            "Lembaga '{$name}' berhasil didaftarkan!<br>" .
            "<strong>Username:</strong> {$adminUsername}<br>" .
            "<strong>Password:</strong> (yang kamu isi tadi)<br><br>" .
            "Silakan login di <a href='admin/login.php'>halaman Login Guru</a>."
        );
        Helper::redirect('index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Lembaga | CBT Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --card: rgba(255,255,255,0.04);
            --border: rgba(255,255,255,0.08);
            --accent: #6366f1;
            --accent-glow: rgba(99,102,241,0.4);
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --input-bg: rgba(255,255,255,0.05);
            --input-border: rgba(255,255,255,0.1);
            --input-focus: rgba(99,102,241,0.5);
            --danger: #ef4444;
            --success: #10b981;
        }
        html[data-theme="light"] {
            --bg: #f8fafc;
            --card: rgba(255,255,255,0.8);
            --border: rgba(0,0,0,0.08);
            --accent: #4f46e5;
            --accent-glow: rgba(79,70,229,0.2);
            --text: #0f172a;
            --text-muted: #64748b;
            --input-bg: rgba(0,0,0,0.03);
            --input-border: rgba(0,0,0,0.1);
            --input-focus: rgba(79,70,229,0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .orb {
            position: fixed; border-radius: 50%; filter: blur(80px); opacity: 0.4; animation: float 20s ease-in-out infinite;
        }
        .orb-1 { width: 350px; height: 350px; background: var(--accent); top: -80px; left: -80px; }
        .orb-2 { width: 250px; height: 250px; background: #a855f7; bottom: -50px; right: -50px; animation-delay: -10s; }
        @keyframes float {
            0%,100% { transform: translate(0,0) scale(1); }
            33% { transform: translate(50px,40px) scale(1.1); }
            66% { transform: translate(-30px,60px) scale(0.9); }
        }

        .reg-wrap { width: 100%; max-width: 440px; position: relative; z-index: 1; }

        .reg-brand { text-align: center; margin-bottom: 28px; }
        .reg-brand .icon {
            font-size: 36px; margin-bottom: 12px; display: block;
        }
        .reg-brand h1 {
            font-size: 22px; font-weight: 700; color: var(--text);
            letter-spacing: -0.5px;
        }
        .reg-brand p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

        .reg-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 28px;
            backdrop-filter: blur(16px);
        }
        html[data-theme="light"] .reg-card {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(16px);
        }

        .alert { padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; line-height: 1.5; }
        .alert-danger { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        .alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }

        .field { margin-bottom: 18px; }
        .field label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .field input {
            width: 100%; padding: 12px 14px; border-radius: 10px;
            border: 1px solid var(--input-border);
            background: var(--input-bg);
            color: var(--text);
            font-size: 15px; font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .field input:focus {
            border-color: var(--input-focus);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        .field input::placeholder { color: var(--text-muted); opacity: 0.6; }

        .field-hint { font-size: 11px; color: var(--text-muted); margin-top: 5px; }

        .btn-submit {
            width: 100%; padding: 13px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, var(--accent) 0%, #a855f7 100%);
            color: #fff; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: opacity 0.2s, transform 0.15s;
            letter-spacing: 0.3px;
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }

        .reg-footer {
            margin-top: 24px; text-align: center; font-size: 13px;
            color: var(--text-muted);
        }
        .reg-footer a { color: var(--accent); text-decoration: none; font-weight: 500; }
        .reg-footer a:hover { text-decoration: underline; }
        .reg-footer span { margin: 0 6px; opacity: 0.4; }

        /* Info box after success */
        .info-box {
            background: rgba(99,102,241,0.08);
            border: 1px solid rgba(99,102,241,0.2);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 18px;
            font-size: 13px;
            color: var(--text);
            line-height: 1.7;
        }
        .info-box strong { color: var(--accent); }
        html[data-theme="light"] .info-box { background: rgba(79,70,229,0.06); border-color: rgba(79,70,229,0.15); }
    </style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="reg-wrap">
    <div class="reg-brand">
        <span class="icon">🎓</span>
        <h1>Daftar Lembaga</h1>
        <p>Cukup isi nama sekolah dan kata sandi. Lainnya otomatis.</p>
    </div>

    <div class="reg-card">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['message'] ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= Helper::csrfField() ?>

            <div class="field">
                <label>Nama Sekolah / Lembaga</label>
                <input type="text" name="name" placeholder="Contoh: SMA Negeri 1 Maju" required autofocus autocomplete="organization">
                <div class="field-hint">Username admin akan dibuat otomatis dari nama ini.</div>
            </div>

            <div class="field">
                <label>Kata Sandi Admin</label>
                <input type="password" name="password" placeholder="Minimal 6 karakter" minlength="6" required autocomplete="new-password">
            </div>

            <div class="field">
                <label>Email (opsional)</label>
                <input type="email" name="email" placeholder="admin@sekolah.sch.id" autocomplete="email">
                <div class="field-hint">Jika kosong, email default akan dibuat dari username.</div>
            </div>

            <button type="submit" class="btn-submit">Daftar Sekarang →</button>
        </form>
    </div>

    <div class="reg-footer">
        <a href="index.php">← Portal</a>
        <span>·</span>
        <a href="student/login.php">Login Siswa</a>
        <span>·</span>
        <a href="admin/login.php">Login Admin</a>
    </div>
</div>
</body>
</html>
