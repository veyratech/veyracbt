<?php
/**
 * Student Password Change Page
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Ganti Sandi';
$pageKey   = 'password';
require_once __DIR__ . '/header.php';

$successMsg = '';
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $oldPass = $_POST['old_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (!$oldPass || !$newPass || !$confirm) {
        $errorMsg = 'Semua field wajib diisi.';
    } elseif ($newPass !== $confirm) {
        $errorMsg = 'Konfirmasi kata sandi tidak cocok.';
    } elseif (strlen($newPass) < 6) {
        $errorMsg = 'Kata sandi minimal 6 karakter.';
    } else {
        // Verify old password
        $stmt = Database::getConnection()->prepare("SELECT password FROM students WHERE id = ? AND tenant_id = ?");
        $stmt->execute([(int)$student['id'], $tenantId]);
        $hash = $stmt->fetchColumn();
        
        if (!password_verify($oldPass, $hash)) {
            $errorMsg = 'Kata sandi lama salah.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            Database::update('students', ['password' => $newHash], 'id = ? AND tenant_id = ?', [(int)$student['id'], $tenantId]);
            $successMsg = 'Kata sandi berhasil diubah!';
        }
    }
}
?>

<style>
.pwd-card { background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:480px;margin:0 auto; }
.pwd-title { font-size:18px;font-weight:800;color:var(--text);margin-bottom:4px; }
.pwd-desc { font-size:13px;color:var(--muted);margin-bottom:20px; }
.field-group { margin-bottom:14px; }
.field-label { font-size:12px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:block; }
.field-input { width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;background:var(--bg);outline:none;font-family:inherit;transition:border-color .15s; }
.field-input:focus { border-color:#0284c7;background:#fff; }
.btn-save { width:100%;padding:12px;background:#0284c7;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s;margin-top:4px; }
.btn-save:hover { background:#0369a1; }
.alert-box { padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px; }
.alert-success { background:#f0fdf4;border:1px solid #bbf7d0;color:#166534; }
.alert-danger { background:#fef2f2;border:1px solid #fecaca;color:#991b1b; }
</style>

<div class="pwd-card">
    <div class="pwd-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Kata Sandi</div>
    <div class="pwd-desc">Ubah kata sandi akun siswa kamu. Gunakan kombinasi huruf dan angka.</div>
    
    <?php if ($successMsg): ?><div class="alert-box alert-success">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert-box alert-danger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
    
    <form method="POST" action="">
        <?= Helper::csrfField() ?>
        <div class="field-group">
            <label class="field-label">Kata Sandi Lama</label>
            <input type="password" name="old_password" class="field-input" placeholder="Masukkan kata sandi lama" required>
        </div>
        <div class="field-group">
            <label class="field-label">Kata Sandi Baru</label>
            <input type="password" name="new_password" class="field-input" placeholder="Minimal 6 karakter" required minlength="6">
        </div>
        <div class="field-group">
            <label class="field-label">Konfirmasi Kata Sandi</label>
            <input type="password" name="confirm_password" class="field-input" placeholder="Ulangi kata sandi baru" required>
        </div>
        <button type="submit" class="btn-save">Ubah Kata Sandi</button>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
