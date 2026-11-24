<?php
/**
 * Manajemen Backup & Cadangan Database — Superadmin
 */

$pageTitle = "Backup & Cadangan Database";
require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::getConnection();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// RESTORE BACKUP
// ============================================================
$isRestore = (isset($_GET['restore']) && $_GET['restore'] === '1')
    || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'restore');

if ($isRestore) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Helper::verifyCsrf();
    }

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        Helper::setFlash('danger', 'File backup tidak valid atau kosong.');
        Helper::redirect('backup.php');
    }

    $file = $_FILES['backup_file'];
    $maxSize = 50 * 1024 * 1024; // 50 MB
    if ($file['size'] > $maxSize) {
        Helper::setFlash('danger', 'Ukuran file maksimal 50 MB.');
        Helper::redirect('backup.php');
    }

    $tmpPath = $file['tmp_name'];
    $content = file_get_contents($tmpPath);

    if ($content === false || strlen($content) < 1000) {
        Helper::setFlash('danger', 'File backup tidak valid atau rusak.');
        Helper::redirect('backup.php');
    }

    // Validasi awal: cek apakah isi file terlihat seperti SQL
    if (!preg_match('/^(--|SET|CREATE|DROP|INSERT)/m', $content)) {
        Helper::setFlash('danger', 'File bukan format SQL yang valid.');
        Helper::redirect('backup.php');
    }

    set_time_limit(120);
    $rowCount = 0;
    $stmt = $pdo->prepare("");
    $errors = [];

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        // Pecah file per statement
        $statements = preg_split("/;\s*\n/", $content);
        foreach ($statements as $stmtSql) {
            $stmtSql = trim($stmtSql);
            if (strlen($stmtSql) < 5 || preg_match('/^--/', $stmtSql)) continue;
            if (stripos($stmtSql, 'CREATE TABLE') !== false) continue;
            if (stripos($stmtSql, 'DROP TABLE') !== false) continue;
            if (stripos($stmtSql, 'SET ') === 0) continue;

            try {
                $pdo->exec($stmtSql . ";");
                if (stripos($stmtSql, 'INSERT INTO') === 0) {
                    $rowCount += $pdo->exec("SELECT ROW_COUNT()") ?: 0;
                }
            } catch (PDOException $e) {
                $errors[] = substr($e->getMessage(), 0, 120);
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Catat log restore
        Database::insert('activity_logs', [
            'tenant_id'  => 0,
            'session_id' => null,
            'event_type' => 'database_restore',
            'message'    => "Superadmin (" . ($user['username'] ?? 'admin') . ") memulihkan database dari file backup. Rows: {$rowCount}, Errors: " . count($errors),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!empty($errors)) {
            Helper::setFlash('warning', "Restore selesai dengan " . count($errors) . " error (lanjutkan). " . implode('; ', array_slice($errors, 0, 3)));
        } else {
            Helper::setFlash('success', "Database berhasil dipulihkan! {$rowCount} baris data dimuat.");
        }
        Helper::redirect('backup.php');

    } catch (Exception $e) {
        Helper::setFlash('danger', 'Restore gagal: ' . $e->getMessage());
        Helper::redirect('backup.php');
    }
}

// Jika aksi unduh dipicu (?download=1 atau POST action=download)
$isDownload = (isset($_GET['download']) && $_GET['download'] === '1')
    || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'download');

