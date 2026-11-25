<?php
/**
 * Modern Fan Analysis Dashboard & CBT Management Hub
 * Multi-Tenant CBT Online
 */

require_once __DIR__ . '/../core/Auth.php';
$pageTitle = (Auth::role() === 'teacher') ? "Dashboard Guru" : "Dashboard Admin";
require_once __DIR__ . '/header.php';

$tenantId = (int)$school['id'];
$studentQuota = (int)($school['student_quota'] ?? 0);

// 1. Ambil statistik global tenant
$totalStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ?", [$tenantId]);
$activeStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ? AND is_active = 1", [$tenantId]);
$totalQuestions = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM questions q INNER JOIN question_banks qb ON q.bank_id = qb.id WHERE qb.tenant_id = ?",
    [$tenantId]
);
$totalBanks = (int)Database::fetchColumn("SELECT COUNT(*) FROM question_banks WHERE tenant_id = ?", [$tenantId]);
$totalSubjects = (int)Database::fetchColumn("SELECT COUNT(*) FROM subjects WHERE tenant_id = ?", [$tenantId]);
$totalClasses = (int)Database::fetchColumn("SELECT COUNT(*) FROM classes WHERE tenant_id = ?", [$tenantId]);
$totalExams = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ?", [$tenantId]);

// Status Onboarding Persiapan Ujian (4 Langkah)
$step1Done = ($totalClasses > 0 && $totalSubjects > 0);
$step2Done = ($totalStudents > 0);
$step3Done = ($totalBanks > 0 && $totalQuestions > 0);
$step4Done = ($totalExams > 0);
$onboardingCompleted = ($step1Done ? 1 : 0) + ($step2Done ? 1 : 0) + ($step3Done ? 1 : 0) + ($step4Done ? 1 : 0);
$activeExamsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ? AND is_active = 1 AND start_time <= NOW() AND end_time >= NOW()", [$tenantId]);
$completedSessions = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed'", [$tenantId]);
$liveSessions = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'in_progress'", [$tenantId]);
$blockedSessions = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'blocked'", [$tenantId]);

$avgScoreVal = Database::fetchColumn("SELECT ROUND(AVG(score), 1) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed'", [$tenantId]);
$avgTenantScore = ($avgScoreVal !== null && $avgScoreVal !== '') ? min(100, (float)$avgScoreVal) : 0.0;

// 2. Data Donut Chart (Distribusi Kelulusan)
$cntHigh = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score >= 80", [$tenantId]);
$cntMed  = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score >= 60 AND score < 80", [$tenantId]);
$cntLow  = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score < 60", [$tenantId]);
$totalScored = $cntHigh + $cntMed + $cntLow;

if ($totalScored > 0) {
    $pctHigh = round(($cntHigh / $totalScored) * 100, 1);
    $pctMed  = round(($cntMed / $totalScored) * 100, 1);
    $pctLow  = max(0, round(100 - $pctHigh - $pctMed, 1));
    $donutGradient = "conic-gradient(#10b981 0% {$pctHigh}%, #f59e0b {$pctHigh}% " . ($pctHigh + $pctMed) . "%, #ef4444 " . ($pctHigh + $pctMed) . "% 100%)";
} else {
    $pctHigh = 0;
    $pctMed  = 0;
    $pctLow  = 0;
    $donutGradient = "conic-gradient(#e2e8f0 0% 100%)";
}

// 3. Data 7 Hari Terakhir (Aktivitas Ujian Bar Chart)
$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-$i days"));
    $labelKey = date('d/m', strtotime("-$i days"));
    $chartDays[$dateKey] = [
        'label' => $labelKey,
        'total' => 0,
        'completed' => 0
    ];
}

$historyRaw = Database::fetchAll(
    "SELECT DATE(start_time) as session_date,
            COUNT(*) as total_num,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as comp_num
     FROM exam_sessions
     WHERE tenant_id = ? AND start_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(start_time)",
    [$tenantId]
);

$maxDayTotal = 0;
foreach ($historyRaw as $hr) {
    if (isset($chartDays[$hr['session_date']])) {
        $tot = (int)$hr['total_num'];
        $comp = (int)$hr['comp_num'];
        $chartDays[$hr['session_date']]['total'] = $tot;
        $chartDays[$hr['session_date']]['completed'] = $comp;
        if ($tot > $maxDayTotal) $maxDayTotal = $tot;
    }
}

// 4. Daftar Ujian (Overview of Works)
$exams = Database::fetchAll(
    "SELECT e.*, qb.title as bank_title, s.name as subject_name, c.name as class_name,
            (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id AND status = 'in_progress') as in_progress_count,
            (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id AND status = 'completed') as completed_count,
            (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id AND status = 'blocked') as blocked_count,
            (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id) as total_sessions,
            (SELECT COUNT(*) FROM students st WHERE st.tenant_id = e.tenant_id AND st.is_active = 1 AND (e.class_id IS NULL OR st.class_id = e.class_id)) as total_eligible,
            (SELECT ROUND(AVG(score), 1) FROM exam_sessions WHERE exam_id = e.id AND status = 'completed') as avg_score
     FROM exams e
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     LEFT JOIN classes c ON e.class_id = c.id
     WHERE e.tenant_id = ?
     ORDER BY e.id DESC
     LIMIT 10",
    [$tenantId]
);

