<?php
/**
 * Start Exam Handler — Token validation & session init
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Mulai Ujian';
$pageKey   = 'start';
require_once __DIR__ . '/header.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helper::redirect('dashboard.php');
}
Helper::verifyCsrf();

$examId = (int)($_POST['exam_id'] ?? 0);
$token  = strtoupper(trim($_POST['token'] ?? ''));

$exam = Database::fetch(
    "SELECT * FROM exams WHERE id = ? AND tenant_id = ? AND is_active = 1",
    [$examId, $tenantId]
);

if (!$exam) {
    Helper::setFlash('danger', 'Ujian tidak ditemukan atau telah nonaktif.');
    Helper::redirect('dashboard.php');
}

$nowTs = time();
if ($nowTs < strtotime($exam['start_time'])) {
    Helper::setFlash('warning', 'Ujian belum dimulai.');
    Helper::redirect('dashboard.php');
}
if ($nowTs > strtotime($exam['end_time'])) {
    Helper::setFlash('danger', 'Waktu ujian sudah berakhir.');
    Helper::redirect('dashboard.php');
}
if (strtoupper($exam['token']) !== $token) {
    Helper::setFlash('danger', 'Token ujian salah! Tanyakan ke pengawas.');
    Helper::redirect('dashboard.php');
}

// Check existing session
$existing = Database::fetch(
    "SELECT * FROM exam_sessions WHERE exam_id = ? AND student_id = ? AND tenant_id = ?",
    [$examId, (int)$student['id'], $tenantId]
);

if ($existing) {
    if ($existing['status'] === 'completed') {
        Helper::redirect('result.php?session_id=' . $existing['id']);
    }
    Helper::redirect('exam.php?session_id=' . $existing['id']);
}

// Get questions from bank
$questions = Database::fetchAll(
    "SELECT id, question_type FROM questions WHERE bank_id = ? AND tenant_id = ?",
    [$exam['bank_id'], $tenantId]
);
if (empty($questions)) {
    Helper::setFlash('danger', 'Bank soal kosong. Hubungi guru.');
    Helper::redirect('dashboard.php');
}

$qIds = [];
$qTypes = [];
foreach ($questions as $q) {
    $qIds[] = (int)$q['id'];
    $qTypes[(int)$q['id']] = $q['question_type'];
}
if ($exam['randomize_questions']) shuffle($qIds);

$questionCount = (int)($exam['question_count'] ?? 0);
if ($questionCount > 0 && $questionCount < count($qIds)) {
    shuffle($qIds);
    $qIds = array_slice($qIds, 0, $questionCount);
}

$now = date('Y-m-d H:i:s');
// Akhir sesi = durasi ujian, dibatasi jendela waktu ujian (end_time).
// Catatan: kolomnya duration_minutes (bukan durasi_minutes).
$examEndTs = strtotime($exam['end_time']);
$durMin    = (int)($exam['duration_minutes'] ?? 0);
$durEndTs  = $durMin > 0 ? strtotime('+' . $durMin . ' minutes') : false;
if ($examEndTs !== false && $durEndTs !== false) {
    $endTimeTs = min($durEndTs, $examEndTs);
} elseif ($examEndTs !== false) {
    $endTimeTs = $examEndTs;
} elseif ($durEndTs !== false) {
    $endTimeTs = $durEndTs;
} else {
    $endTimeTs = $nowTs + 3600;
}
$endTime = date('Y-m-d H:i:s', $endTimeTs);

$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$stmt = Database::getConnection()->prepare(
    "INSERT INTO exam_sessions (tenant_id, exam_id, student_id, start_time, end_time, question_order, total_violations, status, score, ip_address, user_agent, last_activity) VALUES (?, ?, ?, ?, ?, ?, 0, 'in_progress', 0.00, ?, ?, ?)"
);
$stmt->execute([$tenantId, $examId, (int)$student['id'], $now, $endTime, json_encode($qIds), $_SERVER['REMOTE_ADDR'] ?? '', $userAgent, $now]);
$sessionId = (int)Database::lastInsertId();

$insQ = Database::getConnection()->prepare(
    "INSERT INTO exam_answers (tenant_id, session_id, question_id, student_answer, is_doubt, is_correct) VALUES (?, ?, ?, NULL, 0, ?)"
);
foreach ($qIds as $qid) {
    // PG langsung dinilai server saat dijawab; essay tetap NULL sampai guru berkoreksi
    $insQ->execute([$tenantId, $sessionId, $qid, ($qTypes[$qid] ?? 'pg') === 'pg' ? 0 : null]);
}

Database::insert('activity_logs', [
    'tenant_id' => $tenantId,
    'session_id' => $sessionId,
    'event_type' => 'start_exam',
    'message' => "Siswa {$student['name']} memulai ujian '{$exam['title']}'."
]);

Helper::redirect('exam.php?session_id=' . $sessionId);
exit;
