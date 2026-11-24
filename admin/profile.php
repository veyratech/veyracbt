<?php
/**
 * Profil & Ganti Kata Sandi (Admin / Guru)
 */

$pageTitle = "Profil & Kata Sandi";
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            Helper::setFlash('danger', 'Nama lengkap wajib diisi.');
        } else {
            Database::update('users', [
                'name'  => $name,
                'email' => $email !== '' ? $email : null
            ], 'id = :id AND tenant_id = :tenant_id', ['id' => $user['id'], 'tenant_id' => $tenantId]);

            $_SESSION['auth_user']['name'] = $name;
            $_SESSION['auth_user']['email'] = $email !== '' ? $email : null;
            Helper::setFlash('success', 'Profil berhasil diperbarui.');
        }
        Helper::redirect('admin/profile.php');

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = Database::fetch(
            "SELECT password FROM users WHERE id = ? AND tenant_id = ?",
            [$user['id'], $tenantId]
        );

        if (!$row || !password_verify($current, $row['password'])) {
            Helper::setFlash('danger', 'Kata sandi saat ini salah.');
        } elseif (strlen($new) < 8) {
            Helper::setFlash('danger', 'Kata sandi baru minimal 8 karakter.');
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            Helper::setFlash('danger', 'Kata sandi baru harus kombinasi huruf dan angka.');
        } elseif ($new !== $confirm) {
            Helper::setFlash('danger', 'Konfirmasi kata sandi baru tidak cocok.');
        } else {
            Database::update('users', [
                'password' => password_hash($new, PASSWORD_BCRYPT)
            ], 'id = :id AND tenant_id = :tenant_id', ['id' => $user['id'], 'tenant_id' => $tenantId]);
            session_regenerate_id(true);
            Helper::setFlash('success', 'Kata sandi berhasil diubah.');
        }
        Helper::redirect('admin/profile.php');
    }
}

// Data segar
$me = Database::fetch("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$user['id'], $tenantId]);
$nameParts = explode(' ', trim((string)($user['name'] ?? '')));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
$has2fa = !empty($me['totp_verified']);
$secretMasked = '';
if (!empty($me['totp_secret']) && $me['totp_secret'] !== $secretMasked) {
    $s = $me['totp_secret'];
    $secretMasked = substr($s, 0, 4) . '****' . substr($s, -4);
}

require_once __DIR__ . '/header.php';
?>

<div style="display: grid; grid-template-columns: 300px 1fr; gap: 24px; align-items: start;">

    <!-- KARTU PROFIL -->
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 28px;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--primary-gradient); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800; margin: 0 auto 16px auto;">
                <?= $initials ?>
            </div>
            <h3 style="font-size: 17px; font-weight: 800; color: var(--text-heading);"><?= htmlspecialchars($user['name'] ?? '-') ?></h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                <?= ucfirst(htmlspecialchars($user['role'] ?? 'User')) ?> &middot; <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>
            </p>
            <div style="margin-top: 14px;">
                <span class="badge badge-primary">@<?= htmlspecialchars($user['username'] ?? '-') ?></span>
            </div>
            <?php if ($has2fa): ?>
                <div style="margin-top: 10px;">
                    <span class="badge badge-success">&#10003; 2FA Aktif</span>
                </div>
            <?php else: ?>
                <div style="margin-top: 10px;">
                    <span class="badge badge-secondary">&#10008; 2FA Nonaktif</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- 2FA STATUS -->
        <div class="card">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
                <h3 class="card-title">Two-Factor Authentication (2FA)</h3>
                <a href="<?= $has2fa ? 'totp-setup.php' : 'totp-setup.php' ?>" class="btn btn-sm <?= $has2fa ? 'btn-warning' : 'btn-primary' ?>">
                    <?= $has2fa ? 'Kelola 2FA' : 'Aktifkan 2FA' ?>
                </a>
            </div>
            <div class="card-body">
                <div style="display: flex; align-items: center; gap: 16px; padding: 12px; background: var(--bg-subtle); border-radius: 10px;">
                    <div style="font-size: 28px;"><?= $has2fa ? '&#128274;' : '&#128273;' ?></div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 14px; color: var(--text-heading);">
                            <?= $has2fa ? 'Two-Factor Authentication Aktif' : 'Two-Factor Authentication Nonaktif' ?>
                        </div>
                        <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                            <?= $has2fa
                                ? 'Akun Anda dilindungi dengan TOTP. Kode verifikasi diperlukan saat login.'
                                : 'Tambahkan lapisan keamanan ekstra dengan Google Authenticator atau Authy.'; ?>
                        </div>
                    </div>
                    <?php if ($has2fa): ?>
                        <span class="badge badge-success">Aman</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Belum Dilindungi</span>
                    <?php endif; ?>
                </div>
                <?php if ($has2fa && $secretMasked): ?>
                    <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted); font-family: monospace;">
                        Secret tersimpan: <?= htmlspecialchars($secretMasked) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- EDIT PROFIL -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ubah Profil</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= Helper::csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($me['name'] ?? $user['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($me['email'] ?? '') ?>" placeholder="email@sekolah.sch.id">
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-primary">Simpan Profil</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- GANTI PASSWORD -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ganti Kata Sandi</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= Helper::csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label class="form-label">Kata Sandi Saat Ini</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Kata Sandi Baru (Min. 8 Karakter & Huruf + Angka)</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" placeholder="Minimal 8 karakter kombinasi huruf & angka" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ulangi Kata Sandi Baru</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" placeholder="Ulangi kata sandi baru" required>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-primary">Ubah Kata Sandi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
