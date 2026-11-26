<?php
/**
 * Master Data Mata Pelajaran per Tenant
 * Multi-Tenant CBT Online
 */

$pageTitle = "Mata Pelajaran";
require_once __DIR__ . '/_bootstrap.php';

// 1. Download Template Excel / CSV
if (isset($_GET['template'])) {
    $tplHeader = ['kode', 'nama'];
    $tplRows = [
        ['MAT-X',    'Matematika Wajib X'],
        ['BIND-X',   'Bahasa Indonesia X'],
        ['BIG-X',    'Bahasa Inggris X'],
        ['IPA-VII',  'Ilmu Pengetahuan Alam VII'],
        ['IPS-VIII', 'Ilmu Pengetahuan Sosial VIII'],
        ['PAI-X',    'Pendidikan Agama Islam X'],
        ['PJOK-XI',  'Pendidikan Jasmani XI']
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-mata-pelajaran.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-mata-pelajaran.xlsx', $tplHeader, $tplRows);
    }
}

// 2. Ekspor Data Mata Pelajaran
if (isset($_GET['export'])) {
    $allSubs = Database::fetchAll(
        "SELECT code, name, created_at FROM subjects WHERE tenant_id = ? ORDER BY name ASC",
        [$tenantId]
    );
    $expHeader = ['kode', 'nama', 'tanggal_buat'];
    $expRows = array_map(fn($s) => [$s['code'] ?: '-', $s['name'], $s['created_at']], $allSubs);
    if ($_GET['export'] === 'csv') {
        Helper::downloadCsv('data-mapel-' . $school['code'] . '.csv', $expHeader, $expRows);
    } else {
        Helper::downloadXlsx('data-mapel-' . $school['code'] . '.xlsx', $expHeader, $expRows);
    }
}

// 3. Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['_csrf'] ?? '';
    $action    = $_POST['action'] ?? '(no-action)';
    $sessHas   = isset($_SESSION['csrf_token']) ? 'YES' : 'NO';
    $tokenOk   = ($sessHas === 'YES' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken));
    error_log('[subjects] POST action=' . $action . ' sess_csrf=' . $sessHas . ' token_ok=' . ($tokenOk ? '1' : '0') . ' n_fields=' . count($_POST));
    try {
        Helper::verifyCsrf();
        $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                Helper::setFlash('danger', 'Nama mata pelajaran wajib diisi.');
            } else {
                Database::insert('subjects', [
                    'tenant_id' => $tenantId,
                    'code'      => $code,
                    'name'      => $name
                ]);
                Helper::setFlash('success', "Mata pelajaran '{$name}' berhasil ditambahkan.");
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menyimpan data: ' . $e->getMessage());
        }
        Helper::redirect('admin/subjects.php?page=1');

    } elseif ($action === 'update') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if ($id <= 0) {
                Helper::setFlash('danger', 'ID mata pelajaran tidak valid.');
            } elseif (empty($name)) {
                Helper::setFlash('danger', 'Nama mata pelajaran wajib diisi.');
            } else {
                Database::update('subjects', [
                    'code' => $code,
                    'name' => $name
                ], 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
                Helper::setFlash('success', "Mata pelajaran '{$name}' berhasil diperbarui.");
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal memperbarui data: ' . $e->getMessage());
        }
        Helper::redirect('admin/subjects.php?page=1');

    } elseif ($action === 'import_excel') {
        try {
            $upload = $_FILES['file_mapel'] ?? null;
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
                'mapel'              => 'nama',
                'mata_pelajaran'     => 'nama',
                'nama_mata_pelajaran'=> 'nama',
                'kode_matpel'        => 'kode',
                'kodemapel'          => 'kode'
            ] as $alias => $canon) {
                $ai = array_search($alias, $header, true);
                if ($ai !== false && !in_array($canon, $header, true)) {
                    $renameMap[$alias] = ['idx' => $ai, 'canon' => $canon];
                }
            }
            // Alias bawaan user langsung pakai nama standar
            foreach (['name' => 'nama', 'code' => 'kode'] as $alias => $canon) {
                $ai = array_search($alias, $header, true);
                if ($ai !== false && !in_array($canon, $header, true)) {
                    $renameMap[$alias] = ['idx' => $ai, 'canon' => $canon];
                }
            }

            // Terapkan rename sekaligus
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

            if (!in_array('nama', $header)) {
                throw new RuntimeException("Kolom wajib 'nama' tidak ditemukan di file. Header yang terbaca: " . implode(', ', $header));
            }

            $dupMode = $_POST['dup_mode'] ?? 'skip';
            $inserted = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $r) {
                $code = trim((string)($r['kode'] ?? ''));
                $name = trim((string)($r['nama'] ?? ''));
                if (empty($name)) continue;

                $existing = null;
                if ($code !== '') {
                    $existing = Database::fetch("SELECT id FROM subjects WHERE tenant_id = ? AND code = ?", [$tenantId, $code]);
                }
                if (!$existing) {
                    $existing = Database::fetch("SELECT id FROM subjects WHERE tenant_id = ? AND name = ?", [$tenantId, $name]);
                }

                if ($existing) {
                    if ($dupMode === 'update') {
                        Database::update('subjects', [
                            'code' => $code,
                            'name' => $name
                        ], 'id = :id AND tenant_id = :tenant_id', ['id' => $existing['id'], 'tenant_id' => $tenantId]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    Database::insert('subjects', [
                        'tenant_id' => $tenantId,
                        'code'      => $code,
                        'name'      => $name
                    ]);
                    $inserted++;
                }
            }

            $msg = "Impor selesai: <b>{$inserted} mapel</b> berhasil ditambahkan";
            if ($updated > 0) $msg .= ", <b>{$updated} mapel</b> diperbarui";
            if ($skipped > 0) $msg .= ", <b>{$skipped} mapel</b> dilewati (sudah terdaftar)";
            Helper::setFlash('success', $msg . '.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Impor gagal: ' . $e->getMessage());
        }
        Helper::redirect('admin/subjects.php?page=1');

    } elseif ($action === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            Database::delete('subjects', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', 'Mata pelajaran berhasil dihapus.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menghapus data: ' . $e->getMessage());
        }
        Helper::redirect('admin/subjects.php?page=1');

    } elseif ($action === 'clear_all') {
        Helper::verifyCsrf();
        try {
            // Cascade: questions → banks → exams using those banks → sessions → answers
            Database::query("DELETE ea FROM exam_answers ea JOIN exam_sessions es ON ea.session_id = es.id WHERE es.tenant_id = ?", [$tenantId]);
            Database::query("DELETE es FROM exam_sessions es WHERE es.tenant_id = ?", [$tenantId]);
            Database::query("DELETE e FROM exams e JOIN question_banks qb ON e.bank_id = qb.id WHERE qb.tenant_id = ?", [$tenantId]);
            Database::delete('questions', 'bank_id IN (SELECT id FROM question_banks WHERE tenant_id = ?)', [$tenantId]);
            Database::delete('question_banks', 'tenant_id = ?', [$tenantId]);
            Database::delete('subjects', 'tenant_id = ?', [$tenantId]);
            Helper::setFlash('success', 'Semua mata pelajaran, bank soal, soal, dan ujian terkait berhasil dihapus.');
        } catch (Throwable $e) {
            error_log('[subjects clear_all] Error: ' . $e->getMessage());
            Helper::setFlash('danger', 'Gagal menghapus semua mapel: ' . $e->getMessage());
        }
        Helper::redirect('admin/subjects.php?page=1');
    }
    } catch (Throwable $e) {
        error_log('[subjects] Uncaught POST error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        Helper::setFlash('danger', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        Helper::redirect('admin/subjects.php?page=1');
    }
}

