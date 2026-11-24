<?php
/**
 * Master Template ID Card / Kartu Ujian CBT
 * Ruang Pengaturan & Upload Template ID Card — Khusus Dashboard Superadmin
 */

declare(strict_types=1);

$pageTitle = "Template ID Card Ujian";
require_once __DIR__ . '/_bootstrap.php';

$configFile = __DIR__ . '/../config/idcard.php';
$config = file_exists($configFile) ? (require $configFile) : [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $config['theme']           = $_POST['theme'] ?? 'modern_violet';
        $config['primary_color']   = trim($_POST['primary_color'] ?? '#4c1d95');
        $config['secondary_color'] = trim($_POST['secondary_color'] ?? '#7c3aed');
        $config['card_title']      = trim($_POST['card_title'] ?? 'KARTU PESERTA UJIAN');
        $config['academic_year']   = trim($_POST['academic_year'] ?? '2026 / 2027');
        $config['contact_phone']   = trim($_POST['contact_phone'] ?? '');
        $config['contact_email']   = trim($_POST['contact_email'] ?? '');
        $config['signer_title']    = trim($_POST['signer_title'] ?? 'Panitia Ujian');
        $config['signer_name']     = trim($_POST['signer_name'] ?? 'Proktor / Panitia Pelaksana');

        // Rules array
        $rawRules = trim($_POST['rules_text'] ?? '');
        if ($rawRules !== '') {
            $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rawRules))));
            $config['rules'] = array_values($lines);
        }

        $config['updated_at'] = date('Y-m-d H:i:s');

        // Simpan ke config/idcard.php
        $exportContent = "<?php\n/**\n * Konfigurasi Global Template ID Card\n * Terakhir diperbarui oleh Superadmin: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configFile, $exportContent);

        Helper::setFlash('success', 'Pengaturan template ID Card berhasil disimpan.');
        Helper::redirect('superadmin/idcard-templates.php');
        exit;

    } elseif ($action === 'upload_bg') {
        $uploadDir = __DIR__ . '/../assets/uploads/templates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploaded = 0;

        // Upload Background Depan
        if (!empty($_FILES['bg_front']['name']) && $_FILES['bg_front']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['bg_front']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['bg_front']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp']) && @getimagesize($tmpPath) !== false) {
                $destName = 'idcard_front_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $destName)) {
                    // Hapus file lama jika ada
                    if (!empty($config['bg_front']) && file_exists($uploadDir . $config['bg_front'])) {
                        @unlink($uploadDir . $config['bg_front']);
                    }
                    $config['bg_front'] = $destName;
                    $uploaded++;
                }
            }
        }

        // Upload Background Belakang
        if (!empty($_FILES['bg_back']['name']) && $_FILES['bg_back']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['bg_back']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['bg_back']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp']) && @getimagesize($tmpPath) !== false) {
                $destName = 'idcard_back_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $destName)) {
                    // Hapus file lama jika ada
                    if (!empty($config['bg_back']) && file_exists($uploadDir . $config['bg_back'])) {
                        @unlink($uploadDir . $config['bg_back']);
                    }
                    $config['bg_back'] = $destName;
                    $uploaded++;
                }
            }
        }

        if ($uploaded > 0) {
            $config['theme'] = 'custom_image';
            $config['updated_at'] = date('Y-m-d H:i:s');
            $exportContent = "<?php\nreturn " . var_export($config, true) . ";\n";
            file_put_contents($configFile, $exportContent);
            Helper::setFlash('success', "Berhasil mengunggah {$uploaded} gambar template ID Card baru.");
        } else {
            Helper::setFlash('warning', 'Tidak ada file template gambar valid yang dipilih (format didukung: PNG, JPG, WEBP).');
        }

        Helper::redirect('superadmin/idcard-templates.php');
        exit;

    } elseif ($action === 'reset_bg') {
        $uploadDir = __DIR__ . '/../assets/uploads/templates/';
        if (!empty($config['bg_front']) && file_exists($uploadDir . $config['bg_front'])) {
            @unlink($uploadDir . $config['bg_front']);
        }
        if (!empty($config['bg_back']) && file_exists($uploadDir . $config['bg_back'])) {
            @unlink($uploadDir . $config['bg_back']);
        }

        $config['bg_front'] = null;
        $config['bg_back']  = null;
        $config['theme']    = 'modern_violet';
        $config['updated_at'] = date('Y-m-d H:i:s');
        $exportContent = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configFile, $exportContent);

        Helper::setFlash('info', 'Template background kustom direset. Sistem kembali menggunakan Template Utama Modern Violet.');
        Helper::redirect('superadmin/idcard-templates.php');
        exit;
    }
}

