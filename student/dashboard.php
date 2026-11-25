<?php
/**
 * Student Dashboard — Sidebar Layout with Card-Based Modern Design
 */
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Dashboard';
$pageKey   = 'dashboard';
require_once __DIR__ . '/header.php';

// ── Data fetching ──────────────────────────────────────────────
$now = date('Y-m-d H:i:s');

$examSql = "SELECT e.*, qb.title as bank_title, s.name as subject_name,
             es.id as session_id, es.status as session_status, es.score, es.finish_time,
             (SELECT COUNT(*) FROM exam_answers ea JOIN exam_sessions es2 ON ea.session_id = es2.id WHERE es2.exam_id = e.id AND es2.student_id = ? AND (ea.student_answer IS NOT NULL OR ea.answer_text IS NOT NULL)) as answered_questions,
             COALESCE(qb.total_questions, 0) as total_questions
      FROM exams e
      JOIN question_banks qb ON e.bank_id = qb.id
      JOIN subjects s ON qb.subject_id = s.id
      LEFT JOIN exam_sessions es ON (es.exam_id = e.id AND es.student_id = ?)
      WHERE e.tenant_id = ? AND e.is_active = 1";
$examParams = [(int)($student['id'] ?? 0), (int)($student['id'] ?? 0), $tenantId];
if (!empty($student['class_id'])) {
    $examSql .= " AND (e.class_id IS NULL OR e.class_id = ?)";
    $examParams[] = (int)$student['class_id'];
}
$examSql .= " AND e.start_time <= ? AND e.end_time >= ? ORDER BY e.start_time ASC";
$examParams[] = $now;
$examParams[] = $now;
$exams = Database::fetchAll($examSql, $examParams);

$upcomingSql = "SELECT e.*, qb.title as bank_title, s.name as subject_name
      FROM exams e JOIN question_banks qb ON e.bank_id = qb.id
      JOIN subjects s ON qb.subject_id = s.id
      WHERE e.tenant_id = ? AND e.is_active = 1";
$upcomingParams = [$tenantId];
if (!empty($student['class_id'])) {
    $upcomingSql .= " AND (e.class_id IS NULL OR e.class_id = ?)";
    $upcomingParams[] = (int)$student['class_id'];
}
$upcomingSql .= " AND e.start_time > ? ORDER BY e.start_time ASC LIMIT 5";
$upcomingParams[] = $now;
$upcomingExams = Database::fetchAll($upcomingSql, $upcomingParams);

$completedCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions es JOIN exams e ON es.exam_id = e.id
     WHERE es.student_id = ? AND e.tenant_id = ? AND es.status = 'completed'",
    [(int)$student['id'], $tenantId]);

$bestAvg = Database::fetchColumn(
    "SELECT ROUND(AVG(score), 1) FROM exam_sessions WHERE student_id = ? AND status = 'completed' AND tenant_id = ?",
    [(int)$student['id'], $tenantId]);
$bestAvg = ($bestAvg !== null && $bestAvg !== '') ? (float)$bestAvg : 0;

// ── Gamification Data ──────────────────────────────────────
// Learning streak: hitung hari berturut-turut ada aktivitas (exam atau practice)
$_streakDays = (int)Database::fetchColumn(
    "SELECT COUNT(DISTINCT DATE(start_time)) FROM (
        SELECT start_time FROM exam_sessions WHERE student_id=? AND status='completed' AND start_time>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)
        UNION ALL
        SELECT created_at FROM practice_sessions WHERE student_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)
     ) AS a ORDER BY 1",
    [(int)$student['id'], (int)$student['id']]
);
// Simplified streak: 3+ hari aktivitas = streak 3, else hitung unik
$_activeDays = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM (
        SELECT DISTINCT DATE(start_time) AS d FROM exam_sessions WHERE student_id=? AND status='completed' AND start_time>=DATE_SUB(CURDATE(),INTERVAL 14 DAY)
        UNION
        SELECT DISTINCT DATE(created_at) AS d FROM practice_sessions WHERE student_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY)
     ) AS x",
    [(int)$student['id'], (int)$student['id']]
);
$streakCount = min($_activeDays, 7); // cap visual di 7

