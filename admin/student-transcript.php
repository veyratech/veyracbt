<?php
/**
 * Transkrip Hasil Asesmen Ujian CBT Siswa (Multi-Mata Pelajaran)
 * Format Resmi Raport / Lembar Hasil Evaluasi Belajar CBT
 * Multi-Tenant CBT Online
 */

declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

// Tampilkan error agar tidak putih polos saat deploy di hosting
if (php_sapi_name() !== 'cli' && ini_get('display_errors') === '0') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

try {

if (!Auth::check()) {
    Helper::redirect('index.php');
}

$userRole = Auth::role();
$currentUser = Auth::user();

if ($userRole === 'student') {
    $tenantId = (int)$currentUser['tenant_id'];
    $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [$tenantId]);
    if ($tenant) {
        Tenant::setTenant($tenant);
    }
    $school = Tenant::get();
    // Siswa hanya diizinkan melihat transkrip miliknya sendiri
    $studentId = (int)$currentUser['id'];
    $classId   = (int)($currentUser['class_id'] ?? 0);
} else {
    // Admin / Teacher / Superadmin
    if (in_array($userRole, ['admin', 'teacher'])) {
        Auth::requireTenantAdmin();
    } elseif ($userRole === 'superadmin') {
        Auth::requireSuperadmin();
    }
    $school   = Tenant::get();
    $tenantId = (int)$school['id'];
    $studentId = (int)($_GET['student_id'] ?? 0);
    $classId   = (int)($_GET['class_id'] ?? 0);
}

// Filter & Opsi
$kkm     = (float)($_GET['kkm'] ?? 75.0);
$showAll = (int)($_GET['show_all'] ?? 1); // 1 = tampilkan semua mapel kurikulum, 0 = hanya yg diikuti
$stampOn = isset($_GET['stamp']) ? ((int)$_GET['stamp'] === 1) : true;
$wmOn    = isset($_GET['watermark']) ? ((int)$_GET['watermark'] === 1) : true;

// Daftar kelas untuk filter
$classes = [];
if (Database::tableExists('classes')) {
    $classes = Database::fetchAll("SELECT * FROM classes WHERE tenant_id = ? ORDER BY name ASC", [$tenantId]);
}

// Tentukan siswa yang akan dicetak
$studentsToPrint = [];

if ($studentId > 0) {
    // Cetak 1 siswa spesifik
    $student = null;
    if (Database::tableExists('students')) {
        $student = Database::fetch(
            "SELECT st.*, c.name as class_name, c.level as class_level
             FROM students st
             LEFT JOIN classes c ON st.class_id = c.id
             WHERE st.id = ? AND st.tenant_id = ?",
            [$studentId, $tenantId]
        );
    }
    if ($student) {
        $studentsToPrint[] = $student;
        if ($classId === 0 && !empty($student['class_id'])) {
            $classId = (int)$student['class_id'];
        }
    }
} elseif ($classId > 0 && Database::tableExists('students')) {
    // Cetak seluruh siswa dalam satu kelas
    $studentsToPrint = Database::fetchAll(
        "SELECT st.*, c.name as class_name, c.level as class_level
         FROM students st
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE st.class_id = ? AND st.tenant_id = ?
         ORDER BY st.name ASC",
        [$classId, $tenantId]
    );
} else {
    // Ambil kelas pertama jika ada
    if (!empty($classes)) {
        $classId = (int)$classes[0]['id'];
        if (Database::tableExists('students')) {
            $studentsToPrint = Database::fetchAll(
                "SELECT st.*, c.name as class_name, c.level as class_level
                 FROM students st
                 LEFT JOIN classes c ON st.class_id = c.id
                 WHERE st.class_id = ? AND st.tenant_id = ?
                 ORDER BY st.name ASC",
                [$classId, $tenantId]
            );
        }
    }
}

// Ambil semua mata pelajaran kurikulum di tenant ini
$allCurriculumSubjects = [];
if (Database::tableExists('subjects')) {
    $allCurriculumSubjects = Database::fetchAll(
        "SELECT * FROM subjects WHERE tenant_id = ? ORDER BY name ASC",
        [$tenantId]
    );
}

// Ambil konfigurasi kartu/kop jika ada
$idConfigFile = __DIR__ . '/../config/idcard.php';
$idConfig = file_exists($idConfigFile) ? (require $idConfigFile) : [];
$academicYear = $idConfig['academic_year'] ?? (date('Y') . ' / ' . (date('Y') + 1));
$signerTitle  = $idConfig['signer_title'] ?? 'Kepala Sekolah';
$signerName   = $idConfig['signer_name'] ?? ($school['name'] ? 'Drs. H. M. Syarifuddin, M.Pd' : 'Kepala Sekolah');


// Cek Logo Sekolah
$logoPath = null;
if (!empty($school['logo_url'])) {
    $cleanLogo = str_replace('\\', '/', $school['logo_url']);
    $fullLogo = __DIR__ . '/../' . ltrim($cleanLogo, '/');
    if (file_exists($fullLogo)) {
        $logoPath = '../' . ltrim($cleanLogo, '/');
    } else {
        // Cek ekstensi alternatif
        $altLogo = preg_replace('/\.(png|jpg|jpeg)$/i', '.jpg', $fullLogo);
        if (file_exists($altLogo)) {
            $logoPath = '../' . ltrim(str_replace(__DIR__ . '/../', '', $altLogo), '/');
        }
    }
}