// ── Smart Insights ───────────────────────────────────────
$_lastWeekStart = date('Y-m-d', strtotime('-7 days'));
$_thisWeekStart = date('Y-m-d');
$examsThisWeek  = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams WHERE tenant_id=? AND start_time>=?", [$tenantId, $_thisWeekStart]
);
$examsLastWeek  = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams WHERE tenant_id=? AND start_time>=? AND start_time<?",
    [$tenantId, $_lastWeekStart, $_thisWeekStart]
);
$trendPct = $examsLastWeek > 0 ? round((($examsThisWeek - $examsLastWeek) / max(1,$examsLastWeek)) * 100) : 0;
$trendDir = $trendPct >= 0 ? 'up' : 'down';

// Siswa berisiko: completed sessions dengan score < 60 dalam 14 hari
$atRiskStudents = (int)Database::fetchColumn(
    "SELECT COUNT(DISTINCT es.student_id) FROM exam_sessions es
     JOIN students st ON es.student_id = st.id
     WHERE es.tenant_id=? AND es.status='completed' AND es.score<60
       AND es.finish_time>=DATE_SUB(CURDATE(),INTERVAL 14 DAY)",
    [$tenantId]
);

// Soal tersulit: akurasi terendah
$hardestQs = Database::fetchAll(
    "SELECT q.id, LEFT(q.question_text,40) AS excerpt,
            SUM(CASE WHEN ea.is_correct=1 THEN 1 ELSE 0 END) AS correct_cnt,
            COUNT(*) AS total_cnt,
            ROUND(SUM(CASE WHEN ea.is_correct=1 THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)*100,1) AS pct
     FROM exam_answers ea JOIN questions q ON ea.question_id=q.id
     JOIN exam_sessions es ON ea.session_id=es.id
     WHERE es.tenant_id=? AND ea.student_answer IS NOT NULL
     GROUP BY q.id ORDER BY pct ASC LIMIT 5",
    [$tenantId]
);
?>

