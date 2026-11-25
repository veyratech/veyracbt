<?php
/**
 * Student Profile Page — dengan Upload Foto Profil (CSP-compliant)
 * Uses POST-Redirect-GET to prevent stale cached responses.
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Profil Saya';
$pageKey   = 'profile';

// ── POST-Redirect-GET: handle submission then redirect ────────
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isPost) {
    Helper::verifyCsrf();
    
    // ── Handle photo upload ─────────────────────────────────
    $doRedirect = true;
    if (!empty($_FILES['photo'])) {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msg = match($file['error']) {
                UPLOAD_ERR_INI_SIZE  => 'Foto terlalu besar (batas server).',
                UPLOAD_ERR_FORM_SIZE => 'Ukuran foto melebihi batas 3 MB.',
                UPLOAD_ERR_PARTIAL   => 'Upload tidak lengkap. Coba lagi.',
                default              => 'Gagal mengunggah foto (error ' . $file['error'] . ').',
            };
            Helper::setFlash('danger', $msg);
            $doRedirect = false;
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            Helper::setFlash('danger', 'Ukuran foto maksimal 3 MB.');
            $doRedirect = false;
        } else {
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                Helper::setFlash('danger', 'Format foto harus JPG, PNG, atau WEBP.');
                $doRedirect = false;
            } else {
                $ext   = $allowed[$mime];
                $name  = 'stu_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destDir = __DIR__ . '/../assets/uploads/students';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $dest  = $destDir . '/' . $name;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    if (!empty($student['photo'])) {
                        foreach ([__DIR__ . '/' . ltrim($student['photo'], '/'),
                                 __DIR__ . '/../' . ltrim($student['photo'], '/')] as $old) {
                            if (file_exists($old)) @unlink($old);
                        }
                    }
                    Database::update('students', ['photo' => "assets/uploads/students/$name"],
                        'id = ? AND tenant_id = ?', [(int)$student['id'], $tenantId]);
                    // Refresh session immediately
                    $fresh = Database::fetch(
                        "SELECT s.*, c.name as class_name
                         FROM students s LEFT JOIN classes c ON s.class_id = c.id
                         WHERE s.id = ? AND s.tenant_id = ?",
                        [(int)$student['id'], $tenantId]
                    );
                    if ($fresh) {
                        $fresh['role'] = 'student';
                        $_SESSION['auth_user'] = $fresh;
                    }
                    Helper::setFlash('success', 'Foto profil berhasil diperbarui!');
                } else {
                    Helper::setFlash('danger', 'Gagal menyimpan foto ke server.');
                    $doRedirect = false;
                }
            }
        }
    }
    
    // ── Handle name/email update ────────────────────────────
    $newName  = trim($_POST['name']  ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    if ($newName && $newEmail) {
        Database::update('students', ['name' => $newName, 'email' => $newEmail],
            'id = ? AND tenant_id = ?', [(int)$student['id'], $tenantId]);
        Helper::setFlash('success', 'Profil berhasil diperbarui!');
    }
    
    // Redirect to self with unique param to bust any cache
    if ($doRedirect) {
        $redirectUrl = 'profile.php?cbt_save=' . time();
        header("Location: $redirectUrl");
        exit;
    }
}

// ── Refresh $student from DB (always fresh on GET) ──────────
$freshStudent = Database::fetch(
    "SELECT s.*, c.name as class_name
     FROM students s LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.id = ? AND s.tenant_id = ?",
    [(int)$student['id'], $tenantId]
);
if ($freshStudent) {
    $freshStudent['role'] = 'student';
    $_SESSION['auth_user'] = $freshStudent;
    $student = $freshStudent;
}

require_once __DIR__ . '/header.php';

$photoUrl = !empty($student['photo']) ? '../' . ltrim($student['photo'], '/') : null;
?>

<style>
/* ── Profile Page Custom Styles ─────────────────────────── */
.prof-page { display: flex; justify-content: center; padding: 24px 16px 48px; }

.prof-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    width: 100%;
    max-width: 640px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

