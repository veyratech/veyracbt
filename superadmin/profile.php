<?php
/**
 * Veyra CBT - Profil & Keamanan Akun Superadmin
 * Kelola Profil, Ganti Kata Sandi, dan Setup Two-Factor Authentication (2FA)
 */

$pageTitle = "Profil & Keamanan Akun";
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../core/Totp.php';

$saId = (int)$user['id'];
$me = Database::fetch("SELECT * FROM superadmins WHERE id = ?", [$saId]);
if (!$me) {
    Helper::setFlash('danger', 'Data akun Superadmin tidak ditemukan.');
    Helper::redirect('superadmin/index.php');
}

$isDefaultPass = Auth::isDefaultSuperadminPassword($saId);
$has2fa = !empty($me['totp_verified']);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 1. UPDATE PROFIL
    if ($action === 'update_profile') {
        $name     = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if ($name === '' || $username === '') {
            Helper::setFlash('danger', 'Nama lengkap dan Username wajib diisi.');
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $username)) {
            Helper::setFlash('danger', 'Username hanya boleh terdiri dari huruf, angka, titik, strip, atau underscore (3-30 karakter).');
        } else {
            // Cek duplikasi username
            $exists = Database::fetch("SELECT id FROM superadmins WHERE username = ? AND id != ?", [$username, $saId]);
            if ($exists) {
                Helper::setFlash('danger', "Username '{$username}' sudah digunakan oleh administrator lain.");
            } else {
                Database::update('superadmins', [
                    'name'     => $name,
                    'username' => $username,
                    'email'    => $email !== '' ? $email : null,
                ], 'id = :id', ['id' => $saId]);

                $_SESSION['auth_user']['name']     = $name;
                $_SESSION['auth_user']['username'] = $username;
                $_SESSION['auth_user']['email']    = $email !== '' ? $email : null;

                Helper::setFlash('success', 'Data profil Superadmin berhasil diperbarui.');
            }
        }
        Helper::redirect('superadmin/profile.php');
    }

    // 2. GANTI KATA SANDI
    elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password'])) {
            Helper::setFlash('danger', 'Kata sandi saat ini yang Anda masukkan salah.');
        } elseif (strlen($new) < 8) {
            Helper::setFlash('danger', 'Kata sandi baru minimal 8 karakter.');
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            Helper::setFlash('danger', 'Kata sandi baru harus kombinasi huruf dan angka untuk keamanan maksimal.');
        } elseif ($new !== $confirm) {
            Helper::setFlash('danger', 'Konfirmasi kata sandi baru tidak cocok.');
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            Database::update('superadmins', [
                'password' => $newHash,
            ], 'id = :id', ['id' => $saId]);

            session_regenerate_id(true);
            Helper::setFlash('success', 'Kata sandi akun Superadmin berhasil diubah. Sistem sekarang jauh lebih aman!');
        }
        Helper::redirect('superadmin/profile.php#password-section');
    }

    // 3. VERIFIKASI & AKTIFKAN 2FA
    elseif ($action === 'verify_2fa') {
        $secret = trim($_POST['secret'] ?? '');
        $code   = trim($_POST['code'] ?? '');

        if (empty($secret) || empty($code)) {
            Helper::setFlash('danger', 'Kode verifikasi 6-digit wajib diisi.');
            Helper::redirect('superadmin/profile.php#2fa-section');
        }

        if (!Totp::verifyCode($secret, $code)) {
            Helper::setFlash('danger', 'Kode TOTP salah atau kedaluwarsa. Pastikan jam di HP Anda akurat (otomatis dari jaringan).');
            Helper::redirect('superadmin/profile.php#2fa-section');
        }

        Database::update('superadmins', [
            'totp_secret'   => $secret,
            'totp_verified' => 1
        ], 'id = :id', ['id' => $saId]);

        unset($_SESSION['sa_pending_totp_secret']);
        $_SESSION['2fa_verified'] = true;

        Helper::setFlash('success', 'Two-Factor Authentication (2FA) berhasil diaktifkan! Akun Anda kini terlindungi oleh Google Authenticator.');
        Helper::redirect('superadmin/profile.php#2fa-section');
    }

    // 4. NONAKTIFKAN 2FA
    elseif ($action === 'disable_2fa') {
        $pwd = (string)($_POST['confirm_password_disable'] ?? '');
        if (!password_verify($pwd, $me['password'])) {
            Helper::setFlash('danger', 'Kata sandi salah. 2FA tidak dapat dinonaktifkan tanpa verifikasi sandi.');
        } else {
            Database::update('superadmins', [
                'totp_secret'   => null,
                'totp_verified' => 0
            ], 'id = :id', ['id' => $saId]);

            Helper::setFlash('warning', 'Two-Factor Authentication telah dinonaktifkan.');
        }
        Helper::redirect('superadmin/profile.php#2fa-section');
    }
}

