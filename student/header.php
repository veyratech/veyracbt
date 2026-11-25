<?php
/**
 * Student Portal Header — Sidebar Layout with Active Nav Highlight
 * Expects: $student, $tenantId, $school, $flash, $pageKey, $isAdminPreview
 */

// Fallback if bootstrap wasn't loaded
$student      = $student      ?? Auth::user();
$tenantId     = $tenantId     ?? (int)($student['tenant_id'] ?? Tenant::id());
$school       = $school       ?? Tenant::get();
$flash        = $flash        ?? Helper::getFlash();
$pageKey      = $pageKey      ?? str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$isAdminPreview = $isAdminPreview ?? false;

// School logo & initials
$schoolLogo = !empty($school['logo_url'])
    ? $school['logo_url']
    : ($_SESSION['school_logo'] ?? $school['logo'] ?? null);
if ($schoolLogo && !str_starts_with($schoolLogo, 'http') && !str_starts_with($schoolLogo, '/')) {
    if (file_exists(__DIR__ . '/../' . $schoolLogo)) $schoolLogo = '../' . $schoolLogo;
    else $schoolLogo = null; // invalid → use initials fallback
}
$sTake = 0;
$schoolInitials = '';
foreach (explode(' ', trim((string)($school['name'] ?? ''))) as $w) {
    $w = trim($w);
    if ($w !== '') { $schoolInitials .= strtoupper(mb_substr($w, 0, 1)); if (++$sTake >= 2) break; }
}
$schoolInitials = $schoolInitials ?: 'SK';

// Active exam count
$activeExamsCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams e WHERE e.tenant_id = ? AND e.is_active = 1 AND e.start_time <= NOW() AND e.end_time >= NOW()",
    [$tenantId]
);

