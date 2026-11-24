<?php
/**
 * Master Data Kelas per Tenant
 */

$pageTitle = "Data Kelas & Rombel";
require_once __DIR__ . '/_bootstrap.php';

// 1. Download Template Excel / CSV
if (isset($_GET['template'])) {
    $tplHeader = ['nama_kelas', 'tingkat'];
    $tplRows = [
        ['X MIPA 1', '10'],
        ['X MIPA 2', '10'],
        ['XI IPS 1', '11'],
        ['XII IPA 1', '12'],
        ['XII TKJ 1', '12']
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-data-kelas.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-data-kelas.xlsx', $tplHeader, $tplRows);
    }
}

// 2. Ekspor Data Kelas
if (isset($_GET['export'])) {
    $allCls = Database::fetchAll(
        "SELECT c.name, c.level, COUNT(s.id) as total_students, c.created_at 
         FROM classes c 
         LEFT JOIN students s ON s.class_id = c.id 
         WHERE c.tenant_id = ? 
         GROUP BY c.id ORDER BY c.name ASC",
        [$tenantId]
    );
    $expHeader = ['nama_kelas', 'tingkat', 'jumlah_siswa', 'tanggal_buat'];
    $expRows = array_map(fn($c) => [$c['name'], $c['level'] ?: '-', $c['total_students'], $c['created_at']], $allCls);
    if ($_GET['export'] === 'csv') {
        Helper::downloadCsv('data-kelas-' . $school['code'] . '.csv', $expHeader, $expRows);
    } else {
        Helper::downloadXlsx('data-kelas-' . $school['code'] . '.xlsx', $expHeader, $expRows);
    }
}

// Handle Tambah / Edit / Hapus Kelas / Impor Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['_csrf'] ?? '';
    $action    = $_POST['action'] ?? '(no-action)';
    $sessHas   = isset($_SESSION['csrf_token']) ? 'YES' : 'NO';
    $tokenOk   = ($sessHas === 'YES' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken));
    error_log('[classes] POST action=' . $action . ' sess_csrf=' . $sessHas . ' token_ok=' . ($tokenOk ? '1' : '0') . ' n_fields=' . count($_POST));
    try {
        Helper::verifyCsrf();
        $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $name = trim($_POST['name'] ?? '');
            $level = trim($_POST['level'] ?? '');
            if (empty($name)) {
                Helper::setFlash('danger', 'Nama kelas wajib diisi.');
            } else {
                Database::insert('classes', [
                    'tenant_id' => $tenantId,
                    'name'      => $name,
                    'level'     => $level
                ]);
                Helper::setFlash('success', "Kelas '{$name}' berhasil ditambahkan.");
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menyimpan data: ' . $e->getMessage());
        }
        Helper::redirect('admin/classes.php?page=1');

    } elseif ($action === 'update') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $level = trim($_POST['level'] ?? '');
            if ($id <= 0) {
                Helper::setFlash('danger', 'ID kelas tidak valid.');
            } elseif (empty($name)) {
                Helper::setFlash('danger', 'Nama kelas wajib diisi.');
            } else {
                Database::update('classes', [
                    'name'  => $name,
                    'level' => $level
                ], 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
                Helper::setFlash('success', "Kelas '{$name}' berhasil diperbarui.");
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal memperbarui data: ' . $e->getMessage());
        }
        Helper::redirect('admin/classes.php?page=1');

    } elseif ($action === 'import_excel') {
        try {
            $upload = $_FILES['file_kelas'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || empty($upload['tmp_name']) || $upload['size'] === 0) {
                throw new RuntimeException('Silakan pilih file Excel (.xlsx) atau CSV terlebih dahulu.');
            }

            [$header, $rows] = Helper::parseUpload($upload);

            if (empty($header) || count($rows) === 0) {
                throw new RuntimeException('File kosong atau tidak bisa dibaca. Pastikan format sesuai template yang tersedia.');
            }

            // Kumpulkan semua mapping alias dulu dari header asli agar indeks tidak bergeser
            $renameMap = [];
            foreach ([
                'tingkatan'  => 'tingkat',
                'jenjang'    => 'tingkat',
                'kelascustom'=> 'nama_kelas'
            ] as $alias => $canon) {
                $ai = array_search($alias, $header, true);
                if ($ai !== false && !in_array($canon, $header, true)) {
                    $renameMap[$alias] = ['idx' => $ai, 'canon' => $canon];
                }
            }
            // Alias yang langsung mengarah ke kolom standar
            foreach (['name' => 'nama_kelas', 'kelas' => 'nama_kelas', 'nama' => 'nama_kelas'] as $alias => $canon) {
                $ai = array_search($alias, $header, true);
                if ($ai !== false && !in_array($canon, $header, true)) {
                    $renameMap[$alias] = ['idx' => $ai, 'canon' => $canon];
                }
            }

            // Terapkan rename sekaligus setelah semua indeks terbaca dari header asli
            foreach ($renameMap as $alias => $info) {
                $header[$info['idx']] = $info['canon'];
                foreach ($rows as &$rrow) {
                    if (!isset($rrow[$info['canon']]) || $rrow[$info['canon']] === '') {
                        $rrow[$info['canon']] = $rrow[$alias] ?? '';
                    }
                    unset($rrow[$alias]);
                }
                unset($rrow);
            }

            if (!in_array('nama_kelas', $header)) {
                throw new RuntimeException("Kolom 'nama_kelas' (atau 'nama'/'kelas') tidak ditemukan di file. Header yang terbaca: " . implode(', ', $header));
            }

            $dupMode = $_POST['dup_mode'] ?? 'skip';
            $inserted = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $r) {
                $name = trim((string)($r['nama_kelas'] ?? ''));
                $level = trim((string)($r['tingkat'] ?? ($r['level'] ?? '')));
                if (empty($name)) continue;

                $existing = Database::fetch("SELECT id FROM classes WHERE tenant_id = ? AND name = ?", [$tenantId, $name]);

                if ($existing) {
                    if ($dupMode === 'update') {
                        Database::update('classes', [
                            'level' => $level
                        ], 'id = :id AND tenant_id = :tenant_id', ['id' => $existing['id'], 'tenant_id' => $tenantId]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    Database::insert('classes', [
                        'tenant_id' => $tenantId,
                        'name'      => $name,
                        'level'     => $level
                    ]);
                    $inserted++;
                }
            }

            $msg = "Impor selesai: <b>{$inserted} kelas</b> berhasil ditambahkan";
            if ($updated > 0) $msg .= ", <b>{$updated} kelas</b> diperbarui";
            if ($skipped > 0) $msg .= ", <b>{$skipped} kelas</b> dilewati (duplikat)";
            Helper::setFlash('success', $msg . '.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Impor gagal: ' . $e->getMessage());
        }
        Helper::redirect('admin/classes.php?page=1');

    } elseif ($action === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            Database::delete('classes', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', 'Kelas berhasil dihapus.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menghapus data: ' . $e->getMessage());
        }
        Helper::redirect('admin/classes.php?page=1');
    }
    } catch (Throwable $e) {
        error_log('[classes] Uncaught POST error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        Helper::setFlash('danger', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        Helper::redirect('admin/classes.php?page=1');
    }
}

