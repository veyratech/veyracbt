<?php
/**
 * Veyra CBT - Pusat Kontrol Superadmin
 * Tailored for Veyra CBT Platform with Clean Boardto Aesthetic
 */

$pageTitle = "Pusat Kontrol Superadmin";
require_once __DIR__ . '/header.php';

// Helper: human-readable time difference
function diffForHumans(int $timestamp): string {
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Baru saja';
    if ($diff < 3600) return floor($diff/60) . ' menit lalu';
    if ($diff < 86400) return floor($diff/3600) . ' jam lalu';
    if ($diff < 604800) return floor($diff/86400) . ' hari lalu';
    return date('d M Y', $timestamp);
}

// 1. Data Platform & Tenants
$tenants = Database::fetchAll(
    "SELECT t.*,
            (SELECT COUNT(*) FROM students WHERE tenant_id = t.id) as current_students,
            (SELECT COUNT(*) FROM students WHERE tenant_id = t.id AND is_active = 1) as active_students,
            (SELECT COUNT(*) FROM exams WHERE tenant_id = t.id) as current_exams,
            (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as current_users
     FROM tenants t
     ORDER BY t.id DESC"
);

$totalTenants = count($tenants);
$totalPlatformStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM students");
$totalPlatformBanks = (int)Database::fetchColumn("SELECT COUNT(*) FROM question_banks");
$totalPlatformQuestions = (int)Database::fetchColumn("SELECT COUNT(*) FROM questions");
$totalPlatformUsers = (int)Database::fetchColumn("SELECT COUNT(*) FROM users");
$activeExamsNow = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams WHERE is_active = 1 AND start_time <= NOW() AND end_time >= NOW()"
);
$totalSessionsCompleted = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE status = 'completed'"
);
$avgPlatformVal = Database::fetchColumn(
    "SELECT ROUND(AVG(score), 1) FROM exam_sessions WHERE status = 'completed'"
);
$avgPlatformScore = ($avgPlatformVal !== null && $avgPlatformVal !== '') ? (float)$avgPlatformVal : 0.0;

