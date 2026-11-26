<?php
/**
 * Superadmin Login — Platform Manager
 * Redesigned with Upteamist Blue Style (Modern Enterprise Split Layout)
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/session-init.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (Auth::check() && Auth::role() === 'superadmin') {
    Helper::redirect('index.php');
}

$error = '';
$flash = Helper::getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (Auth::loginSuperadmin($username, $password)) {
        session_regenerate_id(true);
        if (Auth::is2faRequired()) {
            Helper::redirect('../admin/totp-verify.php');
        }
        Helper::redirect('index.php');
    } else {
        $error = 'Username atau kata sandi salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Superadmin Platform | Veyra CBT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #0b63e5;
            --primary-blue-hover: #084fc2;
            --primary-blue-light: #eaf2fd;
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

        .card-left-blue {
            background: var(--primary-blue);
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
        .login-subheading-note {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

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
            color: var(--primary-blue);
        }

        .action-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 8px;
            margin-bottom: 18px;
        }

        .btn-signin {
            background: var(--primary-blue);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(11, 99, 229, 0.25);
        }
        .btn-signin:hover {
            background: var(--primary-blue-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(11, 99, 229, 0.35);
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
            accent-color: var(--primary-blue);
            width: 15px;
            height: 15px;
            cursor: pointer;
        }

        .forgot-password-link {
            font-size: 12.5px;
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 18px;
            display: inline-block;
        }
        .forgot-password-link:hover {
            text-decoration: underline;
        }

        .demo-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 14px 0 10px;
            font-size: 11.5px;
            color: var(--text-muted);
        }
        .demo-chip {
            background: #f1f5f9;
            color: #0b63e5;
            border: 1px solid #e2e8f0;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .demo-chip:hover {
            background: #e0f2fe;
            border-color: #bae6fd;
            color: #0284c7;
        }

        .footer-note {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 10px;
        }
        .footer-note a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        .footer-note a:hover {
            text-decoration: underline;
        }

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
                font-size: 12.5px;
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
    </style>
</head>
<body>

    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <main class="login-card-master">

        <!-- LEFT PANEL -->
        <div class="card-left-blue">

            <div class="brand-header">
                <div class="brand-logo-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                </div>
                <div class="brand-title">Veyra Admin</div>
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
                        <rect x="96" y="38" width="128" height="174" rx="14" fill="#04265f" />
                        <rect x="98" y="36" width="128" height="174" rx="14" fill="#0353c7" />
                        <rect x="104" y="44" width="116" height="158" rx="8" fill="#ffffff" />

                        <rect x="132" y="30" width="60" height="24" rx="6" fill="#38bdf8" />
                        <rect x="135" y="32" width="54" height="20" rx="5" fill="#0284c7" />
                        <rect x="151" y="24" width="22" height="12" rx="4" fill="#38bdf8" />
                        <rect x="154" y="26" width="16" height="6" rx="2" fill="#0b63e5" />
                        <circle cx="162" cy="42" r="3" fill="#ffffff" />

                        <g transform="translate(118, 68)">
                            <path d="M4 10 L8 14 L16 4" stroke="#0284c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="56" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="42" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="48" height="3" rx="1.5" fill="#bae6fd" />
                        </g>

                        <g transform="translate(118, 102)">
                            <path d="M5 5 L15 15 M15 5 L5 15" stroke="#0284c7" stroke-width="3" stroke-linecap="round" fill="none" />
                            <rect x="24" y="3" width="52" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="46" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="38" height="3" rx="1.5" fill="#bae6fd" />
                        </g>

                        <g transform="translate(118, 136)">
                            <path d="M4 10 L8 14 L16 4" stroke="#0284c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            <rect x="24" y="3" width="58" height="4" rx="2" fill="#0284c7" />
                            <rect x="24" y="10" width="50" height="3" rx="1.5" fill="#bae6fd" />
                            <rect x="24" y="16" width="40" height="3" rx="1.5" fill="#bae6fd" />
                        </g>
                    </g>

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

            <div class="welcome-block">
                <h2 class="welcome-title">Welcome!</h2>
                <p class="welcome-desc">
                    Pusat kendali platform multi-tenant, lisensi institusi, dan infrastruktur Veyra CBT.
                </p>
                <div class="carousel-dots">
                    <span class="dot active"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>

        </div>

        <!-- RIGHT PANEL -->
        <div class="card-right-white">

            <h1 class="login-heading">Log In</h1>
            <div class="login-subheading-row">
                <span>Konsol Master Superadmin</span>
                <span class="login-subheading-note">Autentikasi tingkat pengelola platform</span>
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

            <form method="POST" id="superadminLoginForm" class="login-form">
                <?= Helper::csrfField() ?>

                <!-- Username Underline -->
                <div class="input-underline-group">
                    <input type="text" name="username" id="usernameInput" class="input-underline-field" placeholder="Username Superadmin" required autofocus autocomplete="username">
                    <span class="input-underline-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </span>
                </div>

                <!-- Password Underline -->
                <div class="input-underline-group">
                    <input type="password" name="password" id="passwordInput" class="input-underline-field" placeholder="Password" required autocomplete="current-password">
                    <span class="input-underline-icon">
                        <button type="button" class="pwd-toggle-btn" id="togglePwd" title="Lihat kata sandi">
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

                <a href="javascript:void(0)" onclick="alert('Kredensial Superadmin dikonfigurasi melalui database internal platform.')" class="forgot-password-link">
                    Forget your password?
                </a>


            </form>

            <div class="footer-note">
                <a href="../admin/login.php">Login Guru &rarr;</a> &bull; <a href="../student/login.php">Portal Siswa &rarr;</a> &bull; <a href="../index.php">Beranda</a>
            </div>

        </div>

    </main>

<script>
(function() {
    var btn = document.getElementById('togglePwd');
    var input = document.getElementById('passwordInput');
    var icon = document.getElementById('eyeIcon');
    if (!btn || !input || !icon) return;

    btn.addEventListener('click', function() {
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        } else {
            input.type = 'password';
            icon.innerHTML = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
        }
    });
})();

function fillDemo(u, p) {
    var uInput = document.getElementById('usernameInput');
    var pInput = document.getElementById('passwordInput');
    if (uInput) uInput.value = u;
    if (pInput) {
        pInput.value = p;
        pInput.focus();
    }
}
</script>

</body>
</html>