// Search + Pagination
$q = trim($_GET['q'] ?? '');
$countParams = ['tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM classes WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND name LIKE :q";
    $countParams['q'] = '%' . $q . '%';
}
$totalClasses = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalClasses, 15);

$listParams = ['tenant_id' => $tenantId];
$listSql = "SELECT c.*, COUNT(s.id) as total_students
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id
     WHERE c.tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND c.name LIKE :q";
    $listParams['q'] = '%' . $q . '%';
}
$listSql .= " GROUP BY c.id ORDER BY c.name ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$classes = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit (jika ada ?edit=ID)
$editClass = null;
if (isset($_GET['edit'])) {
    $editClass = Database::fetch(
        "SELECT * FROM classes WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 class="card-title" style="margin: 0;">Daftar Kelas (<?= $totalClasses ?> Rombel)</h3>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0 0 0;">Kelola rombongan belajar dan impor kelas menggunakan Excel / CSV</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Unduh Template Excel -->
            <a href="classes.php?template=xlsx" class="btn btn-outline btn-sm" title="Unduh format Excel kosong untuk diisi">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Template Excel (.xlsx)
            </a>

            <!-- Impor Excel -->
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportClasses').classList.add('show')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Impor Excel / CSV
            </button>

            <!-- Ekspor Data -->
            <a href="classes.php?export=xlsx" class="btn btn-outline btn-sm" title="Ekspor data kelas ke Excel">
                Ekspor Data
            </a>

            <!-- Tambah Manual -->
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddClass').classList.add('show')">
                + Tambah Kelas Baru
            </button>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0;">
                <div class="search-inline">
                    <input type="text" name="q" class="form-control" placeholder="Cari nama kelas..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="classes.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($classes)): ?>
            <div style="padding: 48px 20px; text-align: center; color: var(--text-muted);">
                <div style="margin-bottom: 12px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
                <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 4px;">Belum Ada Rombel / Kelas Terdaftar</div>
                <div style="font-size: 12.5px; margin-bottom: 18px;">Gunakan template Excel untuk menambahkan banyak kelas sekaligus, atau tambah manual satu per satu.</div>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="classes.php?template=xlsx" class="btn btn-outline btn-sm">Unduh Template Excel</a>
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportClasses').classList.add('show')">Impor File Excel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddClass').classList.add('show')">+ Tambah Kelas</button>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">No</th>
                            <th>Nama Kelas</th>
                            <th>Tingkat / Jenjang</th>
                            <th>Jumlah Siswa</th>
                            <th>Dibuat Pada</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $idx => $c): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td><b><?= htmlspecialchars($c['name']) ?></b></td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars($c['level'] ?: '-') ?></span></td>
                                <td><span class="badge badge-primary"><?= (int)$c['total_students'] ?> Siswa</span></td>
                                <td style="color: var(--text-muted); font-size: 12px;"><?= $c['created_at'] ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="classes.php?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus kelas <?= htmlspecialchars(addslashes($c['name'])) ?>? Siswa di kelas ini tidak akan terhapus namun status kelasnya menjadi kosong.')">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= Helper::paginationLinks($pg, $q !== '' ? ['q' => $q] : []) ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT KELAS -->
