<?php
/**
 * Veyra CBT - Pusat Pengaturan Sistem Platform (Superadmin)
 * Modern Enterprise System Settings with Tab Navigation
 */

$pageTitle = "Pengaturan Sistem";
require_once __DIR__ . '/_bootstrap.php';

// 1. Path Konfigurasi File
$platformConfigFile = __DIR__ . '/../config/platform.php';
$sessionConfigFile  = __DIR__ . '/../config/session.php';
$mailConfigFile     = __DIR__ . '/../config/mail.php';

// 2. Muat Konfigurasi dengan Fallback Default
$platform = file_exists($platformConfigFile) ? require $platformConfigFile : [];
$platform = array_merge([
    'app_name'               => 'Veyra CBT Platform',
    'app_tagline'            => 'Pusat Ujian Komputer Berbasis Web Multi-Sekolah',
    'helpdesk_phone'         => '0812-3456-7890',
    'helpdesk_email'         => 'admin@cbtplatform.test',
    'maintenance_mode'       => false,
    'maintenance_msg'        => 'Sistem Veyra CBT sedang dalam pemeliharaan berkala untuk peningkatan performa. Silakan coba kembali dalam beberapa saat.',
    'allow_registration'     => true,
    'default_max_violations' => 3,
    'enforce_fullscreen'     => true,
    'block_devtools'         => true,
    'block_copy_paste'       => true,
    'auto_sync_interval'     => 5,
], $platform);

$sessionConfig = file_exists($sessionConfigFile) ? require $sessionConfigFile : [];
$sessionConfig = array_merge([
    'superadmin'     => 3600,
    'admin'          => 1800,
    'teacher'        => 1800,
    'student'        => 3600,
    'warning_before' => 120,
], $sessionConfig);

$mailConfig = file_exists($mailConfigFile) ? require $mailConfigFile : [];
$mailConfig = array_merge([
    'mail_from'      => 'noreply@cbtonline.local',
    'mail_from_name' => 'Veyra CBT Platform',
    'smtp_host'      => '',
    'smtp_port'      => 587,
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'use_smtp'       => false,
], $mailConfig);

// Helper fungsi untuk menulis file konfigurasi PHP
function writeConfigFile(string $filePath, array $data, string $description): bool {
    $code = "<?php\n/**\n * " . addslashes($description) . "\n * Terakhir diperbarui: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($data, true) . ";\n";
    return (bool)file_put_contents($filePath, $code, LOCK_EX);
}

