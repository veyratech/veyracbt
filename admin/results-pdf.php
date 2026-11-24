<?php
/**
 * Rekap Nilai Ujian CBT - Versi Print / Dokumen Resmi Eksekutif
 * Multi-Tenant CBT Online
 */

declare(strict_types=1);

$pageTitle = "Rekap Nilai Cetak Resmi";
require_once __DIR__ . '/_bootstrap.php';

$examId = (int)($_GET['exam_id'] ?? 0);

$selectedExam = Database::fetch(
    "SELECT e.*, qb.title as bank_title, s.name as subject_name, s.code as subject_code, c.name as class_name
     FROM exams e 
     JOIN question_banks qb ON e.bank_id = qb.id 
     JOIN subjects s ON qb.subject_id = s.id 
     LEFT JOIN classes c ON e.class_id = c.id
     WHERE e.id = ? AND e.tenant_id = ?",
    [$examId, $tenantId]
);

$results = [];
$avgScore = 0.0;
$maxScore = 0.0;
$minScore = 0.0;
$passedCount = 0;
$failedCount = 0;
$kkm = 75.0;
$optsRandom = $selectedExam && (int)$selectedExam['randomize_questions'] === 1;

if ($selectedExam) {
    $results = Database::fetchAll(
        "SELECT es.*, st.name as student_name, st.nis, c.name as class_name,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id AND is_correct = 1) as correct_count,
                (SELECT COUNT(*) FROM exam_answers ea_w
                 JOIN questions qw ON ea_w.question_id = qw.id
                 WHERE ea_w.session_id = es.id
                    AND (ea_w.student_answer IS NOT NULL OR ea_w.answer_text IS NOT NULL)
                    AND ea_w.is_correct = 0
                    AND qw.question_type != 'essay') as wrong_count,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id
                    AND student_answer IS NULL AND answer_text IS NULL) as empty_count,
                (SELECT COUNT(*) FROM exam_answers ea2
                 JOIN questions q2 ON ea2.question_id = q2.id
                 WHERE ea2.session_id = es.id
                    AND q2.question_type = 'essay'
                    AND ea2.answer_text IS NOT NULL
                    AND ea2.essay_score IS NOT NULL
                    AND ea2.is_correct = 0) as partial_count,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id
                    AND answer_text IS NOT NULL AND essay_score IS NULL) as ungraded_count
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.tenant_id = ? AND es.status = 'completed'
         ORDER BY es.score DESC, st.name ASC",
        [$examId, $tenantId]
    );

    if (!empty($results)) {
        $scores = array_map('floatval', array_column($results, 'score'));
        $avgScore = (float)(array_sum($scores) / count($scores));
        $maxScore = (float)max($scores);
        $minScore = (float)min($scores);
        foreach ($results as $r) {
            if ((float)$r['score'] >= $kkm) $passedCount++;
            else $failedCount++;
        }
    }
}