<div class="modal-backdrop <?= $editClass ? 'show' : '' ?>" id="modalAddClass">
    <div class="modal-dialog">
        <form method="POST" action="./classes.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editClass ? 'update' : 'create' ?>">
            <?php if ($editClass): ?>
                <input type="hidden" name="id" value="<?= $editClass['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;"><?= $editClass ? 'Edit Rombel / Kelas' : 'Tambah Rombel / Kelas' ?></h3>
                <?php if ($editClass): ?>
                    <a href="classes.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kelas</label>
                    <input type="text" name="name" class="form-control" placeholder="Contoh: X MIPA 1 atau XII TKJ 2" value="<?= htmlspecialchars($editClass['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tingkat / Tingkatan</label>
                    <input type="text" name="level" class="form-control" placeholder="Contoh: 10, 11, atau 12" value="<?= htmlspecialchars($editClass['level'] ?? '') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editClass): ?>
                    <a href="classes.php" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editClass ? 'Simpan Perubahan' : 'Simpan Kelas' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL IMPOR EXCEL / CSV KELAS -->
<div class="modal-backdrop" id="modalImportClasses">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_excel">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Data Kelas dari Excel / CSV</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">File Excel (.xlsx) atau CSV</label>
                    <input type="file" name="file_kelas" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Jika Nama Kelas Sudah Terdaftar</label>
                    <select name="dup_mode" class="form-control">
                        <option value="skip">Lewati (pertahankan data lama)</option>
                        <option value="update">Perbarui (update data tingkatan/jenjang)</option>
                    </select>
                </div>

                <div style="background: #f8fafc; border: 1px solid #eef2f7; border-radius: 14px; padding: 16px; font-size: 12.5px; color: #475569; line-height: 1.6;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;">Format Header Kolom File:</div>
                    <code>nama_kelas, tingkat</code><br><br>
                    • <b>nama_kelas</b>: Nama rombongan belajar, contoh <code>X MIPA 1</code> (<b>wajib diisi</b>).<br>
                    • <b>tingkat</b>: Tingkatan jenjang, contoh <code>10</code> atau <code>X</code> (opsional).<br>
                    • Format file mendukung <b>.xlsx</b> (Microsoft Excel) dan <b>.csv</b>.
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="classes.php?template=xlsx" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template Excel (.xlsx)
                        </a>
                        <a href="classes.php?template=csv" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template CSV
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="button" class="btn btn-primary" id="btnMulaiImpor">Mulai Impor File</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('modalImportClasses');
    if (!modal) return;
    var form = modal.querySelector('form');
    var btn  = modal.querySelector('#btnMulaiImpor');
    if (!form || !btn) return;
    btn.addEventListener('click', function () {
        if (!form.checkValidity()) { form.reportValidity(); return; }
        btn.disabled = true;
        btn.textContent = 'Memproses...';
        new Image().src = '../api/ping.php?_=' + Date.now();
        var formData = new FormData(form);
        console.log('[CBT] Import kelas → csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('file_kelas')?.name || 'none'));
        fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                console.log('[CBT] Response status=' + r.status);
                var loc = r.headers.get('Location');
                if (r.ok && loc) { window.location.replace(loc); }
                else if (r.ok) { window.location.reload(); }
                else { return r.text().then(function(t){ throw new Error('HTTP '+r.status+': '+t.substring(0,300)); }); }
            })
            .catch(function(err) {
                console.error('[CBT] Error:', err);
                alert('Impor gagal — ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Mulai Impor File';
            });
    }, false);
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
