<?php
/**
 * API Monitor — JSON endpoint untuk polling real-time peserta ujian
 * Hanya dapat diakses oleh admin/teacher tenant yang terautentikasi.
 */
header('Content-Type: application/json; charset=utf-8');
// Prevent browser caching of live monitor data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/_bootstrap.php';

$errorLog = __DIR__ . '/../storage/logs/api_monitor_error.log';

try {
    $examId = (int)($_GET['exam_id'] ?? 0);

    if ($examId === 0) {
        Helper::jsonResponse(['error' => 'exam_id diperlukan'], 400);
    }

    // Validasi eksistensi tabel esensial sebelum query
    $requiredTables = ['exams', 'question_banks', 'subjects', 'exam_sessions', 'students'];
    foreach ($requiredTables as $tbl) {
        if (!Database::tableExists($tbl)) {
            Helper::jsonResponse(['error' => "Tabel database '{$tbl}' belum tersedia. Jalankan migrasi database terlebih dahulu.", 'missing_table' => $tbl], 503);
        }
    }

    $selectedExam = Database::fetch(
        "SELECT id, title, token, bank_id FROM exams WHERE id = ? AND tenant_id = ?",
        [$examId, $tenantId]
    );

    if (!$selectedExam) {
        Helper::jsonResponse(['error' => 'Ujian tidak ditemukan atau tidak akses'], 404);
    }

    $totalQuestionsInBank = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM questions WHERE bank_id = ?",
        [$selectedExam['bank_id']]
    );

    $now = time();

    $participantsRaw = Database::fetchAll(
        "SELECT es.id, es.status, es.total_violations, es.start_time, es.end_time,
                es.extra_time_minutes, es.last_activity, es.finish_time, es.score, es.ip_address,
                es.last_webcam_frame, es.last_webcam_at, es.webcam_status,
                st.name as student_name, st.nis, c.name as class_name,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id AND (student_answer IS NOT NULL OR answer_text IS NOT NULL)) as answered_count
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.tenant_id = ?
         ORDER BY es.status DESC, es.total_violations DESC, es.start_time DESC",
        [$examId, $tenantId]
    );

$kpi = [
    'total'        => count($participantsRaw),
    'in_progress'  => 0,
    'completed'    => 0,
    'blocked'      => 0,
    'offline'      => 0,
    'online'       => 0,
    'avg_progress' => 0
];

$totalProgressPct = 0;