// Analisis butir soal
$itemAnalysis = [];
if ($selectedExam) {
    $itemAnalysis = Database::fetchAll(
        "SELECT q.id, q.question_text, q.correct_answer, q.points,
                COUNT(ea.id) as answered_count,
                SUM(CASE WHEN ea.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                SUM(CASE WHEN ea.student_answer IS NULL AND ea.answer_text IS NULL THEN 1 ELSE 0 END) as blank_count,
                SUM(CASE WHEN ea.answer_text IS NOT NULL AND ea.essay_score IS NULL THEN 1 ELSE 0 END) as ungraded_count,
                SUM(CASE WHEN ea.student_answer = 'A' THEN 1 ELSE 0 END) as pick_a,
                SUM(CASE WHEN ea.student_answer = 'B' THEN 1 ELSE 0 END) as pick_b,
                SUM(CASE WHEN ea.student_answer = 'C' THEN 1 ELSE 0 END) as pick_c,
                SUM(CASE WHEN ea.student_answer = 'D' THEN 1 ELSE 0 END) as pick_d,
                SUM(CASE WHEN ea.student_answer = 'E' THEN 1 ELSE 0 END) as pick_e
         FROM questions q
         LEFT JOIN exam_answers ea ON ea.question_id = q.id
             AND ea.session_id IN (SELECT id FROM exam_sessions WHERE exam_id = :exam_id AND tenant_id = :tenant_id AND status = 'completed')
         WHERE q.bank_id = :bank_id
         GROUP BY q.id
         ORDER BY q.id ASC",
        ['exam_id' => $examId, 'tenant_id' => $tenantId, 'bank_id' => $selectedExam['bank_id']]
    );
}

// Cek Logo Sekolah
$logoPath = null;
if (!empty($school['logo_url'])) {
    $cleanLogo = str_replace('\\', '/', $school['logo_url']);
    $fullLogo = __DIR__ . '/../' . ltrim($cleanLogo, '/');
    if (file_exists($fullLogo)) {
        $logoPath = '../' . ltrim($cleanLogo, '/');
    }
}

// Config ID Card / Signer
$idConfigFile = __DIR__ . '/../config/idcard.php';
$idConfig = file_exists($idConfigFile) ? (require $idConfigFile) : [];
$academicYear = $idConfig['academic_year'] ?? (date('Y') . ' / ' . (date('Y') + 1));
$signerTitle  = $idConfig['signer_title'] ?? 'Kepala Sekolah';
$signerName   = $idConfig['signer_name'] ?? 'Drs. H. M. Syarifuddin, M.Pd';

function tanggalIndo(string $date): string {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $timestamp = strtotime($date);
    if (!$timestamp) return $date;
    $d = date('j', $timestamp);
    $m = (int)date('n', $timestamp);
    $y = date('Y', $timestamp);
    return "{$d} " . ($bulan[$m] ?? '') . " {$y}";
}

$docNumber = "421.5 / CBT-REKAP / " . date('Y') . " / " . str_pad((string)$examId, 4, '0', STR_PAD_LEFT);
$docHash = strtoupper(substr(hash('sha256', $examId . $tenantId . 'cbtonline_rekap'), 0, 12));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Nilai Resmi — <?= htmlspecialchars($selectedExam['title'] ?? 'CBT') ?> — <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Cinzel:wght@600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-dark: #0f172a;
            --gold: #b45309;
            --border-dark: #1e293b;
            --border-light: #cbd5e1;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #cbd5e1;
            color: var(--text-main);
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── INTERACTIVE FLOATING TOOLBAR ── */
        .no-print-toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #334155;
            color: #f8fafc;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-tool {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-print {
            background: linear-gradient(135deg, #0284c7, #2563eb);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        .btn-print:hover { background: linear-gradient(135deg, #0369a1, #1d4ed8); }

        .btn-transcript {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #ffffff;
        }
        .btn-transcript:hover { background: #047857; }

        .btn-back {
            background: #334155;
            color: #cbd5e1;
        }
        .btn-back:hover { background: #475569; color: #fff; }

        /* ── PAPER CONTAINER (A4) ── */
        .paper-viewport {
            max-width: 210mm;
            margin: 24px auto 40px;
        }

        .sheet-page {
            width: 210mm;
            min-height: 297mm;
            background: #ffffff;
            margin: 0 auto 30px;
            padding: 12mm 14mm 14mm 14mm;
            position: relative;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Certificate Double Border */
        .sheet-frame-outer {
            position: absolute;
            top: 7mm; bottom: 7mm; left: 7mm; right: 7mm;
            border: 2px solid #1e3a8a;
            pointer-events: none;
            z-index: 10;
        }

        .sheet-frame-inner {
            position: absolute;
            top: 8.5mm; bottom: 8.5mm; left: 8.5mm; right: 8.5mm;
            border: 0.8px solid #d97706;
            pointer-events: none;
            z-index: 10;
        }

        /* Watermark Background */
        .watermark-container {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 90mm; height: 90mm;
            opacity: 0.04;
            pointer-events: none;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .watermark-container img {
            max-width: 100%; max-height: 100%;
            filter: grayscale(100%);
        }

        /* ── DOKUMEN CONTENT ── */
        .doc-content {
            position: relative;
            z-index: 5;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        /* Kop Surat Resmi */
        .kop-surat {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .kop-logo {
            width: 22mm; height: 22mm;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .kop-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .kop-logo-emblem {
            width: 100%; height: 100%; border-radius: 50%;
            background: linear-gradient(135deg, #1e3a8a, #0f172a);
            color: #fef08a; border: 2px solid #d97706;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cinzel', serif; font-size: 26px; font-weight: 800;
        }

        .kop-text { flex: 1; text-align: center; }
        .kop-instansi {
            font-size: 10pt; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: #334155; line-height: 1.2;
        }
        .kop-sekolah {
            font-family: 'Cinzel', 'Plus Jakarta Sans', serif;
            font-size: 15.5pt; font-weight: 800; color: #0f172a;
            letter-spacing: 0.8px; margin: 2px 0; line-height: 1.15;
        }
        .kop-alamat { font-size: 8.5pt; color: #475569; line-height: 1.35; }
        .kop-kontak { font-size: 8pt; color: #64748b; margin-top: 1px; }

        .kop-divider { position: relative; margin-bottom: 14px; }
        .kop-line-thick { height: 2.5px; background: #0f172a; margin-bottom: 1.5px; }
        .kop-line-thin { height: 0.8px; background: #b45309; }

        /* Judul Dokumen */
        .doc-title-block { text-align: center; margin-bottom: 14px; }
        .doc-title-main {
            font-size: 12.5pt; font-weight: 800;
            text-transform: uppercase; letter-spacing: 1px; color: #0f172a;
            text-decoration: underline; text-underline-offset: 3px;
        }
        .doc-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 8.5pt; color: #475569; margin-top: 3px; font-weight: 600;
        }

        /* ── METADATA UJIAN & STATISTIK ── */
        .exam-info-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #1e3a8a;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 12px;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            row-gap: 4px;
            column-gap: 20px;
            font-size: 9pt;
        }

        .info-row { display: flex; align-items: baseline; }
        .info-label { width: 120px; color: #475569; font-weight: 600; flex-shrink: 0; }
        .info-sep { width: 14px; color: #64748b; text-align: center; flex-shrink: 0; }
        .info-val { color: #0f172a; font-weight: 700; flex: 1; }

        /* Stats Grid */
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .stat-card-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 12px;
            text-align: center;
        }
        .stat-card-box .val {
            font-size: 14pt;
            font-weight: 800;
            font-family: 'JetBrains Mono', monospace;
            color: #0f172a;
            line-height: 1.2;
        }
        .stat-card-box .lbl {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* ── TABEL REKAPITULASI HASIL ── */
        .table-rekap-wrap { margin-bottom: 14px; }
        .table-rekap {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        .table-rekap th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 6px 8px;
            border: 1px solid #0f172a;
            font-size: 8pt;
            text-align: center;
        }

        .table-rekap td {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            vertical-align: middle;
        }

        .table-rekap tbody tr:nth-child(even) { background: #f8fafc; }
        .col-center { text-align: center; }
        .col-right  { text-align: right; }
        .col-left   { text-align: left; }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            font-weight: 800;
            font-size: 8pt;
        }
        .rank-gold   { background: #fef3c7; color: #b45309; border: 1.5px solid #f59e0b; }
        .rank-silver { background: #f1f5f9; color: #475569; border: 1.5px solid #94a3b8; }
        .rank-bronze { background: #ffedd5; color: #c2410c; border: 1.5px solid #fb923c; }

        .badge-status {
            display: inline-block;
            font-size: 7.5pt;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .status-tuntas { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .status-belum  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ── SIGNATURES BLOCK ── */
        .signature-section {
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 24px;
            text-align: center;
            font-size: 8.5pt;
            padding-top: 10px;
        }

        .sign-box {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 24mm;
            position: relative;
        }

        .sign-title { color: #475569; font-weight: 600; margin-bottom: 2px; }
        .sign-space { height: 16mm; position: relative; display: flex; align-items: center; justify-content: center; }
        .sign-name { font-weight: 800; color: #0f172a; text-decoration: underline; }
        .sign-nip { font-size: 7.5pt; color: #64748b; font-family: 'JetBrains Mono', monospace; }

        .digital-stamp {
            position: absolute;
            top: 50%; left: 30%;
            transform: translate(-30%, -50%) rotate(-12deg);
            width: 25mm; height: 25mm;
            pointer-events: none; opacity: 0.85; z-index: 5;
        }

        /* ── FOOTER KEAMANAN ── */
        .doc-security-footer {
            border-top: 1px dashed #cbd5e1;
            padding-top: 5px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 7pt;
            color: #64748b;
        }

        @media print {
            body { background: #ffffff !important; color: #000000 !important; margin: 0 !important; padding: 0 !important; }
            .no-print-toolbar { display: none !important; }
            .paper-viewport { max-width: 100% !important; margin: 0 !important; }
            .sheet-page {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 10mm 12mm 12mm 12mm !important;
                height: auto !important;
                min-height: 275mm !important;
                width: 210mm !important;
            }
            .table-rekap th { background: #1e293b !important; color: #ffffff !important; }
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    </style>
</head>
<body>

    <!-- ── INTERACTIVE FLOATING TOOLBAR ── -->
    <header class="no-print-toolbar">
        <div class="toolbar-left">
            <div class="toolbar-title">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Dokumen Rekapitulasi Nilai Ujian</span>
            </div>
        </div>

        <div class="toolbar-right">
            <a href="results.php?exam_id=<?= $examId ?>" class="btn-tool btn-back">&#8592; Kembali ke Admin</a>
            <a href="student-transcript.php<?= !empty($selectedExam['class_id']) ? ('?class_id=' . (int)$selectedExam['class_id']) : '' ?>" class="btn-tool btn-transcript" target="_blank">
                📄 Cetak Transkrip Per Siswa (Multi-Materi)
            </a>
            <button type="button" class="btn-tool btn-print" onclick="window.print()">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                <span>Cetak / Simpan PDF (A4)</span>
            </button>
        </div>
    </header>

    <!-- ── CANVAS LEMBAR CETAK REKAP ── -->
    <div class="paper-viewport">
        <?php if (!$selectedExam): ?>
            <div style="background:#fff; padding:48px; border-radius:12px; text-align:center; color:#64748b;">
                Ujian tidak ditemukan.
            </div>
        <?php else: ?>
            <article class="sheet-page">
                <div class="sheet-frame-outer"></div>
                <div class="sheet-frame-inner"></div>

                <!-- Watermark Logo -->
                <div class="watermark-container">
                    <?php if ($logoPath): ?>
                        <img src="<?= htmlspecialchars($logoPath) ?>" alt="Watermark">
                    <?php else: ?>
                        <svg viewBox="0 0 100 100" fill="#1e293b" style="width: 100%; height: 100%;">
                            <path d="M50 5 L85 20 L85 50 C85 75 50 95 50 95 C50 95 15 75 15 50 L15 20 Z" />
                        </svg>
                    <?php endif; ?>
                </div>

                <div class="doc-content">
                    <!-- KOP SURAT RESMI -->
                    <div class="kop-surat">
                        <div class="kop-logo">
                            <?php if ($logoPath): ?>
                                <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo Sekolah">
                            <?php else: ?>
                                <div class="kop-logo-emblem">
                                    <?= strtoupper(substr($school['name'] ?: 'CBT', 0, 2)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="kop-text">
                            <div class="kop-instansi">PEMERINTAH PROVINSI DAERAH KHUSUS &bull; DINAS PENDIDIKAN</div>
                            <h1 class="kop-sekolah"><?= strtoupper(htmlspecialchars($school['name'] ?? 'CBT Online')) ?></h1>
                            <div class="kop-alamat"><?= htmlspecialchars($school['address']) ?> &bull; Telp: <?= htmlspecialchars($school['phone'] ?? '-') ?></div>
                            <div class="kop-kontak">NPSN / KODE: <?= htmlspecialchars($school['code']) ?> &bull; Laman CBT: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'cbt-online.sch.id') ?></div>
                        </div>
                    </div>

                    <div class="kop-divider">
                        <div class="kop-line-thick"></div>
                        <div class="kop-line-thin"></div>
                    </div>

                    <!-- JUDUL DOKUMEN -->
                    <div class="doc-title-block">
                        <div class="doc-title-main">BERITA ACARA & REKAPITULASI HASIL ASESMEN UJIAN CBT</div>
                        <div class="doc-number">Nomor : <?= $docNumber ?></div>
                    </div>

                    <!-- METADATA UJIAN -->
                    <div class="exam-info-card">
                        <div>
                            <div class="info-row">
                                <span class="info-label">Nama Ujian</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><?= htmlspecialchars($selectedExam['title']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Mata Pelajaran</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><?= htmlspecialchars($selectedExam['subject_name']) ?> (<?= htmlspecialchars($selectedExam['subject_code'] ?? 'CBT') ?>)</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Bank Soal Acuan</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><?= htmlspecialchars($selectedExam['bank_title']) ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <span class="info-label">Kelas / Rombel</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><?= htmlspecialchars($selectedExam['class_name'] ?: 'Semua Kelas') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Token & Durasi</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><code><?= htmlspecialchars($selectedExam['token']) ?></code> &bull; <?= (int)$selectedExam['duration_minutes'] ?> Menit</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Tahun Pelajaran</span>
                                <span class="info-sep">:</span>
                                <span class="info-val"><?= htmlspecialchars($academicYear) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- STATS SUMMARY CARDS -->
                    <div class="stats-summary-grid">
                        <div class="stat-card-box">
                            <div class="val" style="color: #0284c7;"><?= number_format($avgScore, 1) ?></div>
                            <div class="lbl">Nilai Rata-rata</div>
                        </div>
                        <div class="stat-card-box">
                            <div class="val" style="color: #059669;"><?= number_format($maxScore, 1) ?></div>
                            <div class="lbl">Nilai Tertinggi</div>
                        </div>
                        <div class="stat-card-box">
                            <div class="val" style="color: #dc2626;"><?= number_format($minScore, 1) ?></div>
                            <div class="lbl">Nilai Terendah</div>
                        </div>
                        <div class="stat-card-box">
                            <div class="val" style="color: #1e3a8a;"><?= count($results) ?></div>
                            <div class="lbl">Siswa Selesai (<?= $passedCount ?> Tuntas)</div>
                        </div>
                    </div>

                    <!-- TABEL REKAP HASIL SISWA -->
                    <div class="table-rekap-wrap">
                        <table class="table-rekap">
                            <thead>
                                <tr>
                                    <th style="width: 35px;">Peringkat</th>
                                    <th style="width: 65px;">NIS</th>
                                    <th class="col-left">Nama Lengkap Siswa</th>
                                    <th style="width: 80px;">Kelas</th>
                                    <th style="width: 45px;">Benar</th>
                                    <th style="width: 45px;">Salah</th>
                                    <th style="width: 45px;">Kosong</th>
                                    <th style="width: 60px;">Parsial</th>
                                    <th style="width: 60px;">Belum Dinilai</th>
                                    <th style="width: 75px;">Nilai Akhir</th>
                                    <th style="width: 85px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                    <tr>
                                        <td colspan="11" class="col-center" style="padding: 24px; color: #64748b;">
                                            Belum ada data siswa yang menyelesaikan ujian ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($results as $idx => $r):
                                        $rank = $idx + 1;
                                        $isPassed = (float)$r['score'] >= $kkm;
                                    ?>
                                        <tr>
                                            <td class="col-center">
                                                <?php if ($rank === 1): ?>
                                                    <span class="rank-badge rank-gold">1</span>
                                                <?php elseif ($rank === 2): ?>
                                                    <span class="rank-badge rank-silver">2</span>
                                                <?php elseif ($rank === 3): ?>
                                                    <span class="rank-badge rank-bronze">3</span>
                                                <?php else: ?>
                                                    <b><?= $rank ?></b>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-center"><code><?= htmlspecialchars($r['nis']) ?></code></td>
                                            <td class="col-left">
                                                <b><?= htmlspecialchars($r['student_name']) ?></b>
                                            </td>
                                            <td class="col-center"><?= htmlspecialchars($r['class_name'] ?: 'Umum') ?></td>
                                            <td class="col-center" style="color: #059669; font-weight: 700;"><?= (int)$r['correct_count'] ?></td>
                                            <td class="col-center" style="color: #dc2626; font-weight: 700;"><?= (int)$r['wrong_count'] ?></td>
                                            <td class="col-center" style="color: #64748b;"><?= (int)$r['empty_count'] ?></td>
                                            <td class="col-center" style="color: #f59e0b; font-weight: 700;"><?= (int)$r['partial_count'] > 0 ? (int)$r['partial_count'] : '—' ?></td>
                                            <td class="col-center" style="color: #d97706; font-weight: 700;"><?= (int)$r['ungraded_count'] > 0 ? (int)$r['ungraded_count'] : '—' ?></td>
                                            <td class="col-center">
                                                <b style="font-size: 9.5pt; font-family: 'JetBrains Mono', monospace; color: <?= $isPassed ? '#059669' : '#dc2626' ?>;">
                                                    <?= number_format((float)$r['score'], 1) ?>
                                                </b>
                                            </td>
                                            <td class="col-center">
                                                <span class="badge-status <?= $isPassed ? 'status-tuntas' : 'status-belum' ?>">
                                                    <?= $isPassed ? 'Tuntas' : 'Remedial' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- BLOK TANDA TANGAN -->
                    <div class="signature-section">
                        <div class="sign-box">
                            <div>
                                <div class="sign-title">Mengetahui & Memeriksa,</div>
                                <div>Proktor / Pengawas Ruang Asesmen</div>
                            </div>
                            <div class="sign-space">
                                <svg width="90" height="36" viewBox="0 0 120 40" style="opacity: 0.65;">
                                    <path d="M10,25 Q30,5 50,22 T90,15 T110,28" fill="none" stroke="#1e3a8a" stroke-width="2" stroke-linecap="round"/>
                                    <path d="M25,32 Q60,35 105,30" fill="none" stroke="#1e3a8a" stroke-width="1.5"/>
                                </svg>
                            </div>
                            <div>
                                <div class="sign-name">Proktor Asesmen CBT</div>
                                <div class="sign-nip">ID. PROKTOR-<?= $tenantId ?>-<?= date('Y') ?></div>
                            </div>
                        </div>

                        <div class="sign-box">
                            <div>
                                <div class="sign-title"><?= (explode(',', (string)($school['address'] ?? ''))[0] ?? 'Sekolah') ?: 'Sekolah' ?>, <?= tanggalIndo(date('Y-m-d')) ?></div>
                                <div>Kepala <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                            </div>
                            <div class="sign-space">
                                <svg class="digital-stamp" viewBox="0 0 100 100">
                                    <circle cx="50" cy="50" r="46" fill="none" stroke="#1d4ed8" stroke-width="2.5" stroke-dasharray="3,1.5"/>
                                    <circle cx="50" cy="50" r="41" fill="none" stroke="#1d4ed8" stroke-width="1.2"/>
                                    <path id="circlePathTop" d="M 18,50 A 32,32 0 0,1 82,50" fill="none"/>
                                    <path id="circlePathBottom" d="M 82,50 A 32,32 0 0,1 18,50" fill="none"/>
                                    <text fill="#1d4ed8" font-size="6.5" font-weight="bold" letter-spacing="1">
                                        <textPath href="#circlePathTop" startOffset="50%" text-anchor="middle">
                                            <?= strtoupper(htmlspecialchars(mb_strimwidth((string)($school['name'] ?? ''), 0, 24))) ?>
                                        </textPath>
                                    </text>
                                    <text fill="#1d4ed8" font-size="6" font-weight="bold" letter-spacing="1.2">
                                        <textPath href="#circlePathBottom" startOffset="50%" text-anchor="middle">
                                            ASESMEN RESMI CBT
                                        </textPath>
                                    </text>
                                    <circle cx="50" cy="50" r="16" fill="none" stroke="#1d4ed8" stroke-width="1"/>
                                    <text x="50" y="48" fill="#1d4ed8" font-size="7" font-weight="900" text-anchor="middle">VALID</text>
                                    <text x="50" y="56" fill="#1d4ed8" font-size="5" font-weight="bold" text-anchor="middle"><?= date('Y') ?></text>
                                </svg>

                                <svg width="100" height="42" viewBox="0 0 140 45" style="opacity: 0.85; position: relative; z-index: 6;">
                                    <path d="M15,30 Q35,8 45,28 T75,10 T115,22 Q125,28 135,18" fill="none" stroke="#0f172a" stroke-width="2.2" stroke-linecap="round"/>
                                    <path d="M30,35 Q70,38 128,32" fill="none" stroke="#0f172a" stroke-width="1.6"/>
                                </svg>
                            </div>
                            <div>
                                <div class="sign-name"><?= htmlspecialchars($signerName) ?></div>
                                <div class="sign-nip">NIP. 19740615 199903 1 004</div>
                            </div>
                        </div>
                    </div>

                    <!-- FOOTER KEAMANAN -->
                    <div class="doc-security-footer">
                        <div>
                            <b>BERITA ACARA RESMI CBT ONLINE</b> &bull; Dokumen Verifikasi Hash: <code style="font-weight:700; color:#0f172a;"><?= $docHash ?></code>
                        </div>
                        <div>
                            Dicetak: <?= date('d/m/Y H:i') ?> WIB &bull; Sistem Terakreditasi
                        </div>
                    </div>
                </div>
            </article>
        <?php endif; ?>
    </div>

</body>
</html>