// 3. Handle Form Submissions (POST)
$activeTab = $_POST['active_tab'] ?? 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    // A. Simpan Pengaturan Umum & Branding
    if ($action === 'save_general') {
        $platform['app_name']           = trim($_POST['app_name'] ?? 'Veyra CBT Platform');
        $platform['app_tagline']        = trim($_POST['app_tagline'] ?? '');
        $platform['helpdesk_phone']     = trim($_POST['helpdesk_phone'] ?? '');
        $platform['helpdesk_email']     = trim($_POST['helpdesk_email'] ?? '');
        $platform['maintenance_mode']   = isset($_POST['maintenance_mode']);
        $platform['maintenance_msg']    = trim($_POST['maintenance_msg'] ?? '');
        $platform['allow_registration'] = isset($_POST['allow_registration']);

        if (writeConfigFile($platformConfigFile, $platform, 'Konfigurasi Global Platform — Veyra CBT')) {
            Helper::setFlash('success', 'Pengaturan umum platform berhasil disimpan.');
        } else {
            Helper::setFlash('danger', 'Gagal menulis file konfigurasi platform. Periksa izin folder config/.');
        }
        Helper::redirect('superadmin/settings.php#general');
    }

    // B. Simpan Pengaturan Keamanan & Sesi
    if ($action === 'save_security') {
        $saMinutes      = max(5, (int)($_POST['timeout_superadmin'] ?? 60));
        $adminMinutes   = max(5, (int)($_POST['timeout_admin'] ?? 30));
        $teacherMinutes = max(5, (int)($_POST['timeout_teacher'] ?? 30));
        $studentMinutes = max(10, (int)($_POST['timeout_student'] ?? 60));
        $warningSecs    = max(30, (int)($_POST['warning_before'] ?? 120));

        $sessionConfig['superadmin']     = $saMinutes * 60;
        $sessionConfig['admin']          = $adminMinutes * 60;
        $sessionConfig['teacher']        = $teacherMinutes * 60;
        $sessionConfig['student']        = $studentMinutes * 60;
        $sessionConfig['warning_before'] = $warningSecs;

        if (writeConfigFile($sessionConfigFile, $sessionConfig, 'Session timeout configuration')) {
            Helper::setFlash('success', 'Kebijakan durasi sesi login dan keamanan berhasil diperbarui.');
        } else {
            Helper::setFlash('danger', 'Gagal menyimpan konfigurasi sesi.');
        }
        Helper::redirect('superadmin/settings.php#security');
    }

    // C. Simpan Pengaturan Kebijakan Ujian & Anti-Curang
    if ($action === 'save_proctoring') {
        $platform['default_max_violations'] = max(1, min(20, (int)($_POST['default_max_violations'] ?? 3)));
        $platform['enforce_fullscreen']     = isset($_POST['enforce_fullscreen']);
        $platform['block_devtools']         = isset($_POST['block_devtools']);
        $platform['block_copy_paste']       = isset($_POST['block_copy_paste']);
        $platform['auto_sync_interval']     = max(3, min(60, (int)($_POST['auto_sync_interval'] ?? 5)));

        if (writeConfigFile($platformConfigFile, $platform, 'Konfigurasi Global Platform — Veyra CBT')) {
            Helper::setFlash('success', 'Kebijakan default proctoring & anti-curang ujian berhasil disimpan.');
        } else {
            Helper::setFlash('danger', 'Gagal menyimpan kebijakan anti-curang.');
        }
        Helper::redirect('superadmin/settings.php#proctoring');
    }

    // D. Simpan Pengaturan Server Email & SMTP
    if ($action === 'save_mail') {
        $mailConfig['use_smtp']       = isset($_POST['use_smtp']);
        $mailConfig['mail_from']      = trim($_POST['mail_from'] ?? 'noreply@cbtonline.local');
        $mailConfig['mail_from_name'] = trim($_POST['mail_from_name'] ?? 'Veyra CBT Platform');
        $mailConfig['smtp_host']      = trim($_POST['smtp_host'] ?? '');
        $mailConfig['smtp_port']      = (int)($_POST['smtp_port'] ?? 587);
        $mailConfig['smtp_user']      = trim($_POST['smtp_user'] ?? '');
        
        $newPass = trim($_POST['smtp_pass'] ?? '');
        if ($newPass !== '') {
            $mailConfig['smtp_pass'] = $newPass;
        }

        if (writeConfigFile($mailConfigFile, $mailConfig, 'Konfigurasi Mail / Email untuk CBT Online')) {
            Helper::setFlash('success', 'Konfigurasi server email SMTP berhasil disimpan.');
        } else {
            Helper::setFlash('danger', 'Gagal menyimpan konfigurasi email.');
        }
        Helper::redirect('superadmin/settings.php#mail');
    }

    // E. Eksekusi Pembersihan Log Aktivitas Lama
    if ($action === 'cleanup_logs') {
        $days = (int)($_POST['retention_days'] ?? 0);
        if ($days >= 30) {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $countDeleted = Database::delete('activity_logs', 'created_at < :cutoff', ['cutoff' => $cutoffDate]);
            Helper::setFlash('success', "Pembersihan selesai: {$countDeleted} entri log lama sebelum tanggal {$cutoffDate} telah dihapus.");
        } else {
            Helper::setFlash('warning', 'Pilih periode retensi log minimal 30 hari.');
        }
        Helper::redirect('superadmin/settings.php#maintenance');
    }
}

// 4. Data Statistik Server & Platform
$totalLogsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM activity_logs");
$totalTenantsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM tenants");
$totalStudentsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM students");
$totalExamsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams");
$totalBanksCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM question_banks");
$dbVersion = Database::fetchColumn("SELECT VERSION()");

