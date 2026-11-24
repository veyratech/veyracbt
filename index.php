<?php
/**
 * CBT ONLINE — Portal Login Utama
 * Clean Modern Enterprise Split Aesthetic (Upteamist Blue Style)
 */

declare(strict_types=1);

// Disable error display in production
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Tenant.php';
require_once __DIR__ . '/core/Helper.php';
require_once __DIR__ . '/sso_client.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Jika ada token SSO di root URL (?token=...), teruskan langsung ke handler SSO Callback
if (!empty($_GET['token'])) {
    require __DIR__ . '/sso/callback.php';
    exit;
}

Helper::checkMaintenance();

$ssoLoginUrl = sso()->getLoginUrl();

// ── Sudah login? Redirect ke dashboard sesuai role ──
if (Auth::check()) {
    $role = Auth::role();
    if ($role === 'superadmin') { Helper::redirect('superadmin/index.php'); exit; }
    if (in_array($role, ['admin', 'teacher'])) { Helper::redirect('admin/index.php'); exit; }
    if ($role === 'student')  { Helper::redirect('student/dashboard.php'); exit; }
}

// ── Subdomain detection ──
$host   = $_SERVER['HTTP_HOST'] ?? '';
$parts  = explode('.', $host);
if (count($parts) >= 2 && !in_array(strtolower($parts[0]), ['www', 'cbt', 'cbtonline', 'localhost', 'portal', 'api', 'admin'], true)) {
    $subdomain = $parts[0];
    $tenant    = Tenant::findByIdentifier($subdomain);
    if ($tenant) {
        Tenant::setTenant($tenant);
        Helper::redirect('student/login.php');
        exit;
    }
}

$flash = Helper::getFlash();

