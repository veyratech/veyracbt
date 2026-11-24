<?php
/**
 * TOTP 2FA Setup Page (Admin / Guru)
 */

$pageTitle = "Setup 2FA";
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_2fa') {
        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['2fa_pending_secret'] ?? '';

        if (empty($secret) || empty($code)) {
            Helper::setFlash('danger', 'Kode TOTP wajib diisi.');
            Helper::redirect('admin/totp-setup.php');
        }

        if (!Totp::verifyCode($secret, $code)) {
            Helper::setFlash('danger', 'Kode TOTP tidak valid. Pastikan waktu perangkat Anda tersinkronisasi.');
            Helper::redirect('admin/totp-setup.php');
        }

        Database::update('users', ['totp_secret' => $secret, 'totp_verified' => 1], 'id = :id AND tenant_id = :tenant_id', ['id' => $user['id'], 'tenant_id' => $tenantId]);
        unset($_SESSION['2fa_pending_secret']);
        $_SESSION['2fa_verified'] = true;
        Helper::setFlash('success', 'Two-Factor Authentication berhasil diaktifkan.');
        Helper::redirect('admin/profile.php');
    } elseif ($action === 'disable_2fa') {
        Database::update('users', ['totp_secret' => null, 'totp_verified' => 0], 'id = :id AND tenant_id = :tenant_id', ['id' => $user['id'], 'tenant_id' => $tenantId]);
        Helper::setFlash('info', 'Two-Factor Authentication berhasil dinonaktifkan.');
        Helper::redirect('admin/profile.php');
    }
}

$me = Database::fetch("SELECT totp_secret, totp_verified FROM users WHERE id = ? AND tenant_id = ?", [$user['id'], $tenantId]);

if ($me['totp_verified'] == 1 && empty($_SESSION['2fa_pending_secret'])) {
    Helper::setFlash('info', '2FA sudah aktif. Jika ingin mengatur ulang, nonaktifkan terlebih dahulu.');
    Helper::redirect('admin/profile.php');
}

if (empty($_SESSION['2fa_pending_secret'])) {
    $secret = Totp::generateSecret();
    $_SESSION['2fa_pending_secret'] = $secret;
}

$secret = $_SESSION['2fa_pending_secret'];
$qrUrl = Totp::getQrCodeUrl($secret, 'CBT Online', $user['username']);
$currentCode = Totp::generateCode($secret);
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | CBT Online</title>
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
        .login-card { background: var(--bg-card); max-width: 480px; width: 100%; border-radius: var(--radius-xl); padding: 36px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); }
        .qr-box { text-align: center; margin: 20px 0; }
        .qr-box img { border: 4px solid var(--border); border-radius: 12px; max-width: 200px; width: 100%; }
        .secret-text { font-family: monospace; font-size: 13px; color: var(--text-muted); word-break: break-all; background: var(--bg-subtle); padding: 8px 12px; border-radius: 8px; margin-top: 10px; }
        .preview-code { display: inline-block; background: var(--primary); color: #fff; padding: 4px 12px; border-radius: 6px; font-family: monospace; font-size: 14px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="login-card">
    <div style="text-align: center; margin-bottom: 24px;">
        <span class="badge badge-primary" style="margin-bottom: 8px;">&#128274; Two-Factor Authentication</span>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-heading);">Setup 2FA untuk <?= htmlspecialchars($user['name']) ?></h2>
        <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">Scan QR code atau masukkan secret key secara manual ke Google Authenticator / Authy</p>
    </div>

    <?php $flash = Helper::getFlash(); if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <div class="qr-box">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($qrUrl) ?>" alt="QR Code TOTP">
        <div class="secret-text">Secret Key: <?= htmlspecialchars($secret) ?></div>
        <div style="font-size: 12px; color: var(--text-muted); margin-top: 6px;">Kode contoh (hanya untuk verifikasi):</div>
        <div class="preview-code"><?= htmlspecialchars($currentCode) ?></div>
    </div>

    <form method="POST">
        <?= Helper::csrfField() ?>
        <input type="hidden" name="action" value="verify_2fa">
        <div class="form-group">
            <label class="form-label">Masukkan Kode 6 Digit dari Aplikasi Authenticator</label>
            <input type="text" name="code" class="form-control" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required style="text-align: center; letter-spacing: 4px; font-size: 20px; font-family: monospace;">
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">Verifikasi & Aktifkan 2FA &rarr;</button>
    </form>

    <div style="margin-top: 16px; text-align: center;">
        <a href="profile.php" style="color: var(--text-muted); font-size: 13px;">&larr; Kembali ke Profil</a>
    </div>
</div>
</body>
</html>