require_once __DIR__ . '/header.php';

$primaryColor = $config['primary_color'] ?? '#4c1d95';
$secondaryColor = $config['secondary_color'] ?? '#7c3aed';
$hasCustomFront = !empty($config['bg_front']) && file_exists(__DIR__ . '/../assets/uploads/templates/' . $config['bg_front']);
$hasCustomBack  = !empty($config['bg_back']) && file_exists(__DIR__ . '/../assets/uploads/templates/' . $config['bg_back']);
$rulesText = implode("\n", $config['rules'] ?? []);
?>

<div class="boardto-content-scroll" style="padding: 24px 28px;">

    <!-- TOP HEADER -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #6366f1; margin-bottom: 4px;">
                Eksklusif Superadmin Platform
            </div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;">
                Pengaturan &amp; Template ID Card Ujian
            </h1>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Desain kartu peserta ujian utama yang diterapkan secara terpusat ke seluruh sekolah mitra berlisensi PRO &amp; ENTERPRISE.
            </p>
        </div>

        <div style="display: flex; gap: 10px;">
            <a href="tenants.php" class="btn btn-outline btn-sm" style="background:#fff;">
                &larr; Sekolah Mitra
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('uploadBgModal').classList.add('show')" style="background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; font-weight: 700;">
                &#128228; Upload Desain Kustom
            </button>
        </div>
    </div>

    <!-- MAIN GRID: PREVIEW & SETTINGS -->
    <div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 28px; align-items: start;">

        <!-- LEFT COLUMN: REALISTIC LANYARD PREVIEW -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 24px; padding: 26px; box-shadow: 0 10px 30px rgba(0,0,0,0.03);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;">
                <div>
                    <h3 style="font-size: 16px; font-weight: 800; color: #0f172a;">Pratinjau Kartu (Lanyard Mockup)</h3>
                    <p style="font-size: 12px; color: #64748b;">Standar portrait CR80 / B1 (Tampak Depan &amp; Belakang)</p>
                </div>
                <span class="badge" style="background: rgba(99,102,241,0.1); color: #4f46e5; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
                    <?= $hasCustomFront ? 'Desain Kustom Aktif' : 'Template Utama (Violet Wave)' ?>
                </span>
            </div>

            <!-- MOCKUP DISPLAY WRAPPER -->
            <div style="background: linear-gradient(180deg, #e2e8f0 0%, #cbd5e1 100%); border-radius: 18px; padding: 36px 20px; display: flex; gap: 24px; justify-content: center; overflow-x: auto;">

                <!-- 1. FRONT CARD MOCKUP -->
                <div style="display: flex; flex-direction: column; align-items: center;">
                    <!-- Lanyard Strap & Clip -->
                    <div style="width: 28px; height: 44px; background: <?= htmlspecialchars($primaryColor) ?>; border-radius: 4px 4px 0 0; box-shadow: inset 0 0 6px rgba(0,0,0,0.3);"></div>
                    <div style="width: 38px; height: 26px; background: linear-gradient(180deg, #94a3b8, #cbd5e1); border-radius: 6px; border: 1.5px solid #64748b; margin-top: -2px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 8px rgba(0,0,0,0.15);">
                        <div style="width: 14px; height: 10px; border-radius: 50%; border: 2px solid #475569;"></div>
                    </div>

                    <!-- Card Body Front -->
                    <div style="width: 215px; height: 340px; background: #ffffff; border-radius: 16px; box-shadow: 0 16px 36px rgba(0,0,0,0.15); margin-top: -6px; overflow: hidden; position: relative; display: flex; flex-direction: column; justify-content: space-between; border: 1px solid rgba(0,0,0,0.06); <?= $hasCustomFront ? 'background: url(\'../assets/uploads/templates/' . htmlspecialchars($config['bg_front']) . '\') center/cover no-repeat;' : '' ?>">
                        
                        <?php if (!$hasCustomFront): ?>
                            <!-- TOP VIOLET WAVE -->
                            <div style="height: 125px; background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?> 0%, <?= htmlspecialchars($secondaryColor) ?> 100%); position: relative; border-radius: 0 0 50% 50% / 0 0 35% 35%; padding: 12px 14px; overflow: hidden;">
                                <!-- Dot pattern -->
                                <div style="position: absolute; top: 12px; left: 10px; opacity: 0.25; font-size: 8px; line-height: 8px; letter-spacing: 2px; color: #fff;">
                                    &bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;
                                </div>
                                <div style="position: absolute; top: 12px; right: 12px; opacity: 0.25; font-size: 8px; line-height: 8px; letter-spacing: 2px; color: #fff;">
                                    &bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;
                                </div>
                                <!-- School logo inside wave -->
                                <div style="display: flex; justify-content: flex-end; align-items: center;">
                                    <div style="background: rgba(255,255,255,0.2); backdrop-filter: blur(4px); padding: 3px 8px; border-radius: 6px; font-size: 9px; font-weight: 800; color: #fff; letter-spacing: 0.5px;">
                                        LOGO SEKOLAH
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="height: 60px;"></div>
                        <?php endif; ?>

                        <!-- PHOTO CIRCLE OVERLAY -->
                        <div style="position: absolute; top: 80px; left: 50%; transform: translateX(-50%); width: 72px; height: 72px; border-radius: 50%; border: 3.5px solid #ffffff; background: #f8fafc; box-shadow: 0 8px 18px rgba(0,0,0,0.18); overflow: hidden; display: flex; align-items: center; justify-content: center; z-index: 5;">
                            <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80" alt="Foto Siswa" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='../assets/img/avatar.svg'">
                        </div>

                        <!-- MIDDLE CONTENT -->
                        <div style="margin-top: 36px; padding: 0 16px; text-align: center; flex: 1;">
                            <div style="font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 2px;">
                                Rizky Pratama
                            </div>
                            <div style="font-size: 9px; font-weight: 800; color: <?= htmlspecialchars($primaryColor) ?>; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">
                                PESERTA UJIAN CBT
                            </div>

                            <div style="background: #f8fafc; border-radius: 8px; padding: 6px 8px; font-size: 9px; color: #475569; line-height: 1.45; text-align: left; margin-bottom: 8px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span>NIS:</span> <strong style="color: #0f172a;">2024101</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Kelas:</span> <strong style="color: #0f172a;">XII RPL 1</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Sesi/Ruang:</span> <strong style="color: #0f172a;">Sesi 1 / Lab 01</strong>
                                </div>
                            </div>

                            <!-- QR CODE MOCKUP -->
                            <div style="display: flex; justify-content: center;">
                                <div style="padding: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
                                    <svg width="44" height="44" viewBox="0 0 24 24" fill="<?= htmlspecialchars($primaryColor) ?>">
                                        <path d="M3 3h8v8H3V3zm2 2v4h4V5H5zm8-2h8v8h-8V3zm2 2v4h4V5h-4zM3 13h8v8H3v-8zm2 2v4h4v-4H5zm13-2h3v2h-3v-2zm-5 0h2v3h-2v-3zm3 3h2v2h-2v-2zm2 2h3v3h-3v-3zm-5 0h2v3h-2v-3zm2-2h2v2h-2v-2z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <?php if (!$hasCustomFront): ?>
                            <!-- BOTTOM VIOLET WAVE -->
                            <div style="height: 38px; background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?> 0%, <?= htmlspecialchars($secondaryColor) ?> 100%); border-radius: 50% 50% 0 0 / 30% 30% 0 0; position: relative;">
                                <div style="position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%); font-size: 7.5px; color: rgba(255,255,255,0.8); font-weight: 700; letter-spacing: 0.5px;">
                                    <?= htmlspecialchars($config['card_title'] ?? 'KARTU PESERTA UJIAN') ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; color: #475569; margin-top: 8px;">Tampak Depan</span>
                </div>

                <!-- 2. BACK CARD MOCKUP -->
                <div style="display: flex; flex-direction: column; align-items: center;">
                    <!-- Lanyard Strap & Clip -->
                    <div style="width: 28px; height: 44px; background: <?= htmlspecialchars($primaryColor) ?>; border-radius: 4px 4px 0 0; box-shadow: inset 0 0 6px rgba(0,0,0,0.3);"></div>
                    <div style="width: 38px; height: 26px; background: linear-gradient(180deg, #94a3b8, #cbd5e1); border-radius: 6px; border: 1.5px solid #64748b; margin-top: -2px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 8px rgba(0,0,0,0.15);">
                        <div style="width: 14px; height: 10px; border-radius: 50%; border: 2px solid #475569;"></div>
                    </div>

                    <!-- Card Body Back -->
                    <div style="width: 215px; height: 340px; background: #ffffff; border-radius: 16px; box-shadow: 0 16px 36px rgba(0,0,0,0.15); margin-top: -6px; overflow: hidden; position: relative; display: flex; flex-direction: column; justify-content: space-between; border: 1px solid rgba(0,0,0,0.06); padding: 14px 14px; <?= $hasCustomBack ? 'background: url(\'../assets/uploads/templates/' . htmlspecialchars($config['bg_back']) . '\') center/cover no-repeat;' : '' ?>">
                        
                        <?php if (!$hasCustomBack): ?>
                            <!-- TOP MINI WAVE -->
                            <div style="position: absolute; top: 0; left: 0; right: 0; height: 24px; background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>); border-radius: 0 0 50% 50% / 0 0 80% 80%;"></div>
                        <?php endif; ?>

                        <div style="margin-top: 14px; flex: 1;">
                            <div style="font-size: 10px; font-weight: 800; color: #0f172a; text-transform: uppercase; margin-bottom: 6px; border-bottom: 1.5px solid #e2e8f0; padding-bottom: 4px;">
                                Tata Tertib Ujian:
                            </div>

                            <ul style="font-size: 8px; color: #475569; line-height: 1.35; padding-left: 12px; margin-bottom: 12px;">
                                <?php foreach (array_slice($config['rules'] ?? [], 0, 4) as $r): ?>
                                    <li style="margin-bottom: 4px;"><?= htmlspecialchars($r) ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <div style="font-size: 8px; color: #64748b; line-height: 1.3; margin-bottom: 10px; background: #f8fafc; padding: 6px 8px; border-radius: 6px;">
                                <div><strong>Kontak Lembaga:</strong></div>
                                <div><?= htmlspecialchars($config['contact_phone'] ?: '+62 812-3456-7890') ?></div>
                                <div><?= htmlspecialchars($config['contact_email'] ?: 'cbt@sekolah.sch.id') ?></div>
                            </div>

                            <div style="font-size: 8px; color: #475569; display: flex; justify-content: space-between; margin-bottom: 12px;">
                                <span>Tahun Ajaran:</span>
                                <strong><?= htmlspecialchars($config['academic_year'] ?? '2026/2027') ?></strong>
                            </div>

                            <div style="border-top: 1px dashed #cbd5e1; padding-top: 8px; text-align: center; margin-top: 6px;">
                                <div style="font-size: 7.5px; color: #64748b; margin-bottom: 16px;">
                                    Mengetahui, <?= htmlspecialchars($config['signer_title'] ?? 'Panitia Ujian') ?>
                                </div>
                                <div style="font-size: 8px; font-weight: 700; color: #0f172a; text-decoration: underline;">
                                    <?= htmlspecialchars($config['signer_name'] ?? 'Proktor / Panitia') ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$hasCustomBack): ?>
                            <!-- BOTTOM MINI WAVE -->
                            <div style="height: 18px; background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>); border-radius: 12px 12px 0 0; display: flex; align-items: center; justify-content: center; font-size: 7px; color: #fff; font-weight: 700;">
                                VEYRA CBT PLATFORM
                            </div>
                        <?php endif; ?>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; color: #475569; margin-top: 8px;">Tampak Belakang</span>
                </div>

            </div>

            <!-- Upload Status Bar -->
            <div style="margin-top: 18px; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 12px; color: #475569; display: flex; align-items: center; justify-content: space-between;">
                <div>
                    Status Background: 
                    <?php if ($hasCustomFront || $hasCustomBack): ?>
                        <strong style="color: #10b981;">Gambar Kustom Terpasang</strong>
                    <?php else: ?>
                        <strong style="color: #6366f1;">Vektor Ungu Otomatis (Default Gambar)</strong>
                    <?php endif; ?>
                </div>

                <?php if ($hasCustomFront || $hasCustomBack): ?>
                    <form method="POST" onsubmit="return confirm('Kembalikan ke template default ungu bawaan sistem?')">
                        <?= Helper::csrfField() ?>
                        <input type="hidden" name="action" value="reset_bg">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size: 11px; color: #ef4444; border-color: rgba(239,68,68,0.3);">
                            Reset ke Bawaan
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: SETTINGS FORM -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 24px; padding: 26px; box-shadow: 0 10px 30px rgba(0,0,0,0.03);">
            <div style="margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;">
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a;">Kustomisasi Teks &amp; Warna</h3>
                <p style="font-size: 12px; color: #64748b;">Perubahan ini otomatis tercetak pada seluruh kartu ujian sekolah mitra.</p>
            </div>

            <form method="POST">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="save_settings">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Warna Utama (Dominan Wave)</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="color" name="primary_color" value="<?= htmlspecialchars($primaryColor) ?>" style="width: 40px; height: 38px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; padding: 2px;">
                            <input type="text" class="form-control" value="<?= htmlspecialchars($primaryColor) ?>" style="font-family: monospace; font-size: 13px;" readonly>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Warna Gradien / Aksen</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="color" name="secondary_color" value="<?= htmlspecialchars($secondaryColor) ?>" style="width: 40px; height: 38px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; padding: 2px;">
                            <input type="text" class="form-control" value="<?= htmlspecialchars($secondaryColor) ?>" style="font-family: monospace; font-size: 13px;" readonly>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px; font-weight: 700;">Judul Utama Kartu</label>
                    <input type="text" name="card_title" class="form-control" value="<?= htmlspecialchars($config['card_title'] ?? 'KARTU PESERTA UJIAN') ?>" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Tahun Pelajaran</label>
                        <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($config['academic_year'] ?? '2026 / 2027') ?>" placeholder="2026 / 2027">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Jabatan Penandatangan</label>
                        <input type="text" name="signer_title" class="form-control" value="<?= htmlspecialchars($config['signer_title'] ?? 'Panitia Ujian') ?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Nama Penandatangan / Proktor</label>
                        <input type="text" name="signer_name" class="form-control" value="<?= htmlspecialchars($config['signer_name'] ?? 'Proktor / Panitia Pelaksana') ?>">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 12px; font-weight: 700;">Kontak Layanan Sekolah</label>
                        <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($config['contact_phone'] ?? '+62 812-3456-7890') ?>">
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label class="form-label" style="font-size: 12px; font-weight: 700;">Tata Tertib Ujian (Sisi Belakang)</label>
                    <textarea name="rules_text" class="form-control" rows="4" style="font-size: 12px; line-height: 1.5;" placeholder="Tuliskan 1 baris per butir tata tertib..."><?= htmlspecialchars($rulesText) ?></textarea>
                    <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">Tiap baris baru akan otomatis menjadi 1 butir poin di kartu sisi belakang.</small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; background: #0f172a; padding: 12px; font-size: 14px; font-weight: 700;">
                    Simpan Perubahan Template
                </button>
            </form>
        </div>

    </div>