<style>
    /* Adaptive Light & Dark Mode Styles for Dashboard CBT */
    .fan-live-banner {
        background: #ffffff;
        border: 1px solid #eef2f7;
        border-radius: 18px;
        padding: 14px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        margin-bottom: 22px;
    }
    .fan-live-banner-text {
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }
    .fan-onboarding-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 20px 22px;
        margin-bottom: 24px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .fan-onboarding-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 12px;
        margin-top: 14px;
    }
    .fan-onboarding-step {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .fan-onboarding-step:hover {
        border-color: #10b981;
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(16, 185, 129, 0.08);
    }
    .fan-onboarding-step.done {
        background: #f0fdf4;
        border-color: #bbf7d0;
    }
    .fan-onboarding-title {
        font-size: 12.5px;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 3px;
    }
    .fan-onboarding-desc {
        font-size: 11px;
        color: #64748b;
        line-height: 1.35;
    }
    .fan-range-pill {
        color: #64748b;
        font-size: 11.5px;
        font-weight: 700;
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 9999px;
    }
    .fan-donut-hole {
        width: 112px;
        height: 112px;
        border-radius: 50%;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.04);
        transition: background 0.25s ease;
    }
    .fan-donut-legend-lbl {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        margin-bottom: 3px;
    }
    .fan-exam-title-link {
        font-weight: 700;
        color: #0f172a;
        text-decoration: none;
        display: inline-block;
        transition: color 0.15s ease;
    }
    .fan-exam-title-link:hover {
        color: #059669;
    }
    .fan-text-primary {
        color: #1e293b;
    }
    .fan-progress-track {
        width: 100%;
        height: 6px;
        background: #f1f5f9;
        border-radius: 9999px;
        overflow: hidden;
    }
    .fan-token-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: monospace;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.06em;
        padding: 5px 10px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: all 0.18s ease;
    }
    .fan-token-btn:hover {
        background: #ecfdf5;
        color: #059669;
        border-color: #a7f3d0;
    }
    .fan-token-btn.copied {
        background: #10b981;
        color: #ffffff;
        border-color: #10b981;
    }

    /* ── SMART INSIGHTS ─────────────────────────────── */
    .insight-alerts { display: flex; gap: 12px; margin-bottom: 22px; flex-wrap: wrap; }
    .insight-card {
        flex: 1; min-width: 200px; border-radius: 14px; padding: 14px 16px;
        border: 1px solid transparent; position: relative; overflow: hidden;
    }
    .insight-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    }
    .insight-card.warning { background: #fffbeb; border-color: #fde68a; }
    .insight-card.warning::before { background: #f59e0b; }
    .insight-card.success { background: #ecfdf5; border-color: #a7f3d0; }
    .insight-card.success::before { background: #10b981; }
    .insight-card.info    { background: #eff6ff; border-color: #bfdbfe; }
    .insight-card.info::before    { background: #3b82f6; }
    .insight-icon { font-size: 20px; margin-bottom: 6px; }
    .insight-val  { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1; }
    .insight-lbl  { font-size: 11px; color: #64748b; font-weight: 600; margin-top: 3px; }
    .trend-arrow  { font-size: 11px; font-weight: 700; margin-left: 4px; }
    .trend-up     { color: #059669; }
    .trend-down   { color: #dc2626; }

    .hard-q-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
    .hard-q-table th { text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; border-bottom: 2px solid #f1f5f9; font-weight: 700; }
    .hard-q-table td { padding: 7px 8px; border-bottom: 1px solid #f8fafc; color: #334155; }
    .hard-q-table tr:last-child td { border-bottom: none; }
    .pct-bar { height: 5px; border-radius: 999px; background: #e2e8f0; overflow: hidden; width: 60px; display: inline-block; vertical-align: middle; margin-right: 6px; }
    .pct-fill { height: 100%; border-radius: 999px; }

    /* FAB */
    .quick-fab {
        position: fixed; bottom: 28px; right: 28px; z-index: 500;
        width: 52px; height: 52px; border-radius: 16px; border: none;
        background: linear-gradient(135deg, #059669, #10b981); color: #fff;
        box-shadow: 0 6px 20px rgba(5,150,105,.4); cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: transform .2s, box-shadow .2s;
    }
    .quick-fab:hover { transform: scale(1.08); box-shadow: 0 8px 28px rgba(5,150,105,.5); }
    .quick-fab svg { width: 22px; height: 22px; }
    .fab-menu {
        position: fixed; bottom: 90px; right: 28px; z-index: 500;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
        box-shadow: 0 8px 30px rgba(0,0,0,.12); padding: 8px; display: none;
        min-width: 180px;
    }
    .fab-menu.show { display: block; animation: fabPop .2s ease; }
    @keyframes fabPop { from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)} }
    .fab-item {
        display: flex; align-items: center; gap: 10px; padding: 10px 12px;
        border-radius: 10px; font-size: 13px; font-weight: 600; color: #334155;
        text-decoration: none; transition: background .15s;
    }
    .fab-item:hover { background: #f0fdf4; color: #059669; }
    .fab-item svg { width: 16px; height: 16px; stroke: currentColor; }
    html[data-theme="dark"] .fab-menu { background: #1e293b; border-color: #334155; }
    html[data-theme="dark"] .fab-item:hover { background: #334155; color: #34d399; }
    html[data-theme="dark"] .insight-card.warning { background: rgba(245,158,11,.1); }
    html[data-theme="dark"] .insight-card.success { background: rgba(16,185,129,.1); }
    html[data-theme="dark"] .insight-card.info    { background: rgba(59,130,246,.1); }
    html[data-theme="dark"] .hard-q-table th { border-bottom-color: #334155; color: #94a3b8; }
    html[data-theme="dark"] .hard-q-table td  { border-bottom-color: #334155; color: #e2e8f0; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .fan-live-banner {
        background: #1e293b;
        border-color: #334155;
    }
    [data-theme="dark"] .fan-live-banner-text,
    [data-theme="dark"] .fan-onboarding-title,
    [data-theme="dark"] .fan-exam-title-link,
    [data-theme="dark"] .fan-text-primary {
        color: #f1f5f9;
    }
    [data-theme="dark"] .fan-onboarding-card {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-color: #334155;
    }
    [data-theme="dark"] .fan-onboarding-step {
        background: #0f172a;
        border-color: #334155;
    }
    [data-theme="dark"] .fan-onboarding-step.done {
        background: rgba(16, 185, 129, 0.1);
        border-color: rgba(16, 185, 129, 0.35);
    }
    [data-theme="dark"] .fan-onboarding-desc {
        color: #94a3b8;
    }
    [data-theme="dark"] .fan-range-pill {
        background: #0f172a;
        color: #94a3b8;
        border: 1px solid #334155;
    }
    [data-theme="dark"] .fan-donut-hole {
        background: #1e293b;
        box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.35);
    }
    [data-theme="dark"] .fan-donut-legend-lbl {
        color: #cbd5e1;
    }
    [data-theme="dark"] .fan-progress-track {
        background: #334155;
    }
    [data-theme="dark"] .fan-token-btn {
        background: #0f172a;
        color: #34d399;
        border-color: #334155;
    }
    [data-theme="dark"] .fan-token-btn:hover {
        background: rgba(16, 185, 129, 0.15);
        border-color: #10b981;
    }

    /* ── Android & Mobile Responsive Enhancements ── */
    @media (max-width: 412px) {
        .fan-kpi-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
        }
        .fan-kpi-card { padding: 14px 12px !important; }
        .fan-kpi-info-val { font-size: 20px !important; }
        .fan-kpi-info-label { font-size: 10.5px !important; }
        .fan-donut-center-wrap { justify-content: center !important; }
        #donut-conic-circle { width: 120px !important; height: 120px !important; }
        .fan-donut-stat-col { flex: 1; min-width: 0; }
        .fan-donut-stat-pct { font-size: 13px !important; }
        .fan-donut-stat-sub { font-size: 10px !important; }
        .fan-bar-chart { overflow-x: auto; }
        .fan-table-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .fan-time-tabs { display: none; }
        .fan-overall-header { flex-direction: column; align-items: flex-start; gap: 8px; }
        table.fan-table { font-size: 12px; }
        table.fan-table th, table.fan-table td { padding: 8px 6px !important; white-space: nowrap; }
        .fan-progress-track { height: 4px !important; }
    }

    @media (max-width: 375px) {
        .fan-kpi-grid { grid-template-columns: 1fr !important; }
        .fan-kpi-card { flex-direction: column; align-items: flex-start !important; gap: 8px !important; }
        .fan-kpi-icon-pill { position: static !important; transform: none !important; }
        .fan-charts-grid { flex-direction: column !important; }
        .fan-chart-card, .fan-donut-card { width: 100% !important; }
        #donut-conic-circle { width: 100px !important; height: 100px !important; }
        .fan-donut-hole { width: 72px !important; height: 72px !important; }
        .fan-donut-big-val { font-size: 18px !important; }
        .fan-section-heading { font-size: 14px !important; }
        .onboarding-step-text { font-size: 11px !important; }
    }
</style>

<!-- LIVE ALERT IF SESSIONS IN PROGRESS OR BLOCKED -->
<?php if ($liveSessions > 0 || $blockedSessions > 0): ?>
    <div class="fan-live-banner">
        <div style="display: flex; align-items: center; gap: 12px;">
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #2ed573; box-shadow: 0 0 0 4px rgba(46,213,115,0.2);"></span>
            <div class="fan-live-banner-text">
                Live Proctoring: <b><?= $liveSessions ?> siswa</b> sedang aktif mengerjakan ujian.
                <?php if ($blockedSessions > 0): ?>
                    <span style="color: #ef4444; margin-left: 6px;">(&#9888; <?= $blockedSessions ?> siswa terblokir)</span>
                <?php endif; ?>
            </div>
        </div>
        <a href="monitor.php" class="btn btn-sm btn-primary" style="border-radius: 9999px; padding: 6px 16px; font-size: 11.5px; font-weight: 700; text-decoration: none;">
            Buka Live Monitor &rarr;
        </a>
    </div>
<?php endif; ?>

<!-- SMART INSIGHTS -->
<div class="insight-alerts">
    <?php if ($atRiskStudents > 0): ?>
    <div class="insight-card warning">
        <div class="insight-icon">⚠️</div>
        <div class="insight-val"><?= $atRiskStudents ?></div>
        <div class="insight-lbl">Siswa Berisiko Gagal (skor &lt;60)</div>
    </div>
    <?php endif; ?>
    <div class="insight-card success">
        <div class="insight-icon">📈</div>
        <div class="insight-val">
            <?= $examsThisWeek ?>
            <?php if ($examsLastWeek > 0): ?>
                <span class="trend-arrow trend-<?= $trendDir ?>">
                    <?= $trendDir === 'up' ? '↑' : '↓' ?> <?= abs($trendPct) ?>%
                </span>
            <?php endif; ?>
        </div>
        <div class="insight-lbl">Ujian Minggu Ini</div>
    </div>
    <?php if (!empty($hardestQs)): ?>
    <div class="insight-card info" style="flex:2;min-width:300px;">
        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">🎯 Soal Tersulit</div>
        <table class="hard-q-table">
            <thead><tr><th>Soal</th><th>Benar</th><th>Akurasi</th></tr></thead>
            <tbody>
            <?php foreach ($hardestQs as $hq):
                $pct = (float)$hq['pct'];
                $barColor = $pct >= 60 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
            ?>
                <tr>
                    <td title="<?= htmlspecialchars($hq['excerpt']) ?>…"><?= htmlspecialchars(mb_substr($hq['excerpt'],0,28)) ?>…</td>
                    <td><?= $hq['correct_cnt'] ?>/<?= $hq['total_cnt'] ?></td>
                    <td>
                        <div class="pct-bar"><div class="pct-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div>
                        <span style="font-weight:700;color:<?= $barColor ?>"><?= $pct ?>%</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- QUICK ACTION FAB -->
<button class="quick-fab" id="quickFabBtn" title="Aksi Cepat" onclick="document.getElementById('quickFabMenu').classList.toggle('show')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
</button>
<div class="fab-menu" id="quickFabMenu">
    <a href="monitor.php" class="fab-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Live Monitor
    </a>
    <a href="results.php" class="fab-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Rekap Nilai
    </a>
    <a href="student-cards.php" class="fab-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="13" y2="12"/></svg>
        Cetak Kartu
    </a>
    <a href="settings.php" class="fab-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Pengaturan
    </a>
</div>
<script>
document.addEventListener('click', function(e) {
    var fab = document.getElementById('quickFabMenu');
    var btn = document.getElementById('quickFabBtn');
    if (fab && btn && !fab.contains(e.target) && !btn.contains(e.target)) fab.classList.remove('show');
});
</script>


<?php if ($onboardingCompleted < 4): ?>
    <div class="fan-onboarding-card" id="cbtOnboardingCard">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 10px; background: rgba(16, 185, 129, 0.14); color: #059669; font-weight: 800; font-size: 13px;">
                    <?= $onboardingCompleted ?>/4
                </span>
                <div>
                    <div class="fan-section-heading" style="font-size: 14.5px;">Panduan Cepat Persiapan Ujian Sekolah</div>
                    <div style="font-size: 12px; color: #64748b;">Selesaikan 4 langkah berikut agar ruang ujian CBT sekolah Anda siap digunakan siswa.</div>
                </div>
            </div>
            <span style="font-size: 11.5px; font-weight: 800; color: #059669; background: rgba(16, 185, 129, 0.12); padding: 5px 12px; border-radius: 9999px;">
                <?= round(($onboardingCompleted / 4) * 100) ?>% Siap
            </span>
        </div>

        <div class="fan-onboarding-grid">
            <!-- Step 1: Kelas & Mapel -->
            <a href="<?= $totalClasses === 0 ? 'classes.php' : 'subjects.php' ?>" class="fan-onboarding-step <?= $step1Done ? 'done' : '' ?>">
                <div>
                    <div class="fan-onboarding-title">1. Kelas &amp; Mata Pelajaran</div>
                    <div class="fan-onboarding-desc">
                        <?= $totalClasses ?> kelas &bull; <?= $totalSubjects ?> mapel terdaftar
                    </div>
                </div>
                <span style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; background: <?= $step1Done ? '#10b981' : '#e2e8f0' ?>; color: <?= $step1Done ? '#fff' : '#475569' ?>;">
                    <?= $step1Done ? '&#10003;' : '1' ?>
                </span>
            </a>

            <!-- Step 2: Peserta Didik -->
            <a href="students.php" class="fan-onboarding-step <?= $step2Done ? 'done' : '' ?>">
                <div>
                    <div class="fan-onboarding-title">2. Data Peserta Didik</div>
                    <div class="fan-onboarding-desc">
                        <?= $step2Done ? $totalStudents . ' siswa siap ujian' : 'Tambah / import data siswa' ?>
                    </div>
                </div>
                <span style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; background: <?= $step2Done ? '#10b981' : '#e2e8f0' ?>; color: <?= $step2Done ? '#fff' : '#475569' ?>;">
                    <?= $step2Done ? '&#10003;' : '2' ?>
                </span>
            </a>

            <!-- Step 3: Bank Soal -->
            <a href="question-banks.php" class="fan-onboarding-step <?= $step3Done ? 'done' : '' ?>">
                <div>
                    <div class="fan-onboarding-title">3. Bank &amp; Butir Soal</div>
                    <div class="fan-onboarding-desc">
                        <?= $step3Done ? $totalBanks . ' bank (' . $totalQuestions . ' soal)' : 'Buat bank soal & input pertanyaan' ?>
                    </div>
                </div>
                <span style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; background: <?= $step3Done ? '#10b981' : '#e2e8f0' ?>; color: <?= $step3Done ? '#fff' : '#475569' ?>;">
                    <?= $step3Done ? '&#10003;' : '3' ?>
                </span>
            </a>

            <!-- Step 4: Jadwal Ujian -->
            <a href="exams.php" class="fan-onboarding-step <?= $step4Done ? 'done' : '' ?>">
                <div>
                    <div class="fan-onboarding-title">4. Jadwal Ujian &amp; Token</div>
                    <div class="fan-onboarding-desc">
                        <?= $step4Done ? $totalExams . ' jadwal ujian dibuat' : 'Aktifkan jadwal & bagikan token' ?>
                    </div>
                </div>
                <span style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; background: <?= $step4Done ? '#10b981' : '#e2e8f0' ?>; color: <?= $step4Done ? '#fff' : '#475569' ?>;">
                    <?= $step4Done ? '&#10003;' : '4' ?>
                </span>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- SECTION 1: OVERALL DATA (4 MODERN KPI CARDS) -->
<div class="fan-overall-header">
    <div>
        <div class="fan-section-heading">Ringkasan Operasional Sekolah</div>
        <div style="font-size: 12.5px; color: #64748b; margin-top: 2px;">Pantau statistik peserta, kesiapan bank soal, dan progres pelaksanaan ujian secara real-time.</div>
    </div>
    <div class="fan-time-tabs">
        <button type="button" class="fan-time-tab" data-range="today">Hari Ini</button>
        <button type="button" class="fan-time-tab" data-range="7d">7 Hari Terakhir</button>
        <button type="button" class="fan-time-tab" data-range="30d">30 Hari</button>
        <button type="button" class="fan-time-tab" data-range="90d">90 Hari</button>
        <button type="button" class="fan-time-tab active" data-range="all">Semua Data</button>
    </div>
</div>

<div class="fan-kpi-grid">
    <!-- 1. Data Siswa -->
    <a href="students.php" class="fan-kpi-card fan-kpi-green" style="text-decoration: none;">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Peserta Didik Terdaftar</div>
                <div class="fan-kpi-info-val" id="kpi-students"><?= number_format($totalStudents, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
        </div>
        <div class="fan-kpi-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;"></span>
            <span><b><?= number_format($activeStudents, 0, ',', '.') ?> aktif</b><?= $studentQuota > 0 ? ' dari kuota ' . number_format($studentQuota, 0, ',', '.') : '' ?></span>
        </div>
    </a>

    <!-- 2. Bank Soal -->
    <a href="question-banks.php" class="fan-kpi-card fan-kpi-purple" style="text-decoration: none;">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Butir Soal Tersimpan</div>
                <div class="fan-kpi-info-val" id="kpi-questions"><?= number_format($totalQuestions, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            </div>
        </div>
        <div class="fan-kpi-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#6366f1;"></span>
            <span><b><?= number_format($totalBanks, 0, ',', '.') ?> bank soal</b> &bull; <?= number_format($totalSubjects, 0, ',', '.') ?> mapel</span>
        </div>
    </a>

    <!-- 3. Jadwal Ujian -->
    <a href="exams.php" class="fan-kpi-card fan-kpi-pink" style="text-decoration: none;">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Jadwal Ujian CBT</div>
                <div class="fan-kpi-info-val" id="kpi-exams"><?= number_format($totalExams, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
        </div>
        <div class="fan-kpi-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= $activeExamsCount > 0 ? '#10b981' : '#0284c7' ?>;"></span>
            <span><b><?= number_format($activeExamsCount, 0, ',', '.') ?> ujian</b> sedang berlangsung</span>
        </div>
    </a>

    <!-- 4. Sesi Selesai -->
    <a href="results.php" class="fan-kpi-card fan-kpi-orange" style="text-decoration: none;">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Sesi Ujian Selesai</div>
                <div class="fan-kpi-info-val" id="kpi-completed"><?= number_format($completedSessions, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;"></span>
            <span>Rata-rata nilai: <b><?= $totalScored > 0 ? $avgTenantScore : '0' ?></b></span>
        </div>
    </a>
</div>

<!-- SECTION 2: CHARTS (AKTIVITAS UJIAN DUAL BAR + DISTRIBUSI NILAI DONUT) -->
<div class="fan-charts-grid">
    <!-- Left: Aktivitas Ujian Bar Chart -->
    <div class="fan-chart-card">
        <div class="fan-chart-header">
            <div>
                <div class="fan-section-heading">Tren Aktivitas Sesi Ujian</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Perbandingan jumlah sesi dimulai dan diselesaikan per periode</div>
            </div>
            <div class="fan-chart-legend">
                <div><span class="fan-legend-dot" style="background: #10b981;"></span> Sesi Dimulai</div>
                <div><span class="fan-legend-dot" style="background: #0284c7;"></span> Sesi Selesai</div>
                <div id="bar-chart-range-label" class="fan-range-pill">
                    7 Hari Terakhir
                </div>
            </div>
        </div>

        <!-- Dynamic Dual Bar Chart Container -->
        <div class="fan-bar-chart" id="fan-bar-chart-container" style="transition: opacity 0.2s ease;">
            <?php 
            $scaleMax = max(1, $maxDayTotal);
            foreach ($chartDays as $dateKey => $cd): 
                $hOrange = $cd['total'] > 0 ? max(16, (int)round(($cd['total'] / $scaleMax) * 145)) : 8;
                $hBlue   = $cd['completed'] > 0 ? max(12, (int)round(($cd['completed'] / $scaleMax) * 145)) : 8;
            ?>
                <div class="fan-bar-col">
                    <div class="fan-stacked-bar-wrap">
                        <div class="fan-bar-orange" style="height: <?= $hOrange ?>px; opacity: <?= $cd['total'] > 0 ? '1' : '0.28' ?>;" title="<?= htmlspecialchars($cd['label']) ?> — Sesi Dimulai: <?= $cd['total'] ?>"></div>
                        <div class="fan-bar-blue" style="height: <?= $hBlue ?>px; opacity: <?= $cd['completed'] > 0 ? '1' : '0.28' ?>;" title="<?= htmlspecialchars($cd['label']) ?> — Sesi Selesai: <?= $cd['completed'] ?>"></div>
                    </div>
                    <div class="fan-bar-date"><?= htmlspecialchars($cd['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right: Floating Donut Card Distribusi Nilai -->
    <div class="fan-donut-card">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="fan-section-heading">Distribusi Nilai</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Sebaran capaian nilai peserta</div>
            </div>
            <div id="donut-range-label" class="fan-range-pill">
                Semua Data
            </div>
        </div>

        <!-- Modern Conic Gradient Donut -->
        <div class="fan-donut-center-wrap">
            <div id="donut-conic-circle" style="width: 152px; height: 152px; border-radius: 50%; background: <?= $donutGradient ?>; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); transition: background 0.3s ease;">
                <div class="fan-donut-hole">
                    <div class="fan-donut-big-val" id="donut-avg-val"><?= ($totalScored > 0) ? $avgTenantScore : '0.0' ?></div>
                    <div class="fan-donut-sub-lbl">Rata-rata Nilai</div>
                </div>
            </div>
        </div>

        <!-- 3-Part Legend Breakdown (Nilai >=80, Nilai 60-79, Remedial) -->
        <div class="fan-donut-breakdown">
            <div class="fan-donut-stat-col">
                <div class="fan-donut-legend-lbl">
                    <span style="display: inline-block; width: 8px; height: 8px; background: #10b981; border-radius: 2px;"></span>
                    <span>&ge; 80 (A/B)</span>
                </div>
                <div class="fan-donut-stat-pct" id="donut-high-pct"><?= $pctHigh ?>%</div>
                <div class="fan-donut-stat-sub" id="donut-high-cnt"><?= $cntHigh ?> Siswa</div>
            </div>

            <div class="fan-donut-stat-col">
                <div class="fan-donut-legend-lbl">
                    <span style="display: inline-block; width: 8px; height: 8px; background: #f59e0b; border-radius: 2px;"></span>
                    <span>60–79 (C)</span>
                </div>
                <div class="fan-donut-stat-pct" id="donut-med-pct"><?= $pctMed ?>%</div>
                <div class="fan-donut-stat-sub" id="donut-med-cnt"><?= $cntMed ?> Siswa</div>
            </div>

            <div class="fan-donut-stat-col">
                <div class="fan-donut-legend-lbl">
                    <span style="display: inline-block; width: 8px; height: 8px; background: #ef4444; border-radius: 2px;"></span>
                    <span>&lt; 60 (Remedi)</span>
                </div>
                <div class="fan-donut-stat-pct" id="donut-low-pct"><?= $pctLow ?>%</div>
                <div class="fan-donut-stat-sub" id="donut-low-cnt"><?= $cntLow ?> Siswa</div>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 3: DAFTAR PELAKSANAAN UJIAN (DATA TABLE) -->
<div class="fan-table-card">
    <div class="fan-table-header">
        <div>
            <div class="fan-section-heading">Daftar Pelaksanaan Ujian</div>
            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Jadwal ujian terbaru beserta token akses, tingkat partisipasi, dan status ruang ujian</div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="exams.php" class="btn btn-sm btn-outline" style="border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none; padding: 7px 14px;">
                Kelola Semua Ujian &rarr;
            </a>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="fan-table">
            <thead>
                <tr>
                    <th style="width: 54px;">No</th>
                    <th>Nama Ujian &amp; Mata Pelajaran</th>
                    <th>Token (Klik Salin)</th>
                    <th>Jadwal Pelaksanaan</th>
                    <th>Partisipasi Siswa</th>
                    <th>Status</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exams)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px 16px; color: #94a3b8;">
                            <div style="margin-bottom: 8px;">
                                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <div class="fan-text-primary" style="font-weight: 700; font-size: 14px; margin-bottom: 4px;">Belum Ada Pelaksanaan Ujian</div>
                            <div style="font-size: 12px; margin-bottom: 16px;">Mulai jadwalkan ujian CBT baru untuk siswa sekolah Anda.</div>
                            <a href="exams.php" class="btn btn-sm btn-primary" style="border-radius: 10px; padding: 8px 18px; font-weight: 700; text-decoration: none;">+ Buat Jadwal Ujian</a>
                        </td>
                    </tr>
                <?php else: 
                    $serialNum = 1;
                    $nowTs = time();
                    foreach ($exams as $ex): 
                        $startTs = strtotime($ex['start_time']);
                        $endTs   = strtotime($ex['end_time']);
                        $targetCount = max(1, (int)$ex['total_eligible']);
                        $doneCount   = (int)$ex['completed_count'];
                        $liveCount   = (int)$ex['in_progress_count'];
                        $pctDone     = min(100, round(($doneCount / $targetCount) * 100));

                        if (empty($ex['is_active'])) {
                            $statusLabel = 'Nonaktif';
                            $statusBg = 'rgba(100, 116, 139, 0.14)';
                            $statusColor = '#64748b';
                        } elseif ($nowTs >= $startTs && $nowTs <= $endTs) {
                            $statusLabel = 'Berlangsung';
                            $statusBg = 'rgba(16, 185, 129, 0.15)';
                            $statusColor = '#10b981';
                        } elseif ($nowTs < $startTs) {
                            $statusLabel = 'Terjadwal';
                            $statusBg = 'rgba(37, 99, 235, 0.14)';
                            $statusColor = '#3b82f6';
                        } else {
                            $statusLabel = 'Selesai';
                            $statusBg = 'rgba(71, 85, 105, 0.14)';
                            $statusColor = '#64748b';
                        }
                ?>
                    <tr>
                        <td style="font-weight: 700; color: #64748b;"><?= $serialNum++ ?></td>
                        <td>
                            <a href="monitor.php?exam_id=<?= $ex['id'] ?>" class="fan-exam-title-link">
                                <?= htmlspecialchars($ex['title']) ?>
                            </a>
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">
                                <?= htmlspecialchars($ex['subject_name']) ?> &bull; <?= $ex['class_name'] ? 'Kelas ' . htmlspecialchars($ex['class_name']) : 'Semua Kelas' ?> &bull; <?= (int)$ex['duration_minutes'] ?> menit
                            </div>
                        </td>
                        <td>
                            <button type="button" class="fan-token-btn" onclick="copyExamToken('<?= htmlspecialchars($ex['token'], ENT_QUOTES) ?>', this)" title="Klik untuk menyalin token ujian">
                                <span class="token-val"><?= htmlspecialchars($ex['token']) ?></span>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                            </button>
                        </td>
                        <td>
                            <div class="fan-text-primary" style="font-size: 12.5px; font-weight: 600;">
                                <?= date('d M Y, H:i', $startTs) ?>
                            </div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                                s/d <?= date('d M Y, H:i', $endTs) ?>
                            </div>
                        </td>
                        <td style="min-width: 165px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 11.5px; margin-bottom: 5px;">
                                <span class="fan-text-primary" style="font-weight: 700;"><?= number_format($doneCount) ?> / <?= number_format((int)$ex['total_eligible']) ?> selesai</span>
                                <?php if ($liveCount > 0): ?>
                                    <span style="font-weight: 700; color: #10b981; font-size: 10.5px;"><?= $liveCount ?> aktif</span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 10.5px;"><?= $pctDone ?>%</span>
                                <?php endif; ?>
                            </div>
                            <div class="fan-progress-track">
                                <div style="width: <?= $pctDone ?>%; height: 100%; background: linear-gradient(90deg, #10b981, #059669); border-radius: 9999px;"></div>
                            </div>
                        </td>
                        <td>
                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: <?= $statusBg ?>; color: <?= $statusColor ?>;">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background: <?= $statusColor ?>;"></span>
                                <?= $statusLabel ?>
                            </span>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                <a href="monitor.php?exam_id=<?= $ex['id'] ?>" class="btn btn-sm btn-primary" style="padding: 5px 12px; font-size: 11.5px; border-radius: 8px; text-decoration: none; font-weight: 700;">
                                    Monitor
                                </a>
                                <a href="results.php?exam_id=<?= $ex['id'] ?>" class="btn btn-sm btn-outline" style="padding: 5px 10px; font-size: 11.5px; border-radius: 8px; text-decoration: none; font-weight: 600;">
                                    Hasil
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// 1-Click Copy Token Ujian
function copyExamToken(token, btn) {
    if (!token || !btn) return;
    const doFeedback = function() {
        const origHtml = btn.getAttribute('data-orig-html') || btn.innerHTML;
        if (!btn.getAttribute('data-orig-html')) {
            btn.setAttribute('data-orig-html', origHtml);
        }
        btn.classList.add('copied');
        btn.innerHTML = '<span>&#10003; Tersalin</span>';
        setTimeout(function() {
            btn.classList.remove('copied');
            btn.innerHTML = btn.getAttribute('data-orig-html') || origHtml;
        }, 1600);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(token).then(doFeedback).catch(function() {
            fallbackCopy(token, doFeedback);
        });
    } else {
        fallbackCopy(token, doFeedback);
    }
}

function fallbackCopy(text, callback) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        if (callback) callback();
    } catch (e) {}
    document.body.removeChild(ta);
}

// Dynamic Time tab toggle interactions & stats fetch
document.querySelectorAll('.fan-time-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        const range = tab.getAttribute('data-range') || '7d';
        document.querySelectorAll('.fan-time-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        // Visual loading indicator
        const kpiGrid = document.querySelector('.fan-kpi-grid');
        const chartWrap = document.getElementById('fan-bar-chart-container');
        if (kpiGrid) kpiGrid.style.opacity = '0.6';
        if (chartWrap) chartWrap.style.opacity = '0.6';

        fetch('api-stats.php?range=' + encodeURIComponent(range))
            .then(res => res.json())
            .then(data => {
                if (kpiGrid) kpiGrid.style.opacity = '1';
                if (chartWrap) chartWrap.style.opacity = '1';

                if (!data || !data.success) return;

                // 1. Update KPI numbers
                const elComp = document.getElementById('kpi-completed');
                if (elComp) elComp.textContent = Number(data.kpi.completed).toLocaleString('id-ID');
                
                const elExams = document.getElementById('kpi-exams');
                if (elExams) elExams.textContent = Number(data.kpi.exams).toLocaleString('id-ID');

                const elQuest = document.getElementById('kpi-questions');
                if (elQuest) elQuest.textContent = Number(data.kpi.questions).toLocaleString('id-ID');

                const elStud = document.getElementById('kpi-students');
                if (elStud) elStud.textContent = Number(data.kpi.students).toLocaleString('id-ID');

                // 2. Update Subtitles
                const barLbl = document.getElementById('bar-chart-range-label');
                if (barLbl) barLbl.textContent = data.range_label;

                const donutLbl = document.getElementById('donut-range-label');
                if (donutLbl) donutLbl.textContent = data.range_label;

                // 3. Update Bar Chart Columns
                if (chartWrap && Array.isArray(data.chart)) {
                    chartWrap.innerHTML = data.chart.map(col => `
                        <div class="fan-bar-col">
                            <div class="fan-stacked-bar-wrap">
                                <div class="fan-bar-orange" style="height: ${col.hOrange}px; opacity: ${col.total > 0 ? '1' : '0.28'};" title="${col.label} — Sesi Dimulai: ${col.total}"></div>
                                <div class="fan-bar-blue" style="height: ${col.hBlue}px; opacity: ${col.completed > 0 ? '1' : '0.28'};" title="${col.label} — Sesi Selesai: ${col.completed}"></div>
                            </div>
                            <div class="fan-bar-date">${col.label}</div>
                        </div>
                    `).join('');
                }

                // 4. Update Donut Chart
                const avgEl = document.getElementById('donut-avg-val');
                if (avgEl) avgEl.textContent = data.donut.avg;

                const donutCircle = document.getElementById('donut-conic-circle');
                if (donutCircle) {
                    const pHigh = Number(data.donut.pctHigh) || 0;
                    const pMed = Number(data.donut.pctMed) || 0;
                    const pLow = Number(data.donut.pctLow) || 0;
                    if (pHigh + pMed + pLow > 0) {
                        donutCircle.style.background = `conic-gradient(#10b981 0% ${pHigh}%, #f59e0b ${pHigh}% ${pHigh + pMed}%, #ef4444 ${pHigh + pMed}% 100%)`;
                    } else {
                        donutCircle.style.background = `conic-gradient(#e2e8f0 0% 100%)`;
                    }
                }

                const dHighPct = document.getElementById('donut-high-pct');
                if (dHighPct) dHighPct.textContent = data.donut.pctHigh + '%';
                const dHighCnt = document.getElementById('donut-high-cnt');
                if (dHighCnt) dHighCnt.textContent = data.donut.cntHigh + ' Siswa';

                const dMedPct = document.getElementById('donut-med-pct');
                if (dMedPct) dMedPct.textContent = data.donut.pctMed + '%';
                const dMedCnt = document.getElementById('donut-med-cnt');
                if (dMedCnt) dMedCnt.textContent = data.donut.cntMed + ' Siswa';

                const dLowPct = document.getElementById('donut-low-pct');
                if (dLowPct) dLowPct.textContent = data.donut.pctLow + '%';
                const dLowCnt = document.getElementById('donut-low-cnt');
                if (dLowCnt) dLowCnt.textContent = data.donut.cntLow + ' Siswa';
            })
            .catch(err => {
                if (kpiGrid) kpiGrid.style.opacity = '1';
                if (chartWrap) chartWrap.style.opacity = '1';
                console.error('Gagal mengambil data statistik:', err);
            });
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
