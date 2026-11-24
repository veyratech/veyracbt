<?php
/**
 * Log Cleanup — Superadmin only
 * Hapus log activity_logs yang lebih tua dari periode yang dipilih.
 */

$pageTitle = "Bersihkan Log Lama";
require_once __DIR__ . '/_bootstrap.php';

$days = 0;
$message = '';
$messageType = 'info';
$countBefore = 0;
$execute = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $days = (int)($_POST['retention_days'] ?? 0);
    $execute = isset($_POST['execute']);

    if ($days > 0) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        if ($execute) {
            $countDeleted = Database::delete('activity_logs', 'created_at < :cutoff', ['cutoff' => $cutoffDate]);
            $message = "Berhasil menghapus {$countDeleted} log lama (sebelum {$cutoffDate}).";
            $messageType = 'success';
            Helper::setFlash($messageType, $message);
            Helper::redirect('superadmin/logs-cleanup.php');
        } else {
            $countBefore = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM activity_logs WHERE created_at < ?",
                [$cutoffDate]
            );
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="card" style="max-width: 680px; margin: 10px auto;">
    <div class="card-header">
        <div>
            <h3 class="card-title">&#128465; Bersihkan Log Aktivitas Lama</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">Pemeliharaan database audit trail dan penghapusan riwayat aktivitas usang</p>
        </div>
    </div>
    <div class="card-body">
        <p style="color: var(--text-secondary); font-size: 13.5px; margin-bottom: 20px; line-height: 1.6;">
            Hapus entri <code>activity_logs</code> yang dibuat lebih dari X hari yang lalu untuk menghemat kapasitas storage database server.
            Tindakan ini permanen dan tidak dapat dibatalkan.
        </p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($countBefore > 0): ?>
            <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 16px; padding: 18px 22px; margin-bottom: 22px;">
                <div style="font-size: 12px; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Log yang Teridentifikasi untuk Dihapus:</div>
                <div style="font-size: 32px; font-weight: 800; color: #dc2626;"><?= number_format($countBefore) ?> Entri</div>
                <div style="font-size: 12px; color: #7f1d1d; margin-top: 4px;">Tercatat sebelum tanggal <?= date('d F Y', strtotime("-{$days} days")) ?></div>
            </div>
        <?php elseif ($days > 0 && !$execute): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px 20px; margin-bottom: 20px; color: var(--text-muted); font-size: 13px;">
                Tidak ada log yang melebihi batas waktu <?= $days ?> hari tersebut.
            </div>
        <?php endif; ?>

        <form method="POST" id="cleanupForm">
            <?= Helper::csrfField() ?>
            <div class="form-group">
                <label class="form-label" style="font-size: 13px; font-weight: 700;">Pilih Periode Retensi Log:</label>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                    <?php
                    $options = [30, 60, 90, 180];
                    foreach ($options as $opt):
                        $checked = ($days === $opt) ? 'checked' : '';
                    ?>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 9999px; transition: all 0.2s ease; background: #ffffff;"
                               onclick="document.getElementById('days<?= $opt ?>').checked = true"
                               class="retention-label <?= $checked ? 'active' : '' ?>"
                               data-days="<?= $opt ?>">
                            <input type="radio" name="retention_days" value="<?= $opt ?>" id="days<?= $opt ?>" <?= $checked ?> style="display: none;">
                            <span style="font-size: 13px; font-weight: 700; color: #334155;"><?= $opt ?> Hari</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="retention_days" id="retentionDaysHidden" value="<?= $days ?>">
            </div>

            <div style="display: flex; gap: 12px; margin-top: 28px; padding-top: 20px; border-top: 1px solid #f1f5f9;">
                <button type="submit" name="execute" value="1" class="btn btn-danger" style="border-radius: 9999px; padding: 9px 24px; font-weight: 700;" onclick="return confirm('PERINGATAN: Yakin ingin menghapus seluruh log lama sesuai periode terpilih? Tindakan ini tidak dapat dibatalkan.')">
                    &#128465; Hapus Log Sekarang
                </button>
                <a href="logs.php" class="btn btn-outline" style="border-radius: 9999px; padding: 9px 20px; font-weight: 600;">Kembali ke Log</a>
                <a href="settings.php#maintenance" class="btn btn-outline" style="border-radius: 9999px; padding: 9px 20px; font-weight: 600; margin-left: auto;">Buka Pusat Pengaturan Sistem &rarr;</a>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var labels = document.querySelectorAll('.retention-label');
    var hidden = document.getElementById('retentionDaysHidden');
    labels.forEach(function(lbl) {
        lbl.addEventListener('click', function() {
            labels.forEach(function(l) { l.classList.remove('active'); });
            lbl.classList.add('active');
            hidden.value = lbl.getAttribute('data-days');
        });
    });
    if (hidden.value) {
        labels.forEach(function(l) {
            if (l.getAttribute('data-days') === hidden.value) l.classList.add('active');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
