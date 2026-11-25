<?php
/**
 * Student Profile Page — dengan Upload Foto Profil (CSP-compliant)
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Profil Saya';
$pageKey   = 'profile';

// ── DEBUG LOGGING (temporary diagnostic) ──────────────────────
$logFile = __DIR__ . '/../admin/upload-debug.log';
$logEntry = date('Y-m-d H:i:s') . " | METHOD={$_SERVER['REQUEST_METHOD']} | FILES_COUNT=" . count($_FILES ?? []) . " | ";
if (!empty($_FILES['photo'])) {
    $logEntry .= "ERROR=" . ($_FILES['photo']['error'] ?? '?') . " SIZE=" . ($_FILES['photo']['size'] ?? 0) . " MIME=" . (@mime_content_type($_FILES['photo']['tmp_name']) ?: '?') . " TMP=" . $_FILES['photo']['tmp_name'] . " | ";
}
file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
// ─────────────────────────────────────────────────────────────

// ── Handle photo upload FIRST (before header renders) ─────────
$photoMsg    = '';
$photoError  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $photoError = 'Gagal mengunggah foto.';
    } elseif ($file['size'] > 3 * 1024 * 1024) {
        $photoError = 'Ukuran foto maksimal 3 MB.';
    } else {
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
            $photoError = 'Format foto harus JPG, PNG, atau WEBP.';
        } else {
            $ext   = $allowed[$mime];
            $name  = 'stu_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir = __DIR__ . '/../assets/uploads/students';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $dest  = $destDir . '/' . $name;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Remove old photo
                if (!empty($student['photo'])) {
                    foreach ([__DIR__ . '/' . ltrim($student['photo'], '/'),
                             __DIR__ . '/../' . ltrim($student['photo'], '/')] as $old) {
                        if (file_exists($old)) @unlink($old);
                    }
                }
                Database::update('students', ['photo' => "assets/uploads/students/$name"],
                    'id = ? AND tenant_id = ?', [(int)$student['id'], $tenantId]);
                // Refresh student data from DB (session doesn't include 'photo')
                $fresh = Database::fetch(
                    "SELECT s.*, c.name as class_name
                     FROM students s LEFT JOIN classes c ON s.class_id = c.id
                     WHERE s.id = ? AND s.tenant_id = ?",
                    [(int)$student['id'], $tenantId]
                );
                if ($fresh) {
                    $fresh['role'] = 'student';
                    $_SESSION['auth_user'] = $fresh;
                    $student = $fresh;
                }
                $photoMsg = 'Foto profil berhasil diperbarui!';
            } else {
                $photoError = 'Gagal menyimpan foto ke server.';
            }
        }
    }
}

// ── Handle name/email update ────────────────────────────────
$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['photo'])) {
    Helper::verifyCsrf();
    $newName = trim($_POST['name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    if ($newName && $newEmail) {
        Database::update('students', ['name' => $newName, 'email' => $newEmail],
            'id = ? AND tenant_id = ?', [(int)$student['id'], $tenantId]);
        $student = Auth::user();
        $successMsg = 'Profil berhasil diperbarui!';
    }
}

// Refresh $student from DB after any POST so header shows latest data
$freshAfterPost = Database::fetch(
    "SELECT s.*, c.name as class_name
     FROM students s LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.id = ? AND s.tenant_id = ?",
    [(int)$student['id'], $tenantId]
);
if ($freshAfterPost) {
    $freshAfterPost['role'] = 'student';
    $_SESSION['auth_user'] = $freshAfterPost;
    $student = $freshAfterPost;
}

require_once __DIR__ . '/header.php';

$photoUrl = !empty($student['photo']) ? '../' . ltrim($student['photo'], '/') : null;
?>

<style>
.prof-wrap{max-width:680px;margin:0 auto;}
.prof-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;margin-bottom:20px;box-shadow:0 2px 12px rgba(0,0,0,.04);}

/* Photo Upload */
.photo-upload-area{text-align:center;margin-bottom:24px;}
.photo-preview{
    width:110px;height:110px;border-radius:50%;
    background:linear-gradient(135deg,#e0e7ff,#dbeafe);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 14px;font-size:36px;font-weight:900;color:#0284c7;
    border:3px dashed var(--border);overflow:hidden;cursor:pointer;
    transition:all .2s cubic-bezier(.34,1.56,.64,1);position:relative;
}
.photo-preview:hover{border-color:#0284c7;background:linear-gradient(135deg,#dbeafe,#bfdbfe);transform:scale(1.05);box-shadow:0 8px 24px rgba(2,132,199,.2);}
.photo-preview img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.photo-preview .photo-overlay{
    position:absolute;inset:0;background:rgba(0,0,0,.45);
    display:flex;align-items:center;justify-content:center;
    opacity:0;transition:opacity .2s;border-radius:50%;
}
.photo-preview:hover .photo-overlay{opacity:1;}
.photo-overlay svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:2;}
.photo-hint{font-size:11px;color:var(--muted);margin-top:6px;}
.photo-hint span{color:#0284c7;font-weight:700;}
.photo-input-hidden{display:none;}

.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}

.prof-name{font-size:20px;font-weight:800;text-align:center;color:var(--text);margin-bottom:2px;}
.field-group{margin-bottom:14px;}
.field-label{font-size:11px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
.field-input{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;background:var(--bg);outline:none;font-family:inherit;transition:border-color .15s;}
.field-input:focus{border-color:#0284c7;background:#fff;}
.field-readonly{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;background:#f8fafc;color:var(--muted);}
.btn-save{padding:12px 32px;background:linear-gradient(135deg,#0284c7,#0ea5e9);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 10px rgba(2,132,199,.25);}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(2,132,199,.35);}
.section-title{font-size:12px;font-weight:800;color:var(--text);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.section-title::after{content:'';flex:1;height:1px;background:var(--border);}
</style>

<div class="prof-wrap">
    <div class="prof-card">
        <div class="section-title">Foto Profil</div>

        <div class="photo-upload-area">
            <div class="photo-preview" id="photoPreview">
                <?php if ($photoUrl): ?>
                    <img src="<?= Helper::cacheBust(htmlspecialchars($photoUrl)) ?>" alt="Foto" id="previewImg">
                <?php else: ?>
                    <span id="photoInit"><?= mb_substr($student['name'] ?? 'S', 0, 1) ?></span>
                <?php endif; ?>
                <div class="photo-overlay">
                    <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
            </div>
            <input type="file" class="photo-input-hidden" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp">
            <div class="photo-hint">Klik foto untuk mengganti &#xB7; Maks 3 MB &#xB7; JPG / PNG / WEBP</div>
            <?php if ($photoMsg): ?><div class="alert-success">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($photoMsg) ?></div><?php endif; ?>
            <?php if ($photoError): ?><div class="alert-danger">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($photoError) ?></div><?php endif; ?>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="photoForm">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="upload_photo">
        </form>

        <div class="section-title" style="margin-top:20px;">Informasi Pribadi</div>

        <form method="POST" action="">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="update_info">
            <div class="field-group">
                <label class="field-label">Nama Lengkap</label>
                <input type="text" name="name" class="field-input" value="<?= htmlspecialchars($student['name'] ?? '') ?>" maxlength="100" required>
            </div>
            <div class="field-group">
                <label class="field-label">Email</label>
                <input type="email" name="email" class="field-input" value="<?= htmlspecialchars($student['email'] ?? '') ?>" maxlength="100">
            </div>
            <div class="field-group">
                <label class="field-label">NIS</label>
                <div class="field-readonly"><?= htmlspecialchars($student['nis'] ?? '-') ?></div>
            </div>
            <div class="field-group">
                <label class="field-label">Kelas</label>
                <div class="field-readonly"><?= htmlspecialchars($student['class_name'] ?? 'Umum') ?></div>
            </div>
            <?php if ($successMsg): ?><div class="alert-success" style="margin-bottom:14px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
            <button type="submit" class="btn-save">Simpan Perubahan</button>
        </form>
    </div>
</div>

<script src="../assets/js/student-portal.js?v=<?= filemtime(__DIR__.'/../assets/js/student-portal.js') ?>&t=<?= time() ?>"></script>
<script>
/* CSP-safe: no inline event attributes */
(function(){
    var input = document.getElementById('photoInput');
    var preview = document.getElementById('photoPreview');
    var form = document.getElementById('photoForm');
    if (!input || !preview || !form) return;

    /* Click anywhere on the avatar circle → open file picker */
    preview.addEventListener('click', function(e){
        e.preventDefault();
        input.click();
    });

    /* After user selects a file → validate + preview + submit */
    input.addEventListener('change', function(){
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        if (file.size > 3 * 1024 * 1024) { alert('Ukuran foto maksimal 3 MB.'); input.value = ''; return; }
        var allowed = ['image/jpeg','image/png','image/webp'];
        if (!allowed.includes(file.type)) { alert('Format harus JPG, PNG, atau WEBP.'); input.value = ''; return; }
        /* Disable input to prevent double-submit */
        input.disabled = true;
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        /* Live preview */
        var reader = new FileReader();
        reader.onload = function(ev){
            var img = document.getElementById('previewImg');
            var initSpan = document.getElementById('photoInit');
            if (img) img.src = ev.target.result;
            else if (initSpan) initSpan.outerHTML = '<img src="' + ev.target.result + '" id="previewImg" alt="Foto" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
        };
        reader.readAsDataURL(file);
        /* Submit immediately */
        form.submit();
    });
})();
</script>
<script src="../assets/js/pwa.js?v=<?= filemtime(__DIR__.'/../assets/js/pwa.js') ?>&t=<?= time() ?>"></script>
</body>
</html>