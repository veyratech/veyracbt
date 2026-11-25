<?php
/**
 * API Analytics — Data for admin/analytics.php
 * Returns score distribution, violation trends, question difficulty, top/bottom students
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';

$range   = strtolower(trim((string)($_GET['range'] ?? '30d')));
$examId  = (int)($_GET['exam_id'] ?? 0);

// ── Time filter ──────────────────────────────────────────────────────
$timeFilters = [
    'today' => " AND es.start_time >= CURDATE()",
    '7d'    => " AND es.start_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    '30d'   => " AND es.start_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)",
    '90d'   => " AND es.start_time >= DATE_SUB(CURDATE(), INTERVAL 89 DAY)",
    'all'   => '',
];
$whereTime   = $timeFilters[$range] ?? $timeFilters['30d'];
$examWhere   = $examId > 0 ? " AND es.exam_id = $examId" : '';

// ── KPI summary ──────────────────────────────────────────────────────
$kpiRows = Database::fetch(
    "SELECT
        COUNT(*)                                          AS total_sessions,
        SUM(CASE WHEN es.status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN es.status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN es.status='blocked'    THEN 1 ELSE 0 END) AS blocked,
        ROUND(AVG(CASE WHEN es.status='completed' THEN es.score END), 1) AS avg_score,
        SUM(es.total_violations)                          AS total_violations
     FROM exam_sessions es
     WHERE es.tenant_id = ? {$whereTime} {$examWhere}",
    [$tenantId]
);
if (!$kpiRows) $kpiRows = ['total_sessions'=>0,'completed'=>0,'in_progress'=>0,'blocked'=>0,'total_violations'=>0,'avg_score'=>0];

// ── Violation trend (daily bar chart data) ────────────────────────────
$violationDays = [];
$suffixes      = ['today'=>'1','7d'=>'7','30d'=>'30','90d'=>'90','all'=>'999999'];
$daysBack      = (int)($suffixes[$range] ?? '30');
$rawViol       = Database::fetchAll(
    "SELECT DATE(al.created_at) AS day, COUNT(*) AS cnt
     FROM activity_logs al
     JOIN exam_sessions es ON al.session_id = es.id
     WHERE al.event_type IN ('violation','violation_blocked')
       AND es.tenant_id = ? {$examWhere}
       AND al.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(al.created_at)
     ORDER BY day ASC",
    [$tenantId, $daysBack]
);
foreach ($rawViol as $v) {
    $violationDays[] = ['label' => date('d/m', strtotime($v['day'])), 'count' => (int)$v['cnt']];
}
// Fill gaps so the chart looks continuous
if (count($violationDays) > 1 && $range !== 'all') {
    $filled  = [];
    $startDate = $violationDays[0]['label'] !== ''
        ? strtotime($violationDays[0]['label'])
        : strtotime(date('Y-m-d'));
    for ($i = 0; $i < count($violationDays); $i++) {
        $d  = date('Y-m-d', strtotime("-{$i} days"));
        $key = date('d/m', strtotime($d));
        $found = false;
        foreach ($violationDays as $vd) {
            if ($vd['label'] === $key) { $filled[] = $vd; $found = true; break; }
        }
        if (!$found) $filled[] = ['label' => $key, 'count' => 0];
    }
    $violationDays = array_reverse($filled);
}

// ── Question difficulty — soal yang paling sering salah ──────────────
$qDiff = Database::fetchAll(
    "SELECT q.id,
            LEFT(q.question_text, 50)   AS q_excerpt,
            q.question_text             AS q_full,
            COUNT(*)                    AS answered,
            SUM(CASE WHEN ea.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
            ROUND(SUM(CASE WHEN ea.is_correct = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) * 100, 1) AS pct_correct
     FROM exam_answers ea
     JOIN questions q ON ea.question_id = q.id
     JOIN exam_sessions es ON ea.session_id = es.id
     WHERE es.tenant_id = ? AND ea.student_answer IS NOT NULL {$whereTime} {$examWhere}
     GROUP BY q.id
     ORDER BY pct_correct ASC
     LIMIT 15",
    [$tenantId]
);

// ── Student ranking per selected exam or overall (tanpa window function) ──
if ($examId > 0) {
    $studentRank = Database::fetchAll(
        "SELECT st.name, st.nis, c.name AS class_name, es.score, es.total_violations
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.status = 'completed' AND es.tenant_id = ?
         ORDER BY es.score DESC, es.finish_time ASC
         LIMIT 20",
        [$examId, $tenantId]
    );
    foreach ($studentRank as $i => $r) $studentRank[$i]['rank_pos'] = $i + 1;
} else {
    // Overall: ambil sesi terbaru tiap siswa
    $studentRank = Database::fetchAll(
        "SELECT st.name, st.nis, c.name AS class_name, es.score, es.total_violations
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.tenant_id = ? AND es.status = 'completed' {$whereTime}
           AND es.id = (
               SELECT es2.id FROM exam_sessions es2
               WHERE es2.student_id = es.student_id AND es2.status = 'completed'
                 AND es2.tenant_id = ?
               ORDER BY es2.finish_time DESC LIMIT 1
           )
         ORDER BY es.score DESC
         LIMIT 20",
        [$tenantId, $tenantId]
    );
    foreach ($studentRank as $i => $r) $studentRank[$i]['rank_pos'] = $i + 1;
}

// ── Score range bucket ────────────────────────────────────────────────
$whereTimeNoAlias = str_replace('es.', '', $whereTime);
$examWhereNoAlias = str_replace('es.', '', $examWhere);
$buckets = Database::fetch(
    "SELECT
        SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) AS high,
        SUM(CASE WHEN score >= 60 AND score < 80 THEN 1 ELSE 0 END) AS med,
        SUM(CASE WHEN score < 60 THEN 1 ELSE 0 END) AS low
     FROM exam_sessions
     WHERE tenant_id = ? AND status = 'completed' {$whereTimeNoAlias} {$examWhereNoAlias}",
    [$tenantId]
);

echo json_encode([
    'success'             => true,
    'range'               => $range,
    'kpi'                 => $kpiRows,
    'buckets'             => [
        'high' => (int)($buckets['high']   ?? 0),
        'med'  => (int)($buckets['med']    ?? 0),
        'low'  => (int)($buckets['low']    ?? 0),
    ],
    'violation_trend'     => $violationDays,
    'question_difficulty' => $qDiff,
    'student_ranking'     => $studentRank,
], JSON_UNESCAPED_UNICODE);
exit;
