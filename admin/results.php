<?php
/**
 * Rekapitulasi Nilai, Hasil Ujian & Analisis Butir Soal
 */

$pageTitle = "Rekap Nilai Ujian";
require_once __DIR__ . '/_bootstrap.php';

$examId = (int)($_GET['exam_id'] ?? 0);

// Daftar ujian untuk filter
$examsList = Database::fetchAll("SELECT id, title, token FROM exams WHERE tenant_id = ? ORDER BY id DESC", [$tenantId]);

if ($examId === 0 && !empty($examsList)) {
    $examId = (int)$examsList[0]['id'];
}

$selectedExam = Database::fetch(
    "SELECT e.*, qb.title as bank_title, s.name as subject_name 
     FROM exams e 
     JOIN question_banks qb ON e.bank_id = qb.id 
     JOIN subjects s ON qb.subject_id = s.id 
     WHERE e.id = ? AND e.tenant_id = ?",
    [$examId, $tenantId]
);

$results = [];
$avgScore = 0.0;
$maxScore = 0.0;
$minScore = 0.0;
$optsRandom = $selectedExam && (int)$selectedExam['randomize_questions'] === 1;

if ($selectedExam) {
    $results = Database::fetchAll(
        "SELECT es.*, st.name as student_name, st.nis, c.name as class_name,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id AND is_correct = 1) as correct_count,
                (SELECT COUNT(*) FROM exam_answers ea_w
                 JOIN questions qw ON ea_w.question_id = qw.id
                 WHERE ea_w.session_id = es.id
                    AND (ea_w.student_answer IS NOT NULL OR ea_w.answer_text IS NOT NULL)
                    AND ea_w.is_correct = 0
                    AND qw.question_type != 'essay') as wrong_count,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id
                    AND student_answer IS NULL AND answer_text IS NULL) as empty_count,
                (SELECT COUNT(*) FROM exam_answers ea2
                 JOIN questions q2 ON ea2.question_id = q2.id
                 WHERE ea2.session_id = es.id
                    AND q2.question_type = 'essay'
                    AND ea2.answer_text IS NOT NULL
                    AND ea2.essay_score IS NOT NULL
                    AND ea2.is_correct = 0) as partial_count,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id
                    AND answer_text IS NOT NULL AND essay_score IS NULL) as ungraded_count
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.tenant_id = ? AND es.status = 'completed'
         ORDER BY es.score DESC, st.name ASC",
        [$examId, $tenantId]
    );

    if (!empty($results)) {
        $scores = array_column($results, 'score');
        $avgScore = array_sum($scores) / count($scores);
        $maxScore = max($scores);
        $minScore = min($scores);
    }
}

// Ekspor CSV rekap nilai
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selectedExam) {
    $rows = [];
    $rank = 0;
    foreach ($results as $r) {
        $rank++;
        $rows[] = [
            $rank,
            $r['nis'],
            $r['student_name'],
            $r['class_name'] ?? '',
            $r['correct_count'],
            $r['wrong_count'],
            $r['empty_count'],
            $r['partial_count'],
            $r['ungraded_count'],
            number_format((float)$r['score'], 1, '.', ''),
            $r['finish_time'] ?? '',
            $r['total_violations'] ?? 0,
        ];
    }
    Helper::downloadCsv(
        'rekap-nilai-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $selectedExam['title']) . '.csv',
        ['peringkat', 'nis', 'nama', 'kelas', 'benar', 'salah', 'kosong', 'parsial', 'belum_dinilai', 'nilai_akhir', 'waktu_selesai', 'pelanggaran'],
        $rows
    );
}

