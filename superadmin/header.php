<?php
/**
 * Veyra CBT - Superadmin Dashboard Layout Header
 * Optimized Sidebar & Navigation for Veyra CBT Platform
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

Auth::requireSuperadmin();
Auth::checkTimeout();
Auth::refreshActivity();

$user = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentFilter = $_GET['filter'] ?? '';
$flash = Helper::getFlash();

// Platform summary counts for sidebar badges
$sidebarActiveStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE is_active = 1");
$sidebarTotalTenants = (int)Database::fetchColumn("SELECT COUNT(*) FROM tenants WHERE is_active = 1");
$sidebarLiveExams = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams WHERE is_active = 1 AND start_time <= NOW() AND end_time >= NOW()"
);

// Real-time Platform Notifications for Superadmin
$notifBlocked = 0;
$notifLiveStudents = 0;
$notifLiveExams = [];
$notifRecentLogs = [];
$notifRecentTenants = [];

try {
    $notifBlocked = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE status = 'blocked'");
    $notifLiveStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE status = 'in_progress'");
    $notifLiveExams = Database::fetchAll(
        "SELECT e.id, e.title, t.name as school_name, e.start_time, e.end_time 
         FROM exams e 
         LEFT JOIN tenants t ON e.tenant_id = t.id 
         WHERE e.is_active = 1 AND e.start_time <= NOW() AND e.end_time >= NOW() 
         ORDER BY e.id DESC LIMIT 3"
    );
    $notifRecentLogs = Database::fetchAll(
        "SELECT al.id, al.event_type, al.message, al.created_at, t.name as tenant_name 
         FROM activity_logs al 
         LEFT JOIN tenants t ON al.tenant_id = t.id 
         ORDER BY al.id DESC LIMIT 4"
    );
    $notifRecentTenants = Database::fetchAll(
        "SELECT id, name, code, created_at FROM tenants WHERE is_active = 1 ORDER BY id DESC LIMIT 2"
    );
} catch (\Throwable $e) {
    error_log("Superadmin notification error: " . $e->getMessage());
}

$totalSuperAlerts = $notifBlocked;
$totalSuperLive = $notifLiveStudents + count($notifLiveExams);
$superBadgeCount = $totalSuperAlerts > 0 ? $totalSuperAlerts : ($totalSuperLive > 0 ? $totalSuperLive : (!empty($notifRecentLogs) ? count($notifRecentLogs) : 0));

if (!function_exists('superTimeAgo')) {
    function superTimeAgo($datetime) {
        $timestamp = strtotime($datetime);
        if (!$timestamp) return $datetime;
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Baru saja';
        if ($diff < 3600) return floor($diff / 60) . 'm lalu';
        if ($diff < 86400) return floor($diff / 3600) . 'j lalu';
        return date('d M H:i', $timestamp);
    }
}

if (!function_exists('superEventMeta')) {
    function superEventMeta($eventType) {
        switch ($eventType) {
            case 'violation':
                return ['label' => 'Pelanggaran', 'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => '⚠️'];
            case 'unblock':
                return ['label' => 'Buka Blokir', 'color' => '#10b981', 'bg' => '#ecfdf5', 'icon' => '🔓'];
            case 'start_exam':
                return ['label' => 'Mulai Ujian', 'color' => '#0284c7', 'bg' => '#f0f9ff', 'icon' => '🚀'];
            case 'finish_exam':
                return ['label' => 'Selesai Ujian', 'color' => '#059669', 'bg' => '#ecfdf5', 'icon' => '🏁'];
            case 'force_finish':
                return ['label' => 'Paksa Selesai', 'color' => '#f59e0b', 'bg' => '#fffbeb', 'icon' => '⚡'];
            default:
                return ['label' => ucwords(str_replace('_', ' ', (string)$eventType)), 'color' => '#64748b', 'bg' => '#f8fafc', 'icon' => '📌'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Pusat Kontrol' ?> | Veyra CBT Superadmin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* 1. SOFT DREAMY GRADIENT BODY WITH AMBIENT GLOW ORBS */
        body.boardto-body {
            background: #eef2f6;
            background-image: 
                radial-gradient(circle at 10% 15%, rgba(255, 255, 255, 0.9) 0%, transparent 35%),
                radial-gradient(circle at 85% 20%, rgba(224, 242, 254, 0.6) 0%, transparent 40%),
                radial-gradient(circle at 50% 85%, rgba(241, 245, 249, 0.8) 0%, transparent 50%);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            overflow-x: hidden;
        }

        /* 2. FLOATING MAIN APPLICATION CONTAINER */
        .boardto-app-container {
            width: 100%;
            max-width: 1420px;
            min-height: 860px;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 25px 70px -10px rgba(0, 0, 0, 0.08), 0 0 1px 1px rgba(255, 255, 255, 0.9) inset;
            display: flex;
            overflow: hidden;
            position: relative;
        }

        /* 3. LEFT SIDEBAR (VEYRA CBT) */
        .boardto-sidebar {
            width: 228px;
            min-width: 228px;
            background: #ffffff;
            border-right: 1px solid #f1f5f9;
            padding: 26px 16px 22px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 10;
        }

        /* Brand Logo: Veyra CBT Badge */
        .boardto-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 6px 22px 6px;
            text-decoration: none;
            border-bottom: 1px solid #f8fafc;
            margin-bottom: 14px;
        }
        .boardto-brand-badge {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.35);
        }
        .boardto-brand-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }
        .boardto-brand-name {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .boardto-brand-tag {
            font-size: 10px;
            font-weight: 700;
            color: #00bcd4;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 1px;
        }

        /* Nav Items */
        .boardto-nav-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .boardto-nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            color: #64748b;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.18s ease;
        }
        .boardto-nav-item-left {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .boardto-nav-item:hover {
            color: #0f172a;
            background: #f8fafc;
        }
        /* Active Cyan Pill */
        .boardto-nav-item.active {
            background: #00bcd4;
            color: #ffffff;
            box-shadow: 0 6px 18px rgba(0, 188, 212, 0.35);
            font-weight: 700;
        }
        .boardto-nav-item.active .boardto-nav-icon {
            color: #ffffff;
        }
        .boardto-nav-icon {
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #64748b;
        }
        .boardto-nav-pill-badge {
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 9999px;
        }
        .boardto-nav-item.active .boardto-nav-pill-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        .boardto-live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
            display: inline-block;
        }

        /* Logout link at bottom */
        .boardto-logout-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 14px;
            color: #64748b;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.18s ease;
        }
        .boardto-logout-item:hover {
            color: #ef4444;
            background: #fee2e2;
        }

        /* 4. MAIN CONTENT AREA (RIGHT) */
        .boardto-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            overflow: visible;
            position: relative;
        }

        /* Top Header Bar */
        .boardto-topbar {
            padding: 18px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            background: #ffffff;
            position: relative;
            z-index: 60;
        }

        /* Search input with magnifying glass */
        .boardto-search-wrap {
            position: relative;
            width: 280px;
        }
        .boardto-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .boardto-search-input {
            width: 100%;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            border-radius: 12px;
            padding: 8px 12px 8px 36px;
            font-size: 12.5px;
            color: #1e293b;
            outline: none;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .boardto-search-input:focus {
            background: #ffffff;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.15);
        }

        /* Topbar Right Controls (Bell + Profile) */
        .boardto-top-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .boardto-notif-wrapper,
        .boardto-profile-wrapper {
            position: relative;
        }

        .boardto-bell-btn {
            position: relative;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .boardto-bell-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }
        .boardto-bell-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #0284c7;
            color: #ffffff;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
        }
        .boardto-bell-dot.dot-danger {
            background: #ef4444;
            animation: pulseDanger 1.8s infinite;
        }
        .boardto-bell-dot.dot-success {
            background: #10b981;
        }
        .boardto-bell-dot.dot-info {
            background: #0284c7;
        }

        @keyframes pulseDanger {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.65); }
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* ── SUPERADMIN NOTIFICATION DROPDOWN ── */
        .super-notif-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 390px;
            max-width: 92vw;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(226, 232, 240, 0.9);
            z-index: 99999 !important;
            display: none;
            overflow: hidden;
            animation: superDropdownSlide 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .super-notif-dropdown.show {
            display: block;
        }

        @keyframes superDropdownSlide {
            0% {
                opacity: 0;
                transform: translateY(-8px) scale(0.97);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .super-notif-header {
            padding: 16px 18px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .super-notif-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .super-notif-title-text {
            font-size: 13.5px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.2px;
        }
        .super-notif-subtitle-text {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        .super-badge-status {
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 9999px;
            white-space: nowrap;
        }
        .badge-alert {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .badge-live {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .badge-ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .super-notif-body {
            padding: 14px 16px;
            max-height: 420px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .super-notif-body::-webkit-scrollbar {
            width: 5px;
        }
        .super-notif-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .super-notif-alert-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .super-notif-alert-card:hover {
            transform: translateY(-1px);
        }
        .super-notif-alert-card.card-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #7f1d1d;
        }
        .super-notif-alert-card.card-danger:hover {
            background: #fee2e2;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.12);
        }
        .super-notif-alert-card.card-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .super-notif-alert-card.card-success:hover {
            background: #d1fae5;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.12);
        }
        .super-notif-alert-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        .super-notif-alert-info {
            flex: 1;
            min-width: 0;
        }
        .super-notif-alert-title {
            font-size: 12.5px;
            font-weight: 800;
            line-height: 1.3;
        }
        .super-notif-alert-desc {
            font-size: 11px;
            opacity: 0.88;
            margin-top: 2px;
            line-height: 1.35;
        }
        .super-notif-link-arrow {
            font-size: 14px;
            font-weight: 800;
            opacity: 0.6;
        }

        .super-notif-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #94a3b8;
            text-transform: uppercase;
            padding-bottom: 2px;
            border-bottom: 1px solid #f1f5f9;
        }

        .super-notif-exams-list,
        .super-notif-logs-list,
        .super-notif-tenants-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .super-notif-exam-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .super-notif-exam-item:hover {
            background: #f1f5f9;
            border-color: #e2e8f0;
        }
        .super-notif-exam-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .super-notif-exam-info {
            flex: 1;
            min-width: 0;
        }
        .super-notif-exam-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .super-notif-exam-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10.5px;
            color: #64748b;
            margin-top: 2px;
        }
        .super-school-tag {
            background: #e2e8f0;
            color: #334155;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 9.5px;
            max-width: 140px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .super-notif-log-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        .super-notif-log-item:hover {
            background: #f8fafc;
        }
        .super-log-icon {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .super-notif-log-content {
            flex: 1;
            min-width: 0;
        }
        .super-notif-log-head {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 2px;
            flex-wrap: wrap;
        }
        .super-event-badge {
            font-size: 9.5px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .super-log-tenant {
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
        }
        .super-log-time {
            font-size: 10px;
            color: #94a3b8;
            margin-left: auto;
        }
        .super-notif-log-msg {
            font-size: 11px;
            color: #334155;
            line-height: 1.35;
            word-break: break-word;
        }

        .super-notif-tenants-list a.super-notif-tenant-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .super-notif-tenants-list a.super-notif-tenant-item:hover {
            background: #f1f5f9;
        }
        .super-tenant-icon {
            font-size: 16px;
        }
        .super-tenant-info {
            flex: 1;
            min-width: 0;
        }
        .super-tenant-name {
            font-size: 12px;
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .super-tenant-code {
            font-size: 10.5px;
            color: #64748b;
            margin-top: 1px;
        }

        .super-notif-empty {
            text-align: center;
            padding: 24px 16px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1px dashed #e2e8f0;
        }

        .super-notif-footer {
            padding: 12px 16px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .super-footer-link {
            font-size: 11.5px;
            font-weight: 700;
            color: #0284c7;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.15s ease;
        }
        .super-footer-link:hover {
            color: #0369a1;
            text-decoration: underline;
        }

        /* ── SUPERADMIN PROFILE DROPDOWN ── */
        .boardto-profile-chip {
            display: flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            cursor: pointer;
            padding: 4px 10px 4px 4px;
            border-radius: 14px;
            transition: background 0.15s ease;
            border: 1px solid transparent;
        }
        .boardto-profile-chip:hover {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        .boardto-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
        }
        .boardto-profile-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .boardto-profile-name {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
        }
        .boardto-profile-role {
            font-size: 11px;
            font-weight: 600;
            color: #00bcd4;
        }
        .boardto-profile-arrow {
            color: #94a3b8;
            transition: transform 0.2s ease;
        }

        .super-profile-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 250px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(226, 232, 240, 0.9);
            z-index: 99999 !important;
            display: none;
            overflow: hidden;
            animation: superDropdownSlide 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .super-profile-dropdown.show {
            display: block;
        }
        .super-profile-dropdown-header {
            padding: 14px 16px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .super-profile-avatar-lg {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .super-profile-header-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.25;
            font-size: 12.5px;
            color: #0f172a;
        }
        .super-badge-role {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            color: #0284c7;
            background: #e0f2fe;
            padding: 1px 6px;
            border-radius: 4px;
            margin-top: 4px;
            width: fit-content;
        }
        .super-profile-dropdown-menu {
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .super-pmenu-item {
            padding: 9px 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.15s ease;
        }
        .super-pmenu-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .super-pmenu-item.text-danger {
            color: #ef4444;
        }
        .super-pmenu-item.text-danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        /* 5. CONTENT CANVAS (Soft gray background with top-left radius) */
        .boardto-content-canvas {
            background: #f7f9fc;
            border-top-left-radius: 28px;
            padding: 26px 32px 34px 32px;
            flex: 1;
            overflow-y: auto;
        }

        /* ── RESPONSIVE & TOPBAR ALIGNMENT STYLES ── */
        .boardto-topbar-main-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: 20px;
        }
        .boardto-top-left {
            display: none;
        }
        .boardto-desktop-search {
            display: block;
            flex: 0 1 360px;
            max-width: 400px;
        }
        .boardto-desktop-search .boardto-search-wrap {
            width: 100%;
        }
        .boardto-mobile-search-row {
            display: none;
        }
        .boardto-top-right {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-left: auto;
            flex-shrink: 0;
        }
        .boardto-sidebar-backdrop {
            display: none;
        }
        .boardto-sidebar-close-btn {
            display: none;
        }
        .boardto-brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        @media (max-width: 1024px) {
            html, body {
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
                position: relative !important;
            }
            body.boardto-body {
                padding: 0 !important;
                background: #f1f5f9;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
                display: block !important;
                min-height: 100vh !important;
                margin: 0 !important;
            }
            .boardto-app-container {
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
                width: 100% !important;
                max-width: 100vw !important;
                flex-direction: column;
                overflow-x: hidden !important;
            }

            .boardto-main {
                width: 100% !important;
                max-width: 100vw !important;
                min-width: 0 !important;
                overflow-x: hidden !important;
            }

            .boardto-content-canvas {
                width: 100% !important;
                max-width: 100vw !important;
                min-width: 0 !important;
                overflow-x: hidden !important;
                padding: 16px 14px 36px 14px !important;
                border-top-left-radius: 0 !important;
            }

            /* Off-Canvas Mobile Drawer Sidebar */
            .boardto-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 280px;
                max-width: 84vw;
                height: 100vh;
                background: #ffffff;
                z-index: 100000;
                box-shadow: 12px 0 40px rgba(15, 23, 42, 0.22);
                transform: translateX(-100%);
                transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
                overflow-y: auto;
                padding: 22px 18px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-width: 0 !important;
            }
            .boardto-sidebar.open {
                transform: translateX(0);
            }

            .boardto-brand-row {
                border-bottom: 1px solid #f1f5f9;
                padding-bottom: 14px;
                margin-bottom: 14px;
            }
            .boardto-brand {
                border-bottom: none;
                margin-bottom: 0;
                padding: 0;
            }

            .boardto-sidebar-close-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                border-radius: 10px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                color: #64748b;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .boardto-sidebar-close-btn:hover {
                background: #fee2e2;
                color: #ef4444;
                border-color: #fecaca;
            }

            /* Backdrop Overlay */
            .boardto-sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(4px);
                z-index: 99999;
                display: none;
                opacity: 0;
                transition: opacity 0.25s ease;
            }
            .boardto-sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }

            /* Mobile Topbar Structure */
            .boardto-topbar {
                padding: 12px 14px !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 10px !important;
                background: #ffffff;
                border-bottom: 1px solid #f1f5f9;
                position: sticky;
                top: 0;
                z-index: 999;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
                width: 100% !important;
                max-width: 100vw !important;
                box-sizing: border-box !important;
            }
            .boardto-topbar-main-row {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                gap: 10px !important;
            }
            .boardto-top-left {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
            }
            .boardto-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 12px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                color: #1e293b;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .boardto-menu-toggle:hover {
                background: #f1f5f9;
                color: #0284c7;
            }
            .boardto-mobile-brand {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .boardto-mobile-brand-icon {
                width: 30px;
                height: 30px;
                border-radius: 8px;
                background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .boardto-mobile-brand-name {
                font-size: 15px;
                font-weight: 800;
                color: #0f172a;
                letter-spacing: -0.3px;
            }

            .boardto-desktop-search {
                display: none !important;
            }

            .boardto-mobile-search-row {
                display: block !important;
                width: 100% !important;
            }
            .boardto-mobile-search-row .boardto-search-wrap {
                width: 100% !important;
            }
            .boardto-search-input {
                padding: 10px 14px 10px 38px;
                font-size: 13.5px;
                border-radius: 12px;
                background: #f8fafc;
                width: 100% !important;
            }

            .boardto-top-right {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                margin-left: auto !important;
                flex-shrink: 0 !important;
            }

            .boardto-profile-meta {
                display: none !important;
            }
            .boardto-profile-arrow {
                display: none !important;
            }
            .boardto-profile-chip {
                padding: 2px !important;
            }
        }

        @media (max-width: 480px) {
            .boardto-content-canvas {
                padding: 14px 12px 32px 12px;
            }
            /* Native-app dropdowns on Android */
            .super-notif-dropdown {
                position: fixed !important;
                top: 68px !important;
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                max-width: none !important;
                max-height: calc(100vh - 85px) !important;
                border-radius: 18px !important;
            }
            .super-profile-dropdown {
                position: fixed !important;
                top: 68px !important;
                left: auto !important;
                right: 10px !important;
                width: calc(100vw - 20px) !important;
                max-width: 280px !important;
                border-radius: 18px !important;
            }

            /* Universal Android Form & Table Polish */
            .table-responsive {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .card-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 12px !important;
            }
            .toolbar-row {
                flex-direction: column !important;
                align-items: stretch !important;
                width: 100% !important;
                gap: 8px !important;
            }
            .toolbar-row select,
            .toolbar-row input,
            .toolbar-row .btn {
                width: 100% !important;
                max-width: 100% !important;
            }
            .modal-dialog {
                width: 95% !important;
                margin: 12px auto !important;
            }
            .form-row {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
        }
    </style>
</head>
<body class="boardto-body">

<div class="boardto-app-container">

    <!-- LEFT SIDEBAR (VEYRA CBT) -->
    <aside class="boardto-sidebar" id="boardtoSidebar">
        <div>
            <!-- Brand & Mobile Close Button -->
            <div class="boardto-brand-row">
                <a href="index.php" class="boardto-brand">
                    <div class="boardto-brand-badge">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                        </svg>
                    </div>
                    <div class="boardto-brand-meta">
                        <span class="boardto-brand-name">Veyra CBT</span>
                        <span class="boardto-brand-tag">Superadmin</span>
                    </div>
                </a>
                <button type="button" class="boardto-sidebar-close-btn" onclick="toggleSuperSidebar(false)" aria-label="Tutup Menu">&times;</button>
            </div>

            <!-- Navigation Links (Tailored for Veyra CBT Superadmin Needs) -->
            <nav class="boardto-nav-list">
                <!-- 1. Dashboard (Overview) -->
                <a href="index.php" class="boardto-nav-item <?= ($currentPage === 'index.php' && empty($currentFilter)) ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                        </span>
                        <span>Dashboard</span>
                    </div>
                </a>

                <!-- 2. Sekolah Mitra (Tenants) -->
                <a href="tenants.php" class="boardto-nav-item <?= $currentPage === 'tenants.php' ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 21h18"></path>
                                <path d="M5 21V7l8-4v18"></path>
                                <path d="M19 21V11l-6-3"></path>
                                <path d="M9 9v.01"></path>
                                <path d="M9 12v.01"></path>
                                <path d="M9 15v.01"></path>
                                <path d="M9 18v.01"></path>
                            </svg>
                        </span>
                        <span>Sekolah Mitra</span>
                    </div>
                    <?php if ($sidebarTotalTenants > 0): ?>
                        <span class="boardto-nav-pill-badge"><?= $sidebarTotalTenants ?></span>
                    <?php endif; ?>
                </a>

                <!-- 3. Live Monitor -->
                <a href="index.php?filter=live" class="boardto-nav-item <?= ($currentPage === 'index.php' && $currentFilter === 'live') ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="23 7 16 12 23 17 23 7"></polygon>
                                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                            </svg>
                        </span>
                        <span>Live Monitor</span>
                    </div>
                    <span class="boardto-live-dot" title="Pemantauan Webcam & Ujian Berlangsung"></span>
                </a>

                <!-- 4. Master Bank Soal -->
                <a href="index.php?filter=banks" class="boardto-nav-item <?= ($currentPage === 'index.php' && $currentFilter === 'banks') ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </span>
                        <span>Master Bank Soal</span>
                    </div>
                </a>

                <!-- 5. Admin Sekolah -->
                <a href="index.php?filter=users" class="boardto-nav-item <?= ($currentPage === 'index.php' && $currentFilter === 'users') ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <span>Admin Sekolah</span>
                    </div>
                </a>

                <!-- 6. Audit & Anti-Curang -->
                <a href="logs.php" class="boardto-nav-item <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            </svg>
                        </span>
                        <span>Audit &amp; Anti-Curang</span>
                    </div>
                </a>

                <!-- 7. Backup Database -->
                <a href="backup.php" class="boardto-nav-item" onclick="return confirm('Unduh database sistem Veyra CBT sebagai file cadangan .sql?')">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                        </span>
                        <span>Backup Database</span>
                    </div>
                </a>

                <!-- 8. Template ID Card -->
                <a href="idcard-templates.php" class="boardto-nav-item <?= $currentPage === 'idcard-templates.php' ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="16" rx="3"></rect>
                                <circle cx="9" cy="10" r="2"></circle>
                                <line x1="15" y1="8" x2="17" y2="8"></line>
                                <line x1="15" y1="12" x2="17" y2="12"></line>
                                <line x1="7" y1="16" x2="17" y2="16"></line>
                            </svg>
                        </span>
                        <span>Template ID Card</span>
                    </div>
                </a>

                <!-- 9. Pengaturan Sistem -->
                <a href="settings.php" class="boardto-nav-item <?= in_array($currentPage, ['settings.php', 'logs-cleanup.php']) ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </span>
                        <span>Pengaturan Sistem</span>
                    </div>
                </a>

                <!-- 10. Profil & Keamanan -->
                <a href="profile.php" class="boardto-nav-item <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
                    <div class="boardto-nav-item-left">
                        <span class="boardto-nav-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <span>Profil &amp; Keamanan</span>
                    </div>
                </a>
            </nav>
        </div>

        <!-- Logout Bottom -->
        <div>
            <a href="../logout.php" class="boardto-logout-item">
                <span class="boardto-nav-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </span>
                <span>Keluar (Logout)</span>
            </a>
        </div>
    </aside>

    <!-- Backdrop Overlay for Mobile Drawer -->
    <div class="boardto-sidebar-backdrop" id="boardtoBackdrop" onclick="toggleSuperSidebar(false)"></div>

    <!-- MAIN DASHBOARD CONTENT AREA -->
    <div class="boardto-main">

        <!-- TOPBAR: Search + Bell + User Profile -->
        <header class="boardto-topbar">
            <!-- Row 1 on Mobile: Menu Toggle + Brand + Right Controls -->
            <div class="boardto-topbar-main-row">
                <div class="boardto-top-left">
                    <button type="button" class="boardto-menu-toggle" id="superMenuToggle" onclick="toggleSuperSidebar(true)" aria-label="Buka Menu" title="Buka Menu">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    <div class="boardto-mobile-brand">
                        <div class="boardto-mobile-brand-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                        </div>
                        <span class="boardto-mobile-brand-name">Veyra CBT</span>
                    </div>
                </div>

                <!-- Desktop Search: Positioned on Left / Center of Topbar on Desktop, hidden on Mobile -->
                <div class="boardto-desktop-search">
                    <div class="boardto-search-wrap">
                        <span class="boardto-search-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </span>
                        <input type="text" id="boardtoGlobalSearch" class="boardto-search-input" placeholder="Cari sekolah, ujian, atau modul..." onkeyup="syncBoardtoSearch(this.value)">
                    </div>
                </div>

                <!-- Right Controls: Bell Notification & User Profile (Docked in Far Top-Right Corner on PC) -->
                <div class="boardto-top-right">
                <!-- 1. Notification Center (Cross-School Live Feed) -->
                <div class="boardto-notif-wrapper">
                    <button type="button" class="boardto-bell-btn" id="superNotifBtn" onclick="toggleSuperDropdown('superNotifDropdown', event)" title="Notifikasi Sistem Veyra CBT" aria-label="Notifikasi Sistem">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <?php if ($superBadgeCount > 0): ?>
                            <span class="boardto-bell-dot <?= $totalSuperAlerts > 0 ? 'dot-danger' : ($totalSuperLive > 0 ? 'dot-success' : 'dot-info') ?>">
                                <?= $superBadgeCount > 99 ? '99+' : $superBadgeCount ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <!-- Superadmin Dropdown Menu -->
                    <div class="super-notif-dropdown" id="superNotifDropdown" onclick="event.stopPropagation()">
                        <!-- Dropdown Header -->
                        <div class="super-notif-header">
                            <div class="super-notif-header-title">
                                <span style="font-size: 16px;">🔔</span>
                                <div>
                                    <div class="super-notif-title-text">Notifikasi Platform</div>
                                    <div class="super-notif-subtitle-text">Monitoring live seluruh sekolah</div>
                                </div>
                            </div>
                            <div>
                                <?php if ($totalSuperAlerts > 0): ?>
                                    <span class="super-badge-status badge-alert">⚠️ <?= $totalSuperAlerts ?> Perlu Ditindak</span>
                                <?php elseif ($totalSuperLive > 0): ?>
                                    <span class="super-badge-status badge-live">🟢 Live Berjalan</span>
                                <?php else: ?>
                                    <span class="super-badge-status badge-ok">✨ Sistem Stabil</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Scrollable Content -->
                        <div class="super-notif-body">
                            <!-- A. ALERT BANNER IF BLOCKED STUDENTS EXIST -->
                            <?php if ($notifBlocked > 0): ?>
                                <a href="logs.php?event=violation" class="super-notif-alert-card card-danger">
                                    <div class="super-notif-alert-icon">⚠️</div>
                                    <div class="super-notif-alert-info">
                                        <div class="super-notif-alert-title"><?= $notifBlocked ?> Sesi Siswa Terblokir!</div>
                                        <div class="super-notif-alert-desc">Terdeteksi kecurangan atau sesi terkunci. Klik untuk periksa log & reset.</div>
                                    </div>
                                    <span class="super-notif-link-arrow">→</span>
                                </a>
                            <?php endif; ?>

                            <!-- B. LIVE ACTIVE STUDENTS IN EXAM -->
                            <?php if ($notifLiveStudents > 0): ?>
                                <a href="index.php?filter=live" class="super-notif-alert-card card-success">
                                    <div class="super-notif-alert-icon">🟢</div>
                                    <div class="super-notif-alert-info">
                                        <div class="super-notif-alert-title"><?= $notifLiveStudents ?> Siswa Sedang Mengerjakan</div>
                                        <div class="super-notif-alert-desc">Sesi ujian berlangsung di platform saat ini secara real-time.</div>
                                    </div>
                                    <span class="super-notif-link-arrow">→</span>
                                </a>
                            <?php endif; ?>

                            <!-- C. LIVE RUNNING EXAMS -->
                            <?php if (!empty($notifLiveExams)): ?>
                                <div class="super-notif-section-label">UJIAN SEDANG BERLANGSUNG</div>
                                <div class="super-notif-exams-list">
                                    <?php foreach ($notifLiveExams as $le): ?>
                                        <a href="index.php?filter=live" class="super-notif-exam-item">
                                            <div class="super-notif-exam-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                            </div>
                                            <div class="super-notif-exam-info">
                                                <div class="super-notif-exam-title"><?= htmlspecialchars($le['title']) ?></div>
                                                <div class="super-notif-exam-meta">
                                                    <span class="super-school-tag"><?= htmlspecialchars($le['school_name'] ?? 'Sekolah Mitra') ?></span>
                                                    <span><?= date('H:i', strtotime($le['start_time'])) ?> - <?= date('H:i', strtotime($le['end_time'])) ?></span>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- D. RECENT PLATFORM AUDIT / ACTIVITY LOGS -->
                            <div class="super-notif-section-label" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>AKTIVITAS & AUDIT TERBARU</span>
                                <a href="logs.php" style="font-size: 11px; color: #0284c7; text-decoration: none; font-weight: 700;">Lihat Log</a>
                            </div>

                            <?php if (!empty($notifRecentLogs)): ?>
                                <div class="super-notif-logs-list">
                                    <?php foreach ($notifRecentLogs as $lg): 
                                        $meta = superEventMeta($lg['event_type']);
                                    ?>
                                        <div class="super-notif-log-item">
                                            <span class="super-log-icon" style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>;">
                                                <?= $meta['icon'] ?>
                                            </span>
                                            <div class="super-notif-log-content">
                                                <div class="super-notif-log-head">
                                                    <span class="super-event-badge" style="color: <?= $meta['color'] ?>; background: <?= $meta['bg'] ?>;">
                                                        <?= $meta['label'] ?>
                                                    </span>
                                                    <?php if (!empty($lg['tenant_name'])): ?>
                                                        <span class="super-log-tenant"><?= htmlspecialchars($lg['tenant_name']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="super-log-time"><?= superTimeAgo($lg['created_at']) ?></span>
                                                </div>
                                                <div class="super-notif-log-msg"><?= htmlspecialchars($lg['message']) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="super-notif-empty">
                                    <div style="font-size: 24px; margin-bottom: 6px;">✨</div>
                                    <div style="font-weight: 700; color: #334155; font-size: 13px;">Belum Ada Aktivitas Terkini</div>
                                    <div style="font-size: 11.5px; color: #94a3b8; margin-top: 2px;">Log aktivitas ujian akan muncul di sini secara real-time.</div>
                                </div>
                            <?php endif; ?>

                            <!-- E. NEW REGISTERED SCHOOLS -->
                            <?php if (!empty($notifRecentTenants)): ?>
                                <div class="super-notif-section-label" style="margin-top: 4px;">SEKOLAH MITRA TERBARU</div>
                                <div class="super-notif-tenants-list">
                                    <?php foreach ($notifRecentTenants as $rt): ?>
                                        <a href="tenants.php" class="super-notif-tenant-item">
                                            <span class="super-tenant-icon">🏫</span>
                                            <div class="super-tenant-info">
                                                <div class="super-tenant-name"><?= htmlspecialchars($rt['name'] ?? '-') ?></div>
                                                <div class="super-tenant-code">Kode: <?= htmlspecialchars($rt['code'] ?? '-') ?> • <?= date('d M Y', strtotime($rt['created_at'])) ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Dropdown Footer -->
                        <div class="super-notif-footer">
                            <a href="logs.php" class="super-footer-link">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                Semua Log Audit
                            </a>
                            <a href="tenants.php" class="super-footer-link">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path></svg>
                                Kelola Sekolah
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2. User Profile Chip & Dropdown -->
                <div class="boardto-profile-wrapper">
                    <div class="boardto-profile-chip" id="superProfileBtn" onclick="toggleSuperDropdown('superProfileDropdown', event)" title="Profil Superadmin Veyra CBT">
                        <div class="boardto-avatar">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                        </div>
                        <div class="boardto-profile-meta">
                            <span class="boardto-profile-name"><?= htmlspecialchars($user['name'] ?? 'Super Administrator') ?></span>
                            <span class="boardto-profile-role">Superadmin CBT</span>
                        </div>
                        <span class="boardto-profile-arrow">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </span>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="super-profile-dropdown" id="superProfileDropdown" onclick="event.stopPropagation()">
                        <div class="super-profile-dropdown-header">
                            <div class="super-profile-avatar-lg">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            </div>
                            <div class="super-profile-header-meta">
                                <strong><?= htmlspecialchars($user['name'] ?? 'Super Administrator') ?></strong>
                                <span style="font-size: 11px; color: #0284c7; font-weight: 600;"><?= htmlspecialchars($user['email'] ?? 'superadmin@cbt.local') ?></span>
                                <span class="super-badge-role">Pusat Superadmin</span>
                            </div>
                        </div>
                        <div class="super-profile-dropdown-menu">
                            <a href="profile.php" class="super-pmenu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <span>Profil &amp; Keamanan Akun</span>
                            </a>
                            <a href="tenants.php" class="super-pmenu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path></svg>
                                <span>Kelola Sekolah Mitra</span>
                            </a>
                            <a href="logs.php" class="super-pmenu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                <span>Audit &amp; Anti-Curang</span>
                            </a>
                            <a href="settings.php" class="super-pmenu-item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                <span>Pengaturan Sistem</span>
                            </a>
                            <a href="backup.php" class="super-pmenu-item" onclick="return confirm('Unduh database sistem Veyra CBT sebagai cadangan SQL?')">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                <span>Backup Database (.sql)</span>
                            </a>
                            <div style="height: 1px; background: #f1f5f9; margin: 6px 0;"></div>
                            <a href="../logout.php" class="super-pmenu-item text-danger">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                <span>Keluar (Logout)</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div> <!-- /boardto-top-right -->
        </div> <!-- /boardto-topbar-main-row -->

        <!-- Row 2 on Mobile: Full-Width Search Input (hidden on desktop) -->
        <div class="boardto-mobile-search-row">
            <div class="boardto-search-wrap">
                <span class="boardto-search-icon">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </span>
                <input type="text" id="boardtoMobileSearch" class="boardto-search-input" placeholder="Cari sekolah, ujian, atau modul..." onkeyup="syncBoardtoSearch(this.value)">
            </div>
        </div>
    </header>

    <script>
    // Sync Search between Desktop & Mobile inputs
    function syncBoardtoSearch(val) {
        var d = document.getElementById('boardtoGlobalSearch');
        var m = document.getElementById('boardtoMobileSearch');
        if (d && d.value !== val) d.value = val;
        if (m && m.value !== val) m.value = val;
        if (typeof filterBoardtoCards === 'function') {
            filterBoardtoCards(val);
        }
    }
    // Toggle Mobile Drawer Sidebar
    function toggleSuperSidebar(open) {
        var sb = document.getElementById('boardtoSidebar');
        var bd = document.getElementById('boardtoBackdrop');
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

    // Toggle Superadmin Dropdowns (Notification & Profile)
    function toggleSuperDropdown(id, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        var target = document.getElementById(id);
        if (!target) return;
        var isShown = target.classList.contains('show');

        // Close all open dropdowns first
        document.querySelectorAll('.super-notif-dropdown, .super-profile-dropdown').forEach(function(el) {
            el.classList.remove('show');
        });

        // Toggle target
        if (!isShown) {
            target.classList.add('show');
        }
    }

    // Global outside click handler
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.boardto-notif-wrapper') && !e.target.closest('.boardto-profile-wrapper')) {
            document.querySelectorAll('.super-notif-dropdown, .super-profile-dropdown').forEach(function(el) {
                el.classList.remove('show');
            });
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            document.querySelectorAll('.super-notif-dropdown, .super-profile-dropdown').forEach(function(el) {
                el.classList.remove('show');
            });
            toggleSuperSidebar(false);
        }
    });
    </script>

        <!-- MAIN WORKSPACE CANVAS -->
        <main class="boardto-content-canvas">
            <?php if (Auth::isDefaultSuperadminPassword()): ?>
                <div style="background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border: 1px solid #fca5a5; border-left: 6px solid #dc2626; border-radius: 16px; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.08);">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <span style="font-size: 26px; line-height: 1;">⚠️</span>
                        <div>
                            <div style="font-weight: 800; color: #991b1b; font-size: 14px;">PERINGATAN KEAMANAN: Kata Sandi Default Masih Aktif!</div>
                            <div style="color: #7f1d1d; font-size: 12.5px; margin-top: 2px;">Akun Superadmin Anda masih memakai kata sandi bawaan sistem (<code>admin123</code>). Segera ganti kata sandi agar terhindar dari peretasan.</div>
                        </div>
                    </div>
                    <a href="profile.php#password-section" class="btn btn-sm" style="background: #dc2626; color: #ffffff; font-weight: 700; border-radius: 9999px; padding: 8px 18px; white-space: nowrap; text-decoration: none; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);">
                        Ubah Kata Sandi Sekarang &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" style="margin-bottom: 20px; border-radius: 14px;">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>
