<?php
/**
 * TOTP 2FA Verification Page (displayed after password login when 2FA is enabled)
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/session-init.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../core/Totp.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['pending_2fa_user'])) {
    Helper::redirect('admin/login.php');
}

$user = $_SESSION['pending_2fa_user'];
$error = '';
$flash = Helper::getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $code = trim($_POST['code'] ?? '');

    if ($user['role'] === 'superadmin') {
        $dbUser = Database::fetch("SELECT totp_secret FROM superadmins WHERE id = ?", [$user['id']]);
    } elseif ($user['role'] === 'student') {
        $dbUser = Database::fetch("SELECT totp_secret FROM students WHERE id = ?", [$user['id']]);
    } else {
        $tenantId = (int)($user['tenant_id'] ?? 0);
        $dbUser = Database::fetch("SELECT totp_secret FROM users WHERE id = ? AND tenant_id = ?", [$user['id'], $tenantId]);
    }

    if (!$dbUser || empty($dbUser['totp_secret'])) {
        $error = 'Konfigurasi 2FA tidak ditemukan.';
    } elseif (!Totp::verifyCode($dbUser['totp_secret'], $code)) {
        $error = 'Kode TOTP tidak valid.';
    } else {
        unset($_SESSION['pending_2fa_user']);
        $_SESSION['auth_user'] = $user;
        $_SESSION['2fa_verified'] = true;
        $_SESSION['last_activity'] = time();
        if ($user['role'] === 'superadmin') {
            Helper::redirect('../superadmin/index.php');
        } elseif ($user['role'] === 'student') {
            Helper::redirect('../student/dashboard.php');
        } else {
            $tenant = Tenant::findByIdentifier('');
            Helper::redirect('index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi 2FA | CBT Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('cbt_theme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) {}
    })();
    </script>
    <style>
        body { background: linear-gradient(135deg, #090d16 0%, #1e1b4b 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .login-card { background: var(--bg-card); max-width: 420px; width: 100%; border-radius: var(--radius-xl); padding: 36px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); }
    </style>
</head>
<body>
<div class="login-card">
    <div style="text-align: center; margin-bottom: 28px;">
        <span class="badge badge-primary" style="margin-bottom: 8px;">&#128274; Two-Factor Authentication</span>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-heading);">Verifikasi Identitas</h2>
        <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">Masukkan kode 6 digit dari Google Authenticator / Authy Anda</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= Helper::csrfField() ?>
        <div class="form-group">
            <label class="form-label">Kode TOTP</label>
            <input type="text" name="code" class="form-control" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required style="text-align: center; letter-spacing: 4px; font-size: 20px; font-family: monospace;" autofocus>
        </div>
        <p style="font-size: 12px; color: var(--text-muted); text-align: center; margin-bottom: 16px;">Kode berubah setiap 30 detik</p>
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">Masuk &rarr;</button>
    </form>

    <div style="margin-top: 20px; text-align: center; font-size: 13px;">
        <a href="login.php" style="color: var(--text-muted);">&larr; Kembali ke halaman login</a>
    </div>
</div>
</body>
</html>