// Analisis butir soal (hanya sesi yang completed)
$itemAnalysis = [];
if ($selectedExam) {
    $itemAnalysis = Database::fetchAll(
        "SELECT q.id, q.question_text, q.correct_answer, q.points,
                COUNT(ea.id) as answered_count,
                SUM(CASE WHEN ea.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                SUM(CASE WHEN ea.student_answer IS NULL AND ea.answer_text IS NULL THEN 1 ELSE 0 END) as blank_count,
                SUM(CASE WHEN ea.answer_text IS NOT NULL AND ea.essay_score IS NULL THEN 1 ELSE 0 END) as ungraded_count,
                SUM(CASE WHEN ea.student_answer = 'A' THEN 1 ELSE 0 END) as pick_a,
                SUM(CASE WHEN ea.student_answer = 'B' THEN 1 ELSE 0 END) as pick_b,
                SUM(CASE WHEN ea.student_answer = 'C' THEN 1 ELSE 0 END) as pick_c,
                SUM(CASE WHEN ea.student_answer = 'D' THEN 1 ELSE 0 END) as pick_d,
                SUM(CASE WHEN ea.student_answer = 'E' THEN 1 ELSE 0 END) as pick_e
         FROM questions q
         LEFT JOIN exam_answers ea ON ea.question_id = q.id
             AND ea.session_id IN (SELECT id FROM exam_sessions WHERE exam_id = :exam_id AND tenant_id = :tenant_id AND status = 'completed')
         WHERE q.bank_id = :bank_id
         GROUP BY q.id
         ORDER BY q.id ASC",
        ['exam_id' => $examId, 'tenant_id' => $tenantId, 'bank_id' => $selectedExam['bank_id']]
    );
}

require_once __DIR__ . '/header.php';
?>

<style>
.chart-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
.chart-row .chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    padding: 18px;
    box-shadow: var(--shadow-card);
}
.chart-row .chart-card.full-width {
    grid-column: 1 / -1;
}
.chart-card h3 {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-heading);
    margin-bottom: 14px;
}
.chart-wrap {
    position: relative;
    width: 100%;
    min-height: 280px;
}
.chart-wrap canvas {
    max-height: 300px;
}
@media (max-width: 900px) {
    .chart-row {
        grid-template-columns: 1fr;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-heading);">Rekap Nilai Peserta Ujian</h2>
        <p style="color: var(--text-muted); font-size: 13.5px;">Evaluasi hasil nilai, distribusi jawaban, dan analisis butir soal</p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 8px;">
            <select name="exam_id" class="form-control" style="font-weight: 600;" onchange="this.form.submit()">
                <?php foreach ($examsList as $ex): ?>
                    <option value="<?= $ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ex['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($selectedExam && !empty($results)): ?>
            <a href="results.php?exam_id=<?= $examId ?>&export=csv" class="btn btn-outline">Ekspor CSV</a>
            <a href="results-pdf.php?exam_id=<?= $examId ?>" class="btn btn-outline" target="_blank">&#128433; Cetak PDF</a>
        <?php endif; ?>
        <a href="student-transcript.php<?= !empty($selectedExam['class_id']) ? ('?class_id=' . (int)$selectedExam['class_id']) : '' ?>" class="btn btn-primary" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, #0284c7, #2563eb); color: #fff; font-weight: 700; border: none; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Cetak Transkrip Nilai Siswa
        </a>
        <button type="button" class="btn btn-outline" onclick="window.print()">Cetak Rekap</button>
    </div>
</div>

<?php if (!$selectedExam): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 48px; color: var(--text-muted);">
            Belum ada data ujian yang dipilih.
        </div>
    </div>
<?php else: ?>

    <!-- STATS OVERVIEW -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon icon-emerald">&#127891;</div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($avgScore, 1) ?></div>
                <div class="stat-label">Nilai Rata-rata</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon icon-blue">&#127942;</div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($maxScore, 1) ?></div>
                <div class="stat-label">Nilai Tertinggi</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon icon-amber">&#9888;</div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($minScore, 1) ?></div>
                <div class="stat-label">Nilai Terendah</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon icon-indigo">&#10004;</div>
            <div class="stat-info">
                <div class="stat-value"><?= count($results) ?></div>
                <div class="stat-label">Siswa Selesai Ujian</div>
            </div>
        </div>
    </div>

    <!-- CHARTS -->
    <?php if (!empty($results)):
        $bins = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($results as $r) {
            $s = (float)$r['score'];
            if ($s <= 20) $bins[0]++;
            elseif ($s <= 40) $bins[1]++;
            elseif ($s <= 60) $bins[2]++;
            elseif ($s <= 80) $bins[3]++;
            else $bins[4]++;
        }
        $totalCorrect = array_sum(array_column($results, 'correct_count'));
        $totalWrong = array_sum(array_column($results, 'wrong_count'));
        $totalBlank = array_sum(array_column($results, 'empty_count'));
        $totalPartial = array_sum(array_column($results, 'partial_count'));
        $totalUngraded = array_sum(array_column($results, 'ungraded_count'));
        $itemData = [];
        foreach ($itemAnalysis as $it) {
            $answered = (int)$it['answered_count'];
            $correct = (int)$it['correct_count'];
            $pct = $answered > 0 ? round(($correct / $answered) * 100, 1) : 0;
            $itemData[] = ['label' => 'Soal ' . ($it['id']), 'pct' => $pct];
        }
        usort($itemData, fn($a, $b) => $a['pct'] <=> $b['pct']);
        $topItems = array_slice($itemData, 0, 10);
    ?>
    <div class="chart-row">
        <div class="chart-card">
            <h3>Distribusi Nilai</h3>
            <div class="chart-wrap"><canvas id="chartScoreDist"></canvas></div>
        </div>
        <div class="chart-card">
            <h3>Distribusi Jawaban Keseluruhan</h3>
            <div class="chart-wrap"><canvas id="chartAnswerDist"></canvas></div>
        </div>
        <div class="chart-card full-width">
            <h3>10 Butir Soal Termudah & Tersulit (% Benar)</h3>
            <div class="chart-wrap"><canvas id="chartItemDiff"></canvas></div>
        </div>
    </div>
    <script>
    (function () {
        if (typeof Chart === 'undefined') return;
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        var textColor = isDark ? '#c3cede' : '#475569';
        var gridColor = isDark ? 'rgba(148,163,184,0.12)' : 'rgba(15,23,42,0.06)';
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = textColor;

        new Chart(document.getElementById('chartScoreDist'), {
            type: 'bar',
            data: {
                labels: ['0–20', '21–40', '41–60', '61–80', '81–100'],
                datasets: [{
                    label: 'Jumlah Siswa',
                    data: [<?php echo implode(',', $bins); ?>],
                    backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#3b82f6', '#10b981'],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: textColor }, grid: { color: gridColor } },
                    x: { ticks: { color: textColor }, grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('chartAnswerDist'), {
            type: 'doughnut',
            data: {
                labels: ['Benar', 'Salah', 'Kosong'<?= $totalUngraded > 0 || $totalPartial > 0 ? ", 'Essay Parsial'" : '' ?><?php if ($totalUngraded > 0): ?>, 'Belum Dinilai"<?php endif; ?>],
                datasets: [{
                    data: [<?php echo $totalCorrect; ?>, <?php echo $totalWrong; ?>, <?php echo $totalBlank; ?><?= $totalPartial > 0 ? ', ' . (int)$totalPartial : '' ?><?php if ($totalUngraded > 0): ?>, <?= (int)$totalUngraded ?><?php endif; ?>],
                    backgroundColor: ['#10b981', '#ef4444', '#94a3b8'<?= $totalPartial > 0 ? ", '#f59e0b'" : '' ?><?php if ($totalUngraded > 0): ?>, '#eab308'<?php endif; ?>],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10 } }
                }
            }
        });

        new Chart(document.getElementById('chartItemDiff'), {
            type: 'bar',
            data: {
                labels: [<?php echo json_encode(array_map(fn($i) => $i['label'], $topItems)); ?>],
                datasets: [{
                    label: '% Benar',
                    data: [<?php echo implode(',', array_map(fn($i) => $i['pct'], $topItems)); ?>],
                    backgroundColor: [<?php echo implode(',', array_map(fn($i) => $i['pct'] >= 75 ? "'#10b981'" : ($i['pct'] >= 40 ? "'#f59e0b'" : "'#ef4444'"), $topItems)); ?>],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: function(v){return v+'%'}, color: textColor }, grid: { color: gridColor } },
                    y: { ticks: { color: textColor }, grid: { display: false } }
                }
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- TABEL HASIL -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><?= htmlspecialchars($selectedExam['title']) ?></h3>
                <small style="color: var(--text-muted);">Mata Pelajaran: <?= htmlspecialchars($selectedExam['subject_name']) ?> | Token: <?= htmlspecialchars($selectedExam['token']) ?></small>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($results)): ?>
                <p style="padding: 36px; text-align: center; color: var(--text-muted);">Belum ada siswa yang berstatus selesai pada ujian ini.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>NIS</th>
                                <th>Nama Lengkap Siswa</th>
                                <th>Kelas</th>
                                <th>Jawaban Benar</th>
                                <th>Jawaban Salah</th>
                                <th>Kosong</th>
                                <th>Essay Parsial</th>
                                <th>Belum Dinilai</th>
                                <th>Waktu Selesai</th>
                                <th style="text-align: right;">Nilai Akhir</th>
                                <th style="text-align: center; width: 110px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $idx => $r): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><code><?= htmlspecialchars($r['nis']) ?></code></td>
                                    <td><b><?= htmlspecialchars($r['student_name']) ?></b></td>
                                    <td><span class="badge badge-neutral"><?= htmlspecialchars($r['class_name'] ?: 'Umum') ?></span></td>
                                    <td><span class="badge badge-success">&#10004; <?= (int)$r['correct_count'] ?></span></td>
                                    <td><span class="badge badge-danger">&#10006; <?= (int)$r['wrong_count'] ?></span></td>
                                    <td><span class="badge badge-neutral"><?= (int)$r['empty_count'] ?></span></td>
                                    <td>
                                        <?php if ((int)$r['partial_count'] > 0): ?>
                                            <span class="badge badge-warning" title="Essay mendapat nilai parsial (bukan salah)">✎ <?= (int)$r['partial_count'] ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$r['ungraded_count'] > 0): ?>
                                            <span class="badge badge-warning" title="Jawaban essay menunggu koreksi manual guru"><?= (int)$r['ungraded_count'] ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: var(--text-muted); font-size: 12px;"><?= $r['finish_time'] ?></td>
                                    <td style="text-align: right;">
                                        <b style="font-size: 16px; color: <?= $r['score'] >= 75 ? '#059669' : ($r['score'] >= 50 ? '#d97706' : '#dc2626') ?>;">
                                            <?= number_format($r['score'], 1) ?>
                                        </b>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="student-transcript.php?student_id=<?= (int)$r['student_id'] ?>" target="_blank" class="btn btn-sm btn-outline" style="font-size: 11px; padding: 4px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="Cetak Transkrip Rapor Nilai Siswa">
                                            <span>&#128196; Transkrip</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ANALISIS BUTIR SOAL -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div>
                <h3 class="card-title">Analisis Butir Soal</h3>
                <small style="color: var(--text-muted);">
                    Tingkat penguasaan (% benar) dan kesukaran dari <?= count($results) ?> siswa selesai
                    <?php if ($optsRandom): ?>
                        &middot; <b>Ujian ini mengacak opsi jawaban per siswa</b> — distribusi A–E dan kolom kunci disembunyikan karena posisi opsi berbeda tiap siswa.
                    <?php else: ?>
                        dan distribusi pilihan jawaban
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($itemAnalysis) || count($results) === 0): ?>
                <p style="padding: 36px; text-align: center; color: var(--text-muted);">Belum ada data jawaban siswa selesai untuk dianalisis.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>Butir Soal</th>
                                <?php if (!$optsRandom): ?>
                                    <th>Kunci</th>
                                    <th>Distribusi Jawaban (A/B/C/D/E)</th>
                                <?php endif; ?>
                                <th>% Benar</th>
                                <th>Kesukaran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemAnalysis as $idx => $it):
                                $answered = (int)$it['answered_count'];
                                $correct = (int)$it['correct_count'];
                                $blank = (int)$it['blank_count'];
                                $pctCorrect = $answered > 0 ? round(($correct / $answered) * 100, 1) : 0;

                                if ($pctCorrect >= 75) {
                                    $diffLabel = 'Mudah';
                                    $diffClass = 'badge-success';
                                } elseif ($pctCorrect >= 40) {
                                    $diffLabel = 'Sedang';
                                    $diffClass = 'badge-warning';
                                } else {
                                    $diffLabel = 'Sulit';
                                    $diffClass = 'badge-danger';
                                }

                                $dist = [
                                    'A' => (int)$it['pick_a'],
                                    'B' => (int)$it['pick_b'],
                                    'C' => (int)$it['pick_c'],
                                    'D' => (int)$it['pick_d'],
                                    'E' => (int)$it['pick_e'],
                                ];
                            ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td style="max-width: 360px;">
                                        <div style="font-size: 13px; color: var(--text-heading); line-height: 1.5;">
                                            <?= htmlspecialchars(mb_strimwidth(strip_tags($it['question_text']), 0, 140, '...')) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                            <?= $answered ?> terjawab &middot; <?= $correct ?> benar &middot; <?= $blank ?> kosong<?php if ((int)$it['ungraded_count'] > 0): ?> &middot; <b style="color: #d97706;"><?= (int)$it['ungraded_count'] ?> belum dinilai</b><?php endif; ?>
                                        </div>
                                    </td>
                                    <?php if (!$optsRandom): ?>
                                        <td><span class="badge badge-primary"><?= htmlspecialchars($it['correct_answer']) ?></span></td>
                                        <td>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                                <?php foreach ($dist as $letter => $cnt):
                                                    $pctDist = $answered > 0 ? round(($cnt / $answered) * 100) : 0;
                                                    $isKey = $letter === $it['correct_answer'];
                                                ?>
                                                    <span class="badge <?= $isKey ? 'badge-success' : 'badge-neutral' ?>" title="<?= $pctDist ?>%">
                                                        <?= $letter ?>: <?= $cnt ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <b style="color: <?= $pctCorrect >= 75 ? '#059669' : ($pctCorrect >= 40 ? '#d97706' : '#dc2626') ?>;">
                                            <?= $pctCorrect ?>%
                                        </b>
                                        <div style="width: 70px; height: 5px; background: var(--bg-track); border-radius: 4px; overflow: hidden; margin-top: 4px;">
                                            <div style="height: 100%; width: <?= min(100, $pctCorrect) ?>%; background: <?= $pctCorrect >= 75 ? '#10b981' : ($pctCorrect >= 40 ? '#f59e0b' : '#ef4444') ?>;"></div>
                                        </div>
                                    </td>
                                    <td><span class="badge <?= $diffClass ?>"><?= $diffLabel ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