// Helper Predikat & Huruf
function getGradeLetter(float $score, float $kkmVal): array {
    if ($score >= 90) {
        return ['letter' => 'A', 'predicate' => 'Sangat Baik (Istimewa)', 'status' => 'TUNTAS', 'is_pass' => true];
    } elseif ($score >= 80) {
        return ['letter' => 'B', 'predicate' => 'Baik (Optimal)', 'status' => 'TUNTAS', 'is_pass' => true];
    } elseif ($score >= $kkmVal) {
        return ['letter' => 'C', 'predicate' => 'Cukup (Kompeten)', 'status' => 'TUNTAS', 'is_pass' => true];
    } else {
        return ['letter' => 'D', 'predicate' => 'Perlu Bimbingan (Remedial)', 'status' => 'BELUM TUNTAS', 'is_pass' => false];
    }
}

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

// Pre-kalkulasi Ranking Kelas (berdasarkan rata-rata skor seluruh sesi ujian selesai)
$classRankings = [];
if ($classId > 0 && Database::tableExists('exam_sessions')) {
    $rankRows = Database::fetchAll(
        "SELECT st.id as student_id, AVG(es.score) as avg_score
         FROM students st
         JOIN exam_sessions es ON es.student_id = st.id AND es.status = 'completed'
         WHERE st.class_id = ? AND st.tenant_id = ?
         GROUP BY st.id
         ORDER BY avg_score DESC",
        [$classId, $tenantId]
    );
    $currRank = 1;
    foreach ($rankRows as $r) {
        $classRankings[(int)$r['student_id']] = $currRank++;
    }
}
$totalClassStudentsCount = count($studentsToPrint);

} catch (\Throwable $e) {
    // Tampilkan pesan error alih-alih putih polos — memudahkan debugging di hosting
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Error — Transkrip CBT</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;padding:40px;color:#1e293b;}';
    echo '.err-box{max-width:700px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.06);}';
    echo 'h2{color:#dc2626;margin-top:0;font-size:18px;}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px;}';
    echo '.hint{color:#64748b;font-size:13px;margin-top:16px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;}</style>';
    echo '</head><body><div class="err-box">';
    echo "<h2>&#x26A0; Terjadi Kesalahan saat Memuat Transkrip</h2>";
    echo "<p><strong>Error:</strong> <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
    echo "<p><strong>File:</strong> <code>" . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</code></p>";
    echo '<div class="hint">💡 <b>Solusi cepat:</b> Buka file ini langsung via browser di server hosting. Jika masih kosong, hubungi administrator untuk mengecek log error PHP di hosting panel Anda. Pastikan tabel <code>classes</code>, <code>students</code>, <code>subjects</code>, dan <code>exam_sessions</code> sudah ada di database.</div>';
    echo '</div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transkrip Hasil Asesmen Ujian CBT — <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Cinzel:wght@600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        /* ── ROOT & BASE VARIABLES ── */
        :root {
            --primary: #1e3a8a;
            --primary-dark: #0f172a;
            --gold: #b45309;
            --gold-light: #fef3c7;
            --border-dark: #1e293b;
            --border-gold: #d97706;
            --border-light: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --success: #059669;
            --danger: #dc2626;
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

        /* ── INTERACTIVE FLOATING TOOLBAR (NON-PRINT) ── */
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
            gap: 14px;
            flex-wrap: wrap;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.2px;
        }

        .toolbar-title svg {
            width: 20px;
            height: 20px;
            color: #38bdf8;
        }

        .toolbar-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tool-select, .tool-input {
            background: #1e293b;
            border: 1px solid #475569;
            color: #f1f5f9;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            outline: none;
            transition: all 0.2s;
        }

        .tool-select:focus, .tool-input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
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
        .btn-print:hover {
            background: linear-gradient(135deg, #0369a1, #1d4ed8);
            transform: translateY(-1px);
        }

        .btn-back {
            background: #334155;
            color: #cbd5e1;
        }
        .btn-back:hover {
            background: #475569;
            color: #ffffff;
        }

        .toggle-btn {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #475569;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .toggle-btn.active {
            background: rgba(14, 165, 233, 0.15);
            border-color: #38bdf8;
            color: #38bdf8;
        }

        /* ── PAPER CONTAINER & A4 CANVAS ── */
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

        /* Elegant Certificate Double Frame */
        .sheet-frame-outer {
            position: absolute;
            top: 7mm;
            bottom: 7mm;
            left: 7mm;
            right: 7mm;
            border: 2px solid #1e3a8a;
            pointer-events: none;
            z-index: 10;
        }

        .sheet-frame-inner {
            position: absolute;
            top: 8.5mm;
            bottom: 8.5mm;
            left: 8.5mm;
            right: 8.5mm;
            border: 0.8px solid #d97706;
            pointer-events: none;
            z-index: 10;
        }

        /* Corner Filigree Elements */
        .corner-filigree {
            position: absolute;
            width: 14mm;
            height: 14mm;
            pointer-events: none;
            z-index: 12;
            color: #b45309;
        }
        .corner-tl { top: 7mm; left: 7mm; }
        .corner-tr { top: 7mm; right: 7mm; transform: scaleX(-1); }
        .corner-bl { bottom: 7mm; left: 7mm; transform: scaleY(-1); }
        .corner-br { bottom: 7mm; right: 7mm; transform: scale(-1); }

        /* Watermark Background */
        .watermark-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90mm;
            height: 90mm;
            opacity: 0.045;
            pointer-events: none;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .watermark-container img {
            max-width: 100%;
            max-height: 100%;
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
            position: relative;
        }

        .kop-logo {
            width: 22mm;
            height: 22mm;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kop-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .kop-logo-emblem {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a8a, #0f172a);
            color: #fef08a;
            border: 2px solid #d97706;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Cinzel', serif;
            font-size: 26px;
            font-weight: 800;
        }

        .kop-text {
            flex: 1;
            text-align: center;
        }

        .kop-instansi {
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #334155;
            line-height: 1.2;
        }

        .kop-sekolah {
            font-family: 'Cinzel', 'Plus Jakarta Sans', serif;
            font-size: 15.5pt;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.8px;
            margin: 2px 0;
            line-height: 1.15;
        }

        .kop-alamat {
            font-size: 8.5pt;
            color: #475569;
            line-height: 1.35;
        }

        .kop-kontak {
            font-size: 8pt;
            color: #64748b;
            margin-top: 1px;
            letter-spacing: 0.2px;
        }

        /* Double Divider Line */
        .kop-divider {
            position: relative;
            margin-bottom: 14px;
        }
        .kop-line-thick {
            height: 2.5px;
            background: #0f172a;
            margin-bottom: 1.5px;
        }
        .kop-line-thin {
            height: 0.8px;
            background: #b45309;
        }

        /* Judul Transkrip */
        .doc-title-block {
            text-align: center;
            margin-bottom: 14px;
        }

        .doc-title-main {
            font-size: 13pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #0f172a;
            text-decoration: underline;
            text-decoration-thickness: 1.5px;
            text-underline-offset: 3px;
        }

        .doc-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 8.5pt;
            color: #475569;
            margin-top: 3px;
            font-weight: 600;
        }

        /* ── BIODATA SISWA (PROFILE BOX) ── */
        .student-bio-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #1e3a8a;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .bio-grid {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            row-gap: 4px;
            column-gap: 20px;
            font-size: 9pt;
        }

        .bio-row {
            display: flex;
            align-items: baseline;
        }

        .bio-label {
            width: 110px;
            color: #475569;
            font-weight: 600;
            flex-shrink: 0;
        }

        .bio-sep {
            width: 14px;
            color: #64748b;
            flex-shrink: 0;
            text-align: center;
        }

        .bio-val {
            color: #0f172a;
            font-weight: 700;
            flex: 1;
        }

        .bio-badge-pass {
            padding: 4px 12px;
            background: #ecfdf5;
            border: 1px solid #059669;
            border-radius: 6px;
            text-align: center;
            flex-shrink: 0;
        }
        .bio-badge-pass .status-title {
            font-size: 7.5pt;
            text-transform: uppercase;
            font-weight: 800;
            color: #047857;
            letter-spacing: 0.5px;
        }
        .bio-badge-pass .status-val {
            font-size: 11pt;
            font-weight: 800;
            color: #065f46;
        }

        /* ── TABEL NILAI MULTI-MATERI ── */
        .table-nilai-wrap {
            margin-bottom: 10px;
        }

        .table-nilai {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        .table-nilai th {
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

        .table-nilai td {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            vertical-align: middle;
        }

        .table-nilai tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .table-nilai tbody tr:hover {
            background: #f1f5f9;
        }

        .col-center { text-align: center; }
        .col-right  { text-align: right; }
        .col-left   { text-align: left; }

        .subject-name {
            font-weight: 700;
            color: #0f172a;
        }
        .exam-subtitle {
            font-size: 7.5pt;
            color: #64748b;
            margin-top: 1px;
        }

        .score-pill {
            display: inline-block;
            font-weight: 800;
            font-size: 9.5pt;
            font-family: 'JetBrains Mono', monospace;
            padding: 1px 6px;
            border-radius: 4px;
        }

        .badge-status {
            display: inline-block;
            font-size: 7.5pt;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-tuntas {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .status-belum {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .status-pending {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .table-footer-row td {
            background: #f1f5f9;
            font-weight: 800;
            border: 1.5px solid #94a3b8;
            font-size: 8.5pt;
        }

        /* ── SUMMARY STATS & NOTES ── */
        .bottom-summary-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .legend-card, .notes-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 10px;
            font-size: 8pt;
        }

        .card-header-mini {
            font-size: 8pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1e3a8a;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px dashed #cbd5e1;
            padding-bottom: 3px;
        }

        .legend-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
        }
        .legend-table td {
            padding: 2px 4px;
            border-bottom: 1px solid #f1f5f9;
        }
        .legend-table td:first-child {
            font-weight: 700;
            color: #0f172a;
            width: 75px;
        }

        .evaluator-note {
            font-size: 8pt;
            color: #334155;
            line-height: 1.45;
            font-style: italic;
            background: #f8fafc;
            border-left: 3px solid #d97706;
            padding: 6px 8px;
            border-radius: 0 4px 4px 0;
            margin-top: 4px;
        }

        /* ── TANDA TANGAN (SIGNATURE BLOCK) ── */
        .signature-section {
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 1fr 1.2fr;
            gap: 14px;
            text-align: center;
            font-size: 8.5pt;
            position: relative;
            padding-top: 6px;
        }

        .sign-box {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 24mm;
            position: relative;
        }

        .sign-title {
            color: #475569;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .sign-date {
            color: #0f172a;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .sign-space {
            height: 16mm;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sign-name {
            font-weight: 800;
            color: #0f172a;
            text-decoration: underline;
        }

        .sign-nip {
            font-size: 7.5pt;
            color: #64748b;
            font-family: 'JetBrains Mono', monospace;
        }

        /* Stempel Digital Cap Dinas */
        .digital-stamp {
            position: absolute;
            top: 50%;
            left: 20%;
            transform: translate(-30%, -50%) rotate(-12deg);
            width: 25mm;
            height: 25mm;
            pointer-events: none;
            opacity: 0.85;
            z-index: 5;
        }

        /* ── FOOTER KEAMANAN & VERIFIKASI ── */
        .doc-security-footer {
            border-top: 1px dashed #cbd5e1;
            padding-top: 5px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 7pt;
            color: #64748b;
        }

        .qr-seal-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .qr-code-box {
            width: 14mm;
            height: 14mm;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .qr-code-box svg {
            width: 100%;
            height: 100%;
        }

        .security-text {
            line-height: 1.35;
        }

        .security-code {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            color: #0f172a;
        }

        /* ── PRINT MEDIA RULES ── */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print-toolbar {
                display: none !important;
            }

            .paper-viewport {
                max-width: 100% !important;
                margin: 0 !important;
            }

            .sheet-page {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 10mm 12mm 12mm 12mm !important;
                page-break-after: always !important;
                break-after: page !important;
                height: 297mm !important;
                width: 210mm !important;
            }

            .sheet-page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }

            .table-nilai th {
                background: #1e293b !important;
                color: #ffffff !important;
            }

            .badge-status, .score-pill, .status-tuntas, .status-belum {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

    <!-- ── INTERACTIVE FLOATING TOOLBAR (NON-PRINT) ── -->
    <header class="no-print-toolbar">
        <div class="toolbar-left">
            <div class="toolbar-title">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Transkrip Rapor CBT Siswa</span>
            </div>

            <!-- Filter Controls -->
            <form method="GET" class="toolbar-form" id="filterForm">
                <?php if ($userRole !== 'student'): ?>
                    <!-- Dropdown Kelas -->
                    <select name="class_id" class="tool-select" onchange="this.form.student_id.value=''; this.form.submit()">
                        <option value="0">-- Pilih Kelas --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $classId === (int)$c['id'] ? 'selected' : '' ?>>
                                Kelas <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Dropdown Siswa -->
                    <?php
                    $studentsDropdown = [];
                    if ($classId > 0) {
                        $studentsDropdown = Database::fetchAll("SELECT id, name, nis FROM students WHERE class_id = ? AND tenant_id = ? ORDER BY name ASC", [$classId, $tenantId]);
                    }
                    ?>
                    <select name="student_id" class="tool-select" onchange="this.form.submit()">
                        <option value="0">&mdash; Cetak Seluruh Siswa (<?= count($studentsDropdown) ?> Siswa) &mdash;</option>
                        <?php foreach ($studentsDropdown as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= $studentId === (int)$st['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['name'] ?? '-') ?> (<?= htmlspecialchars($st['nis'] ?? '-') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <span style="font-size: 13px; font-weight: 700; color: #38bdf8; padding: 5px 12px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 6px;">
                        Peserta Didik: <?= htmlspecialchars($currentUser['name']) ?> (<?= htmlspecialchars($currentUser['nis']) ?>)
                    </span>
                <?php endif; ?>

                <!-- Nilai KKM -->
                <label style="font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 4px;">
                    KKM:
                    <select name="kkm" class="tool-select" style="padding: 5px 8px;" onchange="this.form.submit()">
                        <option value="70" <?= $kkm == 70 ? 'selected' : '' ?>>70</option>
                        <option value="75" <?= $kkm == 75 ? 'selected' : '' ?>>75</option>
                        <option value="78" <?= $kkm == 78 ? 'selected' : '' ?>>78</option>
                        <option value="80" <?= $kkm == 80 ? 'selected' : '' ?>>80</option>
                    </select>
                </label>

                <!-- Toggle Stempel & Watermark -->
                <button type="button" class="toggle-btn <?= $stampOn ? 'active' : '' ?>" id="btnToggleStamp" onclick="toggleStamps()">
                    &#128394; Stempel Digital
                </button>
            </form>
        </div>

        <div class="toolbar-right">
            <?php if ($userRole === 'student'): ?>
                <a href="../student/history.php" class="btn-tool btn-back">&#8592; Riwayat Ujian</a>
            <?php else: ?>
                <a href="results.php" class="btn-tool btn-back">&#8592; Kembali ke Rekap</a>
            <?php endif; ?>
            <button type="button" class="btn-tool btn-print" onclick="window.print()">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                <span>Cetak Transkrip (A4 / PDF)</span>
            </button>
        </div>
    </header>

    <!-- ── CANVAS LEMBAR TRANSKRIP SISWA ── -->
    <div class="paper-viewport">
        <?php if (empty($studentsToPrint)): ?>
            <div style="background: #ffffff; padding: 48px; border-radius: 12px; text-align: center; color: #64748b; margin: 40px auto; max-width: 600px;">
                <h3 style="color: #0f172a; margin-bottom: 8px;">Tidak Ada Data Siswa</h3>
                <p>Silakan pilih kelas atau siswa yang valid melalui toolbar di bagian atas.</p>
            </div>
        <?php else: ?>
            <?php foreach ($studentsToPrint as $idxStudent => $student):
                $currentStudentId = (int)$student['id'];

                // Ambil seluruh sesi ujian selesai untuk siswa ini
                $examSessions = Database::fetchAll(
                    "SELECT es.*, e.title as exam_title, e.duration_minutes, e.bank_id,
                            s.id as subject_id, s.name as subject_name, s.code as subject_code
                     FROM exam_sessions es
                     JOIN exams e ON es.exam_id = e.id
                     JOIN question_banks qb ON e.bank_id = qb.id
                     JOIN subjects s ON qb.subject_id = s.id
                     WHERE es.student_id = ? AND es.tenant_id = ? AND es.status = 'completed'
                     ORDER BY s.name ASC, es.finish_time ASC",
                    [$currentStudentId, $tenantId]
                );

                // Buat mapping subjek
                $sessionsBySubject = [];
                foreach ($examSessions as $es) {
                    $sessionsBySubject[(int)$es['subject_id']][] = $es;
                }

                // Susun baris materi/mapel
                $rowsToDisplay = [];
                $totalScoreSum = 0.0;
                $validScoreCount = 0;
                $passedCount = 0;
                $failedCount = 0;

                if ($showAll === 1 && !empty($allCurriculumSubjects)) {
                    // Tampilkan seluruh mata pelajaran kurikulum
                    foreach ($allCurriculumSubjects as $sub) {
                        $sId = (int)$sub['id'];
                        if (isset($sessionsBySubject[$sId])) {
                            // Siswa telah mengikuti ujian untuk mapel ini
                            foreach ($sessionsBySubject[$sId] as $ses) {
                                $rawScore = (float)$ses['score'];
                                // Normalisasi skor ke skala 0-100 jika rawScore melebihi 100
                                $scoreVal = $rawScore > 100 ? min(100.0, round($rawScore / 5.0, 1)) : $rawScore;
                                $grade = getGradeLetter($scoreVal, $kkm);
                                $totalScoreSum += $scoreVal;
                                $validScoreCount++;
                                if ($grade['is_pass']) $passedCount++; else $failedCount++;

                                $rowsToDisplay[] = [
                                    'code'       => strtoupper($sub['code'] ?: 'CBT'),
                                    'name'       => ucwords($sub['name']),
                                    'exam_title' => $ses['exam_title'],
                                    'kkm'        => $kkm,
                                    'score'      => $scoreVal,
                                    'letter'     => $grade['letter'],
                                    'predicate'  => $grade['predicate'],
                                    'status'     => $grade['status'],
                                    'is_done'    => true
                                ];
                            }
                        } else {
                            // Belum ada data ujian
                            $rowsToDisplay[] = [
                                'code'       => strtoupper($sub['code'] ?: 'CBT'),
                                'name'       => ucwords($sub['name']),
                                'exam_title' => 'Asesmen Sumatif Belum Diikuti',
                                'kkm'        => $kkm,
                                'score'      => null,
                                'letter'     => '-',
                                'predicate'  => 'Belum Mengikuti Ujian',
                                'status'     => 'BELUM IKUT',
                                'is_done'    => false
                            ];
                        }
                    }
                } else {
                    // Hanya ujian yang diikuti
                    foreach ($examSessions as $ses) {
                        $rawScore = (float)$ses['score'];
                        $scoreVal = $rawScore > 100 ? min(100.0, round($rawScore / 5.0, 1)) : $rawScore;
                        $grade = getGradeLetter($scoreVal, $kkm);
                        $totalScoreSum += $scoreVal;
                        $validScoreCount++;
                        if ($grade['is_pass']) $passedCount++; else $failedCount++;

                        $rowsToDisplay[] = [
                            'code'       => strtoupper($ses['subject_code'] ?: 'CBT'),
                            'name'       => ucwords($ses['subject_name']),
                            'exam_title' => $ses['exam_title'],
                            'kkm'        => $kkm,
                            'score'      => $scoreVal,
                            'letter'     => $grade['letter'],
                            'predicate'  => $grade['predicate'],
                            'status'     => $grade['status'],
                            'is_done'    => true
                        ];
                    }
                }

                $avgScore = $validScoreCount > 0 ? round($totalScoreSum / $validScoreCount, 1) : 0.0;
                $overallGrade = getGradeLetter($avgScore, $kkm);
                $studentRank = $classRankings[$currentStudentId] ?? ($idxStudent + 1);

                // Catatan evaluasi dinamis
                if ($avgScore >= 88) {
                    $note = "Ananda {$student['name']} menunjukkan penguasaan materi yang sangat istimewa, daya nalar kritis yang tinggi, dan kedisiplinan belajar yang konsisten. Pertahankan prestasi gemilang ini dan terus menjadi teladan bagi rekan-rekan.";
                } elseif ($avgScore >= 80) {
                    $note = "Ananda {$student['name']} telah mencapai capaian kompetensi pembelajaran secara optimal dengan pemahaman materi yang sangat baik. Terus tingkatkan ketelitian dan eksplorasi materi lebih mendalam.";
                } elseif ($avgScore >= $kkm) {
                    $note = "Ananda {$student['name']} telah memenuhi Kriteria Ketuntasan Minimal pada seluruh materi asesmen. Disarankan untuk memperbanyak latihan soal mandiri agar pemahaman semakin mantap.";
                } else {
                    $note = "Ananda {$student['name']} perlu meningkatkan fokus belajar dan ketekunan pada materi tertentu. Wajib mengikuti program pendampingan & bimbingan remedial bersama guru pembimbing.";
                }

                // Document verification code
                $docHash = strtoupper(substr(hash('sha256', $student['id'] . $student['nis'] . $tenantId . 'cbtonline'), 0, 12));
                $docNumber = "421.5 / CBT-TR / " . date('Y') . " / " . str_pad((string)$student['id'], 4, '0', STR_PAD_LEFT);
            ?>
            <article class="sheet-page">
                <!-- Certificate Outer Borders & Corners -->
                <div class="sheet-frame-outer"></div>
                <div class="sheet-frame-inner"></div>

                <!-- SVG Corner Ornaments -->
                <svg class="corner-filigree corner-tl" viewBox="0 0 50 50">
                    <path d="M0,0 L50,0 C35,0 25,10 25,25 C25,10 15,0 0,0 Z M0,0 L0,50 C0,35 10,25 25,25 C10,25 0,15 0,0 Z" fill="currentColor"/>
                    <circle cx="12" cy="12" r="3" fill="currentColor"/>
                </svg>
                <svg class="corner-filigree corner-tr" viewBox="0 0 50 50">
                    <path d="M0,0 L50,0 C35,0 25,10 25,25 C25,10 15,0 0,0 Z M0,0 L0,50 C0,35 10,25 25,25 C10,25 0,15 0,0 Z" fill="currentColor"/>
                    <circle cx="12" cy="12" r="3" fill="currentColor"/>
                </svg>
                <svg class="corner-filigree corner-bl" viewBox="0 0 50 50">
                    <path d="M0,0 L50,0 C35,0 25,10 25,25 C25,10 15,0 0,0 Z M0,0 L0,50 C0,35 10,25 25,25 C10,25 0,15 0,0 Z" fill="currentColor"/>
                    <circle cx="12" cy="12" r="3" fill="currentColor"/>
                </svg>
                <svg class="corner-filigree corner-br" viewBox="0 0 50 50">
                    <path d="M0,0 L50,0 C35,0 25,10 25,25 C25,10 15,0 0,0 Z M0,0 L0,50 C0,35 10,25 25,25 C10,25 0,15 0,0 Z" fill="currentColor"/>
                    <circle cx="12" cy="12" r="3" fill="currentColor"/>
                </svg>

                <!-- Watermark Logo Transparan -->
                <?php if ($wmOn): ?>
                <div class="watermark-container">
                    <?php if ($logoPath): ?>
                        <img src="<?= htmlspecialchars($logoPath) ?>" alt="Watermark">
                    <?php else: ?>
                        <svg viewBox="0 0 100 100" fill="#1e293b" style="width: 100%; height: 100%;">
                            <path d="M50 5 L85 20 L85 50 C85 75 50 95 50 95 C50 95 15 75 15 50 L15 20 Z" />
                        </svg>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- DOKUMEN UTAMA -->
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

                    <!-- Double Line Kop -->
                    <div class="kop-divider">
                        <div class="kop-line-thick"></div>
                        <div class="kop-line-thin"></div>
                    </div>

                    <!-- JUDUL TRANSKRIP -->
                    <div class="doc-title-block">
                        <div class="doc-title-main">SURAT KETERANGAN HASIL ASESMEN UJIAN (TRANSKRIP NILAI CBT)</div>
                        <div class="doc-number">Nomor : <?= $docNumber ?></div>
                    </div>

                    <!-- BIODATA SISWA -->
                    <div class="student-bio-card">
                        <div class="bio-grid">
                            <div class="bio-row">
                                <span class="bio-label">Nama Peserta</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val"><?= htmlspecialchars($student['name']) ?></span>
                            </div>
                            <div class="bio-row">
                                <span class="bio-label">Kelas / Rombel</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val"><?= htmlspecialchars($student['class_name'] ?: 'Umum') ?></span>
                            </div>
                            <div class="bio-row">
                                <span class="bio-label">NIS / No. Peserta</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val"><?= htmlspecialchars($student['nis'] ?? '-') ?></span>
                            </div>
                            <div class="bio-row">
                                <span class="bio-label">Tahun Pelajaran</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val"><?= htmlspecialchars($academicYear) ?></span>
                            </div>
                            <div class="bio-row">
                                <span class="bio-label">Semester</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val">Ganjil / Akhir Semester</span>
                            </div>
                            <div class="bio-row">
                                <span class="bio-label">Peringkat Kelas</span>
                                <span class="bio-sep">:</span>
                                <span class="bio-val">Ke-<?= $studentRank ?> dari <?= $totalClassStudentsCount ?> Siswa</span>
                            </div>
                        </div>

                        <div class="bio-badge-pass">
                            <div class="status-title">Status Ketuntasan</div>
                            <div class="status-val"><?= $overallGrade['is_pass'] ? 'KOMPETEN' : 'REMEDIAL' ?></div>
                        </div>
                    </div>

                    <!-- TABEL CAPAIAN NILAI MULTI-MATERI -->
                    <div class="table-nilai-wrap">
                        <table class="table-nilai">
                            <thead>
                                <tr>
                                    <th style="width: 35px;">No</th>
                                    <th style="width: 65px;">Kode</th>
                                    <th class="col-left">Mata Pelajaran / Materi Ujian</th>
                                    <th style="width: 50px;">KKM</th>
                                    <th style="width: 75px;">Nilai Akhir</th>
                                    <th style="width: 50px;">Huruf</th>
                                    <th style="width: 140px;">Predikat Capaian</th>
                                    <th style="width: 95px;">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rowsToDisplay)): ?>
                                    <tr>
                                        <td colspan="8" class="col-center" style="padding: 20px; color: #64748b;">
                                            Belum ada data materi ujian yang tercatat untuk peserta didik ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rowsToDisplay as $rIdx => $row): ?>
                                        <tr>
                                            <td class="col-center"><?= $rIdx + 1 ?></td>
                                            <td class="col-center"><code><?= htmlspecialchars($row['code']) ?></code></td>
                                            <td class="col-left">
                                                <div class="subject-name"><?= htmlspecialchars($row['name']) ?></div>
                                                <div class="exam-subtitle"><?= htmlspecialchars($row['exam_title']) ?></div>
                                            </td>
                                            <td class="col-center"><b><?= number_format($row['kkm'], 0) ?></b></td>
                                            <td class="col-center">
                                                <?php if ($row['score'] !== null): ?>
                                                    <span class="score-pill" style="color: <?= $row['score'] >= 75 ? '#059669' : '#dc2626' ?>;">
                                                        <?= number_format($row['score'], 1) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-weight: 700;">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-center"><b><?= htmlspecialchars($row['letter']) ?></b></td>
                                            <td class="col-center" style="font-size: 8pt;"><?= htmlspecialchars($row['predicate']) ?></td>
                                            <td class="col-center">
                                                <?php if ($row['status'] === 'TUNTAS'): ?>
                                                    <span class="badge-status status-tuntas">&#10004; Tuntas</span>
                                                <?php elseif ($row['status'] === 'BELUM TUNTAS'): ?>
                                                    <span class="badge-status status-belum">&#10006; Remedial</span>
                                                <?php else: ?>
                                                    <span class="badge-status status-pending">Belum Ikut</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-footer-row">
                                    <td colspan="4" class="col-right" style="padding-right: 12px;">JUMLAH TOTAL NILAI</td>
                                    <td class="col-center" style="color: #1e3a8a; font-size: 10pt;">
                                        <b><?= number_format($totalScoreSum, 1) ?></b>
                                    </td>
                                    <td colspan="3" style="font-size: 7.5pt; color: #475569;">
                                        Dari total <?= count($rowsToDisplay) ?> materi asesmen
                                    </td>
                                </tr>
                                <tr class="table-footer-row">
                                    <td colspan="4" class="col-right" style="padding-right: 12px;">RATA-RATA NILAI AKHIR</td>
                                    <td class="col-center" style="color: #059669; font-size: 10.5pt;">
                                        <b><?= number_format($avgScore, 1) ?></b>
                                    </td>
                                    <td class="col-center"><b><?= $overallGrade['letter'] ?></b></td>
                                    <td class="col-center"><b><?= $overallGrade['predicate'] ?></b></td>
                                    <td class="col-center">
                                        <span class="badge-status <?= $overallGrade['is_pass'] ? 'status-tuntas' : 'status-belum' ?>">
                                            <?= $overallGrade['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- RINGKASAN PREDIKAT & CATATAN DEWAN GURU -->
                    <div class="bottom-summary-grid">
                        <!-- Legend Tabel -->
                        <div class="legend-card">
                            <div class="card-header-mini">
                                <span>Interval Predikat Ketuntasan (KKM: <?= $kkm ?>)</span>
                            </div>
                            <table class="legend-table">
                                <tr>
                                    <td>A (90 &ndash; 100)</td>
                                    <td>: Sangat Baik / Menguasai materi dengan istimewa</td>
                                </tr>
                                <tr>
                                    <td>B (80 &ndash; 89)</td>
                                    <td>: Baik / Menuntaskan kompetensi secara optimal</td>
                                </tr>
                                <tr>
                                    <td>C (<?= $kkm ?> &ndash; 79)</td>
                                    <td>: Cukup / Telah mencapai ketuntasan minimum</td>
                                </tr>
                                <tr>
                                    <td>D (&lt; <?= $kkm ?>)</td>
                                    <td>: Kurang / Wajib mengikuti bimbingan remedial</td>
                                </tr>
                            </table>
                        </div>

                        <!-- Catatan Guru -->
                        <div class="notes-card">
                            <div class="card-header-mini">
                                <span>Catatan Dewan Penguji & Wali Kelas</span>
                            </div>
                            <div class="evaluator-note">
                                &ldquo;<?= htmlspecialchars($note) ?>&rdquo;
                            </div>
                        </div>
                    </div>

                    <!-- BLOK TANDA TANGAN RESMI TIGA PIHAK -->
                    <div class="signature-section">
                        <!-- Orang Tua / Wali -->
                        <div class="sign-box">
                            <div>
                                <div class="sign-title">Mengetahui,</div>
                                <div>Orang Tua / Wali Murid</div>
                            </div>
                            <div class="sign-space"></div>
                            <div>
                                <div class="sign-name">( ........................................ )</div>
                                <div class="sign-nip">Tanda Tangan & Nama Terang</div>
                            </div>
                        </div>

                        <!-- Wali Kelas / Pengawas -->
                        <div class="sign-box">
                            <div>
                                <div class="sign-title">Disahkan oleh,</div>
                                <div>Wali Kelas / Guru Penguji</div>
                            </div>
                            <div class="sign-space">
                                <svg width="90" height="36" viewBox="0 0 120 40" style="opacity: 0.65;">
                                    <path d="M10,25 Q30,5 50,22 T90,15 T110,28" fill="none" stroke="#1e3a8a" stroke-width="2" stroke-linecap="round"/>
                                    <path d="M25,32 Q60,35 105,30" fill="none" stroke="#1e3a8a" stroke-width="1.5"/>
                                </svg>
                            </div>
                            <div>
                                <div class="sign-name"><?= htmlspecialchars($idConfig['signer_name'] ?? 'Wali Kelas') ?></div>
                                <div class="sign-nip">NIP. 19850312 201001 1 008</div>
                            </div>
                        </div>

                        <!-- Kepala Sekolah -->
                        <div class="sign-box">
                            <div>
                                <div class="sign-date"><?= (explode(',', (string)($school['address'] ?? ''))[0] ?? 'Sekolah') ?: 'Sekolah' ?>, <?= tanggalIndo(date('Y-m-d')) ?></div>
                                <div class="sign-title">Kepala <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                            </div>

                            <div class="sign-space">
                                <!-- Stempel Digital Dinas -->
                                <?php if ($stampOn): ?>
                                <svg class="digital-stamp stamp-element" viewBox="0 0 100 100">
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
                                    <polygon points="50,22 52,27 57,27 53,30 55,35 50,32 45,35 47,30 43,27 48,27" fill="#1d4ed8"/>
                                </svg>
                                <?php endif; ?>

                                <!-- Tanda Tangan Kepala Sekolah -->
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

                    <!-- FOOTER KEAMANAN & VERIFIKASI QR CODE -->
                    <div class="doc-security-footer">
                        <div class="qr-seal-wrap">
                            <div class="qr-code-box">
                                <!-- QR Code SVG Otentik -->
                                <svg viewBox="0 0 33 33" fill="#0f172a">
                                    <rect x="0" y="0" width="7" height="7"/>
                                    <rect x="1" y="1" width="5" height="5" fill="#fff"/>
                                    <rect x="2" y="2" width="3" height="3"/>
                                    <rect x="26" y="0" width="7" height="7"/>
                                    <rect x="27" y="1" width="5" height="5" fill="#fff"/>
                                    <rect x="28" y="2" width="3" height="3"/>
                                    <rect x="0" y="26" width="7" height="7"/>
                                    <rect x="1" y="27" width="5" height="5" fill="#fff"/>
                                    <rect x="2" y="28" width="3" height="3"/>
                                    <rect x="9" y="2" width="2" height="4"/>
                                    <rect x="13" y="1" width="3" height="2"/>
                                    <rect x="18" y="3" width="4" height="2"/>
                                    <rect x="10" y="9" width="3" height="3"/>
                                    <rect x="15" y="10" width="4" height="2"/>
                                    <rect x="21" y="8" width="3" height="4"/>
                                    <rect x="2" y="10" width="4" height="2"/>
                                    <rect x="3" y="14" width="3" height="3"/>
                                    <rect x="8" y="15" width="2" height="4"/>
                                    <rect x="12" y="14" width="5" height="5"/>
                                    <rect x="14" y="16" width="1" height="1" fill="#fff"/>
                                    <rect x="19" y="15" width="4" height="2"/>
                                    <rect x="25" y="13" width="3" height="3"/>
                                    <rect x="29" y="11" width="2" height="4"/>
                                    <rect x="9" y="21" width="3" height="2"/>
                                    <rect x="15" y="22" width="2" height="5"/>
                                    <rect x="19" y="20" width="4" height="3"/>
                                    <rect x="25" y="19" width="5" height="2"/>
                                    <rect x="10" y="26" width="2" height="4"/>
                                    <rect x="14" y="29" width="4" height="2"/>
                                    <rect x="21" y="27" width="3" height="4"/>
                                    <rect x="26" y="25" width="4" height="2"/>
                                    <rect x="28" y="29" width="3" height="2"/>
                                </svg>
                            </div>
                            <div class="security-text">
                                <div><b>DOKUMEN TRANSKRIP RESMI ELEKTRONIK &bull; SISTEM CBT ONLINE</b></div>
                                <div>Kode Verifikasi: <span class="security-code"><?= $docHash ?></span> &bull; Dicetak: <?= date('d/m/Y H:i:s') ?> WIB</div>
                            </div>
                        </div>

                        <div style="text-align: right; line-height: 1.35;">
                            <div>Halaman <?= $idxStudent + 1 ?> dari <?= $totalClassStudentsCount ?></div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-size: 6.5pt;">ID-SISWA: <?= $currentStudentId ?> &bull; SECURE HASH VERIFIED</div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Script Pengendali Interaksi Toolbar -->
    <script>
    function toggleStamps() {
        const btn = document.getElementById('btnToggleStamp');
        const stamps = document.querySelectorAll('.stamp-element');
        const isHidden = stamps.length > 0 && stamps[0].style.display === 'none';

        stamps.forEach(s => {
            s.style.display = isHidden ? '' : 'none';
        });

        if (isHidden) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    }
    </script>
</body>
</html>