$dbSize = "0 MB";
try {
    $bytes = (float)Database::fetchColumn(
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    $dbSize = number_format($bytes / 1048576, 2) . " MB";
} catch (\Throwable $e) {}

require_once __DIR__ . '/header.php';
?>

<style>
/* ========================================================
   VEYRA CBT MODERN SETTINGS STYLES
   ======================================================== */
.settings-wrap {
    display: flex;
    flex-direction: column;
    gap: 22px;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* Header Banner */
.settings-header-card {
    background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
    border-radius: 24px;
    padding: 28px 32px;
    color: #ffffff;
    box-shadow: 0 10px 30px -5px rgba(2, 132, 199, 0.28);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    position: relative;
    overflow: hidden;
}
.settings-header-card::after {
    content: '';
    position: absolute;
    right: -40px;
    bottom: -50px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.18) 0%, transparent 70%);
    pointer-events: none;
}
.settings-title {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.4px;
    margin: 0;
}
.settings-subtitle {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.9);
    margin-top: 5px;
    font-weight: 500;
    max-width: 680px;
    line-height: 1.5;
}
.settings-header-badge {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.35);
    padding: 8px 16px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

/* Tab Navigation Strip */
.settings-tabs-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    padding: 8px 12px;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    width: 100%;
    box-sizing: border-box;
}
.settings-tabs-nav::-webkit-scrollbar {
    display: none;
}
.settings-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
}
.settings-tab-btn:hover {
    color: #0284c7;
    background: #f0f9ff;
}
.settings-tab-btn.active {
    color: #00838f;
    background: #e0f7fa;
    box-shadow: 0 2px 8px rgba(0, 188, 212, 0.15);
}

/* Tab Content Panels */
.settings-panel {
    display: none;
    animation: fadeInPanel 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.settings-panel.active {
    display: block;
}
@keyframes fadeInPanel {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Card Container */
.settings-card {
    background: #ffffff;
    border-radius: 22px;
    border: 1px solid #edf2f7;
    box-shadow: 0 6px 25px rgba(15, 23, 42, 0.04);
    padding: 30px 32px;
}
.settings-card-header {
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 18px;
    margin-bottom: 24px;
}
.settings-section-title {
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}
.settings-section-sub {
    font-size: 12.5px;
    color: #64748b;
    margin-top: 4px;
}

/* Form Layout Grid */
.settings-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 22px 28px;
}
.settings-form-full {
    grid-column: 1 / -1;
}

.settings-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.settings-field-label {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.settings-field-hint {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 2px;
    line-height: 1.4;
}

/* Inputs */
.settings-input, .settings-select, .settings-textarea {
    width: 100%;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 11px 14px;
    font-size: 13.5px;
    color: #0f172a;
    font-family: inherit;
    outline: none;
    transition: all 0.15s ease;
    box-sizing: border-box;
}
.settings-input:focus, .settings-select:focus, .settings-textarea:focus {
    background: #ffffff;
    border-color: #00bcd4;
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.15);
}

/* Modern Toggle Switch (iOS Style) */
.switch-control-card {
    background: #f8fafc;
    border: 1.5px solid #edf2f7;
    border-radius: 16px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    transition: all 0.15s ease;
}
.switch-control-card:hover {
    border-color: #cbd5e1;
    background: #ffffff;
}
.switch-text-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.switch-label-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
}
.switch-label-desc {
    font-size: 11.5px;
    color: #64748b;
    line-height: 1.4;
}

.veyra-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
}
.veyra-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.veyra-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #cbd5e1;
    transition: .22s cubic-bezier(0.16, 1, 0.3, 1);
    border-radius: 34px;
}
.veyra-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .22s cubic-bezier(0.16, 1, 0.3, 1);
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.veyra-switch input:checked + .veyra-slider {
    background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
}
.veyra-switch input:checked + .veyra-slider:before {
    transform: translateX(22px);
}

/* Submit Action Bar */
.settings-action-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 14px;
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid #f1f5f9;
}
.btn-save-settings {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #0284c7 0%, #00bcd4 100%);
    color: #ffffff;
    font-size: 13.5px;
    font-weight: 700;
    border: none;
    border-radius: 12px;
    padding: 12px 26px;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(0, 188, 212, 0.35);
    transition: all 0.18s ease;
}
.btn-save-settings:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(0, 188, 212, 0.45);
}