$participants = array_map(function($p) use ($now, &$kpi, &$totalProgressPct, $totalQuestionsInBank) {
    $effEnd = strtotime($p['end_time']) + ((int)$p['extra_time_minutes'] * 60);
    $remSec = max(0, $effEnd - $now);

    // Hitung status konektivitas live
    $onlineStatus = 'offline';
    $statusText = 'Offline';
    if ($p['status'] === 'completed') {
        $onlineStatus = 'completed';
        $statusText = 'Selesai';
        $kpi['completed']++;
    } elseif ($p['status'] === 'blocked') {
        $onlineStatus = 'blocked';
        $statusText = 'Diblokir';
        $kpi['blocked']++;
    } else {
        $kpi['in_progress']++;
        if (!empty($p['last_activity'])) {
            $diff = $now - strtotime($p['last_activity']);
            if ($diff <= 25) {
                $onlineStatus = 'online';
                $statusText = 'Online';
                $kpi['online']++;
            } elseif ($diff <= 75) {
                $onlineStatus = 'idle';
                $statusText = 'Idle (' . $diff . 'd)';
            } else {
                $onlineStatus = 'offline';
                $statusText = 'Offline (' . floor($diff / 60) . 'm lalu)';
                $kpi['offline']++;
            }
        } else {
            $onlineStatus = 'offline';
            $statusText = 'Belum aktif';
            $kpi['offline']++;
        }
    }

    $answered = (int)$p['answered_count'];
    $pct = $totalQuestionsInBank > 0 ? min(100, round(($answered / $totalQuestionsInBank) * 100)) : 0;
    $totalProgressPct += $pct;

    $hasWebcam = !empty($p['last_webcam_frame']) && file_exists(__DIR__ . '/../' . $p['last_webcam_frame']);
    $isWebcamLive = !empty($p['last_webcam_at']) && ($now - strtotime($p['last_webcam_at']) <= 20);
    // Lewat proxy terautentikasi (file asli diblokir .htaccess)
    $webcamUrl = $hasWebcam
        ? ('webcam-frame.php?session_id=' . (int)$p['id'] . '&t=' . (int)strtotime($p['last_webcam_at']))
        : null;

    return [
        'id'                 => (int)$p['id'],
        'student_name'       => $p['student_name'],
        'nis'                => $p['nis'],
        'class_name'         => $p['class_name'] ?: 'Umum',
        'status'             => $p['status'],
        'total_violations'   => (int)$p['total_violations'],
        'answered_count'     => $answered,
        'progress_pct'       => $pct,
        'start_time'         => $p['start_time'],
        'end_time'           => $p['end_time'],
        'extra_time_minutes' => (int)$p['extra_time_minutes'],
        'remaining_seconds'  => $remSec,
        'remaining_formatted'=> sprintf('%02d:%02d', floor($remSec / 60), $remSec % 60),
        'last_activity'      => $p['last_activity'],
        'online_status'      => $onlineStatus,
        'online_text'        => $statusText,
        'score'              => (float)$p['score'],
        'ip_address'         => null,
        'has_webcam'         => $hasWebcam,
        'webcam_url'         => $webcamUrl,
        'last_webcam_at'     => $p['last_webcam_at'],
        'is_webcam_live'     => $isWebcamLive,
        'webcam_status'      => $p['webcam_status'] ?: 'active'
    ];
}, $participantsRaw);

if ($kpi['total'] > 0) {
    $kpi['avg_progress'] = round($totalProgressPct / $kpi['total']);
}

// Ambil 20 log aktivitas terbaru untuk Ticker Live Pelanggaran (aman jika tabel belum ada)
$recentLogs = [];
if (Database::tableExists('activity_logs')) {
    $recentLogs = Database::fetchAll(
        "SELECT al.id, al.session_id, al.event_type, al.message, al.created_at, al.ip_address,
                st.name as student_name, st.nis
         FROM activity_logs al
         JOIN exam_sessions es ON al.session_id = es.id
         JOIN students st ON es.student_id = st.id
         WHERE es.exam_id = ? AND al.tenant_id = ?
         ORDER BY al.id DESC LIMIT 20",
        [$examId, $tenantId]
    );
}

$formattedLogs = array_map(function($l) {
    return [
        'id'           => (int)$l['id'],
        'session_id'   => (int)$l['session_id'],
        'event_type'   => $l['event_type'],
        'message'      => $l['message'],
        'student_name' => $l['student_name'],
        'nis'          => $l['nis'],
        'time'         => date('H:i:s', strtotime($l['created_at'])),
        'created_at'   => $l['created_at']
    ];
}, $recentLogs);

Helper::jsonResponse([
    'exam_id'             => (int)$selectedExam['id'],
    'exam_title'          => $selectedExam['title'],
    'exam_token'          => $selectedExam['token'],
    'total_questions'     => $totalQuestionsInBank,
    'last_update'         => date('Y-m-d H:i:s'),
    'participant_count'   => count($participants),
    'kpi'                 => $kpi,
    'participants'        => $participants,
    'recent_logs'         => $formattedLogs
]);

} catch (\Throwable $e) {
    error_log('[monitor-api] Fatal error: ' . $e->getMessage());
    file_put_contents($errorLog, date('Y-m-d H:i:s') . ' - ' . get_class($e) . ': ' . $e->getMessage() . "\n", FILE_APPEND);
    Helper::jsonResponse(['error' => 'Terjadi kesalahan server. Silakan coba lagi.'], 500);
}
