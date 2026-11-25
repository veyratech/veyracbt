<?php
/**
 * Kelola Jenis Ujian per Tenant
 * Multi-Tenant CBT Online
 */

$pageTitle = "Jenis Ujian";
require_once __DIR__ . '/_bootstrap.php';

// ── AUTO MIGRATION (pastikan tabel ada) ──────────────────────────────────────
try {
    $exists = Database::fetch("SHOW TABLES LIKE 'exam_types'");
    if (!$exists) {
        Database::query("CREATE TABLE exam_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(60) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tenant_slug (tenant_id, slug),
            KEY idx_exam_types_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {}

// ── POST ACTIONS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $name   = trim($_POST['name'] ?? '');
            $slug   = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)));
            $slug   = trim($slug, '-');
            if (empty($name)) {
                throw new RuntimeException('Nama jenis ujian wajib diisi.');
            }
            if (strlen($slug) < 2) {
                throw new RuntimeException('Slug terlalu pendek.');
            }
            $existing = Database::fetch(
                "SELECT id FROM exam_types WHERE tenant_id = ? AND slug = ?",
                [$tenantId, $slug]
            );
            if ($existing) {
                throw new RuntimeException("Slug '{$slug}' sudah digunakan. Gunakan nama yang berbeda.");
            }
            $maxOrder = (int)Database::fetchColumn(
                "SELECT COALESCE(MAX(sort_order), -1) FROM exam_types WHERE tenant_id = ?",
                [$tenantId]
            );
            Database::insert('exam_types', [
                'tenant_id'  => $tenantId,
                'name'       => $name,
                'slug'       => $slug,
                'sort_order' => $maxOrder + 1
            ]);
            Helper::setFlash('success', "Jenis ujian '{$name}' berhasil ditambahkan.");

        } elseif ($action === 'update') {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = trim($_POST['name'] ?? '');
            if ($id <= 0) throw new RuntimeException('ID tidak valid.');
            if (empty($name)) throw new RuntimeException('Nama wajib diisi.');
            Database::update('exam_types', ['name' => $name], 'id = ? AND tenant_id = ?',
                [$id, $tenantId]);
            Helper::setFlash('success', "Jenis ujian berhasil diperbarui.");

        } elseif ($action === 'delete') {
            $id   = (int)($_POST['id'] ?? 0);
            $banck = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM question_banks WHERE tenant_id = ? AND exam_type IN (SELECT slug FROM exam_types WHERE id = ?)",
                [$tenantId, $id]
            );
            if ($banck > 0) {
                throw new RuntimeException("Jenis ujian ini masih dipakai oleh {$banck} bank soal. Ubah terlebih dahulu di menu Bank Soal.");
            }
            Database::delete('exam_types', 'id = ? AND tenant_id = ?',
                [$id, $tenantId]);
            Helper::setFlash('success', 'Jenis ujian berhasil dihapus.');

        } elseif ($action === 'move_up' || $action === 'move_down') {
            $id = (int)($_POST['id'] ?? 0);
            $cur = (int)Database::fetchColumn(
                "SELECT sort_order FROM exam_types WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            if ($cur !== false && $cur > 0) {
                $dir = $action === 'move_up' ? -1 : 1;
                $swap = (int)Database::fetchColumn(
                    "SELECT id FROM exam_types WHERE tenant_id = ? AND sort_order = ? ORDER BY id ASC LIMIT 1",
                    [$tenantId, $cur + $dir]
                );
                if ($swap) {
                    Database::update('exam_types', ['sort_order' => $cur + $dir], 'id = ? AND tenant_id = ?',
                        [$id, $tenantId]);
                    Database::update('exam_types', ['sort_order' => $cur], 'id = ? AND tenant_id = ?',
                        [$swap, $tenantId]);
                }
            }
        }
    } catch (Throwable $e) {
        Helper::setFlash('danger', 'Gagal: ' . $e->getMessage());
    }
    Helper::redirect('admin/exam-types.php');
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$examTypes = Database::fetchAll(
    "SELECT * FROM exam_types WHERE tenant_id = ? ORDER BY sort_order ASC",
    [$tenantId]
);