// 4. Search + Pagination
$q = trim($_GET['q'] ?? '');
$countParams = ['tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM subjects WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND (name LIKE :q OR code LIKE :qcode)";
    $countParams['q'] = '%' . $q . '%';
    $countParams['qcode'] = '%' . $q . '%';
}
$totalSubjects = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalSubjects, 15);

$listParams = ['tenant_id' => $tenantId];
$listSql = "SELECT s.*, COUNT(qb.id) as total_banks
     FROM subjects s
     LEFT JOIN question_banks qb ON qb.subject_id = s.id
     WHERE s.tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND (s.name LIKE :q OR s.code LIKE :qcode)";
    $listParams['q'] = '%' . $q . '%';
    $listParams['qcode'] = '%' . $q . '%';
}
$listSql .= " GROUP BY s.id ORDER BY s.name ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$subjects = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit
$editSubject = null;
if (isset($_GET['edit'])) {
    $editSubject = Database::fetch(
        "SELECT * FROM subjects WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 class="card-title" style="margin: 0;">Daftar Mata Pelajaran (<?= $totalSubjects ?> Mapel)</h3>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0 0 0;">Kelola mata pelajaran dan impor data menggunakan Excel / CSV</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Tombol Unduh Template Excel -->
            <a href="subjects.php?template=xlsx" class="btn btn-outline btn-sm" title="Unduh format file Excel kosong untuk diisi">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Template Excel (.xlsx)
            </a>

            <!-- Tombol Impor Excel -->
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportSubjects').classList.add('show')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Impor Excel / CSV
            </button>

            <!-- Tombol Ekspor -->
            <a href="subjects.php?export=xlsx" class="btn btn-outline btn-sm" title="Ekspor data mapel ke file Excel">
                Ekspor Data
            </a>

            <!-- Tombol Tambah Manual -->
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddSubject').classList.add('show')">
                + Tambah Mapel Baru
            </button>

            <?php if ($totalSubjects > 0): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus semua <?= number_format($totalSubjects, 0, ',', '.') ?> mata pelajaran beserta seluruh bank soal, butir soal, dan jadwal ujian yang terhubung? Tindakan ini tidak dapat dibatalkan.')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Helper::csrfToken()) ?>">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="btn btn-danger btn-sm" title="Hapus semua mapel, bank soal, soal & ujian terkait" style="white-space:nowrap;">
                    🗑 Hapus Semua
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0;">
                <div class="search-inline">
                    <input type="text" name="q" class="form-control" placeholder="Cari nama atau kode mapel..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="subjects.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($subjects)): ?>
            <div style="padding: 48px 20px; text-align: center; color: var(--text-muted);">
                <div style="margin-bottom: 12px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                </div>
                <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 4px;">Belum Ada Mata Pelajaran Terdaftar</div>
                <div style="font-size: 12.5px; margin-bottom: 18px;">Gunakan template Excel untuk menambahkan mapel sekaligus, atau tambah manual satu per satu.</div>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="subjects.php?template=xlsx" class="btn btn-outline btn-sm">Unduh Template Excel</a>
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportSubjects').classList.add('show')">Impor File Excel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddSubject').classList.add('show')">+ Tambah Mapel</button>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Kode Mapel</th>
                            <th>Nama Mata Pelajaran</th>
                            <th>Total Bank Soal</th>
                            <th>Tanggal Buat</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $idx => $s): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td><code><?= htmlspecialchars($s['code'] ?: '-') ?></code></td>
                                <td><b><?= htmlspecialchars($s['name']) ?></b></td>
                                <td><span class="badge badge-primary"><?= (int)$s['total_banks'] ?> Bank Soal</span></td>
                                <td style="color: var(--text-muted); font-size: 12px;"><?= $s['created_at'] ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="subjects.php?edit=<?= $s['id'] ?>" class="btn btn-outline btn-sm">Edit</a>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus mapel <?= htmlspecialchars(addslashes($s['name'])) ?>? Seluruh bank soal dan ujian terkait akan ikut terhapus.')">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
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

