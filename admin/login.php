<?php
/**
 * Tenant Guru & Pengajar Login — Portal Sekolah
 * Redesigned with Upteamist Blue Style (Modern Enterprise Split Layout)
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../sso_client.php';

if (session_status() === PHP_SESSION_NONE) session_start();
Helper::checkMaintenance();

if (Auth::check() && in_array(Auth::role(), ['admin', 'teacher'])) {
    Helper::redirect('index.php');
}

$activeTenant = Tenant::init();

$ssoLoginUrl = sso()->getLoginUrl();
$error = '';
$flash = Helper::getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $username   = trim($_POST['username'] ?? '');
    $password   = trim($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        // Cari akun user berdasarkan username/email di SELURUH sekolah aktif
        $candidateUsers = Database::fetchAll(
            "SELECT u.*, t.id as tenant_id, t.code as tenant_code, t.name as tenant_name
             FROM users u
             JOIN tenants t ON u.tenant_id = t.id
             WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1 AND t.is_active = 1",
            [$username, $username]
        );

        $found = false;
        foreach ($candidateUsers as $cu) {
            if (password_verify($password, $cu['password'])) {
                Tenant::setTenant([
                    'id'   => $cu['tenant_id'],
                    'code' => $cu['tenant_code'],
                    'name' => $cu['tenant_name'],
                ]);
                if (Auth::loginTenantUser((int)$cu['tenant_id'], $username, $password)) {
                    session_regenerate_id(true);
                    if (Auth::is2faRequired()) {
                        Helper::redirect('../admin/totp-verify.php');
                    }
                    Helper::redirect('index.php');
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            $error = 'Username atau kata sandi salah.';
        }
    } else {
        $error = 'Mohon isi username dan kata sandi.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Guru &amp; Pengajar | Veyra CBT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #059669;
            --primary-green-hover: #047857;
            --primary-green-light: #ecfdf5;
            --primary-green-dark: #064e3b;
            --primary-blue: #059669;
            --primary-blue-hover: #047857;
            --primary-blue-light: #ecfdf5;
            --text-heading: #064e3b;
            --text-body: #4b5563;
            --text-muted: #6b7280;
            --border-line: #d1fae5;
            --border-focus: #059669;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            background: radial-gradient(circle at 75% 20%, #10b981 0%, #059669 35%, #047857 70%, #064e3b 100%);
            position: relative;
            overflow-x: hidden;
            color: #1e293b;
        }

        /* ── AMBIENT LUMINOUS GLOW ── */
        .ambient-glow-1 {
            position: fixed;
            width: 700px;
            height: 700px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(52, 211, 153, 0.25) 0%, rgba(5, 150, 105, 0) 70%);
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
            background: radial-gradient(circle, rgba(16, 185, 129, 0.2) 0%, rgba(6, 78, 59, 0) 70%);
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
            box-shadow: 0 30px 80px -15px rgba(6, 78, 59, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.15);
            display: grid;
            grid-template-columns: 1.12fr 1fr;
            overflow: hidden;
        }

        /* ── LEFT PANEL (FOREST EMERALD GREEN) ── */
        .card-left-blue, .card-left-green {
            background: linear-gradient(160deg, #065f46 0%, #047857 45%, #064e3b 100%);
            padding: 44px 44px 38px 44px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #ffffff;
            position: relative;
        }

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
            color: #047857;
            font-size: 20px;
            font-weight: 800;
            box-shadow: 0 4px 12px rgba(6, 78, 59, 0.18);
        }
        .brand-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #ffffff;
        }

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
            filter: drop-shadow(0 14px 22px rgba(6, 78, 59, 0.35));
        }

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
            font-size: 32px;
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

        /* Alerts */
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

        /* Auto-detected tenant chip */
        .tenant-active-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #065f46;
            background: #ecfdf5;
            border: 1px solid #d1fae5;
            padding: 6px 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .tenant-active-chip::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #059669;
        }

        /* ── MINIMALIST UNDERLINE FORM INPUTS (MATCHING IMAGE) ── */
        .login-form {
            display: flex;
            flex-direction: column;
        }

        .input-underline-group {
            position: relative;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border-line);
            transition: border-color 0.2s;
        }
        .input-underline-group:focus-within {
            border-bottom: 2px solid var(--border-focus);
        }

        .input-underline-field {
            width: 100%;
            border: none;
            background: transparent;
            padding: 10px 32px 10px 0;
            font-size: 14px;
            font-family: inherit;
            color: #1e293b;
            outline: none;
        }
        .input-underline-field::placeholder {
            color: #9aa9bc;
        }

        .input-underline-icon {
            position: absolute;
            right: 2px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa9bc;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pwd-toggle-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #9aa9bc;
            transition: color 0.2s;
        }
        .pwd-toggle-btn:hover {
            color: var(--primary-green);
        }

        /* ── ACTION ROW: BUTTON + REMEMBER ME (MATCHING IMAGE) ── */
        .action-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 8px;
            margin-bottom: 18px;
        }

        .btn-signin {
            background: var(--primary-green);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.28);
        }
        .btn-signin:hover {
            background: var(--primary-green-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(4, 120, 87, 0.38);
        }
        .btn-signin:active {
            transform: translateY(0);
        }

        .remember-checkbox {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            color: #64748b;
            cursor: pointer;
            user-select: none;
        }
        .remember-checkbox input {
            accent-color: var(--primary-green);
            width: 15px;
            height: 15px;
            cursor: pointer;
        }

        .forgot-password-link {
            font-size: 12.5px;
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 18px;
            display: inline-block;
        }
        .forgot-password-link:hover {
            text-decoration: underline;
        }

        /* Demo Chip Bar */
        .demo-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            padding: 7px 12px;
            background: #f8fafc;
            border: 1px dashed #e2e8f0;
            border-radius: 8px;
            font-size: 11.5px;
            color: #64748b;
        }
        .demo-chip {
            padding: 3px 10px;
            border-radius: 6px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .demo-chip:hover {
            background: var(--primary-green-light);
            border-color: #a7f3d0;
            color: var(--primary-green-hover);
        }

        /* Operator SSO Button */
        .btn-operator-sso {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            margin-bottom: 12px;
        }
        .btn-operator-sso:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
        }

        /* Footer */
        .footer-note {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 6px;
        }
        .footer-note a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        .footer-note a:hover {
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
                box-shadow: 0 15px 35px rgba(6, 78, 59, 0.25);
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
            .login-subheading-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 2px;
                font-size: 12px;
            }
            .input-underline-field {
                font-size: 16px;
                height: 48px;
            }
            .action-row {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }
            .btn-signin {
                width: 100%;
                height: 48px;
                font-size: 15px;
            }
            .demo-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .demo-chip {
                width: 100%;
                justify-content: center;
                padding: 10px;
                font-size: 12px;
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
                padding: 18px 14px;
            }
            .brand-title {
                font-size: 16px;
            }
            .brand-logo-icon {
                width: 30px;
                height: 30px;
                font-size: 14px;
            }
            .welcome-title {
                font-size: 18px;
            }
            .card-right-white {
                padding: 20px 14px 22px 14px;
            }
            .login-heading {
                font-size: 20px;
                margin-bottom: 4px;
            }
            .login-subheading-row {
                font-size: 12px;
                margin-bottom: 16px;
            }
            .input-underline-field {
                font-size: 15px;
                padding: 8px 28px 8px 0;
            }
            .action-row button[type="submit"] {
                font-size: 14px;
                padding: 10px 20px;
            }
            .footer-note {
                font-size: 11px;
            }
            .btn-operator-sso {
                font-size: 12px;
                padding: 9px 12px;
            }
            .tenant-active-chip {
                font-size: 11px;
                padding: 5px 10px;
            }
            .school-search-label {
                font-size: 11px;
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
                padding: 16px 12px 18px 12px;
            }
            .login-heading {
                font-size: 18px;
            }
            .input-underline-field {
                font-size: 14px;
                padding: 7px 24px 7px 0;
                height: 44px;
            }
            .action-row button[type="submit"] {
                width: 100%;
                height: 46px;
                font-size: 14px;
            }
        }

        /* ── SPLASH SCREEN ─────────────────────── */
        .splash-screen{position:fixed;inset:0;z-index:9999;background:linear-gradient(135deg,#059669 0%,#047857 50%,#064e3b 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .6s ease,visibility .6s ease;}
        .splash-screen.hide{opacity:0;visibility:hidden;pointer-events:none;}
        .splash-logo{width:80px;height:80px;background:#fff;border-radius:20px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;animation:splashPulse 1.2s ease-in-out infinite;box-shadow:0 8px 32px rgba(0,0,0,.2);}
        .splash-logo svg{width:44px;height:44px;color:#059669;}
        .splash-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:28px;font-weight:900;color:#fff;letter-spacing:-.5px;margin-bottom:6px;}
        .splash-sub{font-size:13px;color:rgba(255,255,255,.7);font-weight:500;}
        .splash-loader{margin-top:32px;width:120px;height:3px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden;}
        .splash-loader-bar{height:100%;background:#fff;border-radius:999px;animation:splashLoad 1.8s ease-in-out forwards;}
        @keyframes splashPulse{0%,100%{transform:scale(1);box-shadow:0 8px 32px rgba(0,0,0,.2)}50%{transform:scale(1.05);box-shadow:0 12px 40px rgba(0,0,0,.3)}}
        @keyframes splashLoad{0%{width:0}100%{width:100%}}
</style>
</head>
<body>

<!-- SPLASH SCREEN -->
<div class="splash-screen" id="splashScreen">
    <div class="splash-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
    </div>
    <div class="splash-title">Veyra CBT</div>
    <div class="splash-sub">Panel Guru & Pengajar</div>
    <div class="splash-loader"><div class="splash-loader-bar"></div></div>
</div>

<!-- AMBIENT GLOW ORBS -->
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
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <div class="brand-title">Veyra CBT</div>
            </div>

            <!-- Crisp Vector Exam Checklist Clipboard SVG -->
            <div class="illustration-wrap">
                <svg viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="70" y="90" width="180" height="26" rx="13" fill="rgba(255,255,255,0.14)" />
                    <rect x="40" y="125" width="110" height="20" rx="10" fill="rgba(255,255,255,0.1)" />
                    <rect x="180" y="135" width="90" height="18" rx="9" fill="rgba(255,255,255,0.12)" />

                    <circle cx="65" cy="75" r="3.5" fill="rgba(255,255,255,0.35)" />
                    <circle cx="250" cy="85" r="4" fill="rgba(255,255,255,0.25)" />
                    <circle cx="50" cy="180" r="3" fill="rgba(255,255,255,0.3)" />
                    <g stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round">
                        <line x1="85" y1="50" x2="85" y2="58" />
                        <line x1="81" y1="54" x2="89" y2="54" />
                    </g>

                    <g transform="rotate(-6 160 140)">
                        <rect x="96" y="38" width="128" height="174" rx="14" fill="#064e3b" />
                        <rect x="98" y="36" width="128" height="174" rx="14" fill="#047857" />
                        <rect x="104" y="44" width="116" height="158" rx="8" fill="#ffffff" />

                        <rect x="132" y="30" width="60" height="24" rx="6" fill="#34d399" />
                        <rect x="135" y="32" width="54" height="20" rx="5" fill="#059669" />
                        <rect x="151" y="24" width="22" height="12" rx="4" fill="#34d399" />
                        <rect x="154" y="26" width="16" height="6" rx="2" fill="#047857" />
                        <circle cx="162" cy="42" r="3" fill="#ffffff" />

                        <g transform="translate(118, 68)">
                            <path d="M4 10 L8 14 L16 4" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="56" height="4" rx="2" fill="#059669" />
                            <rect x="24" y="10" width="42" height="3" rx="1.5" fill="#a7f3d0" />
                            <rect x="24" y="16" width="48" height="3" rx="1.5" fill="#a7f3d0" />
                        </g>

                        <g transform="translate(118, 102)">
                            <path d="M5 5 L15 15 M15 5 L5 15" stroke="#059669" stroke-width="3" stroke-linecap="round" fill="none" />
                            <rect x="24" y="3" width="52" height="4" rx="2" fill="#059669" />
                            <rect x="24" y="10" width="46" height="3" rx="1.5" fill="#a7f3d0" />
                            <rect x="24" y="16" width="38" height="3" rx="1.5" fill="#a7f3d0" />
                        </g>

                        <g transform="translate(118, 136)">
                            <path d="M4 10 L8 14 L16 4" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="58" height="4" rx="2" fill="#059669" />
                            <rect x="24" y="10" width="50" height="3" rx="1.5" fill="#a7f3d0" />
                            <rect x="24" y="16" width="40" height="3" rx="1.5" fill="#a7f3d0" />
                        </g>
                    </g>

                    <g transform="translate(56, 138) rotate(-42)">
                        <rect x="2" y="14" width="76" height="16" rx="8" fill="rgba(6,78,59,0.35)" />
                        <rect x="0" y="0" width="74" height="16" rx="8" fill="#34d399" />
                        <rect x="46" y="0" width="18" height="16" fill="#059669" />
                        <rect x="8" y="3" width="50" height="3" rx="1.5" fill="#ffffff" opacity="0.75" />
                        <path d="M74 0 L88 8 L74 16 Z" fill="#064e3b" />
                        <circle cx="88" cy="8" r="1.5" fill="#34d399" />
                        <rect x="-4" y="2" width="6" height="12" rx="3" fill="#064e3b" />
                    </g>
                </svg>
            </div>

            <!-- Welcome Text & Carousel Indicators -->
            <div class="welcome-block">
                <h2 class="welcome-title">Welcome!</h2>
                <p class="welcome-desc">
                    Kelola bank soal, jadwal ujian, dan rekap penilaian peserta didik dengan mudah.
                </p>
                <div class="carousel-dots">
                    <span class="dot active"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>

        </div>

        <!-- RIGHT PANEL: CLEAN WHITE LOG IN FORM -->
        <div class="card-right-white">

            <h1 class="login-heading">Log In</h1>
            <div class="login-subheading-row">
                <span>Belum memiliki akun? <a href="javascript:void(0)" onclick="alert('Akun Guru didaftarkan dan dikelola langsung oleh Operator Sekolah.')">Hubungi Operator</a></span>
                <span class="login-subheading-note">Portal Tenaga Pendidik &amp; Pengajar</span>
            </div>

            <?php if ($flash): ?>
                <div class="alert-box alert-<?= htmlspecialchars($flash['type']) ?>">
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert-box alert-danger">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" id="loginForm" class="login-form">
                <?= Helper::csrfField() ?>

                <!-- Username Underline -->
                <div class="input-underline-group">
                    <input type="text" name="username" id="usernameInput" class="input-underline-field" placeholder="Username / NIP / Email" required autofocus autocomplete="username">
                    <span class="input-underline-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                </div>

                <!-- Password Underline -->
                <div class="input-underline-group">
                    <input type="password" name="password" id="passwordInput" class="input-underline-field" placeholder="Password" required autocomplete="current-password">
                    <span class="input-underline-icon">
                        <button type="button" class="pwd-toggle-btn" id="togglePwd" title="Lihat / sembunyikan kata sandi">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eyeIcon">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </button>
                    </span>
                </div>

                <!-- Action Row -->
                <div class="action-row">
                    <button type="submit" class="btn-signin">
                        Sign in
                    </button>
                    <label class="remember-checkbox">
                        <input type="checkbox" name="remember" checked>
                        <span>Remember password</span>
                    </label>
                </div>

                <a href="javascript:void(0)" onclick="alert('Silakan hubungi Operator Sekolah untuk mereset kata sandi akun Guru Anda.')" class="forgot-password-link">
                    Forget your password?
                </a>

                <div style="margin-top: 14px;">
                    <a href="<?= htmlspecialchars($ssoLoginUrl) ?>" class="btn-operator-sso" id="btnOperatorSso">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        <span>Masuk via Akun Veyra Educate (SSO)</span>
                    </a>
                </div>

            </form>



            <!-- Footer note -->
            <div class="footer-note">
                Siswa ingin mengikuti ujian? <a href="../student/login.php">Portal Siswa &rarr;</a> &bull; <a href="../index.php">Beranda</a>
            </div>

        </div>

    </main>

<script>
(function() {
    var btn = document.getElementById('togglePwd');
    var input = document.getElementById('passwordInput');
    var icon = document.getElementById('eyeIcon');
    if (btn && input && icon) {
        btn.addEventListener('click', function() {
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
            }
        });
    }

    // Password visibility toggle only
    var btn = document.getElementById('togglePwd');
    var input = document.getElementById('passwordInput');
    var icon = document.getElementById('eyeIcon');
    if (btn && input && icon) {
        btn.addEventListener('click', function() {
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
            }
        });
    }

    /* ── Splash Screen ─────────────────────── */
    var splash = document.getElementById('splashScreen');
    if (splash) {
        setTimeout(function() {
            splash.classList.add('hide');
            setTimeout(function(){ splash.style.display = 'none'; }, 600);
        }, 2000);
    }

    /* ── Theme Auto-detect (no toggle button) ─── */
    var saved = localStorage.getItem('cbt_theme');
    if (saved === 'dark' || (!saved && matchMedia('(prefers-color-scheme:dark)').matches)) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();
</script>

</body>
</html>