$title   = htmlspecialchars($pageTitle ?? 'Dashboard');
$sName   = htmlspecialchars($school['name'] ?? 'CBT Online');
$sStudent = htmlspecialchars($student['name'] ?? 'Siswa');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="theme-color" content="#0b63e5">
    <title><?= $title ?> | CBT Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>&t=<?= time() ?>">
    <style>
        :root{--primary:#0b63e5;--bg:#f0f4f8;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;}
        html[data-theme="dark"]{--bg:#0b1120;--card:#131d33;--text:#f1f5f9;--muted:#94a3b8;--border:#1e293b;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;}
        
        /* SIDEBAR */
        .sb{width:250px;min-width:250px;background:linear-gradient(180deg,#0b63e5,#0740a8 60%,#052d75);color:#fff;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;z-index:100;padding:14px 10px;}
        .sb-brand{display:flex;align-items:center;gap:9px;padding:6px 4px 14px;border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:14px;}
        .sb-logo{width:38px;height:38px;background:#fff;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:var(--primary);flex-shrink:0;overflow:hidden;}
        .sb-logo img{width:100%;height:100%;object-fit:cover;}
        .sb-title{font-size:12.5px;font-weight:800;line-height:1.2;}
        .sb-sub{font-size:9.5px;opacity:.65;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
        .sb-close{display:none;background:none;border:none;color:#fff;font-size:22px;cursor:pointer;margin-left:auto;}
        
        .sb-profile{background:rgba(255,255,255,.1);border-radius:13px;padding:12px;text-align:center;margin-bottom:14px;border:1px solid rgba(255,255,255,.1);}
        .sb-avatar{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;margin:0 auto 7px;border:2px solid rgba(255,255,255,.28);overflow:hidden;}
        .sb-avatar img{width:100%;height:100%;object-fit:cover;}
        .sb-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .sb-meta{font-size:10.5px;opacity:.65;margin-top:2px;}
        
        .sb-label{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:1px;opacity:.4;padding:10px 6px 4px;}
        .sb-nav-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:9px;color:rgba(255,255,255,.78);text-decoration:none;font-size:12.5px;font-weight:600;transition:all .15s;margin-bottom:2px;border:1px solid transparent;}
        .sb-nav-item:hover{background:rgba(255,255,255,.09);color:#fff;}
        .sb-nav-item.active{background:#fff;color:var(--primary);font-weight:800;box-shadow:0 4px 12px rgba(0,0,0,.15);}
        .sb-icon{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;}
        .sb-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:999px;}
        .sb-footer{margin-top:auto;padding-top:12px;border-top:1px solid rgba(255,255,255,.12);}
        .sb-quote{background:rgba(255,255,255,.06);border-radius:9px;padding:9px 11px;text-align:center;font-size:10.5px;opacity:.75;line-height:1.5;}
        .sb-ver{font-size:9px;opacity:.35;text-align:center;margin-top:8px;}
        
        /* MAIN */
        .main-wrap{flex:1;display:flex;flex-direction:column;min-width:0;}
        .topbar{background:var(--card);border-bottom:1px solid var(--border);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:90;}
        .topbar-left{display:flex;align-items:center;gap:10px;}
        .menu-btn{display:none;width:34px;height:34px;border-radius:9px;background:var(--bg);border:1px solid var(--border);align-items:center;justify-content:center;cursor:pointer;}
        .menu-btn svg{width:17px;height:17px;stroke:var(--text);fill:none;stroke-width:2;}
        .topbar-title h2{font-size:15px;font-weight:800;color:var(--text);line-height:1.2;}
        .topbar-title span{font-size:11px;color:var(--muted);}
        .topbar-right{display:flex;align-items:center;gap:7px;}
        .icon-btn{width:34px;height:34px;border-radius:9px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;text-decoration:none;}
        .icon-btn:hover{background:var(--border);}
        .icon-btn svg{width:15px;height:15px;stroke:var(--muted);fill:none;stroke-width:2;}
        .content-body{flex:1;padding:16px;width:100%;}
        
        /* FLASH */
        .flash-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
        .flash-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
        .flash-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
        .flash-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
        
        /* MOBILE */
        @media(max-width:768px){
            .sb{position:fixed;left:-270px;transition:transform .3s cubic-bezier(.4,0,.2,1);z-index:200;}
            .sb.open{transform:translateX(270px);}
            .sb-close{display:block;}
            .menu-btn{display:flex;}
            .content-body{padding:14px;}
            .topbar{padding:8px 14px;}
            .sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:150;}
            .sb-overlay.show{display:block;}
        }
    </style>
</head>
<body<?= $pageKey === 'exam' ? ' class="exam-mode"' : '' ?>>
<?php if ($pageKey !== 'exam'): /* Mode ujian: tanpa sidebar & topbar agar fokus penuh */ ?>

<div class="sb-overlay" id="sbOverlay"></div>

<aside class="sb" id="sbNav">
    <div>
        <div class="sb-brand">
            <div class="sb-logo">
                <?php if ($schoolLogo): ?><img src="<?= htmlspecialchars($schoolLogo) ?>" alt=""><?php else: echo $schoolInitials; endif; ?>
            </div>
            <div>
                <div class="sb-title"><?= $sName ?></div>
                <div class="sb-sub">Portal Siswa</div>
            </div>
            <button type="button" class="sb-close" data-close-sidebar aria-label="Tutup">&times;</button>
        </div>
        
        <div class="sb-profile">
            <div class="sb-avatar">
                <?php
                $pu = !empty($student['photo']) ? '/' . ltrim($student['photo'], '/') : null;
                if ($pu): ?><img src="<?= Helper::cacheBust(htmlspecialchars($pu)) ?>" alt=""><?php else: echo mb_substr($student['name'] ?? 'S', 0, 1); endif; ?>
            </div>
            <div class="sb-name" title="<?= $sStudent ?>"><?= $sStudent ?></div>
            <div class="sb-meta">NIS: <?= htmlspecialchars($student['nis'] ?? '-') ?> · <?= htmlspecialchars($student['class_name'] ?? 'Umum') ?></div>
        </div>
        
        <nav>
            <div class="sb-label">Ujian & Belajar</div>
            
            <a href="dashboard.php?cbt_n=<?= time() ?>" class="sb-nav-item <?= $pageKey === 'dashboard' ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Dashboard
                <?php if ($activeExamsCount > 0): ?><span class="sb-badge"><?= $activeExamsCount ?></span><?php endif; ?>
            </a>
            
            <a href="history.php?tab=ujian&cbt_n=<?= time() ?>" class="sb-nav-item <?= ($pageKey === 'history' && in_array($_GET['tab'] ?? '', ['ujian','latihan'])) ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Jadwal & Ujian
            </a>

            <a href="practice.php?cbt_n=<?= time() ?>" class="sb-nav-item <?= $pageKey === 'practice' ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                Latihan Soal
            </a>
            
            <a href="values.php?cbt_n=<?= time() ?>" class="sb-nav-item <?= $pageKey === 'values' ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Nilai Semua Mapel
            </a>
            
            <a href="#" data-open-idcard class="sb-nav-item">
                <svg class="sb-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>
                Kartu Peserta
            </a>
            
            <div class="sb-label" style="margin-top:4px;">Akun Saya</div>
            
            <a href="profile.php?cbt_n=<?= time() ?>" class="sb-nav-item <?= $pageKey === 'profile' ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profil & 2FA
            </a>
            
            <a href="password.php?cbt_n=<?= time() ?>" class="sb-nav-item <?= $pageKey === 'password' ? 'active' : '' ?>">
                <svg class="sb-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Ganti Sandi
            </a>
            
            <?php if (!$isAdminPreview): ?>
            <a href="../logout.php" class="sb-nav-item" style="margin-top:6px;opacity:.6;" data-confirm-logout>
                <svg class="sb-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span style="color:rgba(255,255,255,.75);">Keluar</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    
    <div class="sb-footer">
        <div class="sb-quote">"Belajar hari ini, juara nanti!" 🌟</div>
        <div class="sb-ver">CBT Online v2.0</div>
    </div>
</aside>
<?php endif; ?>

<div class="main-wrap">
    
    <?php if ($pageKey !== 'exam'): ?>
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="menu-btn" id="menuBtn" data-open-sidebar>
                <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="topbar-title">
                <h2><?= $title ?></h2>
                <span><?= $sName ?></span>
            </div>
        </div>
        <div class="topbar-right">
            <button type="button" class="icon-btn" id="themeToggle" title="Ganti Tema">
                <svg class="ic-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="ic-sun"  viewBox="0 0 24 24" style="display:none;"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
            </button>
            <button type="button" class="icon-btn" id="btnFullscreen" title="Layar Penuh">
                <svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
            </button>
            <a href="profile.php" class="icon-btn" title="Profil" style="text-decoration:none;">
                <div style="width:24px;height:24px;border-radius:50%;background:#e0e7ff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#0284c7;overflow:hidden;">
                    <?php
                    $pp = !empty($student['photo']) ? '/' . ltrim($student['photo'], '/') : null;
                    if ($pp): ?><img src="<?= Helper::cacheBust(htmlspecialchars($pp)) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: echo mb_substr($student['name'] ?? '-', 0, 1); endif; ?>
                </div>
            </a>
        </div>
    </header>
    <?php endif; ?>
    
    <main class="content-body">
        <?php if ($flash): ?>
            <div class="flash-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>
        <?php if (Auth::isDefaultPassword($student['id'], 'student')): ?>
            <div class="flash-danger">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <strong>Keamanan Akun:</strong> Kata sandi default masih digunakan. <a href="password.php" style="color:inherit;font-weight:700;text-decoration:underline;">Ganti sekarang</a>
            </div>
        <?php endif; ?>