// Generate Temporary TOTP Secret for Setup if not active
if (!$has2fa) {
    if (empty($_SESSION['sa_pending_totp_secret'])) {
        $_SESSION['sa_pending_totp_secret'] = Totp::generateSecret();
    }
    $setupSecret = $_SESSION['sa_pending_totp_secret'];
    $qrAuthUrl   = Totp::getQrCodeUrl($setupSecret, 'Veyra CBT Superadmin', $me['username']);
    $sampleCode  = Totp::generateCode($setupSecret);
}

require_once __DIR__ . '/header.php';
?>

<div style="max-width: 1080px; margin: 0 auto;">

    <!-- Page Header Title -->
    <div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">Profil &amp; Keamanan Akun</h1>
            <p style="font-size: 13.5px; color: #64748b;">Pusat kontrol kredensial, proteksi dua langkah (2FA), dan integritas akun Superadmin</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="index.php" class="btn btn-outline" style="border-radius: 9999px; font-size: 13px; font-weight: 600; padding: 7px 18px;">
                &larr; Dashboard
            </a>
            <a href="logs.php" class="btn btn-outline" style="border-radius: 9999px; font-size: 13px; font-weight: 600; padding: 7px 18px;">
                Audit Log &rarr;
            </a>
        </div>
    </div>

    <!-- Grid 2 Kolom: Kolom Kiri (Profil & Password), Kolom Kanan (2FA & Audit Sesi) -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;" class="profile-layout-grid">

        <!-- ==================== KOLOM KIRI ==================== -->
        <div style="display: flex; flex-direction: column; gap: 24px;">

            <!-- 1. KARTU INFORMASI PROFIL -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 26px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #0b63e5 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 800; font-size: 20px;">
                        <?= strtoupper(substr((string)($me['name'] ?? ''), 0, 1)) ?>
                    </div>
                    <div>
                        <h2 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 2px;">Identitas Administrator</h2>
                        <span style="font-size: 12px; color: #0284c7; font-weight: 600;">Peran: Super Administrator Platform</span>
                    </div>
                </div>

                <form method="POST">
                    <?= Helper::csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($me['name'] ?? '') ?>" required style="border-radius: 12px; padding: 10px 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Username Superadmin</label>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($me['username'] ?? '') ?>" required style="border-radius: 12px; padding: 10px 14px; font-family: monospace;">
                        <small style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">Digunakan saat masuk ke panel <code>superadmin/login.php</code>.</small>
                    </div>

                    <div style="margin-bottom: 22px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Email Administrator</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($me['email'] ?? '') ?>" placeholder="admin@domain.com" style="border-radius: 12px; padding: 10px 14px;">
                        <small style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">Menerima notifikasi sistem dan laporan berkala.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 9999px; padding: 10px 20px; font-weight: 700;">
                        Simpan Perubahan Profil
                    </button>
                </form>
            </div>

            <!-- 2. KARTU GANTI KATA SANDI -->
            <div id="password-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 26px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: #fee2e2; display: flex; align-items: center; justify-content: center; color: #dc2626; font-size: 20px;">
                            🔒
                        </div>
                        <div>
                            <h2 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 2px;">Ganti Kata Sandi</h2>
                            <span style="font-size: 12px; color: #64748b;">Perbarui kata sandi secara berkala</span>
                        </div>
                    </div>
                    <?php if ($isDefaultPass): ?>
                        <span style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 9999px;">
                            Bawaan 'admin123'
                        </span>
                    <?php else: ?>
                        <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 9999px;">
                            Kustom &amp; Kuat
                        </span>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <?= Helper::csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Kata Sandi Saat Ini</label>
                        <input type="password" name="current_password" class="form-control" required placeholder="Masukkan kata sandi lama" style="border-radius: 12px; padding: 10px 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Kata Sandi Baru</label>
                        <input type="password" name="new_password" id="newPwdInput" class="form-control" required minlength="8" placeholder="Minimal 8 karakter kombinasi huruf & angka" style="border-radius: 12px; padding: 10px 14px;" onkeyup="checkPasswordStrength(this.value)">
                        <div id="pwdStrengthBar" style="height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 6px; overflow: hidden;">
                            <div id="pwdStrengthFill" style="width: 0%; height: 100%; transition: all 0.3s ease;"></div>
                        </div>
                        <small id="pwdStrengthText" style="font-size: 11px; color: #64748b; margin-top: 4px; display: block;">Kekuatan sandi: belum diisi</small>
                    </div>

                    <div style="margin-bottom: 22px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">Konfirmasi Kata Sandi Baru</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8" placeholder="Ulangi kata sandi baru" style="border-radius: 12px; padding: 10px 14px;">
                    </div>

                    <button type="submit" class="btn btn-danger" style="width: 100%; border-radius: 9999px; padding: 10px 20px; font-weight: 700; background: #dc2626; border-color: #dc2626;">
                        Ubah Kata Sandi Sekarang
                    </button>
                </form>
            </div>

        </div>

        <!-- ==================== KOLOM KANAN ==================== -->
        <div style="display: flex; flex-direction: column; gap: 24px;">

            <!-- 3. KARTU TWO-FACTOR AUTHENTICATION (2FA / TOTP) -->
            <div id="2fa-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 26px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: <?= $has2fa ? '#dcfce7' : '#fef3c7' ?>; display: flex; align-items: center; justify-content: center; color: <?= $has2fa ? '#15803d' : '#b45309' ?>; font-size: 20px;">
                            📱
                        </div>
                        <div>
                            <h2 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 2px;">Two-Factor Auth (2FA)</h2>
                            <span style="font-size: 12px; color: #64748b;">Verifikasi kode Google Authenticator</span>
                        </div>
                    </div>
                    <?php if ($has2fa): ?>
                        <span style="background: #dcfce7; color: #15803d; font-size: 11.5px; font-weight: 800; padding: 4px 12px; border-radius: 9999px;">
                            ✓ 2FA Aktif
                        </span>
                    <?php else: ?>
                        <span style="background: #fef3c7; color: #b45309; font-size: 11.5px; font-weight: 800; padding: 4px 12px; border-radius: 9999px;">
                            ! Belum Aktif
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($has2fa): ?>
                    <!-- KONDISI SUDAH AKTIF: Tampilkan Status & Opsi Nonaktifkan -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
                        <div style="font-weight: 800; color: #166534; font-size: 13.5px; margin-bottom: 4px;">Akun Superadmin Anda Sangat Aman!</div>
                        <p style="font-size: 12.5px; color: #15803d; line-height: 1.5; margin: 0;">
                            Setiap kali login, platform akan meminta 6-digit kode verifikasi acak dari aplikasi Google Authenticator atau Authy Anda.
                        </p>
                    </div>

                    <details style="border: 1px solid #fee2e2; border-radius: 14px; padding: 14px; background: #fff5f5;">
                        <summary style="font-size: 13px; font-weight: 700; color: #991b1b; cursor: pointer;">
                            ⚠️ Klik di sini jika ingin menonaktifkan 2FA
                        </summary>
                        <form method="POST" style="margin-top: 14px;" onsubmit="return confirm('PERINGATAN: Menonaktifkan 2FA akan mengurangi tingkat keamanan akun Anda. Lanjutkan?')">
                            <?= Helper::csrfField() ?>
                            <input type="hidden" name="action" value="disable_2fa">
                            <label style="display: block; font-size: 12.5px; font-weight: 700; color: #7f1d1d; margin-bottom: 6px;">Konfirmasi Kata Sandi Superadmin Anda:</label>
                            <input type="password" name="confirm_password_disable" class="form-control" required placeholder="Masukkan kata sandi untuk verifikasi" style="border-radius: 10px; padding: 8px 12px; margin-bottom: 12px;">
                            <button type="submit" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626; border-radius: 9999px; padding: 8px 18px; font-size: 12px; font-weight: 700;">
                                Nonaktifkan Two-Factor Authentication
                            </button>
                        </form>
                    </details>

                <?php else: ?>
                    <!-- KONDISI BELUM AKTIF: Setup QR Code & Input Token -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; margin-bottom: 18px;">
                        <p style="font-size: 12.5px; color: #475569; line-height: 1.5; margin: 0;">
                            Gunakan aplikasi <strong>Google Authenticator</strong>, <strong>Authy</strong>, atau <strong>Microsoft Authenticator</strong> di HP Anda untuk memindai kode QR di bawah ini:
                        </p>
                    </div>

                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="display: inline-block; padding: 10px; background: #ffffff; border: 2px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=<?= urlencode($qrAuthUrl) ?>" alt="QR Code 2FA" style="display: block; border-radius: 8px; width: 170px; height: 170px;">
                        </div>
                        <div style="margin-top: 10px;">
                            <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 4px;">Atau masukkan Secret Key secara manual:</span>
                            <code style="background: #f1f5f9; padding: 4px 10px; border-radius: 8px; font-size: 13px; font-weight: 700; color: #0b63e5; letter-spacing: 1.5px;"><?= chunk_split($setupSecret, 4, ' ') ?></code>
                        </div>
                    </div>

                    <form method="POST">
                        <?= Helper::csrfField() ?>
                        <input type="hidden" name="action" value="verify_2fa">
                        <input type="hidden" name="secret" value="<?= htmlspecialchars($setupSecret) ?>">

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px; text-align: center;">
                                Masukkan 6 Digit Kode dari Authenticator:
                            </label>
                            <input type="text" name="code" class="form-control" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" style="text-align: center; letter-spacing: 6px; font-size: 24px; font-weight: 800; font-family: monospace; border-radius: 14px; padding: 10px;">
                            <small style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block; text-align: center;">Contoh kode saat ini: <code><?= $sampleCode ?></code></small>
                        </div>

                        <button type="submit" class="btn btn-success" style="width: 100%; border-radius: 9999px; padding: 10px 20px; font-weight: 700; background: #10b981; border-color: #10b981;">
                            ✓ Verifikasi &amp; Aktifkan 2FA
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- 4. KARTU AUDIT STATUS KEAMANAN SISTEM -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                <div style="font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                    🛡️ Ringkasan Integritas Akun
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px; font-size: 12.5px;">
                    <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f8fafc;">
                        <span style="color: #64748b;">Status Sandi:</span>
                        <?php if ($isDefaultPass): ?>
                            <strong style="color: #dc2626;">⚠️ Rentan (Default 'admin123')</strong>
                        <?php else: ?>
                            <strong style="color: #16a34a;">✓ Aman (Telah Dikustomisasi)</strong>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f8fafc;">
                        <span style="color: #64748b;">Two-Factor Auth:</span>
                        <strong><?= $has2fa ? '<span style="color: #16a34a;">✓ Aktif</span>' : '<span style="color: #d97706;">! Belum Aktif</span>' ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f8fafc;">
                        <span style="color: #64748b;">IP Address Saat Ini:</span>
                        <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 6px;"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></code>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f8fafc;">
                        <span style="color: #64748b;">Sesi Terakhir:</span>
                        <span><?= $me['last_activity'] ? date('d M Y, H:i', strtotime($me['last_activity'])) : 'Saat ini' ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Dibuat Pada:</span>
                        <span><?= date('d F Y', strtotime($me['created_at'])) ?></span>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>

<style>
@media (max-width: 860px) {
    .profile-layout-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
function checkPasswordStrength(val) {
    var fill = document.getElementById('pwdStrengthFill');
    var text = document.getElementById('pwdStrengthText');
    if (!fill || !text) return;

    var score = 0;
    if (val.length >= 8) score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    if (val.length === 0) {
        fill.style.width = '0%';
        fill.style.background = '#e2e8f0';
        text.innerText = 'Kekuatan sandi: belum diisi';
        text.style.color = '#64748b';
    } else if (score <= 2) {
        fill.style.width = '33%';
        fill.style.background = '#ef4444';
        text.innerText = 'Kekuatan sandi: Lemah (Wajib min 8 karakter + huruf & angka)';
        text.style.color = '#dc2626';
    } else if (score <= 4) {
        fill.style.width = '66%';
        fill.style.background = '#f59e0b';
        text.innerText = 'Kekuatan sandi: Sedang (Bagus, tambahkan simbol untuk lebih kuat)';
        text.style.color = '#d97706';
    } else {
        fill.style.width = '100%';
        fill.style.background = '#10b981';
        text.innerText = 'Kekuatan sandi: Sangat Kuat! 🎉';
        text.style.color = '#059669';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
