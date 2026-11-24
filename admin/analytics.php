<?php
/**
 * Analytics — Analisis Mendalam Hasil Ujian & Pelanggaran
 * Membutuhkan Chart.js (sudah dimuat via CDN di halaman ini).
 */
$pageTitle = 'Analytics';
require_once __DIR__ . '/_bootstrap.php';

// Ambil daftar ujian untuk filter dropdown
$examsList = Database::fetchAll(
    "SELECT id, title FROM exams WHERE tenant_id = ? ORDER BY id DESC",
    [$tenantId]
);

$selectedExamId = (int)($_GET['exam_id'] ?? 0);
$range          = strtolower(trim((string)($_GET['range'] ?? '30d')));
if (!in_array($range, ['today','7d','30d','90d','all'], true)) $range = '30d';
?>

<style>
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .a-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 16px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
    html[data-theme="dark"] .a-card { background: #1e293b; border-color: #334155; }
    .a-card-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted, #64748b); margin-bottom: 6px; }
    .a-card-value { font-size: 26px; font-weight: 900; color: var(--text, #0f172a); line-height: 1; }
    .a-card-sub   { font-size: 11.5px; color: var(--muted, #64748b); margin-top: 4px; }
    .a-chart-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,.04); margin-bottom: 22px; }
    html[data-theme="dark"] .a-chart-wrap { background: #1e293b; border-color: #334155; }
    .a-chart-title { font-size: 13px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; }
    .a-filter-bar  { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
    .a-filter-bar select, .a-filter-bar button { font-size: 12.5px; padding: 6px 12px; border-radius: 9px; border: 1px solid var(--border, #e2e8f0); background: var(--card, #fff); color: var(--text, #0f172a); cursor: pointer; }
    html[data-theme="dark"] .a-filter-bar select,
    html[data-theme="dark"] .a-filter-bar button { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    .a-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .a-table th { text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted, #64748b); border-bottom: 2px solid var(--border, #e2e8f0); font-weight: 700; }
    .a-table td { padding: 9px 10px; border-bottom: 1px solid var(--border, #e2e8f0); color: var(--text, #0f172a); }
    html[data-theme="dark"] .a-table th { border-bottom-color: #334155; }
    html[data-theme="dark"] .a-table td { border-bottom-color: #334155; }
    .a-table tr:hover td { background: var(--bg-hover, #f8fafc); }
    .a-badge-high  { color: #059669; font-weight: 700; }
    .a-badge-med   { color: #d97706; font-weight: 700; }
    .a-badge-low   { color: #dc2626; font-weight: 700; }
    .a-badge-block { color: #dc2626; font-weight: 700; }
    .a-spacer { height: 2px; }
</style>

<div class="a-filter-bar">
    <select id="filterExam" onchange="loadAnalytics()">
        <option value="0">Semua Ujian</option>
        <?php foreach ($examsList as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $selectedExamId === (int)$e['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['title']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php foreach (['today','7d','30d','90d','all'] as $r): ?>
        <button type="button" class="a-range-btn" data-range="<?= $r ?>"
            style="<?= $range === $r ? 'background:#059669;color:#fff;border-color:#059669;' : '' ?>"
            onclick="setRange('<?= $r ?>')"><?= htmlspecialchars(['today'=>'Hari Ini','7d'=>'7 Hari','30d'=>'30 Hari','90d'=>'90 Hari','all'=>'Semua'][ $r ]) ?></button>
    <?php endforeach; ?>
</div>

<!-- KPI Cards -->
<div class="analytics-grid" id="kpiGrid">
    <div class="a-card"><div class="a-card-label">Total Sesi</div><div class="a-card-value" id="kpiTotal">—</div><div class="a-card-sub">Rentang dipilih</div></div>
    <div class="a-card"><div class="a-card-label">Selesai</div><div class="a-card-value a-badge-high" id="kpiDone">—</div><div class="a-card-sub" id="kpiDonePct"></div></div>
    <div class="a-card"><div class="a-card-label">Sedang Berjalan</div><div class="a-card-value" style="color:#0ea5e9;font-weight:900;" id="kpiLive">—</div><div class="a-card-sub">Real-time monitor</div></div>
    <div class="a-card"><div class="a-card-label">Rata-rata Nilai</div><div class="a-card-value" id="kpiAvg">—</div><div class="a-card-sub">Dari yang sudah selesai</div></div>
    <div class="a-card"><div class="a-card-label">Total Pelanggaran</div><div class="a-card-value" style="color:#ef4444;font-weight:900;" id="kpiViol">—</div><div class="a-card-sub">Semua sesi rentang</div></div>
</div>

<!-- Score Buckets + Violation Trend -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px;">
    <div class="a-chart-wrap">
        <div class="a-chart-title">Distribusi Nilai</div>
        <canvas id="bucketChart" style="max-height:220px;"></canvas>
    </div>
    <div class="a-chart-wrap">
        <div class="a-chart-title">Tren Pelanggaran Harian</div>
        <canvas id="violationChart" style="max-height:220px;"></canvas>
    </div>
</div>

<!-- Question Difficulty + Student Ranking -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
    <div class="a-chart-wrap">
        <div class="a-chart-title">Soal Paling Sulit (Akurasi Terendah)</div>
        <div style="overflow-x:auto;">
            <table class="a-table" id="diffTable">
                <thead><tr><th>#</th><th>Soal</th><th>Terjawab</th><th>Benar</th><th>Akurasi</th></tr></thead>
                <tbody id="diffBody"></tbody>
            </table>
        </div>
    </div>
    <div class="a-chart-wrap">
        <div class="a-chart-title">Peringkat Siswa</div>
        <div style="overflow-x:auto;">
            <table class="a-table" id="rankTable">
                <thead><tr><th>Rank</th><th>Siswa</th><th>Kelas</th><th>Nilai</th><th>Pelanggaran</th></tr></thead>
                <tbody id="rankBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function () {
    let bucketCtx, violCtx, bucketChart, violChart;

    function escHtml(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    async function loadAnalytics() {
        const examId = (document.getElementById('filterExam').value || 0);
        const params = new URLSearchParams({ range: currentRange, exam_id: examId });
        const res = await fetch('api-analytics.php?' + params.toString());
        const d   = await res.json();
        if (!d.success) return;

        // ── KPI ──
        const k = d.kpi;
        document.getElementById('kpiTotal').textContent  = k.total_sessions ?? 0;
        document.getElementById('kpiDone').textContent   = k.completed ?? 0;
        document.getElementById('kpiDonePct').textContent = k.total_sessions > 0
            ? Math.round((k.completed / k.total_sessions) * 100) + '% dari total' : '';
        document.getElementById('kpiLive').textContent   = k.in_progress ?? 0;
        document.getElementById('kpiAvg').textContent    = (k.avg_score ?? 0) + ' / 100';
        document.getElementById('kpiViol').textContent   = k.total_violations ?? 0;

        // ── Bucket Chart ──
        const b = d.buckets || {};
        const totalB = (b.high||0)+(b.med||0)+(b.low||0);
        if (bucketChart) bucketChart.destroy();
        bucketChart = new Chart(document.getElementById('bucketChart'), {
            type: 'doughnut',
            data: {
                labels: ['≥ 80 (Lulus)', '60–79 (Cukup)', '< 60 (Remedial)'],
                datasets: [{ data: [b.high||0, b.med||0, b.low||0], backgroundColor: ['#10b981','#f59e0b','#ef4444'], borderWidth: 0 }]
            },
            options: { responsive:true, plugins: { legend:{position:'bottom',labels:{padding:14,usePointStyle:true}}} }
        });

        // ── Violation Trend Chart ──
        const vt = d.violation_trend || [];
        if (violChart) violChart.destroy();
        violChart = new Chart(document.getElementById('violationChart'), {
            type: 'bar',
            data: {
                labels: vt.map(v => v.label),
                datasets: [{ label:'Pelanggaran', data: vt.map(v=>v.count), backgroundColor:'#ef4444', borderRadius:6 }]
            },
            options: {
                responsive:true, scales:{ y:{beginAtZero:true,ticks:{stepSize:1}}, x:{ticks:{font:{size:10}}}},
                plugins:{ legend:{display:false} }
            }
        });

        // ── Question Difficulty Table ──
        const diffBody = document.getElementById('diffBody');
        diffBody.innerHTML = '';
        (d.question_difficulty || []).slice(0, 12).forEach((q,i) => {
            const pct = parseFloat(q.pct_correct) || 0;
            const cls = pct >= 70 ? 'a-badge-high' : (pct >= 40 ? 'a-badge-med' : 'a-badge-low');
            diffBody.insertAdjacentHTML('beforeend',
                `<tr><td>${i+1}</td><td title="${escHtml(q.q_full ?? q.q_excerpt)}">${escHtml(q.q_excerpt)}</td>`+
                `<td>${q.answered}</td><td class="a-badge-high">${q.correct}</td>`+
                `<td class="${cls}">${pct}%</td></tr>`
            );
        });
        if (!diffBody.children.length) diffBody.innerHTML='<tr><td colspan="5" style="text-align:center;color:var(--muted)">Belum ada data jawaban</td></tr>';

        // ── Student Ranking Table ──
        const rankBody = document.getElementById('rankBody');
        rankBody.innerHTML = '';
        (d.student_ranking || []).forEach((s,i) => {
            const sc = parseFloat(s.score) || 0;
            const cls = sc >= 80 ? 'a-badge-high' : (sc >= 60 ? 'a-badge-med' : 'a-badge-low');
            rankBody.insertAdjacentHTML('beforeend',
                `<tr>`+
                `<td style="font-weight:800;color:${i===0?'#f59e0b':i===1?'#94a3b8':i===2?'#b45309':'inherit'}">#${s.rank_pos??i+1}</td>`+
                `<td><b>${escHtml(s.name)}</b><br><span style="font-size:11px;color:var(--muted)">NIS: ${escHtml(s.nis)}</span></td>`+
                `<td>${escHtml(s.class_name ?? '-')}</td>`+
                `<td class="${cls}" style="font-weight:800">${sc}</td>`+
                `<td>${s.total_violations > 0 ? '<span class="a-badge-block">'+s.total_violations+'</span>' : '0'}</td>`+
                `</tr>`
            );
        });
        if (!rankBody.children.length) rankBody.innerHTML='<tr><td colspan="5" style="text-align:center;color:var(--muted)">Belum ada siswa dengan nilai</td></tr>';
    }

    let currentRange = '30d';
    window.setRange = function(r) {
        currentRange = r;
        document.querySelectorAll('.a-range-btn').forEach(btn => {
            btn.style.background = btn.dataset.range === r ? '#059669' : '';
            btn.style.color      = btn.dataset.range === r ? '#fff' : '';
            btn.style.border     = btn.dataset.range === r ? '#059669' : '';
        });
        loadAnalytics();
    };

    loadAnalytics();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
