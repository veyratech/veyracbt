<?php
/**
 * Modern Fan Analysis Theme Layout Header for Tenant Admin & Teacher
 * Multi-Tenant CBT Online
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

Auth::requireTenantAdmin();
Auth::checkTimeout();
Auth::refreshActivity();

$user = Auth::user();
$school = Tenant::get();
$currentPage = basename($_SERVER['PHP_SELF']);
$flash = Helper::getFlash();
$isAdmin = ($user['role'] === 'admin');

// Inisial nama user untuk avatar Cathy Murray style
$nameParts = explode(' ', trim((string)($user['name'] ?? '')));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Data notifikasi ringan (ujian aktif & sesi terblokir)
$tenantId = (int)$school['id'];
$notifExams = Database::fetchAll(
    "SELECT id, title, end_time FROM exams 
     WHERE tenant_id = ? AND is_active = 1 AND end_time >= NOW() 
     ORDER BY end_time ASC LIMIT 5",
    [$tenantId]
);
$notifBlocked = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'blocked'",
    [$tenantId]
);
$notifLiveCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'in_progress'",
    [$tenantId]
);
$totalNotif = $notifBlocked + $notifLiveCount + count($notifExams);

// Resolusi URL Portal Veyra Educate & Integrasi SSO
$ssoConfigFile = __DIR__ . '/../config/sso.php';
$ssoConfig = file_exists($ssoConfigFile) ? (require $ssoConfigFile) : [];
$isLocalHost = in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1'], true)
    || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost:') === 0
    || substr($_SERVER['HTTP_HOST'] ?? '', -5) === '.test';
$portalBaseUrl = rtrim((string)($ssoConfig['sso_server_url'] ?? ($isLocalHost ? 'http://localhost/veyraeducate/public' : 'https://veyracreative.site')), '/');
$portalDashboardUrl = $portalBaseUrl . '/dashboard';

// Sinkronisasi Logo Sekolah (prioritaskan logo_url hasil upload pengaturan sekolah, lalu SSO session / kolom tenants)
$schoolLogo = !empty($school['logo_url'])
    ? $school['logo_url']
    : ($_SESSION['school_logo'] ?? $school['logo'] ?? null);
// Fallback: coba cari file logo fisik di uploads/logos jika path dari session/DB tidak valid atau 404
if (!$schoolLogo || !file_exists(__DIR__ . '/../' . str_replace('../', '', $schoolLogo))) {
    $fallbackLogo = __DIR__ . '/../assets/uploads/logos';
    if (is_dir($fallbackLogo)) {
        $files = glob($fallbackLogo . '/logo_t' . $tenantId . '*');
        if (!empty($files)) {
            $schoolLogo = 'assets/uploads/logos/' . basename(max($files));
        }
    }
}
if ($schoolLogo && !str_starts_with($schoolLogo, 'http') && !str_starts_with($schoolLogo, '/') && !str_starts_with($schoolLogo, 'data:')) {
    if (file_exists(__DIR__ . '/../' . $schoolLogo)) {
        $schoolLogo = '../' . $schoolLogo;
    } else {
        $schoolLogo = null; // invalid path → fallback to initials
    }
}
$schoolInitials = '';
$sWords = explode(' ', trim((string)($school['name'] ?? '')));
$sTake = 0;
foreach ($sWords as $sw) {
    $sw = trim($sw);
    if ($sw !== '') {
        $schoolInitials .= strtoupper(mb_substr($sw, 0, 1));
        $sTake++;
        if ($sTake >= 2) break;
    }
}
if (!$schoolInitials) {
    $schoolInitials = 'SK';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Panel CBT' ?> | <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <style>
        /* ── DESKTOP FIXED SIDEBAR & INDEPENDENT CONTENT SCROLL ── */
        @media (min-width: 1025px) {
            html, body.admin-fan-page {
                height: 100vh !important;
                max-height: 100vh !important;
                overflow: hidden !important;
                padding: 16px 20px !important;
                box-sizing: border-box !important;
            }

            .fan-app-canvas {
                height: calc(100vh - 32px) !important;
                max-height: calc(100vh - 32px) !important;
                overflow: hidden !important;
                display: flex !important;
                flex-direction: row !important;
                border-radius: 24px !important;
                box-shadow: 0 22px 65px -12px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(226, 232, 240, 0.9) !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 1560px !important;
                position: relative !important;
            }

            .fan-sidebar {
                width: 256px !important;
                min-width: 256px !important;
                background: linear-gradient(180deg, #064e3b 0%, #065f46 45%, #022c22 100%) !important;
                color: #ffffff !important;
                box-shadow: 4px 0 24px rgba(2, 44, 34, 0.16) !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                height: 100% !important;
                max-height: 100% !important;
                padding: 20px 14px 16px 14px !important;
                flex-shrink: 0 !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                position: relative !important;
                z-index: 50 !important;
                scrollbar-width: thin !important;
                scrollbar-color: rgba(255, 255, 255, 0.2) transparent !important;
            }

            .fan-main-content {
                flex: 1 !important;
                min-width: 0 !important;
                height: 100% !important;
                max-height: 100% !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                padding: 0 32px 42px 32px !important;
                scrollbar-width: thin !important;
                scrollbar-color: rgba(148, 163, 184, 0.35) transparent !important;
            }
            .fan-main-content::-webkit-scrollbar {
                width: 6px !important;
            }
            .fan-main-content::-webkit-scrollbar-thumb {
                background: rgba(148, 163, 184, 0.35) !important;
                border-radius: 9999px !important;
            }
            .fan-main-content::-webkit-scrollbar-track {
                background: transparent !important;
            }

            .fan-top-header {
                position: sticky !important;
                top: 0 !important;
                margin-top: 0 !important;
                margin-left: -32px !important;
                margin-right: -32px !important;
                padding: 18px 32px !important;
                background: rgba(248, 250, 252, 0.94) !important;
                /* backdrop-filter dihapus — penyebab utama bug kompositing Chrome saat F12 tutup */
                border-bottom: 1px solid #e2e8f0 !important;
                z-index: 1050 !important;
                margin-bottom: 4px !important;
            }

            html[data-theme="dark"] .fan-top-header {
                background: rgba(15, 23, 42, 0.94) !important;
                border-color: rgba(51, 65, 85, 0.85) !important;
            }

            /* Promosi GPU: pastikan konten admin tidak lepas dari kompositing layer saat
               DevTools menutup dan Chrome memaksa re-layout ulang. */
            .fan-main-content,
            .card,
            .table-responsive {
                transform: translateZ(0);
                will-change: transform;
            }
            /* Cegah card memotong konten tabel — scope ke body.admin-fan-page agar tidak rusak page lain */
            body.admin-fan-page .card { overflow: visible !important; }
            /* Pastikan tbody selalu dirender sebagai baris tabel */
            body.admin-fan-page table.table tbody { display: table-row-group !important; }
            /* Paksa layout recalc setelah F12 tutup */
            .fan-app-canvas { backface-visibility: hidden; }
        }

        .fan-sidebar::-webkit-scrollbar {
            width: 4px !important;
        }
        .fan-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.22) !important;
            border-radius: 9999px !important;
        }
        .fan-sidebar::-webkit-scrollbar-track {
            background: transparent !important;
        }

        .modal-backdrop {
            z-index: 999999 !important;
        }
        .fan-sidebar-brand-box {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.24);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        .fan-sidebar-brand-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #ffffff;
            padding: 2px;
        }
        .fan-sidebar-brand-init {
            font-weight: 800;
            font-size: 13.5px;
            color: #fde68a;
            letter-spacing: 0.4px;
        }
        .fan-portal-return-btn {
            display: flex !important;
            align-items: center !important;
            gap: 9px !important;
            padding: 8.5px 12px !important;
            background: rgba(254, 240, 138, 0.12) !important;
            border: 1px solid rgba(254, 240, 138, 0.32) !important;
            border-radius: 11px !important;
            color: #fef08a !important;
            font-size: 11.5px !important;
            font-weight: 700 !important;
            text-decoration: none !important;
            margin-bottom: 4px !important;
            transition: all 0.2s ease !important;
        }
        .fan-portal-return-btn:hover {
            background: rgba(254, 240, 138, 0.22) !important;
            color: #ffffff !important;
            border-color: #fde047 !important;
            transform: translateY(-1px) !important;
        }
        .fan-portal-badge {
            margin-left: auto !important;
            background: #fde047 !important;
            color: #064e3b !important;
            font-size: 9px !important;
            font-weight: 800 !important;
            padding: 1.5px 6px !important;
            border-radius: 6px !important;
        }

        /* ── DROPDOWN MENU FIX & POLISH (NOTIFIKASI & USER MENU) ── */
        .fan-top-actions {
            position: relative !important;
            z-index: 1050 !important;
        }
        .dropdown-wrap {
            position: relative !important;
        }
        .dropdown-menu {
            display: none;
            position: absolute !important;
            top: calc(100% + 8px) !important;
            right: 0 !important;
            min-width: 320px !important;
            max-width: 380px !important;
            background: #ffffff !important;
            color: #1e293b !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(0, 0, 0, 0.05) !important;
            z-index: 99999 !important;
            overflow: hidden !important;
            animation: fanFadeDown 0.18s ease-out forwards;
        }
        .dropdown-menu.show {
            display: block !important;
        }
        @keyframes fanFadeDown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-menu .dropdown-title {
            padding: 12px 16px !important;
            font-size: 11.5px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.6px !important;
            color: #064e3b !important;
            background: #f0fdf4 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }
        .dropdown-menu .dropdown-item {
            display: flex !important;
            flex-direction: column !important;
            padding: 12px 16px !important;
            font-size: 13px !important;
            color: #1e293b !important;
            text-decoration: none !important;
            border-bottom: 1px solid #f1f5f9 !important;
            transition: background 0.15s ease !important;
            line-height: 1.4 !important;
        }
        .dropdown-menu .dropdown-item:hover {
            background: #f8fafc !important;
            color: #047857 !important;
        }
        .dropdown-menu .dropdown-item:last-child {
            border-bottom: none !important;
        }
        .dropdown-menu .dropdown-sub {
            font-size: 11px !important;
            color: #64748b !important;
            margin-top: 3px !important;
        }

        /* ── RESPONSIVE MOBILE DRAWER FOR SCHOOL/TEACHER ADMIN ── */
        .fan-menu-toggle {
            display: none;
        }
        .fan-sidebar-close {
            display: none;
        }
        .fan-sidebar-backdrop {
            display: none;
        }
        .fan-sidebar-brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        @media (max-width: 1024px) {
            body.admin-fan-page {
                padding: 0 !important;
            }
            .fan-app-canvas {
                border-radius: 0 !important;
                box-shadow: none !important;
                min-height: 100vh !important;
                width: 100% !important;
                flex-direction: column !important;
            }
            /* Off-canvas sidebar */
            .fan-sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;
                width: 280px !important;
                max-width: 84vw !important;
                height: 100vh !important;
                z-index: 100000 !important;
                box-shadow: 12px 0 40px rgba(6, 78, 59, 0.45) !important;
                transform: translateX(-100%) !important;
                transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1) !important;
                overflow-y: auto !important;
                padding: 22px 16px !important;
            }
            .fan-sidebar.open {
                transform: translateX(0) !important;
            }
            .fan-sidebar-brand-row {
                margin-bottom: 20px;
                padding: 0 8px;
            }
            .fan-sidebar-brand {
                padding: 0 !important;
                margin-bottom: 0 !important;
            }
            .fan-sidebar-close {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.15);
                border: 1px solid rgba(255, 255, 255, 0.25);
                color: #ffffff;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
            }
            .fan-sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(4px);
                z-index: 99999;
                display: none;
                opacity: 0;
                transition: opacity 0.25s ease;
            }
            .fan-sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }
            .fan-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 12px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                color: #064e3b;
                cursor: pointer;
                flex-shrink: 0;
            }
            .fan-top-header {
                padding: 10px 12px !important;
                gap: 8px !important;
                position: sticky !important;
                top: 0 !important;
                background: #ffffff !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
            }
            .fan-main-content {
                padding: 12px 10px 32px 10px !important;
            }
            /* Hide non-essential action buttons on tablet */
            .fan-top-actions .btn-outline.btn-sm {
                display: none !important;
            }
            /* Compact the search on tablet */
            .fan-top-actions form {
                max-width: 180px !important;
                padding: 5px 10px !important;
            }
            .fan-top-actions form input {
                font-size: 11px !important;
            }
        }

        @media (max-width: 600px) {
            .fan-top-header {
                padding: 8px 10px !important;
                gap: 6px !important;
            }
            /* Two-row layout on very narrow screens */
            .fan-top-header {
                flex-wrap: wrap !important;
            }
            .fan-header-left {
                width: 100% !important;
                order: 1 !important;
            }
            .fan-mobile-profile {
                display: none !important; /* hide profile pill on very small screens to save space */
            }
            .fan-header-right {
                width: 100% !important;
                order: 2 !important;
                justify-content: space-between !important;
                margin-top: 6px !important;
                padding-top: 6px !important;
                border-top: 1px solid #f1f5f9 !important;
            }
            html[data-theme="dark"] .fan-header-right {
                border-top-color: #334155 !important;
            }
            /* Only show essential right-side controls */
            .fan-top-actions .btn-outline.btn-sm {
                display: none !important;
            }
            .fan-top-actions form {
                display: none !important; /* hide search entirely on phone */
            }
            /* Make icon buttons slightly larger for touch */
            .fan-icon-btn {
                width: 38px !important;
                height: 38px !important;
            }
            /* Page title smaller on phone */
            .fan-header-title {
                font-size: 15px !important;
            }
            .fan-header-subtitle {
                font-size: 10.5px !important;
            }
        }

        @media (max-width: 480px) {
            .dropdown-menu {
                position: fixed !important;
                top: 60px !important;
                left: 8px !important;
                right: 8px !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                max-height: calc(100vh - 75px) !important;
                border-radius: 16px !important;
            }
            .table-responsive {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            /* Right-side controls: only theme + notif on smallest phones */
            .fan-icon-btn:not(#themeToggle):not(#notifBell) {
                display: none !important;
            }
        }

        @media (max-width: 412px) {
            .fan-kpi-grid { gap: 8px; }
            table.fan-table, table.fan-table thead, table.fan-table tbody, table.fan-table th, table.fan-table td, table.fan-table tr { display: block; }
            table.fan-table thead tr { position: absolute; top: -9999px; left: -9999px; }
            table.fan-table tr { margin-bottom: 10px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px; background: #fff; }
            html[data-theme="dark"] table.fan-table tr { background: #1e293b; border-color: #334155; }
            table.fan-table td { padding: 5px 4px !important; text-align: right; position: relative; padding-left: 45% !important; white-space: normal !important; }
            table.fan-table td:before { position: absolute; left: 8px; width: 42%; padding-right: 8px; white-space: nowrap; font-weight: 700; color: #64748b; text-align: left; }
            table.fan-table td:nth-of-type(1):before { content: "No"; }
            table.fan-table td:nth-of-type(2):before { content: "Ujian"; }
            table.fan-table td:nth-of-type(3):before { content: "Token"; }
            table.fan-table td:nth-of-type(4):before { content: "Jadwal"; }
            table.fan-table td:nth-of-type(5):before { content: "Partisipasi"; }
            table.fan-table td:nth-of-type(6):before { content: "Status"; }
            table.fan-table td:nth-of-type(7):before { content: "Aksi"; }
            .fan-donut-center-wrap { justify-content: center !important; }
            #donut-conic-circle { width: 110px !important; height: 110px !important; }
            .fan-bar-chart { overflow-x: auto; }
            .fan-live-banner { flex-direction: column; align-items: flex-start !important; }
        }

        @media (max-width: 375px) {
            .fan-main-content { padding: 8px 8px 24px 8px !important; }
            .fan-top-header { padding: 6px 8px !important; gap: 4px !important; }
            .fan-section-heading { font-size: 14px !important; }
            .fan-kpi-card { padding: 10px 8px !important; }
            .fan-kpi-info-val { font-size: 18px !important; }
            .fan-kpi-info-label { font-size: 10px !important; }
            #donut-conic-circle { width: 90px !important; height: 90px !important; }
            .fan-donut-hole { width: 60px !important; height: 60px !important; }
            .fan-donut-big-val { font-size: 15px !important; }
        }

        /* ── HEADER LAYOUT CLASSES (responsive) ── */
        .fan-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }
        .fan-header-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .fan-header-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }
        .fan-header-subtitle {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .fan-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            flex-shrink: 0;
            position: relative;
        }
        .fan-icon-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        html[data-theme="dark"] .fan-icon-btn {
            background: #1e293b;
            border-color: #334155;
            color: #94a3b8;
        }
        html[data-theme="dark"] .fan-icon-btn:hover {
            background: #334155;
            border-color: #475569;
            color: #f1f5f9;
        }
        .fan-icon-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            border: 1.5px solid #ffffff;
        }
        html[data-theme="dark"] .fan-icon-badge {
            border-color: #1e293b;
        }

        /* School badge in header (logo or initials) */
        .fan-header-school {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }
        .fan-header-logo-box {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid #a7f3d0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        html[data-theme="dark"] .fan-header-logo-box {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.35);
        }
        .fan-header-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .fan-header-logo-init {
            font-size: 12px;
            font-weight: 800;
            color: #059669;
        }
        html[data-theme="dark"] .fan-header-logo-init {
            color: #34d399;
        }

        @media (max-width: 412px) {
            .fan-top-header {
                padding: 10px 12px !important;
                gap: 8px !important;
            }
            .fan-top-actions form {
                max-width: 100% !important;
                flex: 1 !important;
            }
            .fan-search-input { font-size: 12px; }
            .btn-outline.btn-sm { display: none; }
            .fan-kpi-grid { gap: 8px; }
            table.fan-table, table.fan-table thead, table.fan-table tbody, table.fan-table th, table.fan-table td, table.fan-table tr { display: block; }
            table.fan-table thead tr { position: absolute; top: -9999px; left: -9999px; }
            table.fan-table tr { margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #fff; }
            html[data-theme="dark"] table.fan-table tr { background: #1e293b; border-color: #334155; }
            table.fan-table td { padding: 6px 4px !important; text-align: right; position: relative; padding-left: 45% !important; white-space: normal !important; }
            table.fan-table td:before { position: absolute; left: 8px; width: 42%; padding-right: 8px; white-space: nowrap; font-weight: 700; color: #64748b; text-align: left; }
            table.fan-table td:nth-of-type(1):before { content: "No"; }
            table.fan-table td:nth-of-type(2):before { content: "Ujian"; }
            table.fan-table td:nth-of-type(3):before { content: "Token"; }
            table.fan-table td:nth-of-type(4):before { content: "Jadwal"; }
            table.fan-table td:nth-of-type(5):before { content: "Partisipasi"; }
            table.fan-table td:nth-of-type(6):before { content: "Status"; }
            table.fan-table td:nth-of-type(7):before { content: "Aksi"; }
            .fan-donut-center-wrap { justify-content: center !important; }
            #donut-conic-circle { width: 120px !important; height: 120px !important; }
            .fan-bar-chart { overflow-x: auto; }
            .fan-live-banner { flex-direction: column; align-items: flex-start !important; }
        }

        @media (max-width: 375px) {
            .fan-main-content { padding: 8px 8px 24px 8px !important; }
            .fan-top-header { padding: 6px 8px !important; gap: 4px !important; }
            .fan-section-heading { font-size: 14px !important; }
            .fan-kpi-card { padding: 10px 8px !important; }
            .fan-kpi-info-val { font-size: 18px !important; }
            .fan-kpi-info-label { font-size: 10px !important; }
            #donut-conic-circle { width: 90px !important; height: 90px !important; }
            .fan-donut-hole { width: 60px !important; height: 60px !important; }
            .fan-donut-big-val { font-size: 15px !important; }
        }

        /* ── MOBILE HEADER PROFILE (visible ≤1024px, hidden ≤600px) ── */
        .fan-mobile-profile {
            display: none;
            align-items: center;
            gap: 7px;
            padding: 5px 9px 5px 5px;
            background: #f0fdf4;
            border: 1px solid #d1fae5;
            border-radius: 10px;
            flex-shrink: 0;
        }
        html[data-theme="dark"] .fan-mobile-profile {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
        }
        .fan-mobile-profile-avatar {
            width: 26px; height: 26px; border-radius: 7px;
            background: linear-gradient(135deg, #34d399, #059669);
            color: #fff; font-size: 10px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .fan-mobile-profile-name {
            font-size: 11px; font-weight: 700; color: #064e3b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 90px;
        }
        .fan-mobile-profile-role {
            font-size: 9px; font-weight: 600; color: #059669;
        }

        @media (max-width: 1024px) {
            .fan-mobile-profile { display: flex; }
            /* disable hover transform on touch */
            .fan-kpi-card:hover { transform: none; box-shadow: 0 4px 16px -4px rgba(15,23,42,0.04); }
            .insight-alerts { flex-direction: column; }
            .insight-card.info[style*="flex:2"] { min-width: unset; flex: unset; }
        }
    </style>
    <script>
    (function () {
        try {
            var t = localStorage.getItem('cbt_theme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) {}
    })();

    // Toggle Mobile Drawer Sidebar
    function toggleFanSidebar(open) {
        var sb = document.getElementById('fanSidebar');
        var bd = document.getElementById('fanBackdrop');
        if (!sb) return;
        var isOpen = sb.classList.contains('open');
        var shouldOpen = (open !== undefined) ? open : !isOpen;

        if (shouldOpen) {
            sb.classList.add('open');
            if (bd) bd.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else {
            sb.classList.remove('open');
            if (bd) bd.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // Toggle Dropdown (Notifikasi & Profil User)
    function toggleFanDropdown(menuId, e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        var menu = document.getElementById(menuId);
        if (!menu) return;
        var isOpen = menu.classList.contains('show');
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
        if (!isOpen) {
            menu.classList.add('show');
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                m.classList.remove('show');
            });
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                m.classList.remove('show');
            });
            toggleFanSidebar(false);
        }
    });

    // Fix Chrome DevTools F12 bug: DevTools mengubah ukuran viewport & memicu GPU recomposite,
    // yang kadang membuat tbody di dalam chain overflow: hidden hilang dari render.
    var _cbtVW = window.innerWidth;
    function _cbtRestoreContent() {
        console.log('[CBT] Restore triggered vw=' + window.innerWidth);
        // Toggle display pada canvas utama → paksa Chromium me-composite ulang seluruh tree
        var c = document.querySelector('.fan-app-canvas');
        if (c) {
            c.style.display = 'none';
            requestAnimationFrame(function () { c.style.display = ''; });
        }
        // Cari tbody kosong di viewport meski punya baris data → force restore
        var tboxes = document.querySelectorAll('table.table tbody');
        var anyRestored = false;
        tboxes.forEach(function(tb) {
            if (!tb.rows.length) return;
            var h = tb.getBoundingClientRect().height;
            var ch = tb.clientHeight;
            console.log('[CBT] tbody rows=' + tb.rows.length + ' getBBox.h=' + h.toFixed(1) + ' clientH=' + ch);
            if (h < 3 && ch < 3) {
                anyRestored = true;
                // Force reflow chain: tbody → tr → td
                tb.offsetHeight;
                Array.from(tb.rows).forEach(function(tr) { tr.offsetHeight; });
                // Trigger card repaint jika card masih overflow:hidden
                var card = tb.closest('.card');
                if (card && card.style.overflow === 'hidden') {
                    card.style.overflow = 'visible';
                    card.offsetHeight;
                    card.style.overflow = '';
                }
            }
        });
        // Jika ada tbody yang perlu dipulihkan, coba force full page repain
        if (anyRestored) {
            document.body.style.opacity = '0.99';
            requestAnimationFrame(function () {
                document.body.style.opacity = '';
            });
        }
    }
    window.addEventListener('load', _cbtRestoreContent);
    // Safety net: MutationObserver memantau perubahan dimensi .fan-main-content.
    // Jika tinggi turun drastis (indikasi Chromium glitch), otomatis panggil restore.
    try {
        var _cbtMainEl = document.querySelector('.fan-main-content');
        if (_cbtMainEl) {
            new MutationObserver(function () {
                var h = _cbtMainEl.clientHeight;
                var expectedH = Math.max(200, window.innerHeight - 160);
                if (h < expectedH * 0.3 && h > 0) {
                    console.log('[CBT] Safety net: main-content too small (' + h + 'px), triggering restore');
                    setTimeout(_cbtRestoreContent, 20);
                }
            }).observe(_cbtMainEl, { attributes: true, attributeFilter: ['style', 'class'] });
        }
    } catch (e) {}
    // ResizeObserver mendeteksi perubahan ukuran viewport (termasuk saat DevTools buka/tutup)
    try {
        new ResizeObserver(function () {
            var delta = window.innerWidth - _cbtVW;
            if (delta > 40) {
                console.log('[CBT] ResizeObserver delta=' + delta.toFixed(0) + 'px');
                setTimeout(_cbtRestoreContent, 50);
            }
            _cbtVW = window.innerWidth;
        }).observe(document.body);
    } catch (e) {}
    // Tangkap F12 press langsung sebelum DevTools berubah
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F12' || e.keyCode === 123) {
            console.log('[CBT] F12 pressed → scheduling restore in 300ms');
            setTimeout(_cbtRestoreContent, 300);
        }
        if (e.key === 'Escape' || e.keyCode === 27) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                m.classList.remove('show');
            });
            toggleFanSidebar(false);
        }
    });
    console.log('[CBT] DevTools fix v3 active — open Console untuk melihat log [CBT]');
    </script>