// Data untuk modal edit
$editType = null;
if (isset($_GET['edit'])) {
    $editType = Database::fetch(
        "SELECT * FROM exam_types WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 class="card-title" style="margin: 0;">Kelola Jenis Ujian</h3>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0 0 0;">
                Tambah atau ubah jenis ujian yang tersedia di Bank Soal dan pembuatan Ujian
            </p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary btn-sm" onclick="toggleModalAddType(true);">
                + Tambah Jenis Ujian
            </button>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">

        <?php if (empty($examTypes)): ?>
            <div style="padding: 48px 20px; text-align: center; color: var(--text-muted);">
                <div style="margin-bottom: 12px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 4px;">Belum Ada Jenis Ujian</div>
                <div style="font-size: 12.5px; margin-bottom: 18px;">Tambahkan jenis ujian agar bisa dipilih saat membuat atau mengimpor bank soal.</div>
                <button type="button" class="btn btn-primary btn-sm" onclick="toggleModalAddType(true);">+ Tambah Jenis Ujian</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">No</th>
                            <th>Nama Jenis Ujian</th>
                            <th>Slug</th>
                            <th style="width: 100px; text-align: center;">Urutan</th>
                            <th style="width: 140px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examTypes as $idx => $et): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td><b><?= htmlspecialchars($et['name']) ?></b></td>
                                <td><code style="font-size: 12px;"><?= htmlspecialchars($et['slug']) ?></code></td>
                                <td style="text-align: center;">
                                    <form method="POST" style="display: inline-flex; gap: 4px;">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="move_up">
                                        <input type="hidden" name="id" value="<?= $et['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm <?= $idx === 0 ? 'disabled' : '' ?>"
                                            title="<?= $idx === 0 ? 'Sudah paling atas' : 'Pindahkan ke atas' ?>"
                                            <?= $idx === 0 ? 'disabled' : '' ?>>↑</button>
                                    </form>
                                    <form method="POST" style="display: inline-flex; gap: 4px;">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="move_down">
                                        <input type="hidden" name="id" value="<?= $et['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm <?= $idx === count($examTypes) - 1 ? 'disabled' : '' ?>"
                                            title="<?= $idx === count($examTypes) - 1 ? 'Sudah paling bawah' : 'Pindahkan ke bawah' ?>"
                                            <?= $idx === count($examTypes) - 1 ? 'disabled' : '' ?>>↓</button>
                                    </form>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="exam-types.php?edit=<?= $et['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" style="display: inline;"
                                         onsubmit="return confirm('Hapus jenis ujian \'<?= htmlspecialchars(addslashes($et['name'])) ?>\'?')">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $et['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT -->
<div id="modalAddType" class="modal-backdrop" style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;padding:20px;" role="dialog" aria-labelledby="modalAddTypeLabel">
    <div class="modal-dialog" role="document">
        <form method="POST" action="./exam-types.php">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editType ? 'update' : 'create' ?>">
            <?php if ($editType): ?>
                <input type="hidden" name="id" value="<?= $editType['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 id="modalAddTypeLabel" style="font-size: 16px; font-weight: 700;">
                    <?= $editType ? 'Edit Jenis Ujian' : 'Tambah Jenis Ujian Baru' ?>
                </h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalAddType').style.display='none'">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Jenis Ujian</label>
                    <input type="text" name="name" class="form-control"
                        placeholder="Contoh: Ujian Harian, Kuis Online, dll."
                        value="<?= htmlspecialchars($editType['name'] ?? '') ?>" required autofocus>
                    <?php if (!$editType): ?>
                        <small style="color: var(--text-muted); font-size: 11.5px; margin-top: 4px; display: block;">
                            Slug akan dibuat otomatis dari nama.
                        </small>
                    <?php else: ?>
                        <small style="color: var(--text-muted); font-size: 11.5px; margin-top: 4px; display: block;">
                            <code><?= htmlspecialchars($editType['slug']) ?></code> — jangan ubah slug setelah dipakai bank soal.
                        </small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modalAddType').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <?= $editType ? 'Simpan Perubahan' : 'Simpan' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
<style>
.modal-show { display: flex !important; }
.btn.disabled, button[disabled] {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
}
</style>
<script>
// Unified modal control — semua tombol pakai fungsi ini
window.toggleModalAddType = function(show) {
    var m = document.getElementById('modalAddType');
    if (!m) return;
    m.style.display = show ? 'flex' : 'none';
};

(function () {
    var m = document.getElementById('modalAddType');
    if (!m) return;

    // Auto-buka kalau URL punya ?edit=X
    if (window.location.search.indexOf('edit=') !== -1) {
        m.style.display = 'flex';
    }

    // Klik backdrop (area gelap) → tutup modal
    m.addEventListener('click', function (e) {
        if (e.target === m) m.style.display = 'none';
    });

    // Escape key → tutup modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && m.style.display !== 'none') m.style.display = 'none';
    });
})();
</script>
