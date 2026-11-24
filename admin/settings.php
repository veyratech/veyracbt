<?php
/**
 * Pengaturan Sekolah (Tenant) — khusus role admin
 */

$pageTitle = "Pengaturan Sekolah";
require_once __DIR__ . '/_bootstrap.php';

if ($user['role'] !== 'admin') {
    Helper::setFlash('warning', 'Hanya admin sekolah yang dapat mengubah pengaturan sekolah.');
    Helper::redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '') {
            Helper::setFlash('danger', 'Nama sekolah wajib diisi.');
            Helper::redirect('admin/settings.php');
        }

        $data = [
            'name'    => $name,
            'address' => $address !== '' ? $address : null,
            'phone'   => $phone !== '' ? $phone : null,
        ];

        // Upload logo (opsional)
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            if ($file['size'] > 1024 * 1024) {
                Helper::setFlash('danger', 'Ukuran logo maksimal 1 MB.');
                Helper::redirect('admin/settings.php');
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                Helper::setFlash('danger', 'Format logo harus berupa file gambar valid (JPG, PNG, atau WEBP).');
                Helper::redirect('admin/settings.php');
            }
            $ext = $allowed[$mime];
            $nameFile = 'logo_t' . $tenantId . '_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . $ext;
            $destDir = __DIR__ . '/../assets/uploads/logos';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $destDir . '/' . $nameFile)) {
                $logoPath = $destDir . '/' . $nameFile;
                Helper::optimizeQuestionImage($logoPath);
                $data['logo_url'] = 'assets/uploads/logos/' . $nameFile;
                $_SESSION['school_logo'] = $data['logo_url'];
            } else {
                Helper::setFlash('danger', 'Gagal menyimpan file logo.');
                Helper::redirect('admin/settings.php');
            }
        }

        Database::update('tenants', $data, 'id = :id', ['id' => $tenantId]);
        $_SESSION['school_name'] = $name;
        Helper::setFlash('success', 'Pengaturan sekolah berhasil diperbarui.');
        Helper::redirect('admin/settings.php');
    }
}

// Muat ulang data terbaru
$school = Tenant::get();
$previewLogo = !empty($school['logo_url'])
    ? $school['logo_url']
    : ($_SESSION['school_logo'] ?? $school['logo'] ?? $school['logo_path'] ?? null);
if ($previewLogo && !str_starts_with($previewLogo, 'http') && !str_starts_with($previewLogo, '/') && !str_starts_with($previewLogo, 'data:')) {
    if (file_exists(__DIR__ . '/../' . $previewLogo)) {
        $previewLogo = '../' . $previewLogo;
    }
}

require_once __DIR__ . '/header.php';
?>
<div style="max-width: 760px;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Profil Sekolah</h3>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="update_profile">

                <div style="display: flex; align-items: center; gap: 18px; margin-bottom: 22px;">
                    <?php if (!empty($previewLogo)): ?>
                        <img src="<?= htmlspecialchars($previewLogo) ?>" alt="Logo Sekolah" style="width: 72px; height: 72px; object-fit: contain; border-radius: 14px; border: 1px solid var(--border-light); background: #fff;">
                    <?php else: ?>
                        <div style="width: 72px; height: 72px; border-radius: 14px; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800;">
                            <?= htmlspecialchars(substr((string)($school['name'] ?? ''), 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label class="form-label">Logo Sekolah (maks 1 MB: JPG, PNG, WEBP)</label>
                        <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Nama Sekolah / Lembaga</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="Alamat sekolah..."><?= htmlspecialchars($school['address'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon / Kontak</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($school['phone'] ?? '') ?>" placeholder="021-xxxxxxx">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kode Tenant (tidak bisa diubah)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($school['code']) ?>" disabled>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Paket Layanan</label>
                        <input type="text" class="form-control" value="<?= strtoupper(htmlspecialchars($school['package'])) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kuota Siswa</label>
                        <input type="text" class="form-control" value="<?= (int)$school['student_quota'] ?> siswa" disabled>
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