</head>
<body class="admin-fan-page">

<div class="fan-app-canvas">

    <!-- SIDEBAR (REFINED EMERALD SLATE + GROUPED NAVIGATION) -->
    <aside class="fan-sidebar" id="fanSidebar">
        <div>
            <!-- BRAND TITLE (CBT ONLINE & SYNCED SCHOOL LOGO) -->
            <div class="fan-sidebar-brand-row">
                <a href="<?= htmlspecialchars($portalDashboardUrl) ?>" class="fan-sidebar-brand" style="display: flex; align-items: center; gap: 11px; text-decoration: none; color: inherit; min-width: 0; flex: 1;" title="Kembali ke Portal Veyra: <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>">
                    <div class="fan-sidebar-brand-box">
                        <?php if ($schoolLogo): ?>
                            <img src="<?= htmlspecialchars($schoolLogo) ?>" alt="Logo <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>" class="fan-sidebar-brand-img">
                        <?php else: ?>
                            <span class="fan-sidebar-brand-init"><?= $schoolInitials ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="min-width: 0; flex: 1;">
                        <div style="font-size: 13.5px; font-weight: 800; letter-spacing: -0.2px; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>">
                            <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?>
                        </div>
                        <div style="font-size: 10px; color: rgba(255, 255, 255, 0.72); font-weight: 700; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 5px;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399; display: inline-block;"></span>
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($school['code']) ?> &bull; CBT Panel</span>
                        </div>
                    </div>
                </a>
                <button type="button" class="fan-sidebar-close" onclick="toggleFanSidebar(false)" aria-label="Tutup Menu">&times;</button>
            </div>

            <!-- COMPACT USER PROFILE CARD -->
            <div class="fan-user-profile">
                <div class="fan-avatar-ring">
                    <div class="fan-avatar-inner"><?= $initials ?></div>
                </div>
                <div style="min-width: 0; flex: 1;">
                    <div class="fan-user-name"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                    <div class="fan-user-id"><?= ($user['role'] === 'teacher' ? 'Guru Pengampu' : 'Administrator Sekolah') ?></div>
                </div>
            </div>

            <!-- CBT NAVIGATION ITEMS (GROUPED & PROPORTIONAL) -->
            <nav class="fan-sidebar-nav">
                <div class="fan-nav-section-label">Menu Utama</div>

                <a href="index.php" class="fan-nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg>
                        <span>Dashboard</span>
                    </div>
                </a>

                <a href="exams.php" class="fan-nav-item <?= $currentPage === 'exams.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span>Jadwal Ujian</span>
                    </div>
                </a>

                <a href="question-banks.php" class="fan-nav-item <?= in_array($currentPage, ['question-banks.php', 'questions.php']) && empty($_GET['exam_type']) ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        <span>Bank Soal</span>
                    </div>
                </a>

                <a href="monitor.php" class="fan-nav-item <?= $currentPage === 'monitor.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <span>Live Monitor</span>
                    </div>
                    <?php if ($notifBlocked > 0 || !empty($notifExams)): ?>
                        <span class="fan-nav-badge"><?= $notifBlocked > 0 ? $notifBlocked : count($notifExams) ?></span>
                    <?php endif; ?>
                </a>

                <a href="results.php" class="fan-nav-item <?= $currentPage === 'results.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        <span>Rekap Nilai</span>
                    </div>
                </a>

                <a href="analytics.php" class="fan-nav-item <?= $currentPage === 'analytics.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9z"></path><path d="M21 3v9h-9"></path></svg>
                        <span>Analytics</span>
                    </div>
                </a>

                <a href="grading.php" class="fan-nav-item <?= $currentPage === 'grading.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        <span>Koreksi Essay</span>
                    </div>
                </a>

                <div class="fan-nav-section-label">Data Akademik</div>

                <a href="students.php" class="fan-nav-item <?= $currentPage === 'students.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span>Data Siswa</span>
                    </div>
                </a>

                <a href="classes.php" class="fan-nav-item <?= $currentPage === 'classes.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                        <span>Data Kelas</span>
                    </div>
                </a>

                <a href="subjects.php" class="fan-nav-item <?= $currentPage === 'subjects.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span>Mata Pelajaran</span>
                    </div>
                </a>

                <a href="exam-types.php" class="fan-nav-item <?= $currentPage === 'exam-types.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Jenis Ujian</span>
                    </div>
                </a>

                <div class="fan-nav-section-label">Dokumen &amp; Cetak</div>

                <a href="student-transcript.php" target="_blank" class="fan-nav-item <?= $currentPage === 'student-transcript.php' ? 'active' : '' ?>" title="Cetak Transkrip Raport Hasil Asesmen CBT Siswa">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        <span>Transkrip Rapor ↗</span>
                    </div>
                </a>

                <a href="student-cards.php" target="_blank" class="fan-nav-item <?= $currentPage === 'student-cards.php' ? 'active' : '' ?>" title="Cetak ID Card & Kartu Peserta Ujian">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"></rect><circle cx="9" cy="10" r="2"></circle><line x1="15" y1="8" x2="17" y2="8"></line><line x1="15" y1="12" x2="17" y2="12"></line><line x1="7" y1="16" x2="17" y2="16"></line></svg>
                        <span>Kartu Peserta ↗</span>
                    </div>
                </a>

                <a href="bau.php" target="_blank" class="fan-nav-item <?= $currentPage === 'bau.php' ? 'active' : '' ?>">
                    <div class="fan-nav-content">
                        <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        <span>Berita Acara ↗</span>
                    </div>
                </a>

                <?php if ($isAdmin): ?>
                    <div class="fan-nav-section-label">Pengaturan</div>
                    <a href="users.php" class="fan-nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                        <div class="fan-nav-content">
                            <svg class="fan-nav-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>Akun Guru &amp; Admin</span>
                        </div>
                    </a>
                    <a href="settings.php" class="fan-nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                        <div class="fan-nav-content">
                            <svg class="fan-nav-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <span>Pengaturan Sekolah</span>
                        </div>
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- SIDEBAR BOTTOM -->
        <div class="fan-sidebar-bottom">
            <a href="<?= htmlspecialchars($portalDashboardUrl) ?>" class="fan-portal-return-btn" title="Kembali ke Dashboard Portal Utama Veyra Educate">
                <svg class="fan-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" style="width: 15px; height: 15px;">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Portal Veyra</span>
                <span class="fan-portal-badge">PORTAL ↗</span>
            </a>

            <div style="display: flex; gap: 6px;">
                <a href="profile.php" class="fan-logout-btn" style="flex: 1; justify-content: center; background: rgba(255,255,255,0.06);">
                    <svg class="fan-nav-svg" viewBox="0 0 24 24" style="width: 15px; height: 15px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <span>Profil</span>
                </a>
                <a href="../logout.php" class="fan-logout-btn" style="flex: 1; justify-content: center; background: rgba(239,68,68,0.14); color: #fca5a5;">
                    <svg class="fan-nav-svg" viewBox="0 0 24 24" style="width: 15px; height: 15px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Keluar</span>
                </a>
            </div>
            <div class="fan-sidebar-copy">&copy; <?= date('Y') ?> <?= htmlspecialchars($school['code']) ?> &bull; Veyra CBT</div>
        </div>
    </aside>

    <!-- Backdrop Overlay for Mobile Drawer -->
    <div class="fan-sidebar-backdrop" id="fanBackdrop" onclick="toggleFanSidebar(false)"></div>

    <!-- MAIN CONTENT -->
    <main class="fan-main-content page-enter">
        <!-- TOP ACTIONS & SEARCH -->
        <div class="fan-top-header">
            <!-- LEFT: Hamburger + School Identity + Page Title -->
            <div class="fan-header-left">
                <!-- Mobile Menu Toggle Button -->
                <button type="button" class="fan-menu-toggle" id="fanMenuToggle" onclick="toggleFanSidebar(true)" aria-label="Buka Menu" title="Buka Menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                <!-- School Logo / Initials -->
                <div class="fan-header-school">
                    <div class="fan-header-logo-box">
                        <?php if ($schoolLogo): ?>
                            <img src="<?= htmlspecialchars($schoolLogo) ?>" alt="Logo">
                        <?php else: ?>
                            <span class="fan-header-logo-init"><?= $schoolInitials ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="min-width:0;overflow:hidden;">
                        <div class="fan-header-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard Admin') ?></div>
                        <div class="fan-header-subtitle"><?= htmlspecialchars(mb_substr($school['name'] ?? 'CBT Online', 0, 18)) ?> &bull; <?= strtoupper(htmlspecialchars($school['package'] ?? 'PRO')) ?></div>
                    </div>
                </div>
                <!-- Mobile User Profile Pill -->
                <div class="fan-mobile-profile" title="Profil Saya" onclick="toggleFanDropdown('userMenu', event)">
                    <div class="fan-mobile-profile-avatar"><?= $initials ?></div>
                    <div style="min-width:0;overflow:hidden;">
                        <div class="fan-mobile-profile-name"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                        <div class="fan-mobile-profile-role"><?= $isAdmin ? 'Admin' : 'Guru' ?></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Search + Actions -->
            <div class="fan-header-right">
                <form action="students.php" method="GET" style="display: flex; align-items: center; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 5px 10px; gap: 6px; width: 100%; max-width: 200px; flex-shrink: 1;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" name="q" placeholder="Cari siswa..." style="border: none; background: transparent; outline: none; font-size: 11.5px; width: 100%; color: #1e293b; font-family: inherit;">
                    </form>

                    <a href="../student/dashboard.php" target="_blank" class="btn btn-outline btn-sm" style="border-radius: 10px; font-size: 11px; font-weight: 700; padding: 6px 10px; gap: 5px; display: inline-flex; align-items: center; border-color: #e2e8f0; color: #334155; background: #ffffff; text-decoration: none;" title="Buka Portal Siswa">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        <span>Preview</span>
                    </a>

                    <button type="button" class="fan-icon-btn" id="themeToggle" title="Ganti Mode Gelap / Terang">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </button>

                    <div class="dropdown-wrap" style="position: relative;">
                        <button type="button" class="fan-icon-btn" id="notifBell" onclick="toggleFanDropdown('notifMenu', event)" title="Notifikasi Ujian & Aktivitas">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <?php if ($totalNotif > 0): ?>
                                <span class="fan-icon-badge" style="background: <?= $notifBlocked > 0 ? '#ef4444' : '#10b981' ?>;"></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right" id="notifMenu" onclick="event.stopPropagation()">
                            <div class="dropdown-title">
                                <span>🔔 Notifikasi Ujian</span>
                                <?php if ($totalNotif > 0): ?>
                                    <span style="background: #059669; color: #ffffff; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;"><?= $totalNotif ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($notifBlocked > 0): ?>
                                <a href="monitor.php" class="dropdown-item" style="background: #fef2f2; border-left: 3px solid #ef4444;">
                                    <div style="font-weight: 700; color: #b91c1c;">⚠️ <?= $notifBlocked ?> Siswa Terblokir</div>
                                    <div class="dropdown-sub" style="color: #991b1b;">Pelanggaran terdeteksi &bull; Buka Live Monitor &rarr;</div>
                                </a>
                            <?php endif; ?>

                            <?php if ($notifLiveCount > 0): ?>
                                <a href="monitor.php" class="dropdown-item" style="background: #f0fdf4; border-left: 3px solid #10b981;">
                                    <div style="font-weight: 700; color: #047857;">🟢 <?= $notifLiveCount ?> Siswa Sedang Ujian</div>
                                    <div class="dropdown-sub" style="color: #065f46;">Sesi berlangsung &bull; Buka Live Monitor &rarr;</div>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($notifExams)): ?>
                                <?php foreach ($notifExams as $ne): ?>
                                    <a href="monitor.php?exam_id=<?= (int)$ne['id'] ?>" class="dropdown-item">
                                        <div style="font-weight: 700; color: #1e293b;">📝 <?= htmlspecialchars($ne['title']) ?></div>
                                        <div class="dropdown-sub">Berakhir <?= date('d M Y, H:i', strtotime($ne['end_time'])) ?> &bull; Monitor &rarr;</div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($totalNotif === 0): ?>
                                <div style="padding: 24px 16px; text-align: center; color: #64748b;">
                                    <div style="font-size: 26px; margin-bottom: 6px; color: #10b981;">&#10004;</div>
                                    <div style="font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 2px;">Semua Sesi Normal</div>
                                    <div style="font-size: 11.5px; color: #94a3b8;">Tidak ada siswa terblokir atau ujian aktif mendesak.</div>
                                </div>
                            <?php endif; ?>

                            <div style="padding: 10px 16px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; font-size: 11.5px;">
                                <a href="monitor.php" style="color: #059669; font-weight: 700; text-decoration: none;">Live Monitor &rarr;</a>
                                <a href="exams.php" style="color: #64748b; font-weight: 600; text-decoration: none;">Jadwal Ujian</a>
                            </div>
                        </div>
                    </div>

                    <div class="dropdown-wrap" style="position: relative;">
                        <button type="button" class="fan-icon-btn" id="userPillBtn" onclick="toggleFanDropdown('userMenu', event)" title="Menu Pengguna" style="width: auto; padding: 0 8px; gap: 6px;">
                            <span style="width: 24px; height: 24px; border-radius: 7px; background: #059669; color: #fff; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center;"><?= $initials ?></span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right" id="userMenu" onclick="event.stopPropagation()" style="right: 0; min-width: 200px !important;">
                            <div style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; background: #f8fafc;">
                                <div style="font-weight: 700; font-size: 13px; color: #0f172a;"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;"><?= ucfirst(htmlspecialchars($user['role'] ?? 'User')) ?> &bull; <?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                            </div>
                            <a href="profile.php" class="dropdown-item">⚙️ Profil &amp; Keamanan Akun</a>
                            <a href="<?= htmlspecialchars($portalDashboardUrl) ?>" class="dropdown-item">🌐 Kembali ke Portal Veyra</a>
                            <a href="../logout.php" class="dropdown-item" style="color: #ef4444; font-weight: 700;">🚪 Keluar Sesi</a>
                        </div>
                    </div>
            </div>
        </div>

        <?php if (!empty($_SESSION['impersonator_superadmin'])): ?>
            <div style="background: linear-gradient(90deg, #dc2626 0%, #b91c1c 100%); color: #ffffff; padding: 12px 20px; border-radius: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 14px rgba(220,38,38,0.25); margin-bottom: 18px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 18px;">&#9888;</span>
                    <span>Mode Impersonasi Superadmin: Anda sedang melihat panel <u><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></u> sebagai <u><?= htmlspecialchars($user['name'] ?? '-') ?></u>.</span>
                </div>
                <a href="../superadmin/tenants.php?exit_impersonate=1" class="btn btn-sm" style="background: #ffffff; color: #dc2626; font-weight: 700; border-radius: 9999px; text-decoration: none; padding: 6px 16px; white-space: nowrap; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                    &larr; Keluar & Kembali ke Superadmin
                </a>
            </div>
        <?php endif; ?>

        <?php if (Auth::isDefaultTenantAdminPassword()): ?>
            <div style="background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border: 1px solid #fca5a5; border-left: 6px solid #dc2626; border-radius: 16px; padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.08);">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <span style="font-size: 26px; line-height: 1;">⚠️</span>
                    <div>
                        <div style="font-weight: 800; color: #991b1b; font-size: 14px;">PERINGATAN KEAMANAN: Kata Sandi Default Masih Digunakan!</div>
                        <div style="color: #7f1d1d; font-size: 12.5px; margin-top: 2px;">Akun Anda masih menggunakan kata sandi bawaan sistem. Segera perbarui kata sandi Anda demi menjaga keamanan data sekolah dan ujian.</div>
                    </div>
                </div>
                <a href="profile.php" class="btn btn-sm" style="background: #dc2626; color: #ffffff; font-weight: 700; border-radius: 9999px; padding: 8px 18px; white-space: nowrap; text-decoration: none; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);">
                    Ganti Sandi Sekarang &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" style="border-radius: 14px; margin-bottom: 18px;">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Idle Timeout Warning Modal -->
        <div class="modal-backdrop" id="idleTimeoutModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.6); align-items: center; justify-content: center;">
            <div class="modal-box" style="background: #ffffff; border-radius: 24px; padding: 32px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                <div style="font-size: 48px; margin-bottom: 16px;">&#9200;</div>
                <h3 style="font-size: 18px; font-weight: 800; color: #1e293b; margin-bottom: 8px;">Sesi Akan Berakhir</h3>
                <p style="color: #64748b; font-size: 13.5px; margin-bottom: 20px;">Anda tidak aktif selama beberapa saat. Sesi Anda akan berakhir dalam <strong id="idleCountdown">2</strong> menit.</p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="profile.php" class="btn btn-primary" style="flex: 1; border-radius: 9999px;">Lanjutkan Sesi</a>
                    <a href="../logout.php" class="btn btn-secondary" style="flex: 1; border-radius: 9999px;">Logout</a>
                </div>
            </div>
        </div>