// 2. The 8 Operational Cards for Veyra CBT (Live Database Figures)
$cbtCards = [
    [
        'id'          => 1,
        'title'       => 'Portal Peserta Ujian',
        'badge_bg'    => '#ec4899', // Pink
        'icon'        => 'phone',
        'team'        => 'Veyra CBT Student App',
        'time_left'   => number_format($totalPlatformStudents, 0, ',', '.') . ' Siswa Terdaftar',
        'progress'    => 100,
        'category'    => 'live',
        'target_url'  => 'tenants.php',
        'avatars'     => ['#f43f5e', '#3b82f6', '#10b981'],
    ],
    [
        'id'          => 2,
        'title'       => 'Backup & Cloud Database',
        'badge_bg'    => '#10b981', // Teal / Emerald
        'icon'        => 'server',
        'team'        => 'MySQL Multi-Tenant Engine',
        'time_left'   => 'Normal Online & Siap Backup',
        'progress'    => 100,
        'category'    => 'system',
        'target_url'  => 'backup.php',
        'avatars'     => ['#0ea5e9', '#f59e0b'],
    ],
    [
        'id'          => 3,
        'title'       => $tenants[0]['name'] ?? 'Sekolah Mitra Utama',
        'badge_bg'    => '#3b82f6', // Royal Blue
        'icon'        => 'school',
        'team'        => 'Paket ' . ucfirst($tenants[0]['package'] ?? 'Pro') . ' • ' . ($tenants[0]['code'] ?? '-'),
        'time_left'   => (int)($tenants[0]['current_students'] ?? 0) . ' Siswa • ' . (int)($tenants[0]['current_exams'] ?? 0) . ' Jadwal Ujian',
        'progress'    => !empty($tenants[0]['student_quota']) ? min(100, round(((int)$tenants[0]['current_students'] / max(1, (int)$tenants[0]['student_quota'])) * 100)) : 100,
        'category'    => 'tenant',
        'target_url'  => isset($tenants[0]['id']) ? 'tenants.php?impersonate=' . (int)$tenants[0]['id'] : 'tenants.php',
        'avatars'     => ['#8b5cf6', '#ec4899', '#06b6d4'],
    ],
    [
        'id'          => 4,
        'title'       => 'Analisis Nilai & Kelulusan',
        'badge_bg'    => '#f97316', // Orange
        'icon'        => 'pie',
        'team'        => number_format($totalSessionsCompleted, 0, ',', '.') . ' Sesi Ujian Selesai',
        'time_left'   => 'Rata-Rata Nilai Platform: ' . $avgPlatformScore,
        'progress'    => $avgPlatformScore > 0 ? min(100, round($avgPlatformScore)) : 100,
        'category'    => 'system',
        'target_url'  => 'tenants.php',
        'avatars'     => ['#10b981', '#3b82f6'],
    ],
    [
        'id'          => 5,
        'title'       => 'Audit Log & Anti-Curang',
        'badge_bg'    => '#8b5cf6', // Violet
        'icon'        => 'camera',
        'team'        => 'Live Proctoring & Keamanan',
        'time_left'   => $activeExamsNow . ' Ujian Berlangsung Saat Ini',
        'progress'    => 100,
        'category'    => 'live',
        'target_url'  => 'logs.php',
        'avatars'     => ['#ec4899', '#f59e0b'],
    ],
    [
        'id'          => 6,
        'title'       => 'Master Bank Soal Platform',
        'badge_bg'    => '#f59e0b', // Amber
        'icon'        => 'document',
        'team'        => number_format($totalPlatformQuestions, 0, ',', '.') . ' Total Butir Soal',
        'time_left'   => number_format($totalPlatformBanks, 0, ',', '.') . ' Bank Soal Terdaftar',
        'progress'    => 100,
        'category'    => 'banks',
        'target_url'  => 'tenants.php',
        'avatars'     => ['#3b82f6', '#10b981'],
    ],
    [
        'id'          => 7,
        'title'       => $tenants[1]['name'] ?? 'Sekolah Mitra Kedua',
        'badge_bg'    => '#0ea5e9', // Sky Blue
        'icon'        => 'school',
        'team'        => 'Paket ' . ucfirst($tenants[1]['package'] ?? 'Enterprise') . ' • ' . ($tenants[1]['code'] ?? '-'),
        'time_left'   => (int)($tenants[1]['current_students'] ?? 0) . ' Siswa • ' . (int)($tenants[1]['current_exams'] ?? 0) . ' Jadwal Ujian',
        'progress'    => !empty($tenants[1]['student_quota']) ? min(100, round(((int)$tenants[1]['current_students'] / max(1, (int)$tenants[1]['student_quota'])) * 100)) : 100,
        'category'    => 'tenant',
        'target_url'  => isset($tenants[1]['id']) ? 'tenants.php?impersonate=' . (int)$tenants[1]['id'] : 'tenants.php',
        'avatars'     => ['#f43f5e', '#8b5cf6', '#10b981'],
    ],
    [
        'id'          => 8,
        'title'       => 'Admin & Guru Sekolah',
        'badge_bg'    => '#e11d48', // Magenta / Rose
        'icon'        => 'headset',
        'team'        => 'Manajemen Akun & SSO Mitra',
        'time_left'   => number_format($totalPlatformUsers, 0, ',', '.') . ' Akun Admin/Guru Aktif',
        'progress'    => 100,
        'category'    => 'users',
        'target_url'  => 'tenants.php',
        'avatars'     => ['#8b5cf6', '#0ea5e9'],
    ],
];

$initialFilter = $_GET['filter'] ?? 'all';

// ── Command Center Extras ────────────────────────────────
$_recentActivity = Database::fetchAll(
    "SELECT al.message, al.created_at, st.name AS student_name, t.name AS tenant_name
     FROM activity_logs al
     JOIN exam_sessions es ON al.session_id = es.id
     JOIN students st ON es.student_id = st.id
     JOIN tenants t ON es.tenant_id = t.id
     ORDER BY al.id DESC LIMIT 8",
    []
);
$_newTenants = Database::fetchAll(
    "SELECT id, name, code, current_students
     FROM (SELECT t.*, (SELECT COUNT(*) FROM students s WHERE s.tenant_id=t.id) AS current_students
           FROM tenants t ORDER BY t.created_at DESC LIMIT 5) AS x",
    []
);
?>

