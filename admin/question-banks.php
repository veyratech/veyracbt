<?php
/**
 * Bank Soal per Tenant
 */

$pageTitle = "Bank Soal";
require_once __DIR__ . '/_bootstrap.php';

// Auto-migration: tambahkan kolom exam_type jika belum ada, dan buat tabel exam_types
try {
    $hasExamTypeCol = Database::fetch("SHOW COLUMNS FROM question_banks LIKE 'exam_type'");
    if (!$hasExamTypeCol) {
        // Buat terlebih dahulu sebagai VARCHAR sebelum mengubah ENUM di tempat lain
        Database::query("ALTER TABLE question_banks ADD COLUMN exam_type VARCHAR(50) NULL AFTER subject_id");
    } elseif (Database::fetchColumn("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'question_banks' AND COLUMN_NAME = 'exam_type'") === null) {
        // Ubah ENUM lama ke VARCHAR agar bisa menerima nilai custom dari exam_types
        Database::query("ALTER TABLE question_banks MODIFY COLUMN exam_type VARCHAR(50) NULL");
    }
} catch (Throwable $e) {}
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

// 1. Download Template Excel / CSV Bank Soal
if (isset($_GET['template'])) {
    $tplHeader = ['nomor', 'jenis_soal', 'soal', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban', 'bobot', 'pembahasan'];
    $tplRows = [
        [
            '1',
            'PG',
            'Berikut ini yang merupakan tag HTML untuk membuat judul tingkat pertama (heading utama) adalah...',
            '<head>',
            '<h1>',
            '<header>',
            '<title>',
            '<p>',
            'B',
            '10',
            'Tag <h1> digunakan untuk judul atau heading paling utama dalam hierarki dokumen HTML.'
        ],
        [
            '2',
            'PG',
            'Perintah CSS manakah yang digunakan untuk mengubah warna latar belakang sebuah elemen web?',
            'font-color',
            'color',
            'background-color',
            'bgcolor',
            'canvas-color',
            'C',
            '10',
            'background-color adalah properti resmi CSS untuk menentukan warna latar belakang elemen.'
        ],
        [
            '3',
            'PG',
            'Karakter atau simbol manakah yang wajib digunakan untuk mengawali penamaan variabel dalam bahasa PHP?',
            '&',
            '@',
            '#',
            '$',
            '%',
            'D',
            '10',
            'Di PHP, semua variabel selalu diawali dengan tanda dollar ($), contohnya $nama atau $nilai.'
        ],
        [
            '4',
            'PG',
            'Protokol jaringan yang digunakan untuk mengamankan pertukaran data web melalui enkripsi SSL/TLS adalah...',
            'FTP',
            'HTTP',
            'HTTPS',
            'SMTP',
            'DNS',
            'C',
            '10',
            'HTTPS (HTTP Secure) mengenkripsi seluruh komunikasi data antara browser klien dan server.'
        ],
        [
            '5',
            'Essay',
            'Jelaskan perbedaan mendasar antara basis data relasional (SQL) dan basis data non-relasional (NoSQL)!',
            '',
            '',
            '',
            '',
            '',
            '',
            '20',
            'SQL menggunakan skema tabel tetap bertipe relasi, sedangkan NoSQL fleksibel berbasis dokumen JSON, key-value, dsb.'
        ]
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-bank-soal.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-bank-soal.xlsx', $tplHeader, $tplRows);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($subjectId > 0 && !empty($title)) {
            $validSubject = Database::fetch("SELECT id FROM subjects WHERE id = ? AND tenant_id = ?", [$subjectId, $tenantId]);
            if (!$validSubject) {
                Helper::setFlash('danger', 'Mata pelajaran tidak valid.');
                Helper::redirect('admin/question-banks.php');
            }
            Database::insert('question_banks', [
                'tenant_id'       => $tenantId,
                'subject_id'      => $subjectId,
                'title'           => $title,
                'total_questions' => 0,
                'created_by'      => Auth::id()
            ]);
            Helper::setFlash('success', "Bank soal '{$title}' berhasil dibuat.");
        }
        Helper::redirect('admin/question-banks.php');

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($id > 0 && $subjectId > 0 && !empty($title)) {
            $validSubject = Database::fetch("SELECT id FROM subjects WHERE id = ? AND tenant_id = ?", [$subjectId, $tenantId]);
            if (!$validSubject) {
                Helper::setFlash('danger', 'Mata pelajaran tidak valid.');
                Helper::redirect('admin/question-banks.php');
            }
            Database::update('question_banks', [
                'subject_id' => $subjectId,
                'title'      => $title
            ], 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', "Bank soal '{$title}' berhasil diperbarui.");
        }
        Helper::redirect('admin/question-banks.php');

    } elseif ($action === 'import_excel') {
        try {
            if (!isset($_FILES['file_soal']['name']) || $_FILES['file_soal']['error'] !== UPLOAD_ERR_OK || $_FILES['file_soal']['size'] === 0) {
                throw new RuntimeException('Silakan pilih file Excel (.xlsx) atau CSV terlebih dahulu.');
            }

            $importTarget = $_POST['import_target'] ?? 'new'; // 'new' or 'existing'
            $targetBankId = 0;
            $bankTitle = '';

            if ($importTarget === 'new') {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $bankTitle = trim($_POST['title'] ?? '');
                if ($subjectId <= 0 || empty($bankTitle)) {
                    throw new RuntimeException('Untuk bank soal baru, pilih mata pelajaran dan isi judul bank soal.');
                }
                $validSubject = Database::fetch("SELECT id FROM subjects WHERE id = ? AND tenant_id = ?", [$subjectId, $tenantId]);
                if (!$validSubject) {
                    throw new RuntimeException('Mata pelajaran tidak valid.');
                }
                Database::insert('question_banks', [
                    'tenant_id'       => $tenantId,
                    'subject_id'      => $subjectId,
                    'title'           => $bankTitle,
                    'exam_type'       => $_POST['exam_type'] ?? null,
                    'total_questions' => 0,
                    'created_by'      => Auth::id()
                ]);
                $targetBankId = (int)Database::lastInsertId();
            } else {
                $targetBankId = (int)($_POST['bank_id'] ?? 0);
                $existingBank = Database::fetch("SELECT * FROM question_banks WHERE id = ? AND tenant_id = ?", [$targetBankId, $tenantId]);
                if (!$existingBank) {
                    throw new RuntimeException('Bank soal tujuan tidak ditemukan.');
                }
                $bankTitle = $existingBank['title'];
            }

            [$header, $rows] = Helper::parseUpload($_FILES['file_soal']);

            // Alias normalization
            $aliases = [
                'nomor'          => 'nomor',
                'no'             => 'nomor',
                'jenis_soal'     => 'question_type',
                'tipe_soal'      => 'question_type',
                'tipe'           => 'question_type',
                'type'           => 'question_type',
                'question_type'  => 'question_type',
                'soal'           => 'question_text',
                'pertanyaan'     => 'question_text',
                'teks_soal'      => 'question_text',
                'question_text'  => 'question_text',
                'opsi_a'         => 'option_a',
                'pilihan_a'      => 'option_a',
                'jawaban_a'      => 'option_a',
                'a'              => 'option_a',
                'option_a'       => 'option_a',
                'opsi_b'         => 'option_b',
                'pilihan_b'      => 'option_b',
                'jawaban_b'      => 'option_b',
                'b'              => 'option_b',
                'option_b'       => 'option_b',
                'opsi_c'         => 'option_c',
                'pilihan_c'      => 'option_c',
                'jawaban_c'      => 'option_c',
                'c'              => 'option_c',
                'option_c'       => 'option_c',
                'opsi_d'         => 'option_d',
                'pilihan_d'      => 'option_d',
                'jawaban_d'      => 'option_d',
                'd'              => 'option_d',
                'option_d'       => 'option_d',
                'opsi_e'         => 'option_e',
                'pilihan_e'      => 'option_e',
                'jawaban_e'      => 'option_e',
                'e'              => 'option_e',
                'option_e'       => 'option_e',
                'kunci_jawaban'  => 'correct_answer',
                'kunci'          => 'correct_answer',
                'jawaban_benar'  => 'correct_answer',
                'jawaban'        => 'correct_answer',
                'correct'        => 'correct_answer',
                'correct_answer' => 'correct_answer',
                'bobot'          => 'points',
                'poin'           => 'points',
                'nilai'          => 'points',
                'points'         => 'points',
                'pembahasan'     => 'explanation',
                'penjelasan'     => 'explanation',
                'keterangan'     => 'explanation',
                'explanation'    => 'explanation'
            ];

            // Alias normalization (pre-collect map before applying to avoid index shift)
            $renameMap = [];
            foreach ($aliases as $orig => $canon) {
                $idx = array_search($orig, $header, true);
                if ($idx !== false && !in_array($canon, $header, true)) {
                    $renameMap[$idx] = [$orig, $canon];
                }
            }
            foreach ($renameMap as $idx => [$orig, $canon]) {
                $header[$idx] = $canon;
                foreach ($rows as &$r) {
                    if (!isset($r[$canon]) && isset($r[$orig])) {
                        $r[$canon] = $r[$orig];
                    }
                }
                unset($r);
            }

            if (!in_array('question_text', $header, true)) {
                throw new RuntimeException("Kolom pertanyaan/soal tidak ditemukan di file. Kolom terdeteksi: " . implode(', ', $header));
            }

            // Opsi replace/append
            $importMode = $_POST['import_mode'] ?? 'append';
            if ($importMode === 'replace') {
                Database::delete('questions', 'bank_id = :bid AND tenant_id = :tid', ['bid' => $targetBankId, 'tid' => $tenantId]);
            }

            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $qText = trim($row['question_text'] ?? '');
                if ($qText === '') {
                    $skipped++;
                    continue;
                }

                $rawType = strtolower(trim($row['question_type'] ?? 'pg'));
                $qType = (strpos($rawType, 'essay') !== false || strpos($rawType, 'uraian') !== false) ? 'essay' : 'pg';

                $optA = trim($row['option_a'] ?? '');
                $optB = trim($row['option_b'] ?? '');
                $optC = trim($row['option_c'] ?? '');
                $optD = trim($row['option_d'] ?? '');
                $optE = trim($row['option_e'] ?? '');
                $correct = strtoupper(trim($row['correct_answer'] ?? ''));

                if ($qType === 'essay') {
                    $optA = $optB = $optC = $optD = $optE = '';
                    $correct = '-';
                } else {
                    if ($optA === '' || $optB === '' || !in_array($correct, ['A', 'B', 'C', 'D', 'E'], true)) {
                        $skipped++;
                        continue;
                    }
                }

                $points = max(1, (int)($row['points'] ?? 10));
                $explanation = trim($row['explanation'] ?? '');

                Database::insert('questions', [
                    'tenant_id'      => $tenantId,
                    'bank_id'        => $targetBankId,
                    'question_type'  => $qType,
                    'question_text'  => $qText,
                    'question_image' => null,
                    'option_a'       => $optA,
                    'option_b'       => $optB,
                    'option_c'       => $optC,
                    'option_d'       => $optD,
                    'option_e'       => $optE,
                    'correct_answer' => $correct,
                    'points'         => $points,
                    'explanation'    => $explanation !== '' ? $explanation : null
                ]);
                $imported++;
            }

            $actualCount = Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE bank_id = ? AND tenant_id = ?", [$targetBankId, $tenantId]);
            Database::update('question_banks', ['total_questions' => (int)$actualCount], 'id = :id AND tenant_id = :tid', ['id' => $targetBankId, 'tid' => $tenantId]);

            $msg = "Berhasil mengimpor <b>{$imported} butir soal</b> ke bank soal '<b>" . htmlspecialchars($bankTitle) . "</b>'";
            if ($skipped > 0) $msg .= " ({$skipped} baris dilewati karena format tidak lengkap)";
            Helper::setFlash('success', $msg . '.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal: ' . $e->getMessage());
        }
        Helper::redirect('admin/question-banks.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        Database::delete('questions', 'bank_id = :bid AND tenant_id = :tid', ['bid' => $id, 'tid' => $tenantId]);
        Database::delete('question_banks', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
        Helper::setFlash('success', "Bank soal berhasil dihapus.");
        Helper::redirect('admin/question-banks.php');
    }
}

// Ambil data mapel untuk form
$subjects = Database::fetchAll("SELECT * FROM subjects WHERE tenant_id = ? ORDER BY name ASC", [$tenantId]);

// Ambil daftar jenis ujian yang terdaftar
$examTypeOptions = [];
foreach (Database::fetchAll(
    "SELECT slug, name FROM exam_types WHERE tenant_id = ? ORDER BY sort_order ASC",
    [$tenantId]
) as $et) {
    $examTypeOptions[$et['slug']] = $et['name'];
}

// Search + Pagination
$q = trim($_GET['q'] ?? '');
$examType = $_GET['exam_type'] ?? '';
$countParams = ['tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM question_banks WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND title LIKE :q";
    $countParams['q'] = '%' . $q . '%';
}
if ($examType !== '') {
    $countSql .= " AND exam_type = :exam_type";
    $countParams['exam_type'] = $examType;
}
$totalBanks = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalBanks, 15);

$listParams = ['tenant_id' => $tenantId];
$listSql = "SELECT qb.*, s.name as subject_name,
            (SELECT COUNT(*) FROM questions WHERE bank_id = qb.id) as actual_questions
     FROM question_banks qb
     JOIN subjects s ON qb.subject_id = s.id
     WHERE qb.tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND qb.title LIKE :q";
    $listParams['q'] = '%' . $q . '%';
}
if ($examType !== '') {
    $listSql .= " AND qb.exam_type = :exam_type";
    $listParams['exam_type'] = $examType;
}
$listSql .= " ORDER BY qb.id DESC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$banks = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit
$editBank = null;
if (isset($_GET['edit'])) {
    $editBank = Database::fetch(
        "SELECT * FROM question_banks WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

$totalAllBanks = (int)Database::fetchColumn("SELECT COUNT(*) FROM question_banks WHERE tenant_id = ?", [$tenantId]);
$totalAllQuestions = (int)Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE tenant_id = ?", [$tenantId]);
$totalPgQuestions = (int)Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE tenant_id = ? AND question_type = 'pg'", [$tenantId]);
$totalEssayQuestions = (int)Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE tenant_id = ? AND question_type = 'essay'", [$tenantId]);

require_once __DIR__ . '/header.php';
?>

<!-- RINGKASAN BANK SOAL -->
<div class="fan-kpi-grid" style="margin-bottom: 22px;">
    <div class="fan-kpi-card fan-kpi-purple">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Total Bank Soal</div>
                <div class="fan-kpi-info-val"><?= number_format($totalAllBanks, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Paket soal tersimpan</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-green">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Total Butir Soal</div>
                <div class="fan-kpi-info-val"><?= number_format($totalAllQuestions, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Siap diujikan ke siswa</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-pink">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Soal Pilihan Ganda</div>
                <div class="fan-kpi-info-val"><?= number_format($totalPgQuestions, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Koreksi otomatis instan</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-orange">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Soal Uraian / Essay</div>
                <div class="fan-kpi-info-val"><?= number_format($totalEssayQuestions, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Penilaian rubrik guru</span></div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 class="card-title" style="margin: 0;">Daftar Bank Soal (<?= $totalBanks ?> Bank Soal)</h3>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0 0 0;">Kelola bank soal ujian, unduh template, dan impor butir soal otomatis via Excel / CSV</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Tombol Unduh Template Excel -->
            <a href="question-banks.php?template=xlsx" class="btn btn-outline btn-sm" title="Unduh format file Excel kosong untuk mengisi bank soal">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Template Excel (.xlsx)
            </a>

            <!-- Tombol Impor Bank Soal -->
            <button type="button" class="btn btn-outline btn-sm" onclick="openImportModalNew()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Impor Bank Soal (Excel)
            </button>

            <!-- Tombol Tambah Bank Soal Manual -->
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddBank').classList.add('show')">
                + Buat Bank Soal Baru
            </button>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0;">
                <div class="search-inline">
                    <input type="text" name="q" class="form-control" placeholder="Cari judul bank soal..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="question-banks.php<?= $examType ? '?exam_type=' . urlencode($examType) : '' ?>" class="btn btn-outline btn-sm">Reset Pencarian</a>
                <?php endif; ?>
                <?php if ($examType !== ''): ?>
                    <span class="badge badge-warning" style="font-size:11px; margin-left:4px;">
                        Filter: <?= htmlspecialchars($examTypeOptions[$examType] ?? $examType) ?>
                        <a href="question-banks.php<?= $q ? '?q=' . urlencode($q) : '' ?>" style="color:#fff; margin-left:4px; text-decoration:none;" title="Hapus filter jenis ujian">&times;</a>
                    </span>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($banks)): ?>
            <p style="padding: 32px; text-align: center; color: var(--text-muted);">Belum ada bank soal<?= $q !== '' ? ' pada pencarian ini' : '' ?>. Buat bank soal baru atau impor menggunakan file Excel.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Judul Bank Soal</th>
                            <th>Mata Pelajaran</th>
                            <th>Jumlah Soal</th>
                            <th>Dibuat Pada</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banks as $idx => $b): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td>
                                    <b><?= htmlspecialchars($b['title']) ?></b>
                                    <?php if (!empty($b['exam_type'])): ?>
                                        <span class="badge badge-warning" style="margin-left: 6px; font-size: 11px;">
                                            <?= htmlspecialchars($examTypeOptions[$b['exam_type']] ?? $b['exam_type']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-primary"><?= htmlspecialchars($b['subject_name']) ?></span></td>
                                <td>
                                    <span class="badge badge-success"><?= $b['actual_questions'] ?> Butir Soal</span>
                                </td>
                                <td style="color: var(--text-muted); font-size: 12px;"><?= $b['created_at'] ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="questions.php?bank_id=<?= $b['id'] ?>" class="btn btn-primary btn-sm" title="Kelola butir soal">
                                        Kelola Soal (<?= $b['actual_questions'] ?>)
                                    </a>

                                    <button type="button" class="btn btn-outline btn-sm" onclick="openImportToBank(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>')" title="Impor butir soal dari Excel ke bank soal ini">
                                        📥 Impor
                                    </button>

                                    <a href="questions.php?bank_id=<?= $b['id'] ?>&export=xlsx" class="btn btn-outline btn-sm" title="Ekspor seluruh butir soal ke file Excel">
                                        📤 Ekspor
                                    </a>

                                    <a href="question-banks.php?edit=<?= $b['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-outline btn-sm">Edit</a>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus bank soal <?= htmlspecialchars(addslashes($b['title'])) ?> beserta seluruh butir soalnya?')">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= Helper::paginationLinks($pg, array_filter(['q' => $q, 'exam_type' => $examType])) ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL IMPOR BANK SOAL EXCEL / CSV -->
<div class="modal-backdrop" id="modalImportBankSoal">
    <div class="modal-dialog" style="max-width: 640px;">
        <form method="POST" action="./question-banks.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_excel">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Bank Soal (Excel / CSV)</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <!-- Pilihan Tujuan Impor -->
                <div class="form-group">
                    <label class="form-label">Tujuan Impor Soal:</label>
                    <div style="display: flex; gap: 16px; margin-bottom: 10px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="radio" name="import_target" value="new" id="targetRadioNew" checked onchange="toggleImportTarget()">
                            <b>Buat Bank Soal Baru</b>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="radio" name="import_target" value="existing" id="targetRadioExisting" onchange="toggleImportTarget()">
                            <b>Masukkan ke Bank Soal yang Sudah Ada</b>
                        </label>
                    </div>
                </div>

                <!-- Bagian Buat Bank Soal Baru -->
                <div id="sectionNewBank">
                    <div class="form-group">
                        <label class="form-label">Mata Pelajaran <span style="color: #ef4444;">*</span></label>
                        <select name="subject_id" id="importSubjectSelect" class="form-control">
                            <option value="">-- Pilih Mata Pelajaran --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>">
                                    <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['code'] ?: 'Umum') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Judul Bank Soal Baru <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="title" id="importTitleInput" class="form-control" placeholder="Contoh: Asesmen Sumatif Akhir Semester Genap">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jenis Ujian</label>
                        <select name="exam_type" id="importExamTypeSelect" class="form-control">
                            <option value="">— Tidak spesifik —</option>
                            <?php foreach ($examTypeOptions as $slug => $name): ?>
                                <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Bagian Pilih Bank Soal yang Ada -->
                <div id="sectionExistingBank" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">Pilih Bank Soal Tujuan <span style="color: #ef4444;">*</span></label>
                        <select name="bank_id" id="importExistingBankSelect" class="form-control">
                            <option value="">-- Pilih Bank Soal --</option>
                            <?php foreach ($banks as $eb): ?>
                                <option value="<?= $eb['id'] ?>">
                                    <?= htmlspecialchars($eb['title']) ?> (<?= htmlspecialchars($eb['subject_name']) ?> - <?= $eb['actual_questions'] ?> Soal)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode Pengisian Soal:</label>
                        <select name="import_mode" class="form-control">
                            <option value="append">Tambahkan ke butir soal yang sudah ada (Append)</option>
                            <option value="replace">Kosongkan soal lama &amp; ganti dengan file ini (Replace)</option>
                        </select>
                    </div>
                </div>

                <!-- Upload File Excel -->
                <div class="form-group" style="margin-top: 14px;">
                    <label class="form-label">File Excel (.xlsx) atau CSV <span style="color: #ef4444;">*</span></label>
                    <input type="file" name="file_soal" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <small style="color: var(--text-muted); font-size: 11.5px;">Maksimal ukuran file: 5 MB.</small>
                </div>

                <!-- Petunjuk Template -->
                <div style="background: var(--bg-subtle); border-radius: 10px; padding: 12px 14px; font-size: 12px; color: var(--text-muted); line-height: 1.6; border: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <b style="color: var(--text-heading);">Format Kolom Template Excel:</b>
                        <a href="question-banks.php?template=xlsx" style="font-weight: 700; color: #2563eb; text-decoration: underline;">
                            Unduh Template Excel (.xlsx)
                        </a>
                    </div>
                    <code>nomor, jenis_soal, soal, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot, pembahasan</code>
                    <ul style="margin: 6px 0 0 16px; padding: 0;">
                        <li><b>jenis_soal</b>: Isi <code>PG</code> (Pilihan Ganda) atau <code>Essay</code>.</li>
                        <li><b>kunci_jawaban</b>: Isi <code>A</code>, <code>B</code>, <code>C</code>, <code>D</code>, atau <code>E</code> (kosongkan jika Essay).</li>
                        <li><b>bobot</b>: Angka bobot poin per soal (contoh: <code>10</code> atau <code>20</code>).</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="button" class="btn btn-primary" id="btnMulaiImporBank">Mulai Proses Impor</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleImportTarget() {
    var isNew = document.getElementById('targetRadioNew').checked;
    document.getElementById('sectionNewBank').style.display = isNew ? 'block' : 'none';
    document.getElementById('sectionExistingBank').style.display = isNew ? 'none' : 'block';
    
    var subSel = document.getElementById('importSubjectSelect');
    var titInp = document.getElementById('importTitleInput');
    var exSel = document.getElementById('importExistingBankSelect');

    if (isNew) {
        subSel.setAttribute('required', 'required');
        titInp.setAttribute('required', 'required');
        exSel.removeAttribute('required');
    } else {
        subSel.removeAttribute('required');
        titInp.removeAttribute('required');
        exSel.setAttribute('required', 'required');
    }
}

function openImportModalNew() {
    document.getElementById('targetRadioNew').checked = true;
    toggleImportTarget();
    document.getElementById('modalImportBankSoal').classList.add('show');
}

function openImportToBank(bankId, bankTitle) {
    document.getElementById('targetRadioExisting').checked = true;
    toggleImportTarget();
    var sel = document.getElementById('importExistingBankSelect');
    if (sel) sel.value = bankId;
    document.getElementById('modalImportBankSoal').classList.add('show');
}
</script>
<script>
(function(){
    var modal = document.getElementById('modalImportBankSoal');
    if (!modal) return;
    var form = modal.querySelector('form');
    var btn  = modal.querySelector('#btnMulaiImporBank');
    if (!form || !btn) return;
    btn.addEventListener('click', function () {
        if (!form.checkValidity()) { form.reportValidity(); return; }
        btn.disabled = true;
        btn.textContent = 'Memproses...';
        new Image().src = '../api/ping.php?_=' + Date.now();
        var formData = new FormData(form);
        console.log('[CBT] Import bank soal → csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('csv_file')?.name || 'none'));
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
                btn.textContent = 'Mulai Proses Impor';
            });
    }, false);
})();
</script>

<!-- MODAL TAMBAH / EDIT BANK SOAL -->
<div class="modal-backdrop <?= $editBank ? 'show' : '' ?>" id="modalAddBank">
    <div class="modal-dialog">
        <form method="POST" action="./question-banks.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editBank ? 'update' : 'create' ?>">
            <?php if ($editBank): ?>
                <input type="hidden" name="id" value="<?= $editBank['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;"><?= $editBank ? 'Edit Bank Soal' : 'Buat Bank Soal Baru' ?></h3>
                <?php if ($editBank): ?>
                    <a href="question-banks.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Mata Pelajaran</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">-- Pilih Mata Pelajaran --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int)($editBank['subject_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['code'] ?: 'Umum') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Judul Bank Soal</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Asesmen Sumatif Akhir Semester 1" value="<?= htmlspecialchars($editBank['title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jenis Ujian</label>
                    <select name="exam_type" class="form-control">
                        <option value="">— Tidak spesifik —</option>
                        <?php foreach ($examTypeOptions as $slug => $name): ?>
                            <option value="<?= htmlspecialchars($slug) ?>"
                                <?= ($editBank['exam_type'] ?? '') === $slug ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editBank): ?>
                    <a href="question-banks.php" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editBank ? 'Simpan Perubahan' : 'Simpan Bank Soal' ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