// Daily goal: target 5 soal/hari dari practice
$_todayStarted = date('Y-m-d');
$practiceToday = (int)Database::fetchColumn(
    "SELECT COUNT(DISTINCT ea.question_id) FROM exam_answers ea
     JOIN practice_sessions ps ON ea.session_id = ps.id
     WHERE ps.student_id=? AND DATE(ea.updated_at)=? AND ea.student_answer IS NOT NULL",
    [(int)$student['id'], $_todayStarted]
);
$dailyGoal = 5;
$goalPct = min(100, round(($practiceToday / $dailyGoal) * 100));

// Total practice completed
$practiceCompleted = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM practice_sessions WHERE student_id=? AND score>0",
    [(int)$student['id']]
);

// Badges (conditional)
$badges = [];
// Badges (conditional) — SVG icons, no emoji
if ($completedCount > 0) $badges[] = ['icon'=>"<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><circle cx=\"12\" cy=\"12\" r=\"6\"/><circle cx=\"12\" cy=\"12\" r=\"2\"/></svg>",'label'=>'Ujian Pertama','cls'=>'first'];
if ($bestAvg >= 90 && $completedCount > 0) $badges[] = ['icon'=>"<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M12 2L8 7H3l5 4-2 7 6-4 6 4-2-7 5-4h-5z\"/></svg>",'label'=>'Skor Sempurna','cls'=>'perfect'];
if ($practiceCompleted > 0) $badges[] = ['icon'=>"<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M4 19.5A2.5 2.5 0 0 1 6.5 17H20\"/><path d=\"M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z\"/></svg>",'label'=>'Practice Master','cls'=>'practice'];
if ($_activeDays >= 3) $badges[] = ['icon'=>"<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.5-2.5 1-3.5.5.5 1 1.5 1.5 2.5z\"/></svg>",'label'=>"{$streakCount} Hari Streak",'cls'=>"streak"];

$inProgress = 0;
foreach ($exams as $ex) {
    if ($ex['session_status'] === 'in_progress') $inProgress++;
}

$jsCountdowns = [];
foreach ($exams as $ex) {
    if ($ex['session_status'] === 'in_progress' && !empty($ex['end_time'])) {
        $jsCountdowns[] = [
            'id' => (int)$ex['id'],
            'title' => addslashes($ex['title']),
            'subject' => addslashes($ex['subject_name']),
            'end' => strtotime($ex['end_time']),
            'answered' => (int)($ex['answered_questions'] ?? 0),
            'total' => (int)($ex['total_questions'] ?? 0),
        ];
    }
}
$jsCountdownsJson = json_encode($jsCountdowns, JSON_UNESCAPED_UNICODE);

$hour = (int)date('H');
$greeting = $hour < 10 ? 'Selamat Pagi' : ($hour < 15 ? 'Selamat Siang' : ($hour < 18 ? 'Selamat Sore' : 'Selamat Malam'));
$classInfo = $student['class_name'] ?? 'Umum';
$tenantName = $school['name'] ?? '';
?>

