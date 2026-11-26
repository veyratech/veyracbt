<?php
/**
 * Manajemen Jadwal Ujian per Tenant
 */

$pageTitle = "Jadwal Ujian";
require_once __DIR__ . '/_bootstrap.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $bankId = (int)($_POST['bank_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $duration = max(5, (int)($_POST['duration_minutes'] ?? 60));
        $token = strtoupper(trim($_POST['token'] ?? Helper::generateToken(6)));
        $startTime = date('Y-m-d H:i:s', strtotime($_POST['start_time'] ?? 'now'));
        $endTime = date('Y-m-d H:i:s', strtotime($_POST['end_time'] ?? '+7 days'));
        $randomize = isset($_POST['randomize_questions']) ? 1 : 0;
        $showScore = isset($_POST['show_score_immediately']) ? 1 : 0;
        $maxViolations = max(1, (int)($_POST['max_violations'] ?? 3));
        $questionCount = max(0, (int)($_POST['question_count'] ?? 0));
        $questionCount = $questionCount > 0 ? $questionCount : null;

        if (empty($title) || $bankId <= 0) {
            Helper::setFlash('danger', 'Judul ujian dan bank soal wajib diisi.');
            Helper::redirect('admin/exams.php');
        }

        // Validasi kepemilikan bank soal terhadap tenant aktif (mencegah IDOR antar-sekolah)
        $bankCheck = Database::fetch("SELECT id FROM question_banks WHERE id = ? AND tenant_id = ?", [$bankId, $tenantId]);
        if (!$bankCheck) {
            Helper::setFlash('danger', 'Bank soal tidak valid atau bukan milik sekolah Anda.');
            Helper::redirect('admin/exams.php');
        }

        // Validasi kepemilikan kelas jika dipilih
        if ($classId !== null) {
            $classCheck = Database::fetch("SELECT id FROM classes WHERE id = ? AND tenant_id = ?", [$classId, $tenantId]);
            if (!$classCheck) {
                $classId = null;
            }
        }

        if ($endTime <= $startTime) {
            Helper::setFlash('danger', 'Waktu selesai ujian harus setelah waktu mulai.');
            Helper::redirect('admin/exams.php');
        }

        $data = [
            'bank_id'                => $bankId,
            'class_id'               => $classId,
            'title'                  => $title,
            'duration_minutes'       => $duration,
            'token'                  => $token,
            'start_time'             => $startTime,
            'end_time'               => $endTime,
            'randomize_questions'    => $randomize,
            'show_score_immediately' => $showScore,
            'max_violations'         => $maxViolations,
            'question_count'         => $questionCount,
        ];

        if ($action === 'create') {
            $data['tenant_id'] = $tenantId;
            $data['is_active'] = 1;
            Database::insert('exams', $data);
            Helper::setFlash('success', "Jadwal ujian '{$title}' dengan Token [{$token}] berhasil dibuat.");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            Database::update('exams', $data, 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', "Jadwal ujian '{$title}' berhasil diperbarui.");
        }
        Helper::redirect('admin/exams.php');

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $current = (int)Database::fetchColumn("SELECT is_active FROM exams WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        Database::update('exams', ['is_active' => $current ? 0 : 1], 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
        Helper::setFlash('success', $current ? 'Jadwal ujian dinonaktifkan.' : 'Jadwal ujian diaktifkan kembali.');
        Helper::redirect('admin/exams.php');

    } elseif ($action === 'regenerate_token') {
        $id = (int)($_POST['id'] ?? 0);
        $newToken = Helper::generateToken(6);
        $updated = Database::update('exams', ['token' => $newToken], 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
        Helper::setFlash($updated ? 'success' : 'warning', $updated
            ? "Token ujian berhasil diganti menjadi [{$newToken}]. Beritahu siswa token baru."
            : 'Ujian tidak ditemukan.');
        Helper::redirect('admin/exams.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        Database::delete('exams', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
        Helper::setFlash('success', 'Jadwal ujian berhasil dihapus.');
        Helper::redirect('admin/exams.php');

    } elseif ($action === 'clear_all') {
        Helper::verifyCsrf();
        try {
            // Cascade: answers → sessions → broadcasts → exams
            Database::query("DELETE ea FROM exam_answers ea JOIN exam_sessions es ON ea.session_id = es.id WHERE es.tenant_id = ?", [$tenantId]);
            Database::query("DELETE es FROM exam_sessions es WHERE es.tenant_id = ?", [$tenantId]);
            Database::delete('exam_broadcasts', 'exam_id IN (SELECT id FROM exams WHERE tenant_id = ?)', [$tenantId]);
            Database::delete('exams', 'tenant_id = ?', [$tenantId]);
            Helper::setFlash('success', 'Semua jadwal ujian, sesi, dan jawaban terkait berhasil dihapus.');
        } catch (Throwable $e) {
            error_log('[exams clear_all] Error: ' . $e->getMessage());
            Helper::setFlash('danger', 'Gagal menghapus semua ujian: ' . $e->getMessage());
        }
        Helper::redirect('admin/exams.php');
    }
}

// Data untuk dropdown
$banks = Database::fetchAll("SELECT * FROM question_banks WHERE tenant_id = ? ORDER BY title ASC", [$tenantId]);
$classes = Database::fetchAll("SELECT * FROM classes WHERE tenant_id = ? ORDER BY name ASC", [$tenantId]);

// Search + Pagination
$q = trim($_GET['q'] ?? '');
$countParams = ['tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM exams WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND title LIKE :qt";
    $countParams['qt'] = '%' . $q . '%';
}
$totalExams = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalExams, 15);

$listParams = ['tenant_id' => $tenantId];
$listSql = "SELECT e.*, qb.title as bank_title, s.name as subject_name, c.name as class_name,
            (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id) as total_participants
     FROM exams e
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     LEFT JOIN classes c ON e.class_id = c.id
     WHERE e.tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND e.title LIKE :qt";
    $listParams['qt'] = '%' . $q . '%';
}
$listSql .= " ORDER BY e.id DESC LIMIT " . ((int)$pg['per_page']) . " OFFSET " . ((int)$pg['offset']);
$exams = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit
$editExam = null;
if (isset($_GET['edit'])) {
    $editExam = Database::fetch(
        "SELECT * FROM exams WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

function dtLocal(?string $dt): string {
    return $dt ? date('Y-m-d\TH:i', strtotime($dt)) : '';
}

$nowStr = date('Y-m-d H:i:s');
$liveExamsCnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ? AND is_active = 1 AND start_time <= ? AND end_time >= ?", [$tenantId, $nowStr, $nowStr]);
$upcomingExamsCnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ? AND is_active = 1 AND start_time > ?", [$tenantId, $nowStr]);
$totalSessionsCnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ?", [$tenantId]);

require_once __DIR__ . '/header.php';
?>

<!-- RINGKASAN JADWAL UJIAN -->
<div class="fan-kpi-grid" style="margin-bottom: 22px;">
    <div class="fan-kpi-card fan-kpi-pink">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Total Jadwal Ujian</div>
                <div class="fan-kpi-info-val"><?= number_format($totalExams, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Seluruh sesi terjadwal</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-green">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Sedang Berlangsung</div>
                <div class="fan-kpi-info-val"><?= number_format($liveExamsCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Ruang ujian terbuka saat ini</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-purple">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Jadwal Mendatang</div>
                <div class="fan-kpi-info-val"><?= number_format($upcomingExamsCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Menunggu waktu mulai</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-orange">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Total Partisipasi Sesi</div>
                <div class="fan-kpi-info-val"><?= number_format($totalSessionsCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Akumulasi peserta ujian</span></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Daftar Jadwal Ujian CBT (<?= $totalExams ?> Sesi)</h3>
            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Atur token akses, durasi pengerjaan, batas pelanggaran, dan pantau ruang ujian</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddExam').classList.add('show')">
            + Buat Jadwal Ujian Baru
        </button>

        <?php if ($totalExams > 0): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus semua <?= number_format($totalExams, 0, ',', '.') ?> jadwal ujian beserta seluruh sesi & jawaban? Tindakan ini tidak dapat dibatalkan.')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Helper::csrfToken()) ?>">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-danger btn-sm" title="Hapus semua jadwal ujian, sesi & jawaban" style="white-space:nowrap;">
                🗑 Hapus Semua
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 24px;">
            <form method="GET" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0;">
                <div style="flex: 1; min-width: 220px; max-width: 360px;">
                    <input type="text" name="q" class="form-control" placeholder="Cari judul ujian..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="exams.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($exams)): ?>
            <p style="padding: 36px; text-align: center; color: var(--text-muted);">Belum ada jadwal ujian<?= $q !== '' ? ' pada pencarian ini' : '' ?>. Silakan buat jadwal baru.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Nama Ujian &amp; Jadwal</th>
                            <th>Mata Pelajaran &amp; Bank Soal</th>
                            <th>Target Kelas</th>
                            <th>Token &amp; Aturan</th>
                            <th>Status</th>
                            <th>Peserta</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $nowTs = time();
                        foreach ($exams as $idx => $ex): 
                            $startTs = strtotime($ex['start_time']);
                            $endTs   = strtotime($ex['end_time']);
                        ?>
                            <tr>
                                <td style="font-weight: 700; color: #64748b;"><?= $pg['offset'] + $idx + 1 ?></td>
                                <td>
                                    <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($ex['title']) ?></div>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">
                                        <?= date('d M Y, H:i', $startTs) ?> &ndash; <?= date('d M Y, H:i', $endTs) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($ex['subject_name']) ?></div>
                                    <small style="color: #64748b;"><?= htmlspecialchars($ex['bank_title']) ?></small>
                                </td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars($ex['class_name'] ?: 'Semua Kelas') ?></span></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                        <span style="font-family: monospace; font-size: 12px; font-weight: 800; letter-spacing: 0.06em; padding: 3px 8px; border-radius: 6px; background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1;">
                                            <?= htmlspecialchars($ex['token']) ?>
                                        </span>
                                        <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Ganti token ujian <?= htmlspecialchars(addslashes($ex['title'])) ?>?')">
                                            <?= Helper::csrfField() ?>
                                            <input type="hidden" name="action" value="regenerate_token">
                                            <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="padding: 2px 7px; font-size: 10.5px;" title="Acak ulang token">&#x21bb;</button>
                                        </form>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <b><?= (int)$ex['duration_minutes'] ?> mnt</b> &middot;
                                        <?= $ex['question_count'] ? (int)$ex['question_count'] . ' soal' : 'Semua soal' ?>
                                        &middot; Maks <?= (int)$ex['max_violations'] ?>x
                                    </div>
                                </td>
                                <td>
                                    <?php if (!$ex['is_active']): ?>
                                        <span class="badge badge-danger">Nonaktif</span>
                                    <?php elseif ($nowTs >= $startTs && $nowTs <= $endTs): ?>
                                        <span class="badge badge-success">Berlangsung</span>
                                    <?php elseif ($nowTs < $startTs): ?>
                                        <span class="badge badge-primary">Terjadwal</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Selesai</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="monitor.php?exam_id=<?= $ex['id'] ?>" class="badge badge-success" style="text-decoration: none;">
                                        <?= (int)$ex['total_participants'] ?> Peserta
                                    </a>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 5px; flex-wrap: wrap; justify-content: flex-end;">
                                        <a href="monitor.php?exam_id=<?= $ex['id'] ?>" class="btn btn-primary btn-sm" title="Monitor Pengawas">
                                            Monitor
                                        </a>
                                        <a href="results.php?exam_id=<?= $ex['id'] ?>" class="btn btn-outline btn-sm" title="Rekap Nilai & Analisis Butir">
                                            Nilai
                                        </a>
                                        <a href="exams.php?edit=<?= $ex['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-outline btn-sm">Edit</a>

                                        <form method="POST" style="display: inline; margin: 0;">
                                            <?= Helper::csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm"><?= $ex['is_active'] ? 'Off' : 'On' ?></button>
                                        </form>

                                        <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Hapus jadwal ujian <?= htmlspecialchars(addslashes($ex['title'])) ?>?')">
                                            <?= Helper::csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Hapus Jadwal">&times;</button>
                                        </form>
                                    </div>
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

<!-- MODAL TAMBAH / EDIT JADWAL UJIAN -->
<div class="modal-backdrop <?= $editExam ? 'show' : '' ?>" id="modalAddExam">
    <div class="modal-dialog" style="max-width: 600px;">
        <form method="POST" action="./exams.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editExam ? 'update' : 'create' ?>">
            <?php if ($editExam): ?>
                <input type="hidden" name="id" value="<?= $editExam['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;"><?= $editExam ? 'Edit Jadwal Ujian CBT' : 'Buat Jadwal Ujian CBT Baru' ?></h3>
                <?php if ($editExam): ?>
                    <a href="exams.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div class="form-group">
                    <label class="form-label">Judul / Nama Ujian</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Penilaian Akhir Semester Ganjil" value="<?= htmlspecialchars($editExam['title'] ?? '') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Pilih Bank Soal</label>
                        <select name="bank_id" class="form-control" required>
                            <option value="">-- Pilih Bank Soal --</option>
                            <?php foreach ($banks as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= (int)($editExam['bank_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sasaran Kelas</label>
                        <select name="class_id" class="form-control">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (int)($editExam['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Durasi Pengerjaan (Menit)</label>
                        <input type="number" name="duration_minutes" class="form-control" value="<?= htmlspecialchars($editExam['duration_minutes'] ?? '60') ?>" min="5" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Token Ujian (Otomatis/Kustom)</label>
                        <input type="text" name="token" class="form-control" value="<?= htmlspecialchars($editExam['token'] ?? Helper::generateToken(6)) ?>" required style="text-transform: uppercase;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Jumlah Soal per Siswa (Opsional)</label>
                    <input type="number" name="question_count" class="form-control" value="<?= htmlspecialchars($editExam['question_count'] ?? '') ?>" min="0" placeholder="0 = semua soal di bank">
                    <small style="color: var(--text-muted); font-size: 11.5px;">Isi lebih kecil dari total soal bank untuk mengacak N soal per siswa (mis. 40 dari 100). Kosongkan/0 = pakai semua soal.</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Waktu Mulai</label>
                        <input type="datetime-local" name="start_time" class="form-control" value="<?= $editExam ? dtLocal($editExam['start_time']) : date('Y-m-d\TH:i') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Waktu Selesai</label>
                        <input type="datetime-local" name="end_time" class="form-control" value="<?= $editExam ? dtLocal($editExam['end_time']) : date('Y-m-d\TH:i', strtotime('+7 days')) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Toleransi Pelanggaran Layar (Anti-Cheat)</label>
                    <input type="number" name="max_violations" class="form-control" value="<?= htmlspecialchars($editExam['max_violations'] ?? '3') ?>" min="1" max="10" required>
                    <small style="color: var(--text-muted); font-size: 11.5px;">Batas maksimal siswa berpindah tab/layar sebelum ujian otomatis diblokir.</small>
                </div>

                <div style="background: var(--bg-subtle); padding: 14px; border-radius: 8px; border: 1px solid var(--border-light); margin-top: 14px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; cursor: pointer; margin-bottom: 8px;">
                        <input type="checkbox" name="randomize_questions" value="1" <?= !isset($editExam['randomize_questions']) || $editExam['randomize_questions'] ? 'checked' : '' ?>>
                        <span>Acak urutan soal untuk setiap siswa</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; cursor: pointer;">
                        <input type="checkbox" name="show_score_immediately" value="1" <?= !isset($editExam['show_score_immediately']) || $editExam['show_score_immediately'] ? 'checked' : '' ?>>
                        <span>Tampilkan nilai langsung kepada siswa setelah ujian selesai</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editExam): ?>
                    <a href="exams.php" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editExam ? 'Simpan Perubahan' : 'Simpan Jadwal Ujian' ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