</div>

<!-- MODAL UPLOAD GAMBAR TEMPLATE KUSTOM -->
<div class="modal" id="uploadBgModal">
    <div class="modal-dialog" style="max-width: 540px;">
        <form method="POST" enctype="multipart/form-data" class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="upload_bg">

            <div class="modal-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 18px 24px;">
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a;">Upload Template Desain Kustom</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>

            <div class="modal-body" style="padding: 24px;">
                <div style="background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 12px; padding: 12px 16px; font-size: 12.5px; color: #3730a3; margin-bottom: 20px; line-height: 1.5;">
                    💡 <strong>Tips Ukuran Gambar:</strong> Unggah gambar background berorientasi potret (Rekomendasi rasio CR80 / B1: <strong>638 &times; 1016 pixel</strong> atau 54 &times; 86 mm, format PNG / JPG / WEBP).
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label class="form-label" style="font-weight: 700; font-size: 13px;">Background Sisi Depan (Front Card)</label>
                    <input type="file" name="bg_front" class="form-control" accept="image/png, image/jpeg, image/webp">
                    <small style="color: #64748b; font-size: 11.5px; margin-top: 4px; display: block;">Jika dikosongkan, akan tetap memakai template bawaan ungu wave.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; font-size: 13px;">Background Sisi Belakang (Back Card)</label>
                    <input type="file" name="bg_back" class="form-control" accept="image/png, image/jpeg, image/webp">
                    <small style="color: #64748b; font-size: 11.5px; margin-top: 4px; display: block;">Opsional. Jika diunggah, tata tertib dicetak di atas gambar ini.</small>
                </div>
            </div>

            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between;">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #4f46e5; border: none; font-weight: 700;">
                    Unggah &amp; Terapkan
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