/* Header Banner */
.prof-banner {
    height: 110px;
    background: linear-gradient(135deg, #0b63e5 0%, #0ea5e9 50%, #06b6d4 100%);
    position: relative;
    overflow: hidden;
}
.prof-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.prof-banner .banner-decoration {
    position: absolute;
    bottom: -30px;
    right: -20px;
    width: 160px;
    height: 160px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
}
.prof-banner .banner-decoration2 {
    position: absolute;
    top: -50px;
    right: 60px;
    width: 100px;
    height: 100px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}

/* Avatar Section */
.prof-avatar-section {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    padding: 0 28px 20px;
    margin-top: -48px;
    position: relative;
    z-index: 2;
}
.prof-avatar-wrap {
    position: relative;
    flex-shrink: 0;
    cursor: pointer;
    group: avatar;
}
.prof-avatar-ring {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e0e7ff, #bfdbfe);
    border: 4px solid #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,.15);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s;
    position: relative;
}
.prof-avatar-wrap:hover .prof-avatar-ring {
    transform: scale(1.06);
    box-shadow: 0 8px 28px rgba(2,132,199,.25);
}
.prof-avatar-ring img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.prof-avatar-ring .avatar-initial {
    font-size: 32px;
    font-weight: 900;
    color: #0284c7;
}
.prof-avatar-overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,.4);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .2s;
}
.prof-avatar-wrap:hover .prof-avatar-overlay { opacity: 1; }
.prof-avatar-overlay svg { width: 22px; height: 22px; stroke: #fff; fill: none; stroke-width: 2; }
.prof-avatar-badge {
    position: absolute;
    bottom: 2px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(2,132,199,.92);
    color: #fff;
    font-size: 8.5px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 999px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .2s;
    box-shadow: 0 2px 8px rgba(2,132,199,.4);
}
.prof-avatar-badge.show { opacity: 1; }
.photo-input-hidden { display: none; }

.prof-user-info {
    padding-bottom: 8px;
    flex: 1;
    min-width: 0;
}
.prof-user-name {
    font-size: 18px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.2;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.prof-user-meta {
    font-size: 12px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.prof-user-meta span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.prof-user-meta .meta-dot {
    width: 3px;
    height: 3px;
    background: var(--muted);
    border-radius: 50%;
    opacity: .5;
}

/* Body Section */
.prof-body { padding: 4px 28px 28px; }

/* Section Title */
.prof-section-title {
    font-size: 10px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin: 24px 0 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.prof-section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}
.prof-section-title:first-child { margin-top: 4px; }

/* Alert Boxes */
.alert-success {
    background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    border: 1px solid #bbf7d0;
    color: #166534;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    animation: slideDown .3s ease;
}
.alert-danger {
    background: linear-gradient(135deg, #fef2f2, #fff1f2);
    border: 1px solid #fecaca;
    color: #991b1b;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    animation: slideDown .3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Info Grid */
.prof-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}
.prof-field { display: flex; flex-direction: column; gap: 5px; }
.prof-field.full { grid-column: 1 / -1; }
.prof-field-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .6px;
}
.prof-field-input {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-size: 14px;
    background: var(--bg);
    outline: none;
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s;
    color: var(--text);
}
.prof-field-input:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2,132,199,.1);
    background: #fff;
}
.prof-field-readonly {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-size: 14px;
    background: #f8fafc;
    color: var(--muted);
    font-family: inherit;
}

/* Save Button */
.prof-save-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}
.btn-save {
    padding: 12px 36px;
    background: linear-gradient(135deg, #0284c7, #0ea5e9);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    box-shadow: 0 4px 14px rgba(2,132,199,.3);
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.btn-save:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(2,132,199,.4);
}
.btn-save:active { transform: translateY(0); }
.btn-save svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2.2; }
.save-hint {
    font-size: 11.5px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 4px;
}
.save-hint svg { width: 13px; height: 13px; stroke: var(--muted); fill: none; stroke-width: 2; }

