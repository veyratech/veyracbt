<?php
/**
 * Student Login Page — Clean standalone layout (no sidebar)
 * No school selector needed — auto-detect tenant from NIS.
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Helper.php';

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
        // Cari siswa berdasarkan NIS (tanpa perlu pilih sekolah)
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
                    'id'    => $student['tenant_id'],
                    'name'  => $student['tenant_name'],
                    'code'  => $student['tenant_code'],
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
    <title>Login Siswa | CBT Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#0b63e5;--bg:#f0f4f8;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;}
        html[data-theme="dark"]{--bg:#0b1120;--card:#131d33;--text:#f1f5f9;--muted:#94a3b8;--border:#1e293b;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        
        .login-wrap { width:100%;max-width:400px; }
        .login-card { background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08); }
        .login-head { background:linear-gradient(135deg,#0b63e5 0%,#0ea5e9 60%,#0369a1 100%);padding:28px;color:#fff;text-align:center;position:relative; }
        .login-head::before{content:'';position:absolute;top:-30px;right:-30px;width:100px;height:100px;background:rgba(255,255,255,.07);border-radius:50%;}
        .login-head h1{font-size:20px;font-weight:900;margin-bottom:4px;position:relative;}
        .login-head p{font-size:12px;opacity:.8;position:relative;}
        .login-body{padding:24px;}
        .fg{margin-bottom:14px;}
        .fl{font-size:12px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
        .fi{width:100%;padding:11px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:var(--bg);outline:none;font-family:inherit;transition:border-color .15s,color inherit;}
        .fi:focus{border-color:var(--primary);background:var(--card);}
        .btn-login{width:100%;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;transition:background .15s;font-family:inherit;}
        .btn-login:hover{background:#084fc2;}
        .alert-box{padding:11px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:14px;}
        .alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
        .alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}
        .login-foot{text-align:center;padding:14px 24px 20px;font-size:12px;color:var(--muted);border-top:1px solid var(--border);}
        .login-foot a{color:var(--primary);text-decoration:none;font-weight:600;}
        .login-foot a:hover{text-decoration:underline;}
        
        .dm-toggle{position:fixed;top:16px;right:16px;width:38px;height:38px;border-radius:10px;background:var(--card);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;z-index:999;transition:all .15s;}
        .dm-toggle:hover{transform:scale(1.05);}
    </style>
</head>
<body>

<button class="dm-toggle" id="dmToggle" data-toggle-theme aria-label="Ganti tema">&#x1F313;</button>

<div class="login-wrap">
<div class="login-card">
    <div class="login-head">
        <div style="font-size:32px;margin-bottom:8px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#fff;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <h1>Portal Siswa</h1>
        <p>Sistem Ujian Online CBT Online</p>
    </div>
    <div class="login-body">
        <?php if ($error): ?>
            <div class="alert-box alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-box alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" autocomplete="on">
            <div class="fg">
                <label class="fl">NIS (Nomor Induk Siswa)</label>
                <input type="text" name="nis" class="fi" placeholder="Masukkan NIS" required autocomplete="username" maxlength="30" autofocus>
            </div>
            
            <div class="fg">
                <label class="fl">Kata Sandi</label>
                <input type="password" name="password" class="fi" placeholder="Masukkan kata sandi" required autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn-login">Masuk →</button>
        </form>
    </div>
    <div class="login-foot">
        Lupa kata sandi? Hubungi guru pengampu.<br>
        <a href="../index.php">← Kembali ke Beranda</a> · <a href="../admin/login.php">Login Guru</a>
    </div>
</div>
</div>

</body>
</html>