<style>
/* ========================================================
   VEYRA CBT DASHBOARD COMPONENT STYLES
   ======================================================== */
.boardto-reporting-wrap {
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* SECTION 1: TITLE & TOP ACTION */
.boardto-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.boardto-page-title {
    font-size: 22px;
    font-weight: 800;
    color: #1e293b;
    margin: 0;
    letter-spacing: -0.3px;
    word-break: break-word;
}
.boardto-page-sub {
    font-size: 12px;
    font-weight: 500;
    color: #94a3b8;
    margin-top: 3px;
    word-break: break-word;
}
.boardto-add-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #00bcd4;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(0, 188, 212, 0.4);
    transition: all 0.18s ease;
    text-decoration: none;
    flex-shrink: 0;
}
.boardto-add-btn:hover {
    background: #00acc1;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0, 188, 212, 0.5);
}

/* SECTION 2: FILTER PILLS & VIEW CONTROLS */
.boardto-control-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 6px;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.boardto-pills-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    max-width: 100%;
    min-width: 0;
}
.boardto-filter-pill {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.boardto-filter-pill:hover {
    border-color: #cbd5e1;
    color: #1e293b;
}
/* Active Cyan Tag Pill (Matches reference image) */
.boardto-filter-pill.active {
    background: #e0f7fa;
    border: 1px solid #80deea;
    color: #00838f;
    font-weight: 700;
}
.boardto-pill-count {
    background: #f1f5f9;
    color: #475569;
    border-radius: 6px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 700;
}
.boardto-filter-pill.active .boardto-pill-count {
    background: #ffffff;
    color: #00838f;
    border: 1px solid #b2ebf2;
}

/* Right View Control Buttons */
.boardto-actions-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.boardto-tool-btn {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
}
.boardto-tool-btn:hover {
    border-color: #cbd5e1;
    color: #1e293b;
}
.boardto-icon-btn {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.boardto-icon-btn:hover {
    border-color: #cbd5e1;
    color: #1e293b;
}
.boardto-icon-btn.active {
    background: #e0f7fa;
    border-color: #80deea;
    color: #00838f;
}

/* SECTION 3: 8 NEUMORPHIC FLOATING CARDS (4 COLUMNS × 2 ROWS) */
.boardto-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-top: 8px;
}
.boardto-card {
    background: #ffffff;
    border-radius: 22px;
    padding: 22px 20px 18px 20px;
    border: 1px solid rgba(226, 232, 240, 0.85);
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    position: relative;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}
.boardto-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 32px -4px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

/* Circular Top Icon Badge */
.boardto-card-badge {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    margin-bottom: 14px;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.12);
    flex-shrink: 0;
}

/* Card Content Typography */
.boardto-card-title {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    line-height: 1.25;
}
.boardto-card-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: #64748b;
    font-weight: 500;
    line-height: 1.2;
}
.boardto-meta-icon {
    color: #94a3b8;
    display: flex;
    align-items: center;
}
.boardto-card-time {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: #64748b;
    font-weight: 500;
    margin-top: 5px;
    margin-bottom: 18px;
}

/* Card Footer: Team Members & Progress */
.boardto-card-footer {
    border-top: 1px solid #f8fafc;
    padding-top: 12px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-top: auto;
}
.boardto-footer-col {
    display: flex;
    flex-direction: column;
}
.boardto-footer-label {
    font-size: 10px;
    font-weight: 600;
    color: #94a3b8;
    margin-bottom: 5px;
}
.boardto-avatar-stack {
    display: flex;
    align-items: center;
}
.boardto-mini-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid #ffffff;
    margin-right: -6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 8px;
    font-weight: 800;
}
.boardto-avatar-more {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #f1f5f9;
    color: #64748b;
    font-size: 9px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 2px;
}
.boardto-progress-val {
    font-size: 13.5px;
    font-weight: 800;
    color: #1e293b;
    text-align: right;
    line-height: 1.1;
}

/* SECTION 4: LIST VIEW / DATA TABLE (TOGGLEABLE) */
.boardto-table-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 24px;
    border: 1px solid #edf2f7;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
    margin-top: 8px;
}
.boardto-table {
    width: 100%;
    border-collapse: collapse;
}
.boardto-table th {
    text-align: left;
    padding: 12px 14px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #94a3b8;
    border-bottom: 1px solid #f1f5f9;
    letter-spacing: 0.5px;
}
.boardto-table td {
    padding: 14px;
    font-size: 13px;
    color: #334155;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
}
.boardto-table tr:hover td {
    background: #f8fafc;
}

@media (max-width: 1200px) {
    .boardto-cards-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 768px) {
    .boardto-reporting-wrap {
        gap: 14px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        overflow: hidden;
    }
    .boardto-title-row {
        align-items: flex-start;
        gap: 12px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }
    .boardto-page-title {
        font-size: 19px;
    }
    .boardto-page-sub {
        font-size: 11.5px;
    }
    .boardto-control-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        overflow: hidden;
    }
    .boardto-pills-group {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        padding-bottom: 6px;
        scrollbar-width: none;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    .boardto-pills-group::-webkit-scrollbar {
        display: none;
    }
    .boardto-filter-pill {
        flex-shrink: 0 !important;
        white-space: nowrap !important;
        padding: 6px 12px;
        font-size: 11.5px;
    }
    .boardto-actions-group {
        justify-content: flex-end;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }
    .boardto-cards-grid {
        grid-template-columns: 1fr !important;
        gap: 14px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    .boardto-card {
        padding: 18px 16px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    .boardto-table-card {
        padding: 14px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    .boardto-table-header-wrap {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }
}
@media (max-width: 480px) {
    .boardto-title-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .boardto-add-btn {
        width: 100%;
        border-radius: 12px;
        height: 42px;
        font-weight: 700;
        gap: 8px;
    }
    .boardto-add-btn::after {
        content: "Tambah Sekolah Mitra";
        font-size: 13px;
        margin-left: 6px;
    }
}

/* ── COMMAND CENTER EXTRAS ─────────────────────────── */
.command-ticker {
    background: linear-gradient(90deg, #0f172a, #1e293b);
    border-radius: 12px; padding: 10px 18px; display: flex; align-items: center; gap: 12px;
    overflow: hidden; position: relative;
}
.command-ticker-label {
    background: #ef4444; color: #fff; font-size: 10px; font-weight: 800;
    padding: 3px 8px; border-radius: 6px; white-space: nowrap; text-transform: uppercase; letter-spacing: .5px;
    animation: pulse 1.5s infinite; flex-shrink: 0;
}
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.6} }
.command-ticker-track {
    flex: 1; overflow: hidden; position: relative; height: 20px;
}
.command-ticker-content {
    position: absolute; white-space: nowrap;
    animation: tickerScroll 30s linear infinite;
    font-size: 12px; color: #94a3b8; font-weight: 500;
}
@keyframes tickerScroll { from{transform:translateX(100%)}to{transform:translateX(-100%)} }
.command-ticker-content span { color: #e2e8f0; font-weight: 700; }

.recent-tenants {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px; padding: 14px 18px; backdrop-filter: blur(8px);
}
.recent-tenants-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #64748b; margin-bottom: 10px; }
.recent-tenant-row {
    display: flex; align-items: center; gap: 10px; padding: 7px 0;
    border-bottom: 1px solid rgba(255,255,255,.06); font-size: 12px; color: #cbd5e1;
}
.recent-tenant-row:last-child { border-bottom: none; }
.recent-tenant-dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; flex-shrink: 0; }
.recent-tenant-name { flex: 1; font-weight: 600; color: #e2e8f0; }
.recent-tenant-students { color: #94a3b8; font-size: 11px; }
</style>

<div class="boardto-reporting-wrap">

    <!-- ========================================================
         SECTION 1: TITLE & TOP ACTION
         ======================================================== -->
    <div class="boardto-title-row">
        <div>
            <h1 class="boardto-page-title">Pusat Kontrol Superadmin</h1>
            <div class="boardto-page-sub">Pemantauan Real-Time Sekolah Mitra, Sesi Ujian &amp; Infrastruktur Veyra CBT</div>
        </div>

        <a href="tenants.php?action=new" class="boardto-add-btn" title="Tambah Sekolah Mitra / Tenant Baru">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
        </a>
    </div>

    <!-- LIVE ACTIVITY TICKER -->
    <?php if (!empty($_recentActivity)): ?>
    <div class="command-ticker">
        <div class="command-ticker-label">&#9679; LIVE</div>
        <div class="command-ticker-track">
            <div class="command-ticker-content">
                <?php foreach ($_recentActivity as $i => $act):
                    $timeAgo = diffForHumans(strtotime($act['created_at']));
                    $sep = ($i < count($_recentActivity) - 1) ? ' &nbsp;&#124;&nbsp; ' : '';
                ?>
                    <span><?= htmlspecialchars($act['student_name'] ?? 'Sistem') ?></span>
                    <?= htmlspecialchars($act['message']) ?>
                    <span style="color:#64748b;font-weight:400;">· <?= htmlspecialchars($act['tenant_name'] ?? '') ?></span>
                    <?= $sep ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================
         SECTION 2: FILTER PILLS & VIEW CONTROLS
         ======================================================== -->
    <div class="boardto-control-row">
        <!-- Filter Pills Group -->
        <div class="boardto-pills-group">
            <div class="boardto-filter-pill <?= $initialFilter === 'all' ? 'active' : '' ?>" id="pillAll" onclick="filterByStatus('all')">
                <span>Semua Modul</span>
                <span class="boardto-pill-count">8</span>
            </div>

            <div class="boardto-filter-pill <?= $initialFilter === 'live' ? 'active' : '' ?>" id="pillLive" onclick="filterByStatus('live')">
                <span>Live Monitor</span>
                <span class="boardto-pill-count"><?= max(1, $activeExamsNow) ?></span>
            </div>

            <div class="boardto-filter-pill <?= $initialFilter === 'tenant' ? 'active' : '' ?>" id="pillTenant" onclick="filterByStatus('tenant')">
                <span>Sekolah Mitra</span>
                <span class="boardto-pill-count"><?= max(1, $totalTenants) ?></span>
            </div>

            <div class="boardto-filter-pill <?= $initialFilter === 'banks' ? 'active' : '' ?>" id="pillBanks" onclick="filterByStatus('banks')">
                <span>Master Bank Soal</span>
                <span class="boardto-pill-count"><?= max(1, $totalPlatformBanks) ?></span>
            </div>

            <div class="boardto-filter-pill <?= $initialFilter === 'users' ? 'active' : '' ?>" id="pillUsers" onclick="filterByStatus('users')">
                <span>Admin Sekolah</span>
                <span class="boardto-pill-count"><?= max(1, $totalTenants) ?></span>
            </div>

            <div class="boardto-filter-pill <?= $initialFilter === 'system' ? 'active' : '' ?>" id="pillSystem" onclick="filterByStatus('system')">
                <span>Server &amp; Sistem</span>
                <span class="boardto-pill-count">2</span>
            </div>
        </div>

        <!-- Right Tool Controls -->
        <div class="boardto-actions-group">
            <button type="button" class="boardto-tool-btn" onclick="toggleTenantQuickFilter()" title="Filter Tambahan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"></line>
                    <line x1="4" y1="10" x2="4" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12" y2="3"></line>
                    <line x1="20" y1="21" x2="20" y2="16"></line>
                    <line x1="20" y1="12" x2="20" y2="3"></line>
                    <line x1="1" y1="14" x2="7" y2="14"></line>
                    <line x1="9" y1="8" x2="15" y2="8"></line>
                    <line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
                <span>Filter</span>
            </button>

            <!-- Grid View Toggle -->
            <button type="button" class="boardto-icon-btn active" id="btnViewGrid" onclick="switchViewMode('grid')" title="Tampilan Kartu Operasi">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"></rect>
                    <rect x="14" y="3" width="7" height="7"></rect>
                    <rect x="14" y="14" width="7" height="7"></rect>
                    <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
            </button>

            <!-- List View Toggle -->
            <button type="button" class="boardto-icon-btn" id="btnViewList" onclick="switchViewMode('list')" title="Tampilan Tabel Sekolah Mitra">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="8" y1="6" x2="21" y2="6"></line>
                    <line x1="8" y1="12" x2="21" y2="12"></line>
                    <line x1="8" y1="18" x2="21" y2="18"></line>
                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                </svg>
            </button>
        </div>
    </div>

    <!-- ========================================================
         SECTION 3: 8 FLOATING CBT OPERATIONAL CARDS (GRID VIEW)
         ======================================================== -->
    <div class="boardto-cards-grid" id="boardtoGridContainer">
        <?php foreach ($cbtCards as $card): ?>
            <a href="<?= $card['target_url'] ?>" class="boardto-card" data-category="<?= $card['category'] ?>" data-title="<?= strtolower($card['title'] . ' ' . $card['team']) ?>">
                <!-- 1. Circular Top Colored Badge -->
                <div class="boardto-card-badge" style="background: <?= $card['badge_bg'] ?>;">
                    <?php if ($card['icon'] === 'phone'): ?>
                        <!-- Smartphone Icon: Portal Siswa Mobile & Web -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                            <line x1="12" y1="18" x2="12.01" y2="18"></line>
                        </svg>
                    <?php elseif ($card['icon'] === 'server'): ?>
                        <!-- Server Cluster Icon: Cloud Sync -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                            <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                            <line x1="6" y1="6" x2="6.01" y2="6"></line>
                            <line x1="6" y1="18" x2="6.01" y2="18"></line>
                        </svg>
                    <?php elseif ($card['icon'] === 'school'): ?>
                        <!-- School Building Icon: Tenant Sekolah -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18"></path>
                            <path d="M5 21V7l8-4v18"></path>
                            <path d="M19 21V11l-6-3"></path>
                        </svg>
                    <?php elseif ($card['icon'] === 'pie'): ?>
                        <!-- Pie Chart: Analisis Nilai -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                            <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                        </svg>
                    <?php elseif ($card['icon'] === 'camera'): ?>
                        <!-- Camera Icon: Live Monitor Webcam Anti-Curang -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="23 7 16 12 23 17 23 7"></polygon>
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                        </svg>
                    <?php elseif ($card['icon'] === 'document'): ?>
                        <!-- Document Icon: Master Bank Soal -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                    <?php else: ?>
                        <!-- Headset Icon: Support & Billing -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                            <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                        </svg>
                    <?php endif; ?>
                </div>

                <!-- 2. Big Title -->
                <div class="boardto-card-title"><?= htmlspecialchars($card['title']) ?></div>

                <!-- 3. Target Subtitle / Category -->
                <div class="boardto-card-meta">
                    <span class="boardto-meta-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <circle cx="12" cy="12" r="6"></circle>
                            <circle cx="12" cy="12" r="2"></circle>
                        </svg>
                    </span>
                    <span><?= htmlspecialchars($card['team']) ?></span>
                </div>

                <!-- 4. Time / Status Info -->
                <div class="boardto-card-time">
                    <span class="boardto-meta-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </span>
                    <span><?= htmlspecialchars($card['time_left']) ?></span>
                </div>

                <!-- 5. Card Footer: Operator Avatars & Progress -->
                <div class="boardto-card-footer">
                    <div class="boardto-footer-col">
                        <span class="boardto-footer-label">Pengawas / Operator</span>
                        <div class="boardto-avatar-stack">
                            <?php foreach ($card['avatars'] as $avColor): ?>
                                <span class="boardto-mini-avatar" style="background: <?= $avColor ?>;">
                                    &#9679;
                                </span>
                            <?php endforeach; ?>
                            <span class="boardto-avatar-more">+</span>
                        </div>
                    </div>

                    <div class="boardto-footer-col">
                        <span class="boardto-footer-label" style="text-align: right;">Kesiapan / Uptime</span>
                        <span class="boardto-progress-val"><?= $card['progress'] ?>%</span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ========================================================
         SECTION 4: DATA TABLE VIEW (DAFTAR SEKOLAH MITRA)
         ======================================================== -->
    <div class="boardto-table-card" id="boardtoTableContainer">
        <div class="boardto-table-header-wrap" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Daftar Seluruh Sekolah Mitra Veyra CBT (<?= count($tenants) ?> Mitra)</h3>
            <a href="tenants.php?action=new" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px; border-radius: 10px; text-decoration: none;">+ Tambah Sekolah Mitra</a>
        </div>

        <div style="overflow-x: auto;">
            <table class="boardto-table">
                <thead>
                    <tr>
                        <th>NAMA SEKOLAH / MITRA</th>
                        <th>KODE DOMAIN</th>
                        <th>TOTAL SISWA</th>
                        <th>UJIAN AKTIF</th>
                        <th>PAKET LISENSI</th>
                        <th>STATUS</th>
                        <th style="text-align: right;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tenants)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">Belum ada sekolah mitra terdaftar.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tenants as $t): ?>
                            <tr>
                                <td>
                                    <strong style="color: #0f172a;"><?= htmlspecialchars($t['name'] ?? '-') ?></strong>
                                    <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($t['city'] ?? 'Indonesia') ?></div>
                                </td>
                                <td>
                                    <span style="background: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-family: monospace; font-size: 11.5px; color: #00bcd4; font-weight: 700;">
                                        <?= htmlspecialchars($t['code']) ?>
                                    </span>
                                </td>
                                <td><strong><?= (int)$t['current_students'] ?></strong> siswa</td>
                                <td><strong><?= (int)$t['current_exams'] ?></strong> sesi</td>
                                <td>
                                    <span style="text-transform: capitalize; font-weight: 600; color: #475569;">
                                        <?= htmlspecialchars($t['package'] ?? 'basic') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($t['is_active']): ?>
                                        <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 9999px;">Aktif</span>
                                    <?php else: ?>
                                        <span style="background: #fee2e2; color: #b91c1c; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 9999px;">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="tenants.php?edit=<?= $t['id'] ?>" class="btn btn-outline" style="padding: 4px 10px; font-size: 11.5px; border-radius: 8px; text-decoration: none; color: #0284c7; border: 1px solid #bae6fd;">
                                        Edit Kuota
                                    </a>
                                    <a href="tenants.php?impersonate=<?= $t['id'] ?>" class="btn btn-primary" style="padding: 4px 10px; font-size: 11.5px; border-radius: 8px; text-decoration: none; margin-left: 4px; background: #00bcd4; border-color: #00bcd4;">
                                        Masuk Panel &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// Filter project cards by category pill
function filterByStatus(cat) {
    // 1. Update active pill UI
    document.querySelectorAll('.boardto-filter-pill').forEach(p => p.classList.remove('active'));
    const targetPill = document.getElementById('pill' + cat.charAt(0).toUpperCase() + cat.slice(1));
    if (targetPill) targetPill.classList.add('active');

    // 2. Filter Cards
    const cards = document.querySelectorAll('.boardto-card');
    cards.forEach(card => {
        if (cat === 'all' || card.getAttribute('data-category') === cat) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

// Global search filter
function filterBoardtoCards(query) {
    const q = query.toLowerCase().trim();
    const cards = document.querySelectorAll('.boardto-card');
    cards.forEach(card => {
        const text = card.getAttribute('data-title') || '';
        if (!q || text.includes(q)) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

// Switch between Grid view and List view
function switchViewMode(mode) {
    const gridEl = document.getElementById('boardtoGridContainer');
    const tableEl = document.getElementById('boardtoTableContainer');
    const btnGrid = document.getElementById('btnViewGrid');
    const btnList = document.getElementById('btnViewList');

    if (mode === 'grid') {
        gridEl.style.display = 'grid';
        tableEl.style.display = 'none';
        btnGrid.classList.add('active');
        btnList.classList.remove('active');
    } else {
        gridEl.style.display = 'none';
        tableEl.style.display = 'block';
        btnList.classList.add('active');
        btnGrid.classList.remove('active');
    }
}

function toggleTenantQuickFilter() {
    switchViewMode(document.getElementById('boardtoTableContainer').style.display === 'none' ? 'list' : 'grid');
}

// Apply initial filter from query string if present
document.addEventListener('DOMContentLoaded', function() {
    const initCat = '<?= htmlspecialchars($initialFilter) ?>';
    if (initCat && initCat !== 'all') {
        filterByStatus(initCat);
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
