<?php
/**
 * API Stats — Filter statistik dashboard CBT berdasarkan rentang waktu
 * (Hari Ini, 7 Hari Terakhir, 30 Hari, 90 Hari, Semua Data)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';

$range = strtolower(trim((string)($_GET['range'] ?? '7d')));
$validRanges = ['today', '7d', '30d', '90d', 'all'];
if (!in_array($range, $validRanges, true)) {
    $range = '7d';
}

// 1. Hitung Statistik Utama (KPI)
$totalStudents = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ?", [$tenantId]);
$totalQuestions = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM questions q INNER JOIN question_banks qb ON q.bank_id = qb.id WHERE qb.tenant_id = ?",
    [$tenantId]
);

// Filter rentang untuk Sesi & Ujian
$whereTimeSession = "";
$whereTimeExam = "";
$rangeLabel = "7 Hari Terakhir";

switch ($range) {
    case 'today':
        $whereTimeSession = " AND start_time >= CURDATE()";
        $whereTimeExam = " AND ((DATE(start_time) <= CURDATE() AND DATE(end_time) >= CURDATE()) OR DATE(created_at) = CURDATE())";
        $rangeLabel = "Hari Ini";
        break;
    case '7d':
        $whereTimeSession = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
        $whereTimeExam = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
        $rangeLabel = "7 Hari Terakhir";
        break;
    case '30d':
        $whereTimeSession = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
        $whereTimeExam = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
        $rangeLabel = "30 Hari Terakhir";
        break;
    case '90d':
        $whereTimeSession = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 89 DAY)";
        $whereTimeExam = " AND start_time >= DATE_SUB(CURDATE(), INTERVAL 89 DAY)";
        $rangeLabel = "90 Hari Terakhir";
        break;
    case 'all':
    default:
        $whereTimeSession = "";
        $whereTimeExam = "";
        $rangeLabel = "Semua Data";
        break;
}

$completedSessions = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' {$whereTimeSession}",
    [$tenantId]
);

$totalExams = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exams WHERE tenant_id = ? {$whereTimeExam}",
    [$tenantId]
);
if ($range !== 'all' && $totalExams === 0) {
    // Jika tidak ada ujian yang dimulai di rentang ini, ambil total aktif
    $totalExams = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ?", [$tenantId]);
}

// 2. Data Donut Chart (Distribusi Kelulusan pada rentang tersebut)
$avgScoreVal = Database::fetchColumn(
    "SELECT ROUND(AVG(score), 1) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' {$whereTimeSession}",
    [$tenantId]
);
$avgTenantScore = ($avgScoreVal !== null && $avgScoreVal !== '') ? (float)$avgScoreVal : 0.0;

$cntHigh = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score >= 80 {$whereTimeSession}",
    [$tenantId]
);
$cntMed = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score >= 60 AND score < 80 {$whereTimeSession}",
    [$tenantId]
);
$cntLow = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed' AND score < 60 {$whereTimeSession}",
    [$tenantId]
);
$totalScored = $cntHigh + $cntMed + $cntLow;

if ($totalScored > 0) {
    $pctHigh = round(($cntHigh / $totalScored) * 100, 1);
    $pctMed  = round(($cntMed / $totalScored) * 100, 1);
    $pctLow  = max(0, round(100 - $pctHigh - $pctMed, 1));
} else {
    $pctHigh = 0;
    $pctMed  = 0;
    $pctLow  = 0;
}

// 3. Data Bar Chart Aktivitas Ujian Sesuai Rentang
$rawCols = [];
$maxPeriodTotal = 0;

if ($range === 'today') {
    // 6 Interval waktu (00:00 - 24:00)
    $intervals = [
        ['label' => '00:00', 'h1' => '00:00:00', 'h2' => '03:59:59'],
        ['label' => '04:00', 'h1' => '04:00:00', 'h2' => '07:59:59'],
        ['label' => '08:00', 'h1' => '08:00:00', 'h2' => '11:59:59'],
        ['label' => '12:00', 'h1' => '12:00:00', 'h2' => '15:59:59'],
        ['label' => '16:00', 'h1' => '16:00:00', 'h2' => '19:59:59'],
        ['label' => '20:00', 'h1' => '20:00:00', 'h2' => '23:59:59'],
    ];

    $todayRaw = Database::fetchAll(
        "SELECT TIME(start_time) as s_time, status FROM exam_sessions WHERE tenant_id = ? AND start_time >= CURDATE()",
        [$tenantId]
    );

    foreach ($intervals as $inv) {
        $tot = 0; $comp = 0;
        foreach ($todayRaw as $row) {
            if ($row['s_time'] >= $inv['h1'] && $row['s_time'] <= $inv['h2']) {
                $tot++;
                if ($row['status'] === 'completed') $comp++;
            }
        }
        if ($tot > $maxPeriodTotal) $maxPeriodTotal = $tot;
        $rawCols[] = [
            'label' => $inv['label'],
            'total' => $tot,
            'completed' => $comp
        ];
    }
} elseif ($range === '30d') {
    // 6 Kolom interval (tiap interval 5 hari)
    for ($k = 5; $k >= 0; $k--) {
        $daysBackEnd = $k * 5;
        $daysBackStart = $daysBackEnd + 4;
        $lbl = date('d/m', strtotime("-$daysBackStart days"));

        $statPeriod = Database::fetch(
            "SELECT COUNT(*) as total_num, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as comp_num
             FROM exam_sessions
             WHERE tenant_id = ? AND DATE(start_time) >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE(start_time) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$tenantId, $daysBackStart, $daysBackEnd]
        );

        $tot = (int)($statPeriod['total_num'] ?? 0);
        $comp = (int)($statPeriod['comp_num'] ?? 0);
        if ($tot > $maxPeriodTotal) $maxPeriodTotal = $tot;

        $rawCols[] = [
            'label' => $lbl,
            'total' => $tot,
            'completed' => $comp
        ];
    }
} elseif ($range === '90d') {
    // 6 Kolom interval (tiap interval 15 hari)
    for ($k = 5; $k >= 0; $k--) {
        $daysBackEnd = $k * 15;
        $daysBackStart = $daysBackEnd + 14;
        $lbl = date('d/m', strtotime("-$daysBackStart days"));

        $statPeriod = Database::fetch(
            "SELECT COUNT(*) as total_num, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as comp_num
             FROM exam_sessions
             WHERE tenant_id = ? AND DATE(start_time) >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE(start_time) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$tenantId, $daysBackStart, $daysBackEnd]
        );

        $tot = (int)($statPeriod['total_num'] ?? 0);
        $comp = (int)($statPeriod['comp_num'] ?? 0);
        if ($tot > $maxPeriodTotal) $maxPeriodTotal = $tot;

        $rawCols[] = [
            'label' => $lbl,
            'total' => $tot,
            'completed' => $comp
        ];
    }
} elseif ($range === 'all') {
    // 6 Kolom Bulanan (6 Bulan Terakhir / Semua Data)
    $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    $chartMonths = [];
    for ($m = 5; $m >= 0; $m--) {
        $ts = strtotime("first day of -$m month");
        $ymKey = date('Y-m', $ts);
        $lbl = $monthNames[(int)date('n', $ts)] . ' ' . date('y', $ts);
        $chartMonths[$ymKey] = [
            'label' => $lbl,
            'total' => 0,
            'completed' => 0
        ];
    }

    $historyRaw = Database::fetchAll(
        "SELECT DATE_FORMAT(start_time, '%Y-%m') as ym_key,
                COUNT(*) as total_num,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as comp_num
         FROM exam_sessions
         WHERE tenant_id = ? AND start_time IS NOT NULL
         GROUP BY DATE_FORMAT(start_time, '%Y-%m')",
        [$tenantId]
    );

    foreach ($historyRaw as $hr) {
        $ym = $hr['ym_key'] ?? '';
        if (isset($chartMonths[$ym])) {
            $tot = (int)$hr['total_num'];
            $comp = (int)$hr['comp_num'];
            $chartMonths[$ym]['total'] = $tot;
            $chartMonths[$ym]['completed'] = $comp;
            if ($tot > $maxPeriodTotal) $maxPeriodTotal = $tot;
        }
    }

    foreach ($chartMonths as $cm) {
        $rawCols[] = [
            'label' => $cm['label'],
            'total' => $cm['total'],
            'completed' => $cm['completed']
        ];
    }
} else {
    // 7 Kolom (7 Hari Terakhir)
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

    foreach ($historyRaw as $hr) {
        if (isset($chartDays[$hr['session_date']])) {
            $tot = (int)$hr['total_num'];
            $comp = (int)$hr['comp_num'];
            $chartDays[$hr['session_date']]['total'] = $tot;
            $chartDays[$hr['session_date']]['completed'] = $comp;
            if ($tot > $maxPeriodTotal) $maxPeriodTotal = $tot;
        }
    }

    foreach ($chartDays as $cd) {
        $rawCols[] = [
            'label' => $cd['label'],
            'total' => $cd['total'],
            'completed' => $cd['completed']
        ];
    }
}

$scaleMax = max(1, $maxPeriodTotal);
$chartCols = [];
foreach ($rawCols as $rc) {
    $hOrange = $rc['total'] > 0 ? max(16, (int)round(($rc['total'] / $scaleMax) * 145)) : 8;
    $hBlue   = $rc['completed'] > 0 ? max(12, (int)round(($rc['completed'] / $scaleMax) * 145)) : 8;
    $chartCols[] = [
        'label'     => $rc['label'],
        'total'     => $rc['total'],
        'completed' => $rc['completed'],
        'hOrange'   => $hOrange,
        'hBlue'     => $hBlue
    ];
}

// 4. Output Response JSON
echo json_encode([
    'success' => true,
    'range' => $range,
    'range_label' => $rangeLabel,
    'kpi' => [
        'students'  => $totalStudents,
        'questions' => $totalQuestions,
        'exams'     => $totalExams,
        'completed' => $completedSessions
    ],
    'donut' => [
        'avg'      => ($totalScored > 0) ? (string)min(100, (float)$avgTenantScore) : '0.0',
        'pctHigh'  => $pctHigh,
        'cntHigh'  => $cntHigh,
        'pctMed'   => $pctMed,
        'cntMed'   => $cntMed,
        'pctLow'   => $pctLow,
        'cntLow'   => $cntLow,
    ],
    'chart' => $chartCols
], JSON_UNESCAPED_UNICODE);
exit;