<!-- MODAL TAMBAH / EDIT MAPEL MANUAL -->
<div class="modal-backdrop <?= $editSubject ? 'show' : '' ?>" id="modalAddSubject">
    <div class="modal-dialog">
        <form method="POST" action="./subjects.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editSubject ? 'update' : 'create' ?>">
            <?php if ($editSubject): ?>
                <input type="hidden" name="id" value="<?= $editSubject['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;"><?= $editSubject ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran' ?></h3>
                <?php if ($editSubject): ?>
                    <a href="subjects.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Kode Mapel (Opsional)</label>
                    <input type="text" name="code" class="form-control" placeholder="Contoh: MAT-X atau BIND-XII" value="<?= htmlspecialchars($editSubject['code'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Mata Pelajaran</label>
                    <input type="text" name="name" class="form-control" placeholder="Contoh: Matematika Peminatan" value="<?= htmlspecialchars($editSubject['name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editSubject): ?>
                    <a href="subjects.php" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editSubject ? 'Simpan Perubahan' : 'Simpan Mapel' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL IMPOR EXCEL / CSV MATA PELAJARAN -->
<div class="modal-backdrop" id="modalImportSubjects">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_excel">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Mata Pelajaran dari Excel / CSV</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">File Excel (.xlsx) atau CSV</label>
                    <input type="file" name="file_mapel" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Jika Kode / Nama Mapel Sudah Ada</label>
                    <select name="dup_mode" class="form-control">
                        <option value="skip">Lewati (pertahankan data lama)</option>
                        <option value="update">Perbarui (update data kode &amp; nama)</option>
                    </select>
                </div>

                <div style="background: #f8fafc; border: 1px solid #eef2f7; border-radius: 14px; padding: 16px; font-size: 12.5px; color: #475569; line-height: 1.6;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;">Format Header Kolom File:</div>
                    <code>kode, nama</code><br><br>
                    • <b>kode</b>: Kode singkat mapel, misal <code>MAT-X</code> (opsional).<br>
                    • <b>nama</b>: Nama lengkap mata pelajaran (<b>wajib diisi</b>).<br>
                    • Mendukung format Microsoft Excel <b>.xlsx</b> dan <b>.csv</b>.
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="subjects.php?template=xlsx" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template Excel (.xlsx)
                        </a>
                        <a href="subjects.php?template=csv" class="btn btn-outline btn-sm" style="font-size: 11px;">
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
    var modal = document.getElementById('modalImportSubjects');
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
        console.log('[CBT] Import mapel → csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('file_mapel')?.name || 'none'));
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