if ($isDownload) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Helper::verifyCsrf();
    }

    @set_time_limit(0);
    $filename = 'backup-cbt-' . date('Ymd-His') . '.sql';

    // Catat aktivitas unduh backup oleh Superadmin
    try {
        $firstTenantId = (int)Database::fetchColumn("SELECT id FROM tenants ORDER BY id ASC LIMIT 1");
        if ($firstTenantId > 0) {
            Database::insert('activity_logs', [
                'tenant_id'  => $firstTenantId,
                'session_id' => null,
                'event_type' => 'database_backup',
                'message'    => 'Superadmin (' . ($user['username'] ?? 'admin') . ') mengunduh backup database (' . $filename . ')',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('Backup audit log error: ' . $e->getMessage());
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Cache-Control: no-store');

    echo "-- Backup Database CBT Online Multi-Tenant\n";
    echo "-- Diproduksi pada: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Total tabel: " . count($tables) . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `$t`;\n";
        echo $create[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$t`");
        $buffer = [];
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
            }
            $buffer[] = '(' . implode(', ', $vals) . ')';
            if (count($buffer) >= 200) {
                echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $buffer) . ";\n";
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $buffer) . ";\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

// Ambil metadata ukuran & jumlah baris tiap tabel untuk halaman UI
$tableStats = [];
$totalRowsAll = 0;
$totalSizeBytes = 0;

try {
    $statusRows = $pdo->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statusRows as $sr) {
        $tName = $sr['Name'] ?? '';
        $rowCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM `$tName`");
        $sizeB = (int)($sr['Data_length'] ?? 0) + (int)($sr['Index_length'] ?? 0);
        $totalRowsAll += $rowCount;
        $totalSizeBytes += $sizeB;
        $tableStats[] = [
            'name'       => $tName,
            'engine'     => $sr['Engine'] ?? 'InnoDB',
            'rows'       => $rowCount,
            'size_bytes' => $sizeB,
            'collation'  => $sr['Collation'] ?? 'utf8mb4_general_ci',
            'updated_at' => $sr['Update_time'] ?: ($sr['Create_time'] ?? '-')
        ];
    }
} catch (\Throwable $e) {
    foreach ($tables as $tName) {
        $rowCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM `$tName`");
        $totalRowsAll += $rowCount;
        $tableStats[] = [
            'name'       => $tName,
            'engine'     => 'InnoDB',
            'rows'       => $rowCount,
            'size_bytes' => 0,
            'collation'  => 'utf8mb4',
            'updated_at' => '-'
        ];
    }
}

$backupHistory = Database::fetchAll(
    "SELECT id, message, ip_address, created_at
     FROM activity_logs
     WHERE event_type = 'database_backup'
     ORDER BY id DESC
     LIMIT 8"
);

function formatBytesSize(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

require_once __DIR__ . '/header.php';
?>

<!-- HERO / HEADER ACTION -->
<div class="card" style="background: linear-gradient(135deg, #064e3b 0%, #047857 100%); color: #ffffff; border: none; margin-bottom: 22px;">
    <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; padding: 26px 28px;">
        <div style="max-width: 640px;">
            <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.14); padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; margin-bottom: 10px; letter-spacing: 0.4px; text-transform: uppercase;">
                <span>&#128190; Pusat Pencadangan Sistem</span>
            </div>
            <h2 style="font-size: 21px; font-weight: 800; color: #ffffff; margin: 0 0 6px 0; letter-spacing: -0.3px;">
                Backup &amp; Snapshot Database CBT Multi-Tenant
            </h2>
            <p style="font-size: 13px; color: rgba(255,255,255,0.85); margin: 0; line-height: 1.55;">
                Unduh salinan lengkap seluruh struktur tabel (<code>CREATE TABLE</code>) dan isi data (<code>INSERT INTO</code>) seluruh sekolah/tenant dalam format SQL standar yang siap dipulihkan kapan saja.
            </p>
        </div>
        <form method="POST" action="backup.php" style="margin: 0;">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="download">
            <button type="submit" class="btn" style="background: #ffffff; color: #064e3b; border: none; padding: 12px 22px; border-radius: 12px; font-size: 13.5px; font-weight: 800; box-shadow: 0 10px 25px rgba(0,0,0,0.18); display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <span>Unduh File Backup (.sql)</span>
            </button>
        </form>
    </div>
</div>

<!-- 4 RINGKASAN KPI DATABASE -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 22px;">
    <div class="card" style="margin-bottom: 0;">
        <div class="card-body" style="padding: 18px 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Total Tabel Aktif</div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a;"><?= number_format(count($tableStats)) ?> Tabel</div>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 12px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                &#128451;
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div class="card-body" style="padding: 18px 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Total Rekaman Data</div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a;"><?= number_format($totalRowsAll, 0, ',', '.') ?> Baris</div>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                &#128202;
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div class="card-body" style="padding: 18px 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Estimasi Ukuran DB</div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a;"><?= formatBytesSize($totalSizeBytes) ?></div>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 12px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                &#128190;
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div class="card-body" style="padding: 18px 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Backup Terakhir</div>
                <div style="font-size: 15px; font-weight: 800; color: #0f172a;">
                    <?= !empty($backupHistory) ? date('d M Y, H:i', strtotime($backupHistory[0]['created_at'])) : 'Belum Pernah' ?>
                </div>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 12px; background: #fffbeb; color: #d97706; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                &#128338;
            </div>
        </div>
    </div>
</div>

<!-- GRID 2 KOLOM: STRUKTUR TABEL & RIWAYAT BACKUP -->
<div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; align-items: start;">
    <!-- Kolom Kiri: Daftar Tabel Database -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Rincian Tabel Database</h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Seluruh tabel berikut akan disertakan dalam file ekspor SQL</div>
            </div>
            <span class="badge badge-success">Siap Dicadangkan</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Tabel</th>
                            <th>Engine</th>
                            <th>Jumlah Baris</th>
                            <th style="text-align: right;">Ukuran</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableStats as $idx => $ts): ?>
                            <tr>
                                <td style="color: #64748b; font-weight: 700;"><?= $idx + 1 ?></td>
                                <td>
                                    <code style="font-weight: 700; color: #0f172a; background: #f1f5f9; padding: 3px 8px; border-radius: 6px; font-size: 12px;">
                                        <?= htmlspecialchars($ts['name']) ?>
                                    </code>
                                </td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars($ts['engine']) ?></span></td>
                                <td style="font-weight: 700; color: #1e293b;"><?= number_format($ts['rows'], 0, ',', '.') ?> baris</td>
                                <td style="text-align: right; color: #64748b; font-weight: 600;"><?= formatBytesSize($ts['size_bytes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Riwayat Unduh Backup -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Riwayat Pencadangan</h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Log aktivitas pengunduhan backup oleh Superadmin</div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($backupHistory)): ?>
                <div style="text-align: center; padding: 28px 12px; color: #94a3b8;">
                    <div style="font-size: 28px; margin-bottom: 8px;">&#128190;</div>
                    <div style="font-weight: 700; color: #334155; font-size: 13.5px;">Belum Ada Riwayat Backup</div>
                    <div style="font-size: 12px; margin-top: 4px;">Klik tombol <b>Unduh File Backup (.sql)</b> di atas untuk membuat cadangan pertama Anda.</div>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($backupHistory as $bh): ?>
                        <div style="padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span class="badge badge-primary" style="font-size: 10.5px;">SQL Snapshot</span>
                                <span style="font-size: 11px; color: #64748b; font-weight: 600;">
                                    <?= date('d M Y, H:i', strtotime($bh['created_at'])) ?>
                                </span>
                            </div>
                            <div style="font-size: 12.5px; color: #1e293b; font-weight: 600; line-height: 1.45;">
                                <?= htmlspecialchars($bh['message']) ?>
                            </div>
                            <?php if (!empty($bh['ip_address'])): ?>
                                <div style="font-size: 11px; color: #94a3b8; margin-top: 4px;">
                                    IP: <?= htmlspecialchars($bh['ip_address']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                <a href="logs-cleanup.php" class="btn btn-outline btn-sm">Bersihkan Log Lama</a>
                <a href="settings.php#maintenance" class="btn btn-outline btn-sm">Pengaturan Sistem &rarr;</a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     RESTORE BACKUP SECTION
     ============================================ -->
<div class="card" style="margin-top: 22px; border: 1px solid #fee2e2;">
    <div class="card-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca; padding: 16px 20px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 18px;">&#9888;</span>
            <h3 class="card-title" style="color: #991b1b; margin: 0;">Pulihkan Database dari Backup</h3>
        </div>
        <p style="font-size: 12px; color: #b91c1c; margin: 6px 0 0 28px; font-weight: 600;">
            Peringatan: Proses ini akan menimpa semua data saat ini. Pastikan Anda memiliki backup sebelum melanjutkan.
        </p>
    </div>
    <div class="card-body" style="padding: 24px;">
        <form method="POST" action="backup.php?restore=1" enctype="multipart/form-data" id="restoreForm">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="restore">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 6px;">
                        Pilih File Backup (.sql)
                    </label>
                    <div style="border: 2px dashed #d1d5db; border-radius: 12px; padding: 24px; text-align: center; transition: border-color 0.2s;"
                         onmouseover="this.style.borderColor='#ef4444'"
                         onmouseout="this.style.borderColor='#d1d5db'">
                        <input type="file" name="backup_file" accept=".sql,text/sql" required
                               style="width: 100%; font-size: 13px; color: #374151;"
                               onchange="document.getElementById('restoreFileName').textContent = this.files[0]?.name || 'Belum memilih file'">
                        <div id="restoreFileName" style="margin-top: 10px; font-size: 12px; color: #6b7280; font-weight: 600;">
                            Belum memilih file
                        </div>
                        <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">Maksimal 50 MB · Format: .sql</div>
                    </div>
                </div>

                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 16px; font-size: 12.5px; color: #92400e; line-height: 1.7;">
                    <strong style="display: block; margin-bottom: 8px; font-size: 13px;">&#128203; Panduan Restore:</strong>
                    <ol style="margin: 0; padding-left: 18px;">
                        <li>Unduh backup terlebih dahulu jika belum ada.</li>
                        <li>Pilih file <code>.sql</code> yang ingin dipulihkan.</li>
                        <li>Klik <strong>"Proses Restore"</strong> untuk memulai.</li>
                        <li>Tunggu hingga muncul notifikasi berhasil/gagal.</li>
                    </ol>
                    <div style="margin-top: 12px; padding: 8px 12px; background: rgba(239,68,68,0.08); border-radius: 8px; font-size: 11.5px;">
                        &#9888; Data yang sudah ada akan <strong>ditimpa</strong>. Tidak dapat dibatalkan.
                    </div>
                </div>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: none; padding: 11px 24px; border-radius: 10px; font-size: 13.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
                    Proses Restore Database
                </button>
                <span style="font-size: 12px; color: #9ca3af;">Aksi ini memerlukan konfirmasi dan akan memuat ulang halaman.</span>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