<style>
.dash-hero {
    background: linear-gradient(135deg, #0b63e5 0%, #0ea5e9 60%, #0369a1 100%);
    border-radius: 18px; margin-bottom: 20px; padding: 22px; color: #fff;
    position: relative; overflow: hidden; box-shadow: 0 6px 20px rgba(11,99,229,.2);
}
.dash-hero::before { content:'';position:absolute;top:-35px;right:-35px;width:140px;height:140px;background:rgba(255,255,255,.08);border-radius:50%; }
.dh-greeting { font-size:13px;opacity:.75;font-weight:600;letter-spacing:.5px; }
.dh-name { font-size:21px;font-weight:900;margin:3px 0 10px;text-shadow:0 2px 12px rgba(0,0,0,.15); }
.dh-meta { display:flex;gap:7px;flex-wrap:wrap; }
.dh-pill { background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:3px 11px;font-size:11px;font-weight:700;backdrop-filter:blur(4px); }
.dh-dot { display:inline-block;width:6px;height:6px;border-radius:50%;background:#4ade80;margin-right:3px;animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.3} }

.dash-stats { display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px; }
.ds-card { background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 10px;text-align:center;transition:transform .15s; }
.ds-card:hover { transform:translateY(-2px); }
.ds-icon { font-size:20px;margin-bottom:5px; }
.ds-val { font-size:21px;font-weight:900;color:var(--text);line-height:1; }
.ds-lbl { font-size:10px;color:var(--muted);font-weight:700;margin-top:3px;text-transform:uppercase;letter-spacing:.3px; }

.sec-head { display:flex;align-items:center;justify-content:space-between;margin-bottom:10px; }
.sec-title { font-size:14px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px; }
.sec-badge { font-size:10px;font-weight:700;background:#eff6ff;color:#0284c7;padding:2px 8px;border-radius:999px; }

.live-card {
    background:linear-gradient(135deg,#0ea5e9,#0284c7);border-radius:16px;
    padding:18px;color:#fff;margin-bottom:18px;position:relative;overflow:hidden;
    box-shadow:0 5px 18px rgba(14,165,233,.25);
}
.live-card::before { content:'';position:absolute;top:-18px;right:-18px;width:70px;height:70px;background:rgba(255,255,255,.1);border-radius:50%; }
.lc-header { display:flex;align-items:center;gap:7px;margin-bottom:12px; }
.lc-badge { background:rgba(255,255,255,.18);border-radius:999px;padding:3px 10px;font-size:10px;font-weight:700;display:flex;align-items:center;gap:4px; }
.lc-dot { width:5px;height:5px;border-radius:50%;background:#4ade80;animation:pulse 1s infinite; }
.lc-body { display:flex;align-items:center;gap:14px;flex-wrap:wrap; }
.lc-info { flex:1;min-width:0; }
.lc-title { font-size:15px;font-weight:800;margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.lc-subj  { font-size:11px;opacity:.8; }
.lc-timer { text-align:center; }
.lc-tl { font-size:9px;opacity:.7;font-weight:600;text-transform:uppercase; }
.lc-time { font-family:'Courier New',monospace;font-size:28px;font-weight:900;letter-spacing:2px;line-height:1; }
.lc-btn { background:#fff;color:#0284c7;padding:9px 18px;border-radius:11px;font-size:12px;font-weight:800;text-decoration:none;box-shadow:0 3px 10px rgba(0,0,0,.15);transition:transform .15s;white-space:nowrap; }
.lc-btn:hover { transform:translateY(-1px); }

.empty-state { background:var(--card);border:1.5px dashed var(--border);border-radius:16px;padding:24px;text-align:center;margin-bottom:18px; }
.es-icon { font-size:36px;margin-bottom:8px; }
.es-title { font-size:14px;font-weight:800;color:var(--text);margin-bottom:5px; }
.es-desc { font-size:12px;color:var(--muted);line-height:1.5;max-width:260px;margin:0 auto 12px; }

.exam-list { display:flex;flex-direction:column;gap:9px;margin-bottom:22px; }
.exam-card { background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 1px 5px rgba(0,0,0,.04);transition:border-color .15s; }
.exam-card:hover { border-color:#bfdbfe; }
.exam-bar { height:3px; }
.exam-bar.blue   { background:linear-gradient(90deg,#0284c7,#0ea5e9); }
.exam-bar.green  { background:linear-gradient(90deg,#059669,#10b981); }
.exam-bar.orange { background:linear-gradient(90deg,#d97706,#f59e0b); }
.exam-bar.red    { background:linear-gradient(90deg,#dc2626,#ef4444); }
.exam-body { padding:12px 14px; }
.exam-top { display:flex;align-items:center;justify-content:space-between;margin-bottom:7px; }
.exam-tag { font-size:10px;font-weight:800;background:#eff6ff;color:#0284c7;padding:2px 8px;border-radius:7px;text-transform:uppercase; }
.exam-dur { font-size:10px;color:#94a3b8;font-weight:600; }
html[data-theme="dark"] .exam-tag { background:rgba(2,132,199,.15);color:#93c5fd; }
.exam-name { font-size:13px;font-weight:800;color:var(--text);margin-bottom:7px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.exam-row { display:flex;align-items:center;gap:4px;font-size:11px;color:#64748b;margin-bottom:2px; }
.exam-actions { display:flex;flex-direction:column;gap:5px;margin-top:10px; }
.chip { display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700; }
.chip.available  { background:#eff6ff;color:#0284c7; }
.chip.in-prog    { background:#fffbeb;color:#d97706; }
.chip.done       { background:#ecfdf5;color:#059669; }
.chip.blocked    { background:#fef2f2;color:#dc2626; }
html[data-theme="dark"] .chip.available  { background:rgba(2,132,199,.15);color:#93c5fd; }
html[data-theme="dark"] .chip.in-prog    { background:rgba(245,158,11,.15);color:#fcd34d; }
html[data-theme="dark"] .chip.done       { background:rgba(16,185,129,.15);color:#6ee7b7; }
html[data-theme="dark"] .chip.blocked    { background:rgba(239,68,68,.15);color:#fca5a5; }
.btn { display:block;width:100%;padding:9px;border-radius:9px;font-size:12px;font-weight:700;text-align:center;text-decoration:none;border:none;cursor:pointer;transition:all .15s; }
.btn.pri  { background:#0284c7;color:#fff; }
.btn.pri:hover { background:#0369a1; }
.btn.warn { background:#f59e0b;color:#fff; }
.btn.ok   { background:#10b981;color:#fff; }
.btn.outl { background:transparent;border:1.5px solid var(--border);color:var(--text); }

.score-box { background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:9px;text-align:center;margin-bottom:5px; }
.score-lbl { font-size:10px;font-weight:700;color:#166534;text-transform:uppercase; }
.score-val { font-size:22px;font-weight:900;color:#15803d; }
.blocked-box { background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:9px;text-align:center;margin-bottom:5px; }
.bb-title { font-size:11px;font-weight:800;color:#b91c1c;margin-bottom:2px; }
.bb-msg   { font-size:11px;color:#dc2626; }

.up-list { display:flex;flex-direction:column;gap:7px;margin-bottom:22px; }
.up-item { background:var(--card);border:1px solid var(--border);border-radius:12px;padding:11px 13px;display:flex;align-items:center;gap:11px; }
.up-date { background:#ecfdf5;border:1px solid #a7f3d0;border-radius:9px;padding:5px 7px;text-align:center;min-width:42px;flex-shrink:0; }
.up-day  { font-size:14px;font-weight:900;color:#059669;line-height:1; }
.up-month { font-size:8px;font-weight:700;color:#059669;opacity:.7;text-transform:uppercase; }
.up-info { flex:1;min-width:0; }
.up-title { font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.up-sub { font-size:10px;color:#64748b;margin-top:1px; }

.modal-bg { display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px; }
.modal-bg.show { display:flex; }
.modal-box { background:#fff;border-radius:18px;width:100%;max-width:380px;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.25);animation:popIn .25s cubic-bezier(.34,1.56,.64,1); }
@keyframes popIn { from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1} }
.modal-close { border:none;background:none;font-size:20px;color:#94a3b8;cursor:pointer;float:right; }
.modal-label { font-size:11px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px; }
.modal-input { width:100%;padding:12px;border:2px solid var(--border);border-radius:9px;font-size:17px;font-weight:800;text-align:center;letter-spacing:3px;outline:none;margin:7px 0 10px;background:var(--bg);transition:border-color .15s; }
.modal-input:focus { border-color:#0284c7; }
.modal-hint { font-size:11px;text-align:center;margin-bottom:12px;font-weight:600; }
.modal-footer { display:flex;gap:7px; }
.modal-footer .btn { flex:1; }

@media(max-width:640px){
    .dash-stats { grid-template-columns:repeat(2,1fr);gap:7px; }
    .ds-card { padding:10px 7px; }
    .ds-val { font-size:19px; }
    .dash-hero { margin:0 0 14px;padding:18px;border-radius:14px; }
    .dh-name { font-size:18px; }
    .live-card { margin:0 0 14px; }
    .exam-list { margin:0 0 14px; }
    .up-list  { margin:0 0 14px; }
}

/* ── GAMIFICATION ─────────────────────────────────────── */
.gamify-streak-bar {
    background: linear-gradient(135deg, #ff6b35 0%, #f7931e 50%, #ffd231 100%);
    border-radius: 14px; padding: 14px 18px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 4px 16px rgba(255,107,53,.25); color: #fff;
    position: relative; overflow: hidden;
}
.gamify-streak-bar::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(circle at 80% 50%, rgba(255,255,255,.15), transparent 60%);
}
.streak-flame { font-size: 32px; animation: flame 0.6s ease-in-out infinite alternate; flex-shrink: 0; }
@keyframes flame { from{transform:scale(1) rotate(-3deg)}to{transform:scale(1.15) rotate(3deg)} }
.streak-info { flex: 1; }
.streak-label { font-size: 11px; opacity: .85; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.streak-count { font-size: 26px; font-weight: 900; line-height: 1.1; }
.streak-sub { font-size: 11px; opacity: .8; margin-top: 2px; }

.gamify-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
.gamify-goal-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 14px;
    padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.gamify-goal-title { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
.gamify-progress-track { height: 8px; background: var(--bg-alt); border-radius: 999px; overflow: hidden; margin-bottom: 6px; }
.gamify-progress-fill { height: 100%; border-radius: 999px; transition: width .5s ease; background: linear-gradient(90deg, #059669, #10b981); }
.gamify-progress-fill.done { background: linear-gradient(90deg, #f59e0b, #ef4444); }
.gamify-goal-text { font-size: 12px; color: var(--text-muted); font-weight: 600; }

.gamify-badges-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
.gamify-badge {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 10px 12px; display: flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 700; color: var(--text); cursor: default;
    transition: transform .15s, box-shadow .15s;
}
.gamify-badge:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.gamify-badge.locked { opacity: .4; filter: grayscale(1); }
.gamify-badge-icon { font-size: 18px; }
.gamify-badge.cls-first   { border-color: #bfdbfe; background: #eff6ff; }
.gamify-badge.cls-perfect { border-color: #a7f3d0; background: #ecfdf5; }
.gamify-badge.cls-practice{ border-color: #fef3c7; background: #fffbeb; }
.gamify-badge.cls-streak  { border-color: #fecaca; background: #fff1f2; }
html[data-theme="dark"] .gamify-badge.cls-first   { background: rgba(2,132,199,.12); border-color: rgba(2,132,199,.3); }
html[data-theme="dark"] .gamify-badge.cls-perfect { background: rgba(5,150,105,.12); border-color: rgba(5,150,105,.3); }
html[data-theme="dark"] .gamify-badge.cls-practice{ background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.3); }
html[data-theme="dark"] .gamify-badge.cls-streak  { background: rgba(239,68,68,.12);  border-color: rgba(239,68,68,.3); }

html[data-theme="dark"] .gamify-streak-bar { background: linear-gradient(135deg, #dc2626, #ea580c); box-shadow: 0 4px 16px rgba(239,68,68,.3); }
html[data-theme="dark"] .gamify-goal-card  { background: var(--card); }

@media(max-width:640px){ .gamify-row { grid-template-columns: 1fr; } }
</style>

<!-- HERO -->
<div class="dash-hero">
    <div class="dh-greeting"><?= $greeting ?>,</div>
    <div class="dh-name"><?= htmlspecialchars($student['name'] ?? '-') ?></div>
    <div class="dh-meta">
        <span class="dh-pill"><span class="dh-dot"></span>Online</span>
        <span class="dh-pill"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><path d="M3 21h18M3 7V4a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3M5 7v14m14-14v14"/><path d="M9 21V11h6v10"/></svg><?= htmlspecialchars($tenantName) ?></span>
        <span class="dh-pill"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>Kelas: <?= htmlspecialchars($classInfo) ?></span>
    </div>
</div>

<!-- LIVE COUNTDOWN -->
<?php if ($inProgress > 0): ?>
<div class="live-card" id="liveCard">
    <div class="lc-header">
        <span class="lc-badge"><span class="lc-dot"></span>SEDANG BERLANGSUNG</span>
    </div>
    <div class="lc-body">
        <div class="lc-info">
            <div class="lc-title" id="lcTitle">—</div>
            <div class="lc-subj" id="lcSubject"></div>
        </div>
        <div class="lc-timer">
            <div class="lc-tl">Sisa Waktu</div>
            <div class="lc-time" id="lcTime">00:00:00</div>
        </div>
        <a href="#" class="lc-btn" id="lcStartBtn"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Kerjakan</a>
    </div>
</div>
<?php else: ?>
<div class="empty-state">
    <div class="es-icon">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    </div>
    <div class="es-title">Tidak Ada Ujian Berlangsung</div>
    <div class="es-desc">Saat ini tidak ada ujian yang sedang dikerjakan.<br>Ayo ikuti ujian yang tersedia!</div>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="sec-head"><div class="sec-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Statistik Nilai</div></div>

<!-- GAMIFICATION: Streak + Goal -->
<?php if ($streakCount > 0 || $practiceToday > 0): ?>
<div class="gamify-streak-bar">
    <div class="streak-flame">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2" style="vertical-align:-4px;"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.5-2.5 1-3.5.5.5 1 1.5 1.5 2.5z"/></svg>
    </div>
    <div class="streak-info">
        <div class="streak-label">Learning Streak</div>
        <div class="streak-count"><?= $streakCount ?> Hari Berturut-turut</div>
        <div class="streak-sub">Terus latihan untuk menjaga api semangatmu!</div>
    </div>
</div>
<?php endif; ?>

<div class="gamify-row">
    <div class="gamify-goal-card">
        <div class="gamify-goal-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-1px;margin-right:2px;"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Target Harian</div>
        <div class="gamify-progress-track">
            <div class="gamify-progress-fill<?= $goalPct >= 100 ? ' done' : '' ?>" style="width:<?= $goalPct ?>%"></div>
        </div>
        <div class="gamify-goal-text"><?= $practiceToday ?> / <?= $dailyGoal ?> soal hari ini<?= $goalPct >= 100 ? ' — Tercapai!' : '' ?></div>
    </div>
    <div class="gamify-goal-card">
        <div class="gamify-goal-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-1px;margin-right:2px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Rekor Pribadi</div>
        <div style="display:flex;gap:12px;margin-top:4px;">
            <div style="flex:1;text-align:center;">
                <div style="font-size:22px;font-weight:900;color:#059669;line-height:1;"><?= $completedCount ?></div>
                <div style="font-size:10px;color:var(--muted);font-weight:700;margin-top:2px;">UJIAN</div>
            </div>
            <div style="flex:1;text-align:center;">
                <div style="font-size:22px;font-weight:900;color:#0284c7;line-height:1;"><?= $bestAvg > 0 ? number_format($bestAvg,0) : '—' ?></div>
                <div style="font-size:10px;color:var(--muted);font-weight:700;margin-top:2px;">NILAI TERBAIK</div>
            </div>
            <div style="flex:1;text-align:center;">
                <div style="font-size:22px;font-weight:900;color:#d97706;line-height:1;"><?= $practiceCompleted ?></div>
                <div style="font-size:10px;color:var(--muted);font-weight:700;margin-top:2px;">LATIHAN</div>
            </div>
        </div>
    </div>
</div>

<!-- BADGES -->
<?php if (!empty($badges)): ?>
<div class="sec-head"><div class="sec-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    Pencapaian</div><span class="sec-badge"><?= count($badges) ?> Terbuka</span></div>
<div class="gamify-badges-row">
    <?php foreach ($badges as $b): ?>
        <div class="gamify-badge cls-<?= $b['cls'] ?>">
            <span class="gamify-badge-icon"><?= $b['icon'] ?></span>
            <span><?= htmlspecialchars($b['label']) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="sec-head"><div class="sec-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Statistik Nilai</div></div>
<div class="dash-stats">
    <div class="ds-card"><div class="ds-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" style="vertical-align:-3px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div><div class="ds-val"><?= count($exams) ?></div><div class="ds-lbl">Ujian Aktif</div></div>
    <div class="ds-card"><div class="ds-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" style="vertical-align:-3px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div class="ds-val"><?= $completedCount ?></div><div class="ds-lbl">Selesai</div></div>
    <div class="ds-card"><div class="ds-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" style="vertical-align:-3px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="ds-val"><?= count($upcomingExams) ?></div><div class="ds-lbl">Akan Datang</div></div>
    <div class="ds-card"><div class="ds-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" style="vertical-align:-3px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5C7 4 9 7 12 7s5-3 7.5-3a2.5 2.5 0 0 1 0 5H18"/><path d="M6 9v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V9"/><polyline points="9 12 12 15 16 10"/></svg></div><div class="ds-val" style="color:#059669;"><?= $bestAvg > 0 ? number_format($bestAvg, 1) : '—' ?></div><div class="ds-lbl">Rata-rata</div></div>
</div>

<!-- UJIAN HARI INI -->
<div class="sec-head"><div class="sec-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Ujian Hari Ini</div><span class="sec-badge"><?= count($exams) ?> Tersedia</span></div>
<div class="exam-list">
    <?php if (empty($exams)): ?>
        <div class="empty-state">
            <div class="es-icon">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <div class="es-title">Belum Ada Ujian Aktif</div>
            <div class="es-desc">Saat ini tidak ada jadwal ujian untuk kelas kamu.<br>Tunggu pengumuman dari guru.</div>
            <a href="history.php?tab=ujian" class="btn outl" style="display:inline-block;width:auto;padding:9px 18px;margin-top:8px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Lihat Riwayat</a>
        </div>
    <?php else: foreach ($exams as $ex):
        $bar = match($ex['session_status']) { 'completed'=>'green','in_progress'=>'orange','blocked'=>'red',default=>'blue' };
        $chip = match($ex['session_status']) {
            'completed'=>['label'=>'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Selesai','cls'=>'done'],
            'in_progress'=>['label'=>'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> Sedang Berjalan','cls'=>'in-prog'],
            'blocked'=>['label'=>'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Terblokir','cls'=>'blocked'],
            default=>['label'=>"<svg width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='3'><polygon points='5 3 19 12 5 21 5 3'/></svg> Siap Diikuti",'cls'=>'available']
        };
    ?>
        <article class="exam-card">
            <div class="exam-bar <?= $bar ?>"></div>
            <div class="exam-body">
                <div class="exam-top">
                    <span class="exam-tag"><?= htmlspecialchars($ex['subject_name']) ?></span>
                    <span class="exam-dur"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= $ex['duration_minutes'] ?> menit</span>
                </div>
                <div class="exam-name" title="<?= htmlspecialchars($ex['title']) ?>"><?= htmlspecialchars($ex['title']) ?></div>
                <div class="exam-row"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><span><?= htmlspecialchars($ex['bank_title']) ?></span></div>
                <div class="exam-row"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span>Maks <?= $ex['max_violations'] ?>× pelanggaran</span></div>
                <div class="exam-row"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span>Batas akhir: <b><?= date('d M Y, H:i', strtotime($ex['end_time'])) ?></b></span></div>
                <div class="exam-actions">
                    <span class="chip <?= $chip['cls'] ?>"><?= $chip['label'] ?></span>
                    <?php if ($ex['session_status'] === 'completed'): ?>
                        <div class="score-box">
                            <div class="score-lbl">Nilai Kamu</div>
                            <div class="score-val"><?= $ex['show_score_immediately'] ? number_format($ex['score'],1) : 'Terkirim' ?></div>
                        </div>
                        <a href="result.php?session_id=<?= (int)$ex['session_id'] ?>" class="btn ok"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Lihat Hasil</a>
                    <?php elseif ($ex['session_status'] === 'blocked'): ?>
                        <div class="blocked-box">
                            <div class="bb-title"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Sesi Terblokir</div>
                            <div class="bb-msg">Batas pelanggaran terlampaui. Hubungi Pengawas.</div>
                        </div>
                    <?php elseif ($ex['session_status'] === 'in_progress'): ?>
                        <a href="exam.php?session_id=<?= (int)$ex['session_id'] ?>" class="btn warn"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Lanjutkan Ujian</a>
                    <?php else: ?>
                        <button type="button" class="btn pri" data-modal-open data-exam-id="<?= (int)$ex['id'] ?>" data-exam-title="<?= htmlspecialchars(addslashes($ex['title'])) ?>" data-exam-token="<?= $isAdminPreview ? htmlspecialchars(addslashes($ex['token'])) : '' ?>">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>Mulai Kerjakan</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; endif; ?>
</div>

<!-- JADWAL MENDATANG -->
<?php if (!empty($upcomingExams)): ?>
<div class="sec-head"><div class="sec-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Jadwal Mendatang</div><span class="sec-badge"><?= count($upcomingExams) ?></span></div>
<div class="up-list">
    <?php foreach ($upcomingExams as $ue): $sts = strtotime($ue['start_time']); ?>
        <div class="up-item">
            <div class="up-date"><div class="up-day"><?= date('d', $sts) ?></div><div class="up-month"><?= mb_substr(mb_strtolower(date('M',$sts)),0,3) ?></div></div>
            <div class="up-info"><div class="up-title"><?= htmlspecialchars($ue['title']) ?></div><div class="up-sub"><?= htmlspecialchars($ue['subject_name']) ?> · <?= $ue['duration_minutes'] ?> menit</div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- MODAL TOKEN -->
<div class="modal-bg" id="modalStart">
    <div class="modal-box">
        <form method="POST" action="start.php">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="exam_id" id="modalExamId">
            <button type="button" class="modal-close" data-modal-close>&times;</button>
            <div style="clear:both;"></div>
            <h3 id="modalExamTitle" style="font-size:15px;font-weight:800;color:var(--text);margin:7px 0 4px;">Konfirmasi Ujian</h3>
            <p style="font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.5;">Pastikan sudah siap di ruang ujian. Token hanya bisa dipakai satu kali!</p>
            <label class="modal-label">Token Ujian</label>
            <input type="text" name="token" id="modalTokenInput" class="modal-input" placeholder="MASUKKAN TOKEN" required autocomplete="off">
            <div id="tokenHint" class="modal-hint" style="color:#0284c7;"></div>
            <div class="modal-footer">
                <button type="button" class="btn outl" data-modal-close>Batal</button>
                <button type="submit" class="btn pri">Verifikasi & Mulai</button>
            </div>
        </form>
    </div>
</div>

<!-- Countdown data — consumed by student-portal.js -->
<div id="cbtCountdownData" data-countdowns='<?= htmlspecialchars($jsCountdownsJson) ?>' style="display:none;"></div>

<?php require_once __DIR__ . '/footer.php'; ?>