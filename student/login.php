<?php
/**
 * Student Login Page — Modern Two-Panel Layout with Splash Screen
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → go to dashboard
if (Auth::check() && Auth::role() === 'student') {
    Helper::redirect('dashboard.php');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nis  = trim($_POST['nis'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (!$nis || !$pass) {
        $error = 'NIS dan kata sandi wajib diisi.';
    } else {
        $student = Database::fetch(
            "SELECT s.*, t.id as tenant_id, t.name as tenant_name, t.code as tenant_code
             FROM students s
             JOIN tenants t ON s.tenant_id = t.id
             WHERE s.nis = ? AND s.is_active = 1 AND t.is_active = 1
             LIMIT 1",
            [$nis]
        );

        if (!$student) {
            $error = 'NIS tidak ditemukan. Pastikan NIS sudah didaftarkan oleh sekolah.';
        } else {
            $result = Auth::loginStudent((int)$student['tenant_id'], $nis, $pass);
            if ($result === true) {
                Tenant::setTenant([
                    'id'   => $student['tenant_id'],
                    'name' => $student['tenant_name'],
                    'code' => $student['tenant_code'],
                ]);
                setcookie('veyra_cbt_school', $student['tenant_code'], time() + 30*86400, '/');
                Helper::redirect('dashboard.php');
            }
            $error = 'NIS atau kata sandi salah. Coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<title>Login Siswa | Veyra CBT Online</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--primary-blue:#0b63e5;--primary-hover:#084fc2;--primary-light:#eaf2fd;--navy-dark:#072559;--text-heading:#102a43;--text-body:#627d98;--text-muted:#829ab1;--border-line:#dbe2ea;--border-focus:#0b63e5;}
html[data-theme="dark"]{--bg:#0f172a;--card:#1e293b;--text-heading:#f1f5f9;--text-body:#94a3b8;--border-line:#334155;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at 75% 20%,#3b82f6 0%,#1d4ed8 35%,#1e40af 70%,#1e3a8a 100%);position:relative;overflow:hidden;color:#1e293b;}

/* ── SPLASH SCREEN ─────────────────────────── */
.splash-screen{position:fixed;inset:0;z-index:9999;background:linear-gradient(135deg,#0b63e5 0%,#0ea5e9 50%,#0369a1 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .6s ease,visibility .6s ease;}
.splash-screen.hide{opacity:0;visibility:hidden;pointer-events:none;}
.splash-logo{width:80px;height:80px;background:#fff;border-radius:20px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;animation:splashPulse 1.2s ease-in-out infinite;box-shadow:0 8px 32px rgba(0,0,0,.2);}
.splash-logo svg{width:44px;height:44px;color:#0b63e5;}
.splash-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:28px;font-weight:900;color:#fff;letter-spacing:-.5px;margin-bottom:6px;}
.splash-sub{font-size:13px;color:rgba(255,255,255,.7);font-weight:500;}
.splash-loader{margin-top:32px;width:120px;height:3px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden;}
.splash-loader-bar{height:100%;background:#fff;border-radius:999px;animation:splashLoad 1.8s ease-in-out forwards;}
@keyframes splashPulse{0%,100%{transform:scale(1);box-shadow:0 8px 32px rgba(0,0,0,.2)}50%{transform:scale(1.05);box-shadow:0 12px 40px rgba(0,0,0,.3)}}
@keyframes splashLoad{0%{width:0}100%{width:100%}}

/* ── AMBIENT GLOW ──────────────────────────── */
.ambient-glow-1{position:fixed;width:700px;height:700px;border-radius:50%;background:radial-gradient(circle,rgba(59,130,246,.25) 0%,transparent 70%);top:-200px;right:-150px;pointer-events:none;z-index:0;}
.ambient-glow-2{position:fixed;width:650px;height:650px;border-radius:50%;background:radial-gradient(circle,rgba(29,78,216,.2) 0%,transparent 70%);bottom:-200px;left:-150px;pointer-events:none;z-index:0;}

/* ── MAIN CARD ─────────────────────────────── */
.login-card-master{position:relative;z-index:10;width:100%;max-width:980px;min-height:540px;background:#fff;border-radius:20px;box-shadow:0 30px 80px -15px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.1);display:grid;grid-template-columns:1.1fr 1fr;overflow:hidden;}

/* LEFT PANEL */
.card-left-blue{background:linear-gradient(135deg,#0b63e5 0%,#084fc2 60%,#0740a8 100%);padding:44px 44px 38px;display:flex;flex-direction:column;justify-content:space-between;color:#fff;position:relative;}
.brand-header{display:flex;align-items:center;gap:12px;z-index:2;}
.brand-logo-icon{width:38px;height:38px;background:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.brand-logo-icon svg{width:20px;height:20px;color:#0b63e5;}
.brand-title{font-size:18px;font-weight:800;letter-spacing:-.3px;}
.illustration-wrap{width:100%;max-width:300px;margin:18px auto;display:flex;align-items:center;justify-content:center;z-index:2;}
.illustration-wrap svg{width:100%;height:auto;filter:drop-shadow(0 14px 22px rgba(4,38,95,.3));}
.welcome-block{z-index:2;}
.welcome-title{font-size:32px;font-weight:800;letter-spacing:-.5px;margin-bottom:8px;line-height:1.1;color:#fff;}
.welcome-desc{font-size:13px;color:rgba(255,255,255,.82);line-height:1.6;margin-bottom:22px;max-width:360px;}
.carousel-dots{display:flex;gap:8px;align-items:center;}
.dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.35);transition:all .3s;}
.dot.active{background:#fff;width:24px;border-radius:999px;}

/* RIGHT PANEL */
.card-right-white{background:#fff;padding:48px 46px 40px;display:flex;flex-direction:column;justify-content:center;}
.login-heading{font-size:30px;font-weight:800;color:var(--primary-blue);letter-spacing:-.5px;margin-bottom:6px;}
.login-subheading-row{font-size:13px;color:var(--text-body);margin-bottom:24px;line-height:1.5;display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.login-subheading-row a{color:var(--primary-blue);text-decoration:none;font-weight:600;}
.login-subheading-note{font-size:12px;color:var(--text-muted);font-weight:500;}

/* ALERTS */
.alert-box{padding:11px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}

/* INPUT UNDERLINE STYLE */
.input-underline-group{position:relative;margin-bottom:20px;}
.input-underline-field{width:100%;padding:12px 40px 12px 0;border:none;border-bottom:2px solid var(--border-line);background:transparent;font-size:15px;font-family:inherit;color:var(--text-heading);outline:none;transition:border-color .2s;}
.input-underline-field:focus{border-color:var(--primary-blue);}
.input-underline-field::placeholder{color:var(--text-muted);}
.input-underline-icon{position:absolute;right:0;top:50%;transform:translateY(-50%);color:var(--text-muted);display:flex;align-items:center;}
.pwd-toggle-btn{background:none;border:none;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:color .2s;}
.pwd-toggle-btn:hover{color:var(--primary-blue);}

/* ACTION ROW */
.action-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;margin-top:4px;}
.btn-signin{padding:12px 32px;background:var(--primary-blue);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(11,99,229,.3);}
.btn-signin:hover{background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 6px 18px rgba(11,99,229,.4);}
.remember-checkbox{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text-muted);cursor:pointer;user-select:none;}
.remember-checkbox input{accent-color:var(--primary-blue);width:15px;height:15px;cursor:pointer;}
.forgot-password-link{font-size:12.5px;color:var(--primary-blue);text-decoration:none;font-weight:500;display:inline-block;margin-bottom:18px;}
.forgot-password-link:hover{text-decoration:underline;}

/* FOOTER */
.footer-note{font-size:12px;color:var(--text-muted);text-align:center;margin-top:auto;padding-top:16px;border-top:1px solid var(--border-line);}
.footer-note a{color:var(--primary-blue);text-decoration:none;font-weight:600;}
.footer-note a:hover{text-decoration:underline;}

/* THEME TOGGLE */
.dm-toggle{position:fixed;top:16px;right:16px;width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;z-index:999;backdrop-filter:blur(8px);transition:all .15s;}
.dm-toggle:hover{transform:scale(1.05);background:rgba(255,255,255,.25);}

/* RESPONSIVE */
@media(max-width:860px){.login-card-master{grid-template-columns:1fr;min-height:auto;}.card-left-blue{padding:30px 24px;}.illustration-wrap{max-width:200px;margin:12px auto 0;}.welcome-title{font-size:24px;}.card-right-white{padding:32px 24px;}.login-heading{font-size:24px;}}
@media(max-width:480px){body{padding:12px 10px;align-items:flex-start;}.login-card-master{border-radius:16px;}.card-left-blue{padding:22px 18px;}.illustration-wrap{display:none;}.welcome-desc,.carousel-dots{display:none;}.card-right-white{padding:24px 18px 28px;}.input-underline-field{font-size:16px;height:48px;padding:12px 0;}.btn-signin{width:100%;height:48px;font-size:15px;}}
</style>
</head>
<body>

<!-- AMBIENT GLOWS -->
<div class="ambient-glow-1"></div>
<div class="ambient-glow-2"></div>

<!-- THEME TOGGLE -->
<button class="dm-toggle" id="dmToggle" data-toggle-theme aria-label="Ganti tema">🌙</button>

<!-- SPLASH SCREEN -->
<div class="splash-screen" id="splashScreen">
    <div class="splash-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
            <path d="M6 12v5c3 3 9 3 12 0v-5"/>
        </svg>
    </div>
    <div class="splash-title">Veyra CBT</div>
    <div class="splash-sub">Portal Ujian Siswa</div>
    <div class="splash-loader"><div class="splash-loader-bar"></div></div>
</div>

<!-- MAIN LOGIN CARD -->
<main class="login-card-master">

    <!-- LEFT PANEL: BRANDING + ILLUSTRATION -->
    <div class="card-left-blue">
        <div class="brand-header">
            <div class="brand-logo-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                </svg>
            </div>
            <div class="brand-title">Veyra CBT</div>
        </div>

        <div class="illustration-wrap">
            <svg viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Desk -->
                <rect x="40" y="200" width="240" height="8" rx="4" fill="rgba(255,255,255,.15)"/>
                <!-- Monitor -->
                <rect x="80" y="60" width="160" height="110" rx="10" fill="#0f172a" stroke="rgba(255,255,255,.2)" stroke-width="2"/>
                <rect x="88" y="68" width="144" height="90" rx="6" fill="#1e3a5f"/>
                <!-- Screen content lines -->
                <rect x="100" y="80" width="60" height="4" rx="2" fill="rgba(56,189,248,.6)"/>
                <rect x="100" y="90" width="90" height="4" rx="2" fill="rgba(255,255,255,.15)"/>
                <rect x="100" y="100" width="75" height="4" rx="2" fill="rgba(255,255,255,.15)"/>
                <rect x="100" y="110" width="85" height="4" rx="2" fill="rgba(255,255,255,.15)"/>
                <!-- Checkmarks on screen -->
                <circle cx="106" cy="82" r="3" fill="#22c55e"/>
                <circle cx="106" cy="92" r="3" fill="#22c55e"/>
                <circle cx="106" cy="102" r="3" fill="rgba(255,255,255,.3)"/>
                <circle cx="106" cy="112" r="3" fill="rgba(255,255,255,.3)"/>
                <!-- Monitor stand -->
                <rect x="145" y="170" width="30" height="30" rx="2" fill="rgba(255,255,255,.1)"/>
                <rect x="130" y="196" width="60" height="6" rx="3" fill="rgba(255,255,255,.15)"/>
                <!-- Books stack -->
                <rect x="250" y="170" width="40" height="8" rx="2" fill="rgba(251,191,36,.5)"/>
                <rect x="252" y="160" width="36" height="8" rx="2" fill="rgba(244,114,182,.5)"/>
                <rect x="248" y="150" width="44" height="8" rx="2" fill="rgba(74,222,128,.5)"/>
                <!-- Pencil -->
                <g transform="translate(260,120) rotate(30)">
                    <rect x="0" y="0" width="6" height="50" rx="3" fill="rgba(255,255,255,.25)"/>
                    <polygon points="0,50 3,58 6,50" fill="rgba(255,255,255,.4)"/>
                </g>
                <!-- Floating elements -->
                <circle cx="50" cy="50" r="6" fill="rgba(255,255,255,.15)"/>
                <circle cx="280" cy="70" r="4" fill="rgba(255,255,255,.12)"/>
                <circle cx="30" cy="140" r="3" fill="rgba(255,255,255,.1)"/>
                <rect x="270" y="30" width="16" height="16" rx="4" fill="rgba(255,255,255,.1)" transform="rotate(15 278 38)"/>
            </svg>
        </div>

        <div class="welcome-block">
            <h2 class="welcome-title">Selamat Datang!</h2>
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

    <!-- RIGHT PANEL: LOGIN FORM -->
    <div class="card-right-white">
        <h1 class="login-heading">Log In</h1>
        <div class="login-subheading-row">
            <span>Belum memiliki akun? <a href="javascript:void(0)" onclick="alert('Pendaftaran akun Siswa dikelola langsung oleh Operator Sekolah masing-masing.')">Hubungi Operator</a></span>
            <span class="login-subheading-note">Portal Peserta Didik</span>
        </div>

        <?php if ($flash): ?>
            <div class="alert-box alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-box alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="on" id="loginForm">
            <div class="input-underline-group">
                <input type="text" name="nis" id="nisInput" class="input-underline-field" placeholder="Nomor Induk Siswa (NIS)" required autofocus autocomplete="username" maxlength="30">
                <span class="input-underline-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
            </div>
            <div class="input-underline-group">
                <input type="password" name="password" id="passwordInput" class="input-underline-field" placeholder="Kata Sandi" required autocomplete="current-password">
                <span class="input-underline-icon">
                    <button type="button" class="pwd-toggle-btn" id="togglePwd" title="Lihat / sembunyikan kata sandi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eyeIcon"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </button>
                </span>
            </div>
            <div class="action-row">
                <button type="submit" class="btn-signin">Masuk →</button>
                <label class="remember-checkbox"><input type="checkbox" name="remember" checked><span>Ingat saya</span></label>
            </div>
            <a href="javascript:void(0)" onclick="alert('Silakan hubungi guru pengampu untuk mereset kata sandi.')" class="forgot-password-link">Lupa kata sandi?</a>
        </form>

        <div class="footer-note">
            Ingin masuk sebagai Guru? <a href="../admin/login.php">Login Guru →</a> · <a href="../index.php">Beranda</a>
        </div>
    </div>
</main>

<script>
/* ── Theme Toggle ─────────────────────────── */
(function(){
    var saved = localStorage.getItem('cbt_theme');
    if(saved==='dark'||(!saved&&matchMedia('(prefers-color-scheme:dark)').matches)){
        document.documentElement.setAttribute('data-theme','dark');
        var btn=document.getElementById('dmToggle');if(btn)btn.textContent='☀️';
    }
    document.getElementById('dmToggle').addEventListener('click',function(){
        var d=document.documentElement,next=d.getAttribute('data-theme')==='dark'?'light':'dark';
        d.setAttribute('data-theme',next);localStorage.setItem('cbt_theme',next);this.textContent=next==='dark'?'☀️':'🌙';
    });
})();

/* ── Password Visibility Toggle ───────────── */
(function(){
    var btn=document.getElementById('togglePwd'),inp=document.getElementById('passwordInput'),icon=document.getElementById('eyeIcon');
    if(btn&&inp&&icon){
        btn.addEventListener('click',function(){
            if(inp.type==='password'){inp.type='text';icon.innerHTML='<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }else{inp.type='password';icon.innerHTML='<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';
            }});
    }
})();

/* ── Splash Screen ────────────────────────── */
(function(){
    var splash=document.getElementById('splashScreen');
    if(!splash)return;
    setTimeout(function(){
        splash.classList.add('hide');
        setTimeout(function(){splash.style.display='none';},600);
    },2000);
})();
</script>
</body>
</html>