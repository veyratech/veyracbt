<?php
/**
 * Cetak Kartu Peserta Ujian Siswa (Exam Cards Generator)
 * Multi-Tenant CBT Online — Feature Gated for PRO & ENTERPRISE
 * Desain Template Utama Modern Violet / Lanyard Style (Sesuai Referensi)
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$school = Tenant::get();
$tenantId = (int)$school['id'];
$package = strtolower((string)($school['package'] ?? 'semester'));

$packageLabels = [
    'semester'   => 'Paket Semesteran',
    'tahunan'    => 'Paket 1 Tahun Penuh',
    '1tahun'     => 'Paket 1 Tahun Penuh',
    'super'      => 'Paket Super',
    'basic'      => 'Paket Semesteran',
    'pro'        => 'Paket 1 Tahun Penuh',
    'enterprise' => 'Paket Super',
];
$packageLabel = $packageLabels[$package] ?? ('Paket ' . ucfirst($package));

// Cek Lisensi Paket Veyra Educate: Hanya 'tahunan' (1 Tahun) dan 'super' (Super) yang berhak mencetak kartu
$isPremium = in_array($package, ['tahunan', 'super', '1tahun', '1_tahun', 'pro', 'enterprise'], true);

// URL Portal Veyra dinamis untuk upgrade paket
$ssoConfigFile = __DIR__ . '/../config/sso.php';
$ssoConfig = file_exists($ssoConfigFile) ? (require $ssoConfigFile) : [];
$portalServerUrl = rtrim((string)($ssoConfig['sso_server_url'] ?? 'http://localhost/veyraeducate/public'), '/');
$portalUpgradeUrl = $portalServerUrl . '/harga';

// Muat Konfigurasi Template Global dari Superadmin
$configFile = __DIR__ . '/../config/idcard.php';
$idConfig = file_exists($configFile) ? (require $configFile) : [];

$primaryColor   = $idConfig['primary_color'] ?? '#4c1d95';
$secondaryColor = $idConfig['secondary_color'] ?? '#7c3aed';
$cardTitle      = $idConfig['card_title'] ?? 'KARTU PESERTA UJIAN';
$academicYear   = $idConfig['academic_year'] ?? (date('Y') . ' / ' . (date('Y') + 1));
$signerTitle    = $idConfig['signer_title'] ?? 'Panitia Ujian';
$signerName     = $idConfig['signer_name'] ?? 'Proktor / Panitia Pelaksana';
$contactPhone   = $idConfig['contact_phone'] ?? ($school['phone'] ?? '+62 812-3456-7890');
$contactEmail   = $idConfig['contact_email'] ?? 'cbt@' . ($school['slug'] ?? 'sekolah') . '.sch.id';
$rules          = $idConfig['rules'] ?? [
    'Wajib membawa kartu ujian ini selama pelaksanaan asesmen.',
    'Dilarang membawa catatan, gawai lain, atau membuka tab lain.',
    'Hadir di ruang ujian selambat-lambatnya 15 menit sebelum waktu dimulai.',
    'Wajib mematuhi seluruh instruksi pengawas ruang & proktor.'
];

$hasCustomFront = !empty($idConfig['bg_front']) && file_exists(__DIR__ . '/../assets/uploads/templates/' . $idConfig['bg_front']);
$hasCustomBack  = !empty($idConfig['bg_back']) && file_exists(__DIR__ . '/../assets/uploads/templates/' . $idConfig['bg_back']);
$bgFrontUrl     = $hasCustomFront ? '../assets/uploads/templates/' . htmlspecialchars($idConfig['bg_front']) : null;
$bgBackUrl      = $hasCustomBack  ? '../assets/uploads/templates/' . htmlspecialchars($idConfig['bg_back']) : null;


try {

// Parameter Filter & Mode Tampilan
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$viewMode        = $_GET['mode'] ?? 'pelajar'; // default 'pelajar' (Template Baru Kartu Pelajar Modern Terracotta)
$cardTitle       = trim((string)($_GET['title'] ?? ($idConfig['card_title'] ?? 'KARTU PELAJAR')));
if ($cardTitle === '') {
    $cardTitle = 'KARTU PELAJAR';
}
$passMode        = $_GET['pass_mode'] ?? 'db';
$sessionText     = trim((string)($_GET['session'] ?? 'Lab CBT / Sesi 1'));
$allClasses      = Database::fetchAll("SELECT * FROM classes WHERE tenant_id = ? ORDER BY name ASC", [$tenantId]);

// URL Langsung Login Ujian Peserta
$cbtProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$cbtHost = $_SERVER['HTTP_HOST'] ?? 'cbt.veyracreative.site';
$studentLoginShort = $cbtHost . '/student/login.php?school=' . htmlspecialchars($school['code']);

// Helper Barcode / QR Code SVG
function generateBarcodeSvg(string $code): string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code)) ?: '123456';
    $bars = '';
    $x = 6;
    for ($i = 0; $i < strlen($code); $i++) {
        $val = ord($code[$i]) % 5 + 1;
        $w = ($val % 2 === 0) ? 2 : 1;
        $bars .= "<rect x='{$x}' y='1' width='{$w}' height='20' fill='#1e293b' />";
        $x += $w + 2;
        $w2 = (($val + 1) % 2 === 0) ? 2.5 : 1;
        $bars .= "<rect x='{$x}' y='1' width='{$w2}' height='20' fill='#1e293b' />";
        $x += $w2 + 2;
    }
    $totalWidth = $x + 6;
    return "<svg viewBox='0 0 {$totalWidth} 22' style='height: 18px; width: auto;' xmlns='http://www.w3.org/2000/svg'>{$bars}</svg>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Kartu Ujian Siswa — <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── TOOLBAR (NON-PRINT) ── */
        .no-print-bar {
            background: #0f172a;
            color: #f8fafc;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        .toolbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .badge-pkg {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 6px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #000;
        }
        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-select {
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #334155;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s;
        }
        .btn-print { background: #2563eb; color: #ffffff; }
        .btn-print:hover { background: #1d4ed8; }
        .btn-back { background: #334155; color: #cbd5e1; }
        .btn-back:hover { background: #475569; color: #fff; }

        /* ── UPSELL VIEW FOR BASIC PACKAGE ── */
        .lock-container {
            max-width: 650px;
            margin: 60px auto;
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.05);
        }
        .lock-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: #fef3c7; color: #d97706;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; margin: 0 auto 20px;
        }

        /* ── PRINT CANVAS (A4) ── */
        .print-canvas {
            max-width: 210mm;
            margin: 20px auto;
        }

        /* Layout A: Tampak Depan (8 Kartu / A4) */
        .sheet-front-only {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 7mm 7mm;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
            gap: 5mm 6mm;
            page-break-after: always;
            break-after: page;
        }

        /* Layout B: Dupleks Depan-Belakang (4 Pasang / A4) */
        .sheet-duplex {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 7mm 7mm;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
            gap: 5mm 6mm;
            page-break-after: always;
            break-after: page;
        }

        /* Layout Utama: Kartu Pelajar Modern Terracotta (8 Kartu / A4) */
        .sheet-pelajar {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 7mm 7mm;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
            gap: 5mm 6mm;
            page-break-after: always;
            break-after: page;
        }

        .card-pelajar-unit {
            width: 100%;
            height: 65mm;
            background: #ffffff;
            border: 1px solid #e7ded7;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            display: flex;
            box-shadow: 0 2px 8px rgba(67, 26, 11, 0.05);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .kp-left {
            width: 63%;
            height: 100%;
            padding: 4.5mm 3mm 4mm 5mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            z-index: 2;
        }

        .kp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kp-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            max-width: 82%;
        }

        .kp-logo-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .kp-logo-img {
            width: 22px;
            height: 22px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .kp-school-name {
            font-size: 8.8pt;
            font-weight: 800;
            color: #3b1408;
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kp-dots {
            color: #9a3412;
            font-size: 8.5px;
            line-height: 4.5px;
            letter-spacing: 2px;
            user-select: none;
            opacity: 0.85;
        }

        .kp-dots-top {
            text-align: right;
            padding-right: 2px;
        }

        .kp-title-box {
            margin-top: 1.5mm;
            margin-bottom: 2mm;
        }

        .kp-title {
            font-size: 14pt;
            font-weight: 900;
            color: #3b1408;
            letter-spacing: 1.2px;
            line-height: 1.1;
            text-transform: uppercase;
        }

        .kp-title-underline {
            height: 2.5px;
            background: #9a3412;
            width: 100%;
            margin-top: 3px;
            border-radius: 1px;
        }

        .kp-meta-table {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-bottom: auto;
            padding-top: 1mm;
        }

        .kp-meta-row {
            display: flex;
            align-items: baseline;
            font-size: 7.2pt;
            line-height: 1.35;
            color: #3b1408;
        }

        .kp-label {
            width: 18mm;
            font-weight: 700;
            color: #3b1408;
            flex-shrink: 0;
        }

        .kp-colon {
            width: 3.5mm;
            text-align: center;
            font-weight: 700;
            flex-shrink: 0;
        }

        .kp-val {
            flex: 1;
            font-weight: 500;
            color: #4a1f10;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kp-val-name {
            font-weight: 700;
            color: #270f06;
            font-size: 7.8pt;
        }

        .kp-footer {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-top: 2mm;
            padding-top: 1mm;
        }

        .kp-dots-bottom {
            line-height: 4.5px;
            letter-spacing: 2px;
            font-size: 8px;
        }

        .kp-barcode-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: flex-end;
        }

        .kp-barcode-text {
            font-size: 5.5pt;
            font-family: 'JetBrains Mono', monospace;
            color: #64748b;
            margin-top: 1px;
            letter-spacing: 0.5px;
        }

        .kp-right {
            width: 37%;
            height: 100%;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kp-wave-svg {
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }

        .kp-photo-capsule {
            width: 25.5mm;
            height: 37mm;
            border-radius: 40px;
            border: 3.5px solid #ffffff;
            box-shadow: 0 5px 15px rgba(59, 20, 8, 0.3);
            background: #fbf7f4;
            overflow: hidden;
            position: relative;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kp-photo-capsule img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            display: block;
        }

        .kp-photo-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #9a3412;
            gap: 2px;
        }

        .kp-avatar-svg {
            width: 25px;
            height: 25px;
            opacity: 0.75;
        }

        .kp-photo-hint {
            font-size: 6pt;
            font-weight: 700;
            color: #9a3412;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Layout C: Kartu Resmi dengan Tabel Jadwal Seluruh Mapel (2 Kartu / A4) */
        .sheet-schedule {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 8mm 10mm;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            gap: 8mm;
            page-break-after: always;
            break-after: page;
        }

        .schedule-card-unit {
            border: 2px solid #1e3a8a;
            border-radius: 8px;
            padding: 6mm 8mm;
            background: #ffffff;
            position: relative;
            box-sizing: border-box;
            height: 135mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .schedule-card-unit::before {
            content: '';
            position: absolute;
            top: 1.5mm; bottom: 1.5mm; left: 1.5mm; right: 1.5mm;
            border: 0.8px solid #d97706;
            pointer-events: none;
            border-radius: 6px;
        }

        .sc-kop {
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 5px;
            margin-bottom: 6px;
        }
        .sc-logo { width: 13mm; height: 13mm; flex-shrink: 0; }
        .sc-logo img { width: 100%; height: 100%; object-fit: contain; }
        .sc-kop-text { flex: 1; text-align: center; }
        .sc-kop-instansi { font-size: 7.5pt; font-weight: 700; color: #475569; text-transform: uppercase; }
        .sc-kop-title { font-size: 10.5pt; font-weight: 800; color: #0f172a; letter-spacing: 0.5px; }
        .sc-kop-sub { font-size: 7pt; color: #64748b; }

        .sc-grid {
            display: grid;
            grid-template-columns: 75px 1fr 100px;
            gap: 10px;
            margin-bottom: 6px;
            align-items: center;
        }
        .sc-photo {
            width: 70px; height: 85px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            border-radius: 4px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-size: 7.5pt; color: #94a3b8; text-align: center;
        }
        .sc-info-table { width: 100%; font-size: 7.5pt; border-collapse: collapse; }
        .sc-info-table td { padding: 1.5px 3px; }
        .sc-info-label { width: 95px; color: #475569; font-weight: 600; }
        .sc-barcode-box { text-align: center; }

        .sc-table-schedule {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            margin-bottom: 5px;
        }
        .sc-table-schedule th {
            background: #1e293b;
            color: #fff;
            padding: 3px 5px;
            border: 1px solid #0f172a;
            font-size: 7pt;
            text-align: center;
        }
        .sc-table-schedule td {
            padding: 3px 5px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }
        .sc-table-schedule tr:nth-child(even) td { background: #f8fafc; }

        .sc-footer {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            font-size: 7.5pt;
            margin-top: 2px;
        }
        .sc-rules {
            max-width: 55%;
            font-size: 6.5pt;
            color: #64748b;
            line-height: 1.35;
        }
        .sc-sign { text-align: center; width: 140px; }

        /* ── MODERN PORTRAIT CARD ITEM ── */
        .id-card-unit {
            width: 100%;
            height: 66mm;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }

        /* Front Card Header (Violet Wave Style) */
        .card-wave-top {
            height: 24mm;
            background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?> 0%, <?= htmlspecialchars($secondaryColor) ?> 100%);
            border-radius: 0 0 50% 50% / 0 0 35% 35%;
            padding: 6px 10px;
            position: relative;
            overflow: hidden;
        }
        .dot-matrix-left {
            position: absolute; top: 6px; left: 8px; opacity: 0.25;
            font-size: 7px; line-height: 6px; letter-spacing: 2px; color: #fff;
        }
        .dot-matrix-right {
            position: absolute; top: 6px; right: 8px; opacity: 0.25;
            font-size: 7px; line-height: 6px; letter-spacing: 2px; color: #fff;
        }
        .card-logo-wrap {
            display: flex; justify-content: flex-end; align-items: center;
        }
        .card-logo-pill {
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(4px);
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 800;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            max-width: 130px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Photo Circle */
        .photo-circle-frame {
            position: absolute;
            top: 13mm;
            left: 50%;
            transform: translateX(-50%);
            width: 19mm;
            height: 19mm;
            border-radius: 50%;
            border: 2.5px solid #ffffff;
            background: #f8fafc;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
        }
        .photo-circle-frame img {
            width: 100%; height: 100%; object-fit: cover;
        }

        /* Front Content Area */
        .card-front-content {
            margin-top: 9mm;
            padding: 0 8px;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .student-name-main {
            font-size: 10.5px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .student-role-sub {
            font-size: 7.5px;
            font-weight: 800;
            color: <?= htmlspecialchars($primaryColor) ?>;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 1px;
            margin-bottom: 3px;
        }
        .meta-pill-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 3px 6px;
            font-size: 7.5px;
            color: #475569;
            line-height: 1.35;
            text-align: left;
            margin: 0 auto 3px;
            width: 95%;
        }
        .meta-row { display: flex; justify-content: space-between; }
        .meta-val { font-weight: 700; color: #0f172a; }

        /* QR Code & Wave Bottom */
        .qr-wrap-center {
            display: flex;
            justify-content: center;
            margin-bottom: 2px;
        }
        .qr-box {
            background: #fff;
            padding: 2px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .card-wave-bottom {
            height: 6mm;
            background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?> 0%, <?= htmlspecialchars($secondaryColor) ?> 100%);
            border-radius: 50% 50% 0 0 / 40% 40% 0 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-wave-bottom-text {
            font-size: 6.5px;
            color: rgba(255,255,255,0.9);
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* ── BACK SIDE CARD DESIGN ── */
        .card-back-body {
            padding: 8px 10px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 7.5px;
            position: relative;
        }
        .back-top-bar {
            height: 3mm;
            background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>);
            margin: -8px -10px 6px -10px;
        }
        .back-title {
            font-size: 8.5px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 2px;
            margin-bottom: 4px;
        }
        .back-rules {
            padding-left: 10px;
            color: #475569;
            line-height: 1.25;
            margin-bottom: 4px;
        }
        .back-rules li { margin-bottom: 2px; }
        .back-contact-box {
            background: #f8fafc;
            border-radius: 4px;
            padding: 4px 6px;
            font-size: 7px;
            color: #64748b;
            line-height: 1.25;
            margin-bottom: 4px;
        }
        .back-signature-area {
            border-top: 1px dashed #cbd5e1;
            padding-top: 4px;
            text-align: center;
        }
        .back-signature-title { font-size: 6.5px; color: #64748b; margin-bottom: 8px; }
        .back-signature-name { font-size: 7.5px; font-weight: 700; color: #0f172a; text-decoration: underline; }
        .back-bottom-bar {
            height: 3mm;
            background: linear-gradient(145deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>);
            margin: 6px -10px -8px -10px;
        }

        /* ── PRINT MEDIA RULES ── */
        @media print {
            body { background: #ffffff; margin: 0; padding: 0; }
            .no-print-bar { display: none !important; }
            .lock-container { display: none !important; }
            .print-canvas { max-width: none; margin: 0; }
            .sheet-pelajar, .sheet-front-only, .sheet-duplex, .sheet-schedule {
                box-shadow: none;
                margin: 0;
                padding: 6mm 6mm;
                page-break-after: always;
                break-after: page;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <!-- ── ACTIVE VIEW FOR ALL CBT PACKAGES ── -->
    <?php
    $sql = "SELECT s.*, c.name as class_name 
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.tenant_id = ? AND s.is_active = 1";
    $params = [$tenantId];
    if ($selectedClassId > 0) {
        $sql .= " AND s.class_id = ?";
        $params[] = $selectedClassId;
    }
    $sql .= " ORDER BY c.name ASC, s.name ASC";
    $students = Database::fetchAll($sql, $params);
    $totalStudents = count($students);

    if ($viewMode === 'duplex') {
        // Mode Dupleks: 4 Pasang kartu (Depan + Belakang berdampingan) per A4
        $pairsPerPage = 4;
        $studentChunks = array_chunk($students, $pairsPerPage);
    } elseif ($viewMode === 'schedule') {
        // Mode Resmi + Jadwal Mapel Ujian: 2 Kartu per A4
        $cardsPerPage = 2;
        $studentChunks = array_chunk($students, $cardsPerPage);
        $examSchedules = Database::fetchAll(
            "SELECT e.*, s.name as subject_name, s.code as subject_code
             FROM exams e
             JOIN question_banks qb ON e.bank_id = qb.id
             JOIN subjects s ON qb.subject_id = s.id
             WHERE e.tenant_id = ? AND e.is_active = 1
             ORDER BY e.start_time ASC, s.name ASC",
            [$tenantId]
        );
        if (empty($examSchedules)) {
            $examSchedules = Database::fetchAll(
                "SELECT id, name as subject_name, code as subject_code FROM subjects WHERE tenant_id = ? ORDER BY name ASC",
                [$tenantId]
            );
        }
    } else {
        // Mode Standar: 8 Kartu Tampak Depan per A4
        $cardsPerPage = 8;
        $studentChunks = array_chunk($students, $cardsPerPage);
    }
    ?>

    <!-- Top Toolbar (Non-Print) -->
    <div class="no-print-bar">
        <div class="toolbar-brand">
            <span class="badge-pkg"><?= htmlspecialchars($packageLabel) ?></span>
            <span style="font-size: 13px; font-weight: 600;">Cetak ID Card Ujian (<?= $totalStudents ?> Siswa)</span>
        </div>

        <div class="toolbar-actions">
            <!-- Filter Rombel Kelas & Mode -->
            <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="text" name="title" value="<?= htmlspecialchars($cardTitle) ?>" placeholder="Judul Kartu" class="form-select" style="width: 130px; font-weight: 700; color: #f59e0b;" title="Ubah judul kartu (cth: KARTU PESERTA UJIAN / KARTU PELAJAR)" onchange="this.form.submit()">

                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Rombel (<?= $totalStudents ?>) --</option>
                    <?php foreach ($allClasses as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selectedClassId === (int)$cls['id'] ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($cls['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="mode" class="form-select" onchange="this.form.submit()">
                    <option value="pelajar" <?= $viewMode === 'pelajar' ? 'selected' : '' ?>>🏷️ Kartu Pelajar (Desain Terracotta Modern)</option>
                    <option value="front_only" <?= $viewMode === 'front_only' ? 'selected' : '' ?>>Kartu Peserta Ujian (Portrait 8/A4)</option>
                    <option value="duplex" <?= $viewMode === 'duplex' ? 'selected' : '' ?>>Depan + Belakang Lanyard (Dupleks)</option>
                    <option value="schedule" <?= $viewMode === 'schedule' ? 'selected' : '' ?>>Kartu + Jadwal Mapel (2 / A4)</option>
                </select>

                <select name="pass_mode" class="form-select" onchange="this.form.submit()" title="Format kata sandi yang dicetak pada kartu">
                    <option value="db" <?= $passMode === 'db' ? 'selected' : '' ?>>🔑 Sandi: Database / Akun</option>
                    <option value="default" <?= $passMode === 'default' ? 'selected' : '' ?>>🔑 Sandi: "password"</option>
                    <option value="nis" <?= $passMode === 'nis' ? 'selected' : '' ?>>🔑 Sandi: Sama dgn NIS</option>
                    <option value="hidden" <?= $passMode === 'hidden' ? 'selected' : '' ?>>🔒 Sandi: Sensor (••••••)</option>
                </select>

                <input type="text" name="session" value="<?= htmlspecialchars($sessionText) ?>" placeholder="Ruang / Sesi" class="form-select" style="width: 135px;" title="Keterangan Ruang & Sesi Ujian" onchange="this.form.submit()">
            </form>

            <a href="student-transcript.php<?= $selectedClassId > 0 ? ('?class_id=' . $selectedClassId) : '' ?>" class="btn" style="background: linear-gradient(135deg, #059669, #10b981); color: #fff;" target="_blank">
                📄 Transkrip Nilai Siswa
            </a>

            <button type="button" onclick="window.print()" class="btn btn-print">
                🖨️ Cetak A4 / Simpan PDF
            </button>

            <a href="students.php" class="btn btn-back">
                &larr; Tutup
            </a>
        </div>
    </div>

    <!-- Printable Sheets Canvas -->
    <div class="print-canvas">
        <?php if (empty($students)): ?>
            <div style="background: #fff; padding: 40px; border-radius: 12px; text-align: center; margin-top: 40px;">
                <h3>Tidak ada siswa yang ditemukan</h3>
                <p style="color: #64748b; margin-top: 6px;">Silakan pilih rombel kelas lain atau tambahkan data siswa terlebih dahulu.</p>
            </div>
        <?php else: ?>

            <?php if ($viewMode === 'duplex'): ?>
                <!-- ── MODE DUPLEKS: DEPAN & BELAKANG BERDAMPINGAN ── -->
                <?php foreach ($studentChunks as $chunk): ?>
                    <div class="sheet-duplex">
                        <?php foreach ($chunk as $st): ?>
                            <!-- KARTU DEPAN -->
                            <div class="id-card-unit" style="<?= $bgFrontUrl ? "background: url('{$bgFrontUrl}') center/cover no-repeat;" : '' ?>">
                                <?php if (!$bgFrontUrl): ?>
                                    <div class="card-wave-top">
                                        <div class="dot-matrix-left">&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;</div>
                                        <div class="dot-matrix-right">&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;</div>
                                        <div class="card-logo-wrap">
                                            <div class="card-logo-pill"><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="height: 18mm;"></div>
                                <?php endif; ?>

                                <div class="photo-circle-frame">
                                    <div style="font-size: 16px;">🎓</div>
                                </div>

                                <?php
                                if ($passMode === 'nis') {
                                    $cardPass = $st['nis'];
                                } elseif ($passMode === 'default') {
                                    $cardPass = 'password';
                                } elseif ($passMode === 'hidden') {
                                    $cardPass = '••••••••';
                                } else {
                                    $cardPass = 'password';
                                }
                                ?>
                                <div class="card-front-content">
                                    <div>
                                        <div class="student-name-main"><?= htmlspecialchars($st['name'] ?? '-') ?></div>
                                        <div class="student-role-sub">PESERTA UJIAN CBT</div>

                                        <div class="meta-pill-box">
                                            <div class="meta-row"><span>NIS:</span><span class="meta-val"><?= htmlspecialchars($st['nis'] ?? '-') ?></span></div>
                                            <div class="meta-row"><span>Kelas:</span><span class="meta-val"><?= htmlspecialchars($st['class_name'] ?? 'Umum') ?></span></div>
                                            <div class="meta-row"><span>Sandi:</span><span class="meta-val" style="font-weight: 800; color: #7c2d12;"><?= htmlspecialchars($cardPass) ?></span></div>
                                            <div class="meta-row"><span>Sesi:</span><span class="meta-val"><?= htmlspecialchars($sessionText) ?></span></div>
                                        </div>
                                    </div>

                                    <div class="qr-wrap-center">
                                        <div class="qr-box">
                                            <?= generateBarcodeSvg($st['nis']) ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$bgFrontUrl): ?>
                                    <div class="card-wave-bottom">
                                        <span class="card-wave-bottom-text"><?= htmlspecialchars($cardTitle) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- KARTU BELAKANG (PASANGANNYA) -->
                            <div class="id-card-unit" style="<?= $bgBackUrl ? "background: url('{$bgBackUrl}') center/cover no-repeat;" : '' ?>">
                                <div class="card-back-body">
                                    <?php if (!$bgBackUrl): ?><div class="back-top-bar"></div><?php endif; ?>

                                    <div>
                                        <div class="back-title">Tata Tertib Ujian:</div>
                                        <ul class="back-rules">
                                            <?php foreach (array_slice($rules, 0, 4) as $r): ?>
                                                <li><?= htmlspecialchars($r) ?></li>
                                            <?php endforeach; ?>
                                        </ul>

                                        <div class="back-contact-box">
                                            <div><strong>Layanan:</strong> <?= htmlspecialchars($contactPhone) ?></div>
                                            <div><strong>Email:</strong> <?= htmlspecialchars($contactEmail) ?></div>
                                            <div><strong>Tahun:</strong> <?= htmlspecialchars($academicYear) ?></div>
                                        </div>
                                    </div>

                                    <div class="back-signature-area">
                                        <div class="back-signature-title"><?= htmlspecialchars($signerTitle) ?>,</div>
                                        <div class="back-signature-name"><?= htmlspecialchars($signerName) ?></div>
                                    </div>

                                    <?php if (!$bgBackUrl): ?><div class="back-bottom-bar"></div><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($viewMode === 'schedule'): ?>
                <!-- ── MODE RESMI: KARTU + JADWAL SELURUH MATERI UJIAN (2 KARTU / A4) ── -->
                <?php foreach ($studentChunks as $chunk): ?>
                    <div class="sheet-schedule">
                        <?php foreach ($chunk as $st): ?>
                            <div class="schedule-card-unit">
                                <!-- KOP KARTU -->
                                <div class="sc-kop">
                                    <div class="sc-logo">
                                        <?php if (!empty($school['logo_url']) && file_exists(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $school['logo_url']), '/'))): ?>
                                            <img src="../<?= htmlspecialchars(ltrim(str_replace('\\', '/', $school['logo_url']), '/')) ?>" alt="Logo">
                                        <?php else: ?>
                                            <div style="width: 100%; height: 100%; border-radius: 50%; background: #1e3a8a; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px;">
                                                <?= strtoupper(substr($school['name'] ?: 'CBT', 0, 2)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sc-kop-text">
                                        <div class="sc-kop-instansi">DINAS PENDIDIKAN &bull; TAHUN PELAJARAN <?= htmlspecialchars($academicYear) ?></div>
                                        <div class="sc-kop-title"><?= strtoupper(htmlspecialchars($school['name'] ?? 'CBT Online')) ?></div>
                                        <div class="sc-kop-sub">KARTU PESERTA & JADWAL RESMI ASESMEN UJIAN CBT</div>
                                    </div>
                                </div>

                                <!-- PROFIL SISWA & BARCODE -->
                                <div class="sc-grid">
                                    <div class="sc-photo">
                                        <span style="font-size: 24px; margin-bottom: 2px;">👤</span>
                                        <span>PASFOTO<br>3 &times; 4</span>
                                    </div>
                                    <?php
                                    if ($passMode === 'nis') {
                                        $cardPass = $st['nis'];
                                    } elseif ($passMode === 'default') {
                                        $cardPass = 'password';
                                    } elseif ($passMode === 'hidden') {
                                        $cardPass = '••••••••';
                                    } else {
                                        $cardPass = 'password';
                                    }
                                    ?>
                                    <table class="sc-info-table">
                                        <tr>
                                            <td class="sc-info-label">Nama Siswa</td>
                                            <td style="width: 10px;">:</td>
                                            <td><b><?= htmlspecialchars($st['name'] ?? '-') ?></b></td>
                                        </tr>
                                        <tr>
                                            <td class="sc-info-label">NIS / No. Peserta</td>
                                            <td>:</td>
                                            <td><code><?= htmlspecialchars($st['nis'] ?? '-') ?></code></td>
                                        </tr>
                                        <tr>
                                            <td class="sc-info-label">Kata Sandi</td>
                                            <td>:</td>
                                            <td><code style="font-weight: 800; background: #e0f2fe; padding: 1px 6px; border-radius: 4px; color: #0369a1;"><?= htmlspecialchars($cardPass) ?></code></td>
                                        </tr>
                                        <tr>
                                            <td class="sc-info-label">Kelas / Rombel</td>
                                            <td>:</td>
                                            <td><?= htmlspecialchars($st['class_name'] ?? 'Umum') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="sc-info-label">Ruang / Sesi</td>
                                            <td>:</td>
                                            <td><?= htmlspecialchars($sessionText) ?></td>
                                        </tr>
                                    </table>
                                    <div class="sc-barcode-box">
                                        <div style="font-size: 7pt; font-weight: 700; color: #64748b; margin-bottom: 2px;">SCAN LOGIN</div>
                                        <?= generateBarcodeSvg($st['nis']) ?>
                                        <div style="font-size: 6.5pt; font-family: 'JetBrains Mono', monospace; color: #0f172a; margin-top: 1px;">*<?= htmlspecialchars($st['nis'] ?? '-') ?>*</div>
                                    </div>
                                </div>

                                <!-- TABEL JADWAL SELURUH MATERI UJIAN -->
                                <table class="sc-table-schedule">
                                    <thead>
                                        <tr>
                                            <th style="width: 28px;">No</th>
                                            <th style="width: 85px;">Hari / Tanggal</th>
                                            <th style="width: 75px;">Waktu</th>
                                            <th>Mata Pelajaran / Materi Asesmen</th>
                                            <th style="width: 60px;">Ruang</th>
                                            <th style="width: 75px;">Paraf Pengawas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($examSchedules)): ?>
                                            <?php foreach (array_slice($examSchedules, 0, 5) as $sIdx => $sch): ?>
                                                <tr>
                                                    <td style="text-align: center;"><?= $sIdx + 1 ?></td>
                                                    <td style="text-align: center;">Hari ke-<?= $sIdx + 1 ?></td>
                                                    <td style="text-align: center; font-family: 'JetBrains Mono', monospace; font-size: 7pt;">07:30 - 09:00</td>
                                                    <td><b><?= htmlspecialchars(ucwords($sch['subject_name'])) ?></b></td>
                                                    <td style="text-align: center;">Lab CBT</td>
                                                    <td style="text-align: center; height: 16px;"></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" style="text-align: center; color: #94a3b8;">Jadwal belum dikonfigurasi.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <!-- FOOTER & TANDA TANGAN -->
                                <div class="sc-footer">
                                    <div class="sc-rules">
                                        <b>Tata Tertib:</b> 1. Wajib hadir 15 menit sebelum asesmen; 2. Membawa kartu ini setiap sesi; 3. Dilarang membawa catatan/gawai lain.
                                    </div>
                                    <div class="sc-sign">
                                        <div style="font-size: 6.5pt; color: #64748b;"><?= (explode(',', (string)($school['address'] ?? ''))[0] ?? 'Sekolah') ?: 'Sekolah' ?>, <?= date('d/m/Y') ?></div>
                                        <div style="font-weight: 700; font-size: 7pt; color: #0f172a;"><?= htmlspecialchars($signerTitle) ?></div>
                                        <div style="height: 9mm; display: flex; align-items: center; justify-content: center;">
                                            <svg width="60" height="22" viewBox="0 0 100 30" style="opacity: 0.65;">
                                                <path d="M10,20 Q30,5 50,18 T80,10" fill="none" stroke="#1e3a8a" stroke-width="1.8"/>
                                            </svg>
                                        </div>
                                        <div style="font-weight: 800; font-size: 7pt; text-decoration: underline; color: #0f172a;"><?= htmlspecialchars($signerName) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($viewMode === 'front_only'): ?>
                <!-- ── MODE PORTRAIT STANDAR: 8 KARTU DEPAN PER A4 ── -->
                <?php foreach ($studentChunks as $chunk): ?>
                    <div class="sheet-front-only">
                        <?php foreach ($chunk as $st): ?>
                            <div class="id-card-unit" style="<?= $bgFrontUrl ? "background: url('{$bgFrontUrl}') center/cover no-repeat;" : '' ?>">
                                <?php if (!$bgFrontUrl): ?>
                                    <div class="card-wave-top">
                                        <div class="dot-matrix-left">&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;</div>
                                        <div class="dot-matrix-right">&bull;&bull;&bull;&bull;<br>&bull;&bull;&bull;&bull;</div>
                                        <div class="card-logo-wrap">
                                            <div class="card-logo-pill"><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="height: 18mm;"></div>
                                <?php endif; ?>

                                <div class="photo-circle-frame">
                                    <div style="font-size: 16px;">🎓</div>
                                </div>

                                <?php
                                if ($passMode === 'nis') {
                                    $cardPass = $st['nis'];
                                } elseif ($passMode === 'default') {
                                    $cardPass = 'password';
                                } elseif ($passMode === 'hidden') {
                                    $cardPass = '••••••••';
                                } else {
                                    $cardPass = 'password';
                                }
                                ?>
                                <div class="card-front-content">
                                    <div>
                                        <div class="student-name-main"><?= htmlspecialchars($st['name'] ?? '-') ?></div>
                                        <div class="student-role-sub">PESERTA UJIAN CBT</div>

                                        <div class="meta-pill-box">
                                            <div class="meta-row"><span>NIS:</span><span class="meta-val"><?= htmlspecialchars($st['nis'] ?? '-') ?></span></div>
                                            <div class="meta-row"><span>Kelas:</span><span class="meta-val"><?= htmlspecialchars($st['class_name'] ?? 'Umum') ?></span></div>
                                            <div class="meta-row"><span>Sandi:</span><span class="meta-val" style="font-weight: 800; color: #7c2d12;"><?= htmlspecialchars($cardPass) ?></span></div>
                                            <div class="meta-row"><span>Sesi:</span><span class="meta-val"><?= htmlspecialchars($sessionText) ?></span></div>
                                        </div>
                                    </div>

                                    <div class="qr-wrap-center">
                                        <div class="qr-box">
                                            <?= generateBarcodeSvg($st['nis']) ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$bgFrontUrl): ?>
                                    <div class="card-wave-bottom">
                                        <span class="card-wave-bottom-text"><?= htmlspecialchars($cardTitle) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <!-- ── MODE UTAMA: KARTU PELAJAR MODERN TERRACOTTA (8 KARTU PER A4) ── -->
                <?php
                $schoolWebsite = !empty($school['website']) ? $school['website'] : ($school['slug'] ? ($school['slug'] . '.sch.id') : 'www.veyraeducate.com');
                $defaultSchoolAddress = !empty($school['address']) ? $school['address'] : 'Jl. Pendidikan No. 1, Kota';
                ?>
                <?php foreach ($studentChunks as $chunk): ?>
                    <div class="sheet-pelajar">
                        <?php foreach ($chunk as $st): ?>
                            <?php
                            $studentEmail = !empty($st['email']) ? $st['email'] : ($st['nis'] ? ($st['nis'] . '@' . ($school['slug'] ?: 'sekolah') . '.sch.id') : $contactEmail);
                            $studentAddress = !empty($st['address']) ? $st['address'] : $defaultSchoolAddress;

                            // Cek File Foto Siswa
                            $hasPhoto = false;
                            $photoSrc = '';
                            if (!empty($st['photo'])) {
                                $cleanedPhoto = ltrim(str_replace('\\', '/', $st['photo']), '/');
                                $photoPhysical = __DIR__ . '/../' . $cleanedPhoto;
                                if (file_exists($photoPhysical)) {
                                    $hasPhoto = true;
                                    $photoSrc = '../' . htmlspecialchars($cleanedPhoto);
                                }
                            }
                            ?>
                            <div class="card-pelajar-unit">
                                <!-- BAGIAN KIRI: IDENTITAS SISWA & SEKOLAH -->
                                <div class="kp-left">
                                    <div class="kp-header">
                                        <div class="kp-brand">
                                            <?php if (!empty($school['logo_url']) && file_exists(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $school['logo_url']), '/'))): ?>
                                                <img class="kp-logo-img" src="../<?= htmlspecialchars(ltrim(str_replace('\\', '/', $school['logo_url']), '/')) ?>" alt="Logo">
                                            <?php else: ?>
                                                <div class="kp-logo-icon">
                                                    <svg viewBox="0 0 24 24" width="22" height="22" fill="#5c2410">
                                                        <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm0 2.22l7.78 4.24L12 13.7 4.22 9.46 12 5.22zM7 12.33l5 2.73 5-2.73v4.06l-5 2.73-5-2.73v-4.06z"/>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <div class="kp-school-name"><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                                        </div>
                                        <div class="kp-dots kp-dots-top">
                                            &bull;&bull;&bull;&bull;&bull;<br>
                                            &bull;&bull;&bull;&bull;&bull;
                                        </div>
                                    </div>

                                    <div class="kp-title-box">
                                        <h1 class="kp-title"><?= htmlspecialchars($cardTitle) ?></h1>
                                        <div class="kp-title-underline"></div>
                                    </div>

                                    <?php
                                    if ($passMode === 'nis') {
                                        $cardPass = $st['nis'];
                                    } elseif ($passMode === 'default') {
                                        $cardPass = 'password';
                                    } elseif ($passMode === 'hidden') {
                                        $cardPass = '••••••••';
                                    } else {
                                        $cardPass = 'password';
                                    }
                                    ?>
                                    <div class="kp-meta-table">
                                        <div class="kp-meta-row">
                                            <span class="kp-label">Nama Siswa</span>
                                            <span class="kp-colon">:</span>
                                            <span class="kp-val kp-val-name"><?= htmlspecialchars($st['name'] ?? '-') ?></span>
                                        </div>
                                        <div class="kp-meta-row">
                                            <span class="kp-label">NIS / Peserta</span>
                                            <span class="kp-colon">:</span>
                                            <span class="kp-val"><code><?= htmlspecialchars($st['nis'] ?? '-') ?></code></span>
                                        </div>
                                        <div class="kp-meta-row">
                                            <span class="kp-label">Kelas / Rombel</span>
                                            <span class="kp-colon">:</span>
                                            <span class="kp-val"><?= htmlspecialchars($st['class_name'] ?? 'Umum') ?></span>
                                        </div>
                                        <div class="kp-meta-row">
                                            <span class="kp-label">Sandi Ujian</span>
                                            <span class="kp-colon">:</span>
                                            <span class="kp-val"><code style="background: rgba(154, 52, 18, 0.12); padding: 1px 6px; border-radius: 4px; font-weight: 800; font-size: 8.5pt; color: #7c2d12; letter-spacing: 0.5px;"><?= htmlspecialchars($cardPass) ?></code></span>
                                        </div>
                                        <div class="kp-meta-row">
                                            <span class="kp-label">Ruang / Sesi</span>
                                            <span class="kp-colon">:</span>
                                            <span class="kp-val"><?= htmlspecialchars($sessionText) ?></span>
                                        </div>
                                    </div>

                                    <div class="kp-footer">
                                        <div style="font-size: 6.2pt; color: #78350f; line-height: 1.3; max-width: 52%; overflow: hidden;">
                                            <b>Login CBT:</b><br>
                                            <span style="font-family: 'JetBrains Mono', monospace; font-size: 5.6pt; color: #9a3412;"><?= htmlspecialchars($studentLoginShort) ?></span>
                                        </div>
                                        <div class="kp-barcode-wrap">
                                            <?= generateBarcodeSvg($st['nis']) ?>
                                            <span class="kp-barcode-text">CBT ID: <?= htmlspecialchars($st['nis'] ?? '-') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- BAGIAN KANAN: GELOMBANG TERRACOTTA & FOTO SISWA -->
                                <div class="kp-right">
                                    <svg class="kp-wave-svg" viewBox="0 0 160 220" preserveAspectRatio="none">
                                        <defs>
                                            <pattern id="pat-stripes-<?= $st['id'] ?>" width="7" height="7" patternTransform="rotate(45 0 0)" patternUnits="userSpaceOnUse">
                                                <line x1="0" y1="0" x2="0" y2="7" stroke="#b44b1c" stroke-width="2.2" />
                                            </pattern>
                                        </defs>
                                        <!-- Gelombang Dasar Terracotta -->
                                        <path d="M45,0 C15,35 10,80 40,120 C70,160 45,190 15,220 L160,220 L160,0 Z" fill="#9a3412"/>
                                        
                                        <!-- Lengkungan Atas Coklat Tua -->
                                        <path d="M50,0 C25,32 30,58 65,65 C105,72 140,35 160,25 L160,0 Z" fill="#3b1408"/>
                                        
                                        <!-- Lingkaran Aksen Garis Diagonal -->
                                        <circle cx="132" cy="54" r="23" fill="url(#pat-stripes-<?= $st['id'] ?>)"/>
                                        
                                        <!-- Pita Lengkung Terracotta di sekeliling lingkaran -->
                                        <path d="M102,58 C118,78 148,74 160,62 L160,42 C145,58 122,52 102,38 Z" fill="#b44b1c" opacity="0.85"/>

                                        <!-- Lengkungan Bawah Coklat Tua -->
                                        <path d="M22,220 C42,188 82,182 108,202 C124,214 140,218 160,210 L160,220 Z" fill="#3b1408"/>
                                        
                                        <!-- Aksen Riak Hangat Bawah -->
                                        <path d="M50,220 C68,204 94,200 115,214 L105,220 Z" fill="#d97706" opacity="0.6"/>
                                    </svg>

                                    <!-- BINGKAI FOTO OVAL KAPSUL -->
                                    <div class="kp-photo-capsule">
                                        <?php if ($hasPhoto): ?>
                                            <img src="<?= $photoSrc ?>" alt="<?= htmlspecialchars($st['name'] ?? '-') ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="kp-photo-placeholder" style="display: none;">
                                                <svg viewBox="0 0 24 24" class="kp-avatar-svg" fill="#9a3412">
                                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                                </svg>
                                                <span class="kp-photo-hint">Pasfoto</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="kp-photo-placeholder">
                                                <svg viewBox="0 0 24 24" class="kp-avatar-svg" fill="#9a3412">
                                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                                </svg>
                                                <span class="kp-photo-hint">Pasfoto</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
    <?php endif; ?>

<?php } catch (Exception $e) { error_log('student-cards error: ' . $e->getMessage()); } ?>

</body>
</html>