/* Photo Hint */
.prof-photo-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    font-size: 11px;
    color: var(--muted);
}
.prof-photo-hint svg { width: 13px; height: 13px; stroke: var(--muted); fill: none; stroke-width: 2; flex-shrink: 0; }
.prof-photo-hint span { color: #0284c7; font-weight: 700; }

@media (max-width: 600px) {
    .prof-page { padding: 16px 12px 32px; }
    .prof-card { border-radius: 18px; }
    .prof-banner { height: 80px; }
    .prof-avatar-section { padding: 0 20px 16px; margin-top: -36px; }
    .prof-avatar-ring { width: 76px; height: 76px; }
    .prof-avatar-ring .avatar-initial { font-size: 26px; }
    .prof-user-name { font-size: 16px; }
    .prof-body { padding: 4px 20px 24px; }
    .prof-grid { grid-template-columns: 1fr; }
    .prof-section-title { margin: 20px 0 10px; }
}
</style>

<div class="prof-page">
    <div class="prof-card">

        <!-- Banner -->
        <div class="prof-banner">
            <div class="banner-decoration"></div>
            <div class="banner-decoration2"></div>
        </div>

        <!-- Avatar + User Info -->
        <div class="prof-avatar-section">
            <div class="prof-avatar-wrap" id="avatarWrap">
                <div class="prof-avatar-ring" id="avatarRing">
                    <?php if ($photoUrl): ?>
                        <img src="<?= Helper::cacheBust(htmlspecialchars($photoUrl)) ?>" alt="Foto" id="previewImg">
                    <?php else: ?>
                        <span class="avatar-initial"><?= mb_substr($student['name'] ?? 'S', 0, 1) ?></span>
                    <?php endif; ?>
                    <div class="prof-avatar-overlay">
                        <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                </div>
                <div class="prof-avatar-badge" id="photoBadge">✓ Siap disimpan</div>
                <input type="file" class="photo-input-hidden" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="prof-user-info">
                <div class="prof-user-name"><?= htmlspecialchars($student['name'] ?? 'Siswa') ?></div>
                <div class="prof-user-meta">
                    <span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?= htmlspecialchars($student['class_name'] ?? 'Umum') ?>
                    </span>
                    <span class="meta-dot"></span>
                    <span>NIS: <?= htmlspecialchars($student['nis'] ?? '-') ?></span>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="prof-body">

            <?php $flash = Helper::getFlash(); if ($flash): ?><div class="alert-<?= htmlspecialchars($flash['type']) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?= htmlspecialchars($flash['message']) ?>
            </div><?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" id="profForm">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="update_info">

                <div class="prof-section-title">Informasi Pribadi</div>

                <div class="prof-grid">
                    <div class="prof-field full">
                        <label class="prof-field-label">Nama Lengkap</label>
                        <input type="text" name="name" class="prof-field-input" value="<?= htmlspecialchars($student['name'] ?? '') ?>" maxlength="100" required placeholder="Masukkan nama lengkap">
                    </div>
                    <div class="prof-field full">
                        <label class="prof-field-label">Email</label>
                        <input type="email" name="email" class="prof-field-input" value="<?= htmlspecialchars($student['email'] ?? '') ?>" maxlength="100" placeholder="contoh@email.com">
                    </div>
                    <div class="prof-field">
                        <label class="prof-field-label">NIS</label>
                        <div class="prof-field-readonly"><?= htmlspecialchars($student['nis'] ?? '-') ?></div>
                    </div>
                    <div class="prof-field">
                        <label class="prof-field-label">Kelas</label>
                        <div class="prof-field-readonly"><?= htmlspecialchars($student['class_name'] ?? 'Umum') ?></div>
                    </div>
                </div>

                <div class="prof-photo-hint">
                    <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Klik foto di atas untuk mengganti &#xB7; Maks 3 MB &#xB7; JPG / PNG / WEBP &#xB7; Klik Simpan setelah memilih
                </div>

                <div class="prof-save-row" style="margin-top:18px;">
                    <button type="submit" class="btn-save">
                        <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Simpan Perubahan
                    </button>
                    <div class="save-hint">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        Semua perubahan akan tersimpan sekaligus
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var input  = document.getElementById('photoInput');
    var preview = document.getElementById('avatarRing');
    var badge  = document.getElementById('photoBadge');
    if (!input || !preview) return;

    preview.addEventListener('click', function(e){
        e.preventDefault();
        input.click();
    });

    input.addEventListener('change', function(){
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        if (file.size > 3 * 1024 * 1024) { alert('Ukuran foto maksimal 3 MB.'); input.value = ''; return; }
        var allowed = ['image/jpeg','image/png','image/webp'];
        if (!allowed.includes(file.type)) { alert('Format harus JPG, PNG, atau WEBP.'); input.value = ''; return; }
        var reader = new FileReader();
        reader.onload = function(ev){
            var img = document.getElementById('previewImg');
            var init = document.querySelector('.avatar-initial');
            if (img) {
                img.src = ev.target.result;
                if (init) init.style.display = 'none';
            } else if (init) {
                init.outerHTML = '<img src="' + ev.target.result + '" id="previewImg" alt="Foto" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
            }
            if (badge) badge.classList.add('show');
        };
        reader.readAsDataURL(file);
    });
})();
</script>
<script src="../assets/js/pwa.js?v=<?= filemtime(__DIR__.'/../assets/js/pwa.js') ?>&t=<?= time() ?>"></script>
</body>
</html>