// Cek apakah SSO aktif (key sama dengan Veyra Educate & server reachable)
$ssoActive = false;
if (!empty($_ENV['SSO_SECRET_KEY']) || getenv('SSO_SECRET_KEY')) {
    $ssoActive = true;
}
$ssoLoginUrl = sso()->getLoginUrl();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Veyra CBT Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #0b63e5;
            --primary-blue-hover: #084fc2;
            --primary-blue-light: #eaf2fd;
            --navy-dark: #072559;
            --text-heading: #102a43;
            --text-body: #627d98;
            --text-muted: #829ab1;
            --border-line: #dbe2ea;
            --border-focus: #0b63e5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            background: radial-gradient(circle at 75% 20%, #1a73f8 0%, #0d5be0 38%, #0741b8 70%, #042985 100%);
            position: relative;
            overflow-x: hidden;
            color: #1e293b;
        }

        /* ── AMBIENT LUMINOUS GLOW / WATER EFFECT ── */
        .ambient-glow-1 {
            position: fixed;
            width: 700px;
            height: 700px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.22) 0%, rgba(13, 91, 224, 0) 70%);
            top: -200px;
            right: -150px;
            pointer-events: none;
            z-index: 0;
        }
        .ambient-glow-2 {
            position: fixed;
            width: 650px;
            height: 650px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(96, 165, 250, 0.18) 0%, rgba(7, 65, 184, 0) 70%);
            bottom: -200px;
            left: -150px;
            pointer-events: none;
            z-index: 0;
        }

        /* ── MAIN CARD CONTAINER ── */
        .login-card-master {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 980px;
            min-height: 560px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 30px 80px -15px rgba(2, 25, 75, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.12);
            display: grid;
            grid-template-columns: 1.12fr 1fr;
            overflow: hidden;
        }

        /* ── LEFT PANEL (ROYAL BLUE) ── */
        .card-left-blue {
            background: var(--primary-blue);
            padding: 44px 44px 38px 44px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #ffffff;
            position: relative;
        }

        /* Brand Row */
        .brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 2;
        }
        .brand-logo-icon {
            width: 38px;
            height: 38px;
            background: #ffffff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
            font-size: 20px;
            font-weight: 800;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        .brand-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #ffffff;
        }

        /* Center Vector Illustration */
        .illustration-wrap {
            width: 100%;
            max-width: 320px;
            margin: 18px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }
        .illustration-wrap svg {
            width: 100%;
            height: auto;
            filter: drop-shadow(0 14px 22px rgba(4, 38, 95, 0.3));
        }

        /* Bottom Welcome Block */
        .welcome-block {
            z-index: 2;
        }
        .welcome-title {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
            line-height: 1.15;
            color: #ffffff;
        }
        .welcome-desc {
            font-size: 13.5px;
            color: rgba(255, 255, 255, 0.88);
            line-height: 1.5;
            margin-bottom: 22px;
            max-width: 380px;
        }

        /* Pagination / Carousel Dots */
        .carousel-dots {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.7);
            transition: all 0.2s;
        }
        .dot.active {
            background: #ffffff;
            border-color: #ffffff;
            width: 9px;
            height: 9px;
        }

        /* ── RIGHT PANEL (PURE WHITE) ── */
        .card-right-white {
            background: #ffffff;
            padding: 48px 46px 40px 46px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-heading {
            font-size: 30px;
            font-weight: 700;
            color: var(--primary-blue);
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        .login-subheading-row {
            font-size: 13px;
            color: var(--text-body);
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .login-subheading-row a {
            color: var(--primary-blue);
            font-weight: 600;
            text-decoration: none;
        }
        .login-subheading-row a:hover {
            text-decoration: underline;
        }
        .login-subheading-note {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Alert Box */
        .alert-box {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-danger  { background: #fef2f2; border: 1px solid #fee2e2; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #dcfce7; color: #15803d; }

        /* Role Selection Cards in Right Panel */
        .roles-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .role-nav-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #1e293b;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .role-nav-card:hover {
            background: #ffffff;
            border-color: var(--primary-blue);
            box-shadow: 0 6px 18px rgba(11, 99, 229, 0.1);
            transform: translateY(-2px);
        }
        .role-nav-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .role-nav-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: var(--primary-blue-light);
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .role-nav-info h4 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 2px;
        }
        .role-nav-info p {
            font-size: 12px;
            color: var(--text-body);
        }
        .role-nav-arrow {
            font-size: 16px;
            color: #94a3b8;
            font-weight: 700;
            transition: transform 0.2s, color 0.2s;
        }
        .role-nav-card:hover .role-nav-arrow {
            color: var(--primary-blue);
            transform: translateX(4px);
        }
        .role-nav-card-green:hover {
            border-color: #059669;
            box-shadow: 0 6px 18px rgba(5, 150, 105, 0.16);
        }
        .role-nav-card-green .role-nav-icon {
            background: #ecfdf5;
            color: #059669;
        }
        .role-nav-card-green:hover .role-nav-arrow {
            color: #059669;
        }

        /* Divider */
        .auth-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 4px 0 16px 0;
            color: #94a3b8;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 600;
        }
        .auth-divider::before, .auth-divider::after {
            content: ''; flex: 1; border-bottom: 1px solid #e2e8f0;
        }
        .auth-divider::before { margin-right: 12px; }
        .auth-divider::after  { margin-left: 12px; }

        /* Veyra Portal SSO Button */
        .btn-portal-sso {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 8px;
            background: var(--primary-blue);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 8px 18px rgba(11, 99, 229, 0.28);
            border: none;
            width: 100%;
        }
        .btn-portal-sso:hover {
            background: var(--primary-blue-hover);
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(11, 99, 229, 0.35);
        }

        /* Footer info */
        .login-footer-notes {
            margin-top: 22px;
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.5;
        }
        .login-footer-notes a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        .login-footer-notes a:hover {
            text-decoration: underline;
        }

        /* ── RESPONSIVE DESIGN ── */
        @media (max-width: 860px) {
            .login-card-master {
                grid-template-columns: 1fr;
                border-radius: 16px;
            }
            .card-left-blue {
                padding: 30px 24px;
            }
            .illustration-wrap {
                max-width: 220px;
                margin: 12px auto;
            }
            .welcome-title {
                font-size: 26px;
            }
            .card-right-white {
                padding: 36px 24px;
            }
            .login-heading {
                font-size: 26px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 12px 10px;
                align-items: flex-start;
            }
            .login-card-master {
                border-radius: 18px;
                box-shadow: 0 15px 35px rgba(2, 25, 75, 0.25);
            }
            .card-left-blue {
                padding: 22px 18px;
            }
            .illustration-wrap {
                display: none;
            }
            .welcome-desc,
            .carousel-dots {
                display: none;
            }
            .welcome-title {
                font-size: 20px;
                margin-top: 10px;
            }
            .card-right-white {
                padding: 24px 18px 28px 18px;
            }
            .login-heading {
                font-size: 22px;
            }
            .login-portal-links {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .btn-portal {
                width: 100%;
                justify-content: center;
                padding: 14px;
                font-size: 14px;
            }
        }

        @media (max-width: 412px) {
            body {
                padding: 8px 6px;
            }
            .login-card-master {
                border-radius: 14px;
                min-height: auto;
            }
            .card-left-blue {
                padding: 18px 16px;
            }
            .brand-title {
                font-size: 17px;
            }
            .brand-logo-icon {
                width: 32px;
                height: 32px;
                font-size: 16px;
            }
            .welcome-title {
                font-size: 18px;
            }
            .card-right-white {
                padding: 22px 16px 24px 16px;
            }
            .login-heading {
                font-size: 20px;
                margin-bottom: 4px;
            }
            .login-subheading-row {
                font-size: 12px;
                margin-bottom: 18px;
            }
            .roles-list {
                gap: 10px;
                margin-bottom: 14px;
            }
            .role-nav-card {
                padding: 12px 14px;
            }
            .role-nav-info h4 {
                font-size: 13px;
            }
            .role-nav-info p {
                font-size: 11px;
            }
            .auth-divider span:last-of-type,
            .auth-divider span:first-of-type {
                flex: 1;
            }
            .login-footer-notes {
                font-size: 11px;
                padding: 0 4px;
            }
            .btn-portal-sso {
                font-size: 13px;
                padding: 11px 18px;
            }
        }

        @media (max-width: 375px) {
            body {
                padding: 6px 4px;
            }
            .login-card-master {
                border-radius: 12px;
            }
            .card-left-blue {
                padding: 14px 12px;
            }
            .card-right-white {
                padding: 18px 12px 20px 12px;
            }
            .login-heading {
                font-size: 18px;
            }
            .role-nav-card {
                padding: 10px 12px;
            }
            .role-nav-icon {
                width: 32px;
                height: 32px;
                font-size: 15px;
            }
            .role-nav-info h4 {
                font-size: 12px;
            }
            .role-nav-info p {
                font-size: 10.5px;
            }
        }
    </style>
</head>
<body>

    <!-- Ambient Glow Orbs -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- MAIN TWO-COLUMN CARD -->
    <main class="login-card-master">

        <!-- LEFT PANEL: BLUE THEME WITH EXAM CHECKLIST ILLUSTRATION -->
        <div class="card-left-blue">

            <!-- Brand Header -->
            <div class="brand-header">
                <div class="brand-logo-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                        <path d="M2 17l10 5 10-5"></path>
                        <path d="M2 12l10 5 10-5"></path>
                    </svg>
                </div>
                <div class="brand-title">Veyra CBT</div>
            </div>

            <!-- Crisp Vector Exam Checklist Clipboard SVG -->
            <div class="illustration-wrap">
                <svg viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Ambient Pill Shapes -->
                    <rect x="70" y="90" width="180" height="26" rx="13" fill="rgba(255,255,255,0.14)" />
                    <rect x="40" y="125" width="110" height="20" rx="10" fill="rgba(255,255,255,0.1)" />
                    <rect x="180" y="135" width="90" height="18" rx="9" fill="rgba(255,255,255,0.12)" />

                    <!-- Ambient Accent Dots & Plus -->
                    <circle cx="65" cy="75" r="3.5" fill="rgba(255,255,255,0.35)" />
                    <circle cx="250" cy="85" r="4" fill="rgba(255,255,255,0.25)" />
                    <circle cx="50" cy="180" r="3" fill="rgba(255,255,255,0.3)" />
                    <g stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round">
                        <line x1="85" y1="50" x2="85" y2="58" />
                        <line x1="81" y1="54" x2="89" y2="54" />
                    </g>

                    <!-- Clipboard Group (Dynamic rotation) -->
                    <g transform="rotate(-6 160 140)">
                        <!-- Clipboard Backboard -->
                        <rect x="96" y="38" width="128" height="174" rx="14" fill="#04265f" />
                        <rect x="98" y="36" width="128" height="174" rx="14" fill="#0353c7" />

                        <!-- White Paper Sheet -->
                        <rect x="104" y="44" width="116" height="158" rx="8" fill="#ffffff" />

                        <!-- Top Clip Mechanism -->
                        <rect x="132" y="30" width="60" height="24" rx="6" fill="#38bdf8" />
                        <rect x="135" y="32" width="54" height="20" rx="5" fill="#0284c7" />
                        <rect x="151" y="24" width="22" height="12" rx="4" fill="#38bdf8" />
                        <rect x="154" y="26" width="16" height="6" rx="2" fill="#0b63e5" />
                        <circle cx="162" cy="42" r="3" fill="#ffffff" />

                        <!-- Checklist Item 1 (✓ Check) -->
                        <g transform="translate(118, 68)">
                            <path d="M4 10 L8 14 L16 4" stroke="#0284c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="56" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="42" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="48" height="3" rx="1.5" fill="#bae6fd" />
                        </g>

                        <!-- Checklist Item 2 (✗ Cross) -->
                        <g transform="translate(118, 102)">
                            <path d="M5 5 L15 15 M15 5 L5 15" stroke="#0284c7" stroke-width="3" stroke-linecap="round" fill="none" />
                            <rect x="24" y="3" width="52" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="46" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="38" height="3" rx="1.5" fill="#bae6fd" />
                        </g>

                        <!-- Checklist Item 3 (✓ Check) -->
                        <g transform="translate(118, 136)">
                            <path d="M4 10 L8 14 L16 4" stroke="#0284c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="58" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="50" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="40" height="3" rx="1.5" fill="#bae6fd" />
                        </g>
                    </g>

                    <!-- Stylus / Digital Pen -->
                    <g transform="translate(56, 138) rotate(-42)">
                        <rect x="2" y="14" width="76" height="16" rx="8" fill="rgba(0,25,80,0.35)" />
                        <rect x="0" y="0" width="74" height="16" rx="8" fill="#38bdf8" />
                        <rect x="46" y="0" width="18" height="16" fill="#0284c7" />
                        <rect x="8" y="3" width="50" height="3" rx="1.5" fill="#ffffff" opacity="0.75" />
                        <path d="M74 0 L88 8 L74 16 Z" fill="#072047" />
                        <circle cx="88" cy="8" r="1.5" fill="#38bdf8" />
                        <rect x="-4" y="2" width="6" height="12" rx="3" fill="#072047" />
                    </g>
                </svg>
            </div>

            <!-- Welcome Text & Carousel Indicators -->
            <div class="welcome-block">
                <h2 class="welcome-title">Welcome!</h2>
                <p class="welcome-desc">
                    Sistem Ujian Online Terpadu &amp; Portal Penilaian Sekolah Berbasis CBT Modern.
                </p>
                <div class="carousel-dots">
                    <span class="dot active"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>

        </div>

        <!-- RIGHT PANEL: CLEAN WHITE LOG IN FLOW -->
        <div class="card-right-white">

            <h1 class="login-heading">Log In</h1>
            <div class="login-subheading-row">
                <span>Belum memiliki akun? <a href="javascript:void(0)" onclick="alert('Pendaftaran akun Guru dan Siswa dikelola langsung oleh Operator Sekolah masing-masing.')">Hubungi Operator</a></span>
                <span class="login-subheading-note">Pilih peran Anda untuk masuk ke sistem:</span>
            </div>

            <?php if (!empty($flash)): ?>
                <div class="alert-box alert-<?= htmlspecialchars($flash['type']) ?>">
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <!-- Veyra Portal SSO Button (hanya tampil jika SSO diaktifkan di .env) -->
            <?php if ($ssoActive): ?>
            <div style="margin-bottom: 16px;">
                <a href="<?= htmlspecialchars($ssoLoginUrl) ?>" class="btn-portal-sso" id="btnSsoLogin">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                        <polyline points="10 17 15 12 10 7"></polyline>
                        <line x1="15" y1="12" x2="3" y2="12"></line>
                    </svg>
                    <span>Masuk dengan Akun Veyra Educate</span>
                </a>
            </div>
            <?php endif; ?>

            <div class="auth-divider" style="margin: 16px 0; display: flex; align-items: center; text-align: center; color: var(--text-muted); font-size: 13px;">
                <span style="flex: 1; border-bottom: 1px solid #e2e8f0;"></span>
                <span style="padding: 0 12px;">atau login manual</span>
                <span style="flex: 1; border-bottom: 1px solid #e2e8f0;"></span>
            </div>

            <!-- Role Options -->
            <div class="roles-list">
                <!-- 1. Siswa -->
                <a href="student/login.php" class="role-nav-card">
                    <div class="role-nav-left">
                        <div class="role-nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                            </svg>
                        </div>
                        <div class="role-nav-info">
                            <h4>Masuk sebagai Siswa</h4>
                            <p>Mulai ujian aktif dengan NIS &amp; sesi sekolah</p>
                        </div>
                    </div>
                    <span class="role-nav-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </span>
                </a>

                <!-- 2. Guru / Pengajar -->
                <a href="admin/login.php" class="role-nav-card role-nav-card-green">
                    <div class="role-nav-left">
                        <div class="role-nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                                <path d="M9 7h6M9 11h6"></path>
                            </svg>
                        </div>
                        <div class="role-nav-info">
                            <h4>Masuk sebagai Guru</h4>
                            <p>Kelola bank soal &amp; rekap nilai siswa</p>
                        </div>
                    </div>
                    <span class="role-nav-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </span>
                </a>
            </div>


            <!-- Footer notes -->
            <div class="login-footer-notes">
                Butuh bantuan teknis ujian? Hubungi Proktor / Tim IT Sekolah
            </div>

        </div>

    </main>

</body>
</html>