/* Server Stats Grid */
.server-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.server-stat-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.15s ease;
}
.server-stat-card:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
}
.server-stat-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.server-stat-value {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    word-break: break-all;
}
.server-stat-sub {
    font-size: 11.5px;
    color: #94a3b8;
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    .server-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 768px) {
    .settings-header-card {
        padding: 22px 20px;
        flex-direction: column;
        align-items: flex-start;
        border-radius: 18px;
    }
    .settings-title {
        font-size: 20px;
    }
    .settings-card {
        padding: 20px 18px;
        border-radius: 18px;
    }
    .settings-form-grid {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    .server-stats-grid {
        grid-template-columns: 1fr;
    }
    .settings-action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .btn-save-settings {
        justify-content: center;
        width: 100%;
    }
}
</style>

<div class="settings-wrap">

    <!-- 1. Header Banner -->
    <div class="settings-header-card">
        <div>
            <h1 class="settings-title">Pusat Pengaturan Sistem Platform</h1>
            <div class="settings-subtitle">Konfigurasi parameter global, kebijakan sesi keamanan, anti-curang ujian, server email, dan pemeliharaan database Veyra CBT.</div>
        </div>
        <div class="settings-header-badge">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <span>Auto-Save Protected</span>
        </div>
    </div>

    <!-- 2. Segmented Navigation Tabs -->
    <div class="settings-tabs-nav">
        <button type="button" class="settings-tab-btn active" data-tab="general" onclick="switchSettingTab('general')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            <span>Umum &amp; Platform</span>
        </button>

        <button type="button" class="settings-tab-btn" data-tab="security" onclick="switchSettingTab('security')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <span>Keamanan &amp; Sesi</span>
        </button>

        <button type="button" class="settings-tab-btn" data-tab="proctoring" onclick="switchSettingTab('proctoring')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><polygon points="12 8 8 12 12 16 16 12 12 8"></polygon></svg>
            <span>Kebijakan Anti-Curang</span>
        </button>

        <button type="button" class="settings-tab-btn" data-tab="mail" onclick="switchSettingTab('mail')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
            <span>Server Email SMTP</span>
        </button>

        <button type="button" class="settings-tab-btn" data-tab="maintenance" onclick="switchSettingTab('maintenance')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            <span>Pembersihan Log &amp; DB</span>
        </button>

        <button type="button" class="settings-tab-btn" data-tab="server" onclick="switchSettingTab('server')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
            <span>Informasi Server</span>
        </button>
    </div>

    <!-- ========================================================
         TAB 1: UMUM & PLATFORM
         ======================================================== -->
    <div class="settings-panel active" id="panel-general">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    Identitas &amp; Pengaturan Umum Platform
                </h2>
                <div class="settings-section-sub">Kelola nama sistem, narasi branding, kontak helpdesk, serta status operasional platform CBT.</div>
            </div>

            <form method="POST">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="save_general">
                <input type="hidden" name="active_tab" value="general">

                <div class="settings-form-grid">
                    <div class="settings-field">
                        <label class="settings-field-label">Nama Aplikasi / Platform</label>
                        <input type="text" name="app_name" class="settings-input" value="<?= htmlspecialchars($platform['app_name']) ?>" required>
                        <span class="settings-field-hint">Nama platform utama yang tampil di login, header, dan sertifikat/kartu ujian.</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Tagline / Deskripsi Singkat</label>
                        <input type="text" name="app_tagline" class="settings-input" value="<?= htmlspecialchars($platform['app_tagline']) ?>">
                        <span class="settings-field-hint">Slogan atau penjelasan singkat platform di halaman publik portal.</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Nomor WhatsApp / Hotline Bantuan</label>
                        <input type="text" name="helpdesk_phone" class="settings-input" value="<?= htmlspecialchars($platform['helpdesk_phone']) ?>" placeholder="Misal: 0812-3456-7890">
                        <span class="settings-field-hint">Kontak customer service / technical support pusat untuk admin sekolah.</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Email Dukungan Pelanggan (Helpdesk)</label>
                        <input type="email" name="helpdesk_email" class="settings-input" value="<?= htmlspecialchars($platform['helpdesk_email']) ?>" placeholder="support@domain.com">
                        <span class="settings-field-hint">Alamat email resmi untuk pengaduan kendala operasional CBT.</span>
                    </div>

                    <div class="settings-form-full">
                        <div class="switch-control-card">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title">Izinkan Pendaftaran Sekolah Mandiri (Self-Registration)</span>
                                <span class="switch-label-desc">Jika diaktifkan, formulir pendaftaran sekolah mitra baru (register.php) terbuka untuk publik. Matikan jika pendaftaran hanya melalui Superadmin.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="allow_registration" value="1" <?= $platform['allow_registration'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-form-full">
                        <div class="switch-control-card" style="border-color: <?= $platform['maintenance_mode'] ? '#fecaca' : '#edf2f7' ?>; background: <?= $platform['maintenance_mode'] ? '#fff5f5' : '#f8fafc' ?>;">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title" style="color: <?= $platform['maintenance_mode'] ? '#dc2626' : '#0f172a' ?>;">
                                    Mode Pemeliharaan (Maintenance Mode)
                                </span>
                                <span class="switch-label-desc">Kunci akses seluruh siswa dan guru ke portal CBT saat server sedang update. Hanya Superadmin yang tetap dapat masuk.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="maintenance_mode" value="1" <?= $platform['maintenance_mode'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-form-full">
                        <label class="settings-field-label">Pesan Pemberitahuan Saat Mode Pemeliharaan Aktif</label>
                        <textarea name="maintenance_msg" rows="3" class="settings-textarea"><?= htmlspecialchars($platform['maintenance_msg']) ?></textarea>
                        <span class="settings-field-hint">Pesan yang ditampilkan kepada siswa/guru saat mencoba login saat maintenance mode aktif.</span>
                    </div>
                </div>

                <div class="settings-action-bar">
                    <button type="submit" class="btn-save-settings">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Simpan Pengaturan Umum</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         TAB 2: KEAMANAN & SESI
         ======================================================== -->
    <div class="settings-panel" id="panel-security">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Durasi Sesi Login &amp; Keamanan Akun
                </h2>
                <div class="settings-section-sub">Atur masa berlaku sesi inaktif otomatis (session timeout) per hak akses untuk mencegah pembajakan akun saat perangkat ditinggalkan.</div>
            </div>

            <form method="POST">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="save_security">
                <input type="hidden" name="active_tab" value="security">

                <div class="settings-form-grid">
                    <div class="settings-field">
                        <label class="settings-field-label">Timeout Sesi Superadmin (Menit)</label>
                        <input type="number" name="timeout_superadmin" class="settings-input" value="<?= (int)($sessionConfig['superadmin'] / 60) ?>" min="5" max="480" required>
                        <span class="settings-field-hint">Otomatis logout jika Superadmin tidak aktif selama X menit (Default: 60 menit).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Timeout Sesi Admin Sekolah (Menit)</label>
                        <input type="number" name="timeout_admin" class="settings-input" value="<?= (int)($sessionConfig['admin'] / 60) ?>" min="5" max="240" required>
                        <span class="settings-field-hint">Batas inaktivitas untuk admin sekolah / operator (Default: 30 menit).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Timeout Sesi Guru &amp; Pembuat Soal (Menit)</label>
                        <input type="number" name="timeout_teacher" class="settings-input" value="<?= (int)($sessionConfig['teacher'] / 60) ?>" min="5" max="240" required>
                        <span class="settings-field-hint">Batas inaktivitas saat guru menginput soal atau rekap nilai (Default: 30 menit).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Timeout Sesi Siswa (Menit)</label>
                        <input type="number" name="timeout_student" class="settings-input" value="<?= (int)($sessionConfig['student'] / 60) ?>" min="10" max="360" required>
                        <span class="settings-field-hint">Batas waktu sesi siswa di luar durasi ujian (Default: 60 menit).</span>
                    </div>

                    <div class="settings-form-full">
                        <div class="settings-field">
                            <label class="settings-field-label">Peringatan Sebelum Sesi Berakhir (Detik)</label>
                            <input type="number" name="warning_before" class="settings-input" value="<?= (int)($sessionConfig['warning_before'] ?? 120) ?>" min="30" max="600" required>
                            <span class="settings-field-hint">Modal dialog peringatan hitung mundur muncul sebelum logout otomatis dilakukan (Default: 120 detik / 2 menit).</span>
                        </div>
                    </div>
                </div>

                <div class="settings-action-bar">
                    <button type="submit" class="btn-save-settings">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Simpan Kebijakan Keamanan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         TAB 3: KEBIJAKAN ANTI-CURANG & UJIAN
         ======================================================== -->
    <div class="settings-panel" id="panel-proctoring">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><circle cx="12" cy="12" r="10"></circle><polygon points="12 8 8 12 12 16 16 12 12 8"></polygon></svg>
                    Default Kebijakan Anti-Curang (Proctoring Policy)
                </h2>
                <div class="settings-section-sub">Parameter baku anti-curang yang otomatis diterapkan pada setiap pembuatan jadwal ujian baru di seluruh sekolah.</div>
            </div>

            <form method="POST">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="save_proctoring">
                <input type="hidden" name="active_tab" value="proctoring">

                <div class="settings-form-grid">
                    <div class="settings-field">
                        <label class="settings-field-label">Default Batas Toleransi Pindah Tab (Kali)</label>
                        <input type="number" name="default_max_violations" class="settings-input" value="<?= (int)$platform['default_max_violations'] ?>" min="1" max="20" required>
                        <span class="settings-field-hint">Jumlah maksimal siswa boleh keluar dari jendela ujian sebelum akun terkunci otomatis (Default: 3x).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Interval Sinkronisasi Live Monitor (Detik)</label>
                        <input type="number" name="auto_sync_interval" class="settings-input" value="<?= (int)$platform['auto_sync_interval'] ?>" min="3" max="30" required>
                        <span class="settings-field-hint">Kecepatan refresh data polling pada dashboard pengawas ujian (Default: 5 detik).</span>
                    </div>

                    <div class="settings-form-full">
                        <div class="switch-control-card">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title">Kunci Layar Penuh (Enforce Fullscreen Mode)</span>
                                <span class="switch-label-desc">Siswa diwajibkan masuk mode layar penuh (F11/Fullscreen API) saat mengerjakan ujian dan diperingatkan jika keluar.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="enforce_fullscreen" value="1" <?= $platform['enforce_fullscreen'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-form-full">
                        <div class="switch-control-card">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title">Blokir Developer Tools &amp; Inspect Element</span>
                                <span class="switch-label-desc">Menonaktifkan tombol F12, Ctrl+Shift+I, Ctrl+Shift+C, dan Ctrl+U untuk mencegah siswa mengintip source code jawaban.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="block_devtools" value="1" <?= $platform['block_devtools'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-form-full">
                        <div class="switch-control-card">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title">Blokir Klik Kanan &amp; Salin Teks (Copy-Paste Lock)</span>
                                <span class="switch-label-desc">Mencegah siswa menduplikasi soal ke mesin pencari atau aplikasi AI luar (ChatGPT/Google Lens) via copy-paste.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="block_copy_paste" value="1" <?= $platform['block_copy_paste'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="settings-action-bar">
                    <button type="submit" class="btn-save-settings">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Simpan Kebijakan Anti-Curang</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         TAB 4: SERVER EMAIL & SMTP
         ======================================================== -->
    <div class="settings-panel" id="panel-mail">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    Konfigurasi Server Email &amp; SMTP
                </h2>
                <div class="settings-section-sub">Digunakan untuk mengirim notifikasi reset password, pengumuman jadwal ujian, atau alert sistem ke sekolah mitra.</div>
            </div>

            <form method="POST">
                <?= Helper::csrfField() ?>
                <input type="hidden" name="action" value="save_mail">
                <input type="hidden" name="active_tab" value="mail">

                <div class="settings-form-grid">
                    <div class="settings-form-full">
                        <div class="switch-control-card">
                            <div class="switch-text-wrap">
                                <span class="switch-label-title">Gunakan Server SMTP Khusus (External Mailer)</span>
                                <span class="switch-label-desc">Aktifkan untuk mengirim email via protokol SMTP (Gmail, Mailgun, Brevo, AWS SES) daripada mail bawaan server lokal.</span>
                            </div>
                            <label class="veyra-switch">
                                <input type="checkbox" name="use_smtp" value="1" <?= $mailConfig['use_smtp'] ? 'checked' : '' ?>>
                                <span class="veyra-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Email Pengirim (Sender Address)</label>
                        <input type="email" name="mail_from" class="settings-input" value="<?= htmlspecialchars($mailConfig['mail_from']) ?>" required>
                        <span class="settings-field-hint">Alamat email yang tampil di bagian "From" penerima (misal: noreply@veyracbt.com).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Nama Pengirim (Sender Name)</label>
                        <input type="text" name="mail_from_name" class="settings-input" value="<?= htmlspecialchars($mailConfig['mail_from_name']) ?>" required>
                        <span class="settings-field-hint">Nama instansi pengirim (misal: Veyra CBT Notification).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Host Server SMTP</label>
                        <input type="text" name="smtp_host" class="settings-input" value="<?= htmlspecialchars($mailConfig['smtp_host']) ?>" placeholder="smtp.gmail.com">
                        <span class="settings-field-hint">Alamat hostname server mail pengirim.</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Port SMTP</label>
                        <input type="number" name="smtp_port" class="settings-input" value="<?= (int)$mailConfig['smtp_port'] ?>" placeholder="587">
                        <span class="settings-field-hint">Port pengiriman (Port 587 untuk TLS/STARTTLS, 465 untuk SSL).</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Username / Akun SMTP</label>
                        <input type="text" name="smtp_user" class="settings-input" value="<?= htmlspecialchars($mailConfig['smtp_user']) ?>" placeholder="user@gmail.com">
                        <span class="settings-field-hint">Alamat login atau API key akun email.</span>
                    </div>

                    <div class="settings-field">
                        <label class="settings-field-label">Password / App Password SMTP</label>
                        <input type="password" name="smtp_pass" class="settings-input" placeholder="Biarkan kosong jika tidak diubah">
                        <span class="settings-field-hint"><?= !empty($mailConfig['smtp_pass']) ? 'Tersimpan (disamarkan demi keamanan).' : 'Belum diatur.' ?></span>
                    </div>
                </div>

                <div class="settings-action-bar">
                    <button type="submit" class="btn-save-settings">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Simpan Konfigurasi Email</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         TAB 5: PEMELIHARAAN LOG & DATABASE
         ======================================================== -->
    <div class="settings-panel" id="panel-maintenance">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    Pembersihan Log Audit &amp; Backup Database
                </h2>
                <div class="settings-section-sub">Pemeliharaan storage server, penghapusan audit trail usang, serta pembuatan salinan cadangan SQL database.</div>
            </div>

            <!-- Bagian 1: Pembersihan Log -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 18px; padding: 24px; margin-bottom: 28px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Pembersihan Log Aktivitas Lama (Audit Trail Cleanup)</h3>
                        <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Saat ini tersimpan <strong><?= number_format($totalLogsCount) ?> entri log</strong> di dalam database platform.</p>
                    </div>
                    <span style="font-size: 12px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 6px 14px; border-radius: 9999px;">
                        Status: Database Sehat
                    </span>
                </div>

                <form method="POST">
                    <?= Helper::csrfField() ?>
                    <input type="hidden" name="action" value="cleanup_logs">
                    <input type="hidden" name="active_tab" value="maintenance">

                    <label class="settings-field-label" style="margin-bottom: 10px;">Pilih Periode Retensi Log yang Ingin Dihapus:</label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
                        <?php foreach ([30, 60, 90, 180] as $rDays): ?>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 18px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; font-size: 12.5px; font-weight: 700; color: #334155;">
                                <input type="radio" name="retention_days" value="<?= $rDays ?>" <?= $rDays === 90 ? 'checked' : '' ?>>
                                <span>Lebih dari <?= $rDays ?> Hari</span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-danger" style="border-radius: 12px; padding: 10px 22px; font-weight: 700;" onclick="return confirm('PERINGATAN: Yakin ingin menghapus seluruh log lama sesuai periode terpilih? Data log audit yang dihapus tidak dapat dipulihkan kembali.')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" style="vertical-align: -2px; margin-right: 4px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        Bersihkan Log Sekarang
                    </button>
                </form>
            </div>

            <!-- Bagian 2: Backup Database SQL -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1px solid #bbf7d0; border-radius: 18px; padding: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #166534; margin: 0;">Cadangan Database Sistem (One-Click SQL Dump)</h3>
                        <p style="font-size: 12.5px; color: #15803d; margin-top: 4px;">Unduh seluruh skema, data sekolah mitra, butir soal, dan hasil ujian dalam bentuk file <code>.sql</code> terenkripsi standar.</p>
                    </div>

                    <a href="backup.php" class="btn" style="background: #16a34a; color: #ffffff; border-radius: 12px; padding: 11px 22px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);" onclick="return confirm('Unduh file cadangan database Veyra CBT (.sql) sekarang?')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>Unduh File Backup (.sql)</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         TAB 6: INFORMASI SERVER & SPESIFIKASI SISTEM
         ======================================================== -->
    <div class="settings-panel" id="panel-server">
        <div class="settings-card">
            <div class="settings-card-header">
                <h2 class="settings-section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.3"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
                    Spesifikasi Lingkungan Server &amp; Kesehatan Sistem
                </h2>
                <div class="settings-section-sub">Informasi teknis runtime PHP, database MySQL/MariaDB, dan utilisasi kapasitas platform Veyra CBT saat ini.</div>
            </div>

            <div class="server-stats-grid">
                <div class="server-stat-card">
                    <span class="server-stat-label">Versi PHP</span>
                    <span class="server-stat-value"><?= phpversion() ?></span>
                    <span class="server-stat-sub">Sesuai standar (PHP &gt;= 8.1)</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Database Server</span>
                    <span class="server-stat-value"><?= htmlspecialchars(explode('-', $dbVersion)[0] ?? $dbVersion) ?></span>
                    <span class="server-stat-sub">MySQL / MariaDB Engine</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Ukuran Database</span>
                    <span class="server-stat-value"><?= $dbSize ?></span>
                    <span class="server-stat-sub">Data + Index Tabel</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Batas Memori PHP</span>
                    <span class="server-stat-value"><?= ini_get('memory_limit') ?></span>
                    <span class="server-stat-sub">Kapasitas RAM worker</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Maksimal Upload File</span>
                    <span class="server-stat-value"><?= ini_get('upload_max_filesize') ?></span>
                    <span class="server-stat-sub">Upload logo &amp; gambar soal</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Maksimal POST Size</span>
                    <span class="server-stat-value"><?= ini_get('post_max_size') ?></span>
                    <span class="server-stat-sub">Batas impor Excel butir soal</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Max Execution Time</span>
                    <span class="server-stat-value"><?= ini_get('max_execution_time') ?> Detik</span>
                    <span class="server-stat-sub">Waktu proses ekspor/backup</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Zona Waktu Server</span>
                    <span class="server-stat-value"><?= date_default_timezone_get() ?></span>
                    <span class="server-stat-sub"><?= date('d M Y - H:i:s') ?></span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Total Sekolah Mitra</span>
                    <span class="server-stat-value"><?= number_format($totalTenantsCount) ?> Mitra</span>
                    <span class="server-stat-sub">Tenant terdaftar di sistem</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Total Siswa Terdaftar</span>
                    <span class="server-stat-value"><?= number_format($totalStudentsCount) ?> Siswa</span>
                    <span class="server-stat-sub">Akun ujian di seluruh sekolah</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Bank Soal Terverifikasi</span>
                    <span class="server-stat-value"><?= number_format($totalBanksCount) ?> Modul</span>
                    <span class="server-stat-sub">Bank materi &amp; butir soal</span>
                </div>

                <div class="server-stat-card">
                    <span class="server-stat-label">Sistem Operasi Host</span>
                    <span class="server-stat-value"><?= PHP_OS_FAMILY ?></span>
                    <span class="server-stat-sub"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Web Server') ?></span>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Switcher Tab Navigasi Pengaturan
function switchSettingTab(tabName) {
    // 1. Update tombol tab aktif
    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        if (btn.getAttribute('data-tab') === tabName) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    // 2. Tampilkan panel konten yang dipilih
    document.querySelectorAll('.settings-panel').forEach(function(panel) {
        panel.classList.remove('active');
    });
    var targetPanel = document.getElementById('panel-' + tabName);
    if (targetPanel) {
        targetPanel.classList.add('active');
    }

    // 3. Simpan hash di URL agar refresh tetap di tab yang sama
    if (history.replaceState) {
        history.replaceState(null, null, '#' + tabName);
    }
}

// Deteksi hash URL saat halaman dimuat
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash.replace('#', '');
    if (hash && ['general', 'security', 'proctoring', 'mail', 'maintenance', 'server'].includes(hash)) {
        switchSettingTab(hash);
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
