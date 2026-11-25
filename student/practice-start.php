<?php
/**
 * Practice Start — Buat sesi latihan dari bank soal tertentu
 * Tidak butuh token, tidak ada timer, tidak ada pelanggaran.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$bankId = (int)($_GET['bank_id'] ?? 0);
if ($bankId <= 0) { Helper::redirect('dashboard.php'); exit; }

// Validasi bank soal milik tenant ini
$bank = Database::fetch(
    "SELECT qb.id, qb.title, s.name AS subject_name
     FROM question_banks qb JOIN subjects s ON qb.subject_id = s.id
     WHERE qb.id = ? AND qb.tenant_id = ?",
    [$bankId, $tenantId]
);
if (!$bank) { Helper::setFlash('danger', 'Bank soal tidak ditemukan.'); Helper::redirect('practice.php'); exit; }

// Ambil semua soal di bank ini
$questions = Database::fetchAll(
    "SELECT id, question_type FROM questions WHERE bank_id = ? AND tenant_id = ?",
    [$bankId, $tenantId]
);
if (empty($questions)) {
    Helper::setFlash('warning', 'Bank soal ini kosong. Hubungi guru.');
    Helper::redirect('practice.php');
    exit;
}

shuffle($questions);
$qIds    = array_column($questions, 'id');
$qTypes  = [];
foreach ($questions as $q) $qTypes[(int)$q['id']] = $q['question_type'];

$now = date('Y-m-d H:i:s');

// Cari satu exam_id yang menggunakan bank ini (untuk memenuhi FK constraint exam_sessions.exam_id)
$dummyExam = Database::fetch(
    "SELECT id FROM exams WHERE bank_id = ? AND tenant_id = ? LIMIT 1",
    [$bankId, $tenantId]
);
$examIdForSession = $dummyExam ? (int)$dummyExam['id'] : (int)Database::fetchColumn(
    "SELECT id FROM exams WHERE tenant_id = ? LIMIT 1", [$tenantId]
);
// Jika sama sekali tidak ada exam, gunakan 0 — tapi FK akan menolak; fallback aman: pakai bank pertama tenant
if ($examIdForSession <= 0) {
    $examIdForSession = (int)Database::fetchColumn(
        "SELECT MIN(id) FROM exams WHERE tenant_id = ?", [$tenantId]
    );
}

// ── 1. Simpan ke exam_sessions (memenuhi FK exam_answers.session_id → exam_sessions.id)
Database::insert('exam_sessions', [
    'tenant_id'          => $tenantId,
    'exam_id'            => $examIdForSession,
    'student_id'         => (int)$student['id'],
    'start_time'         => $now,
    'end_time'           => date('Y-m-d H:i:s', strtotime('+99 years')), // tak terbatas
    'question_order'     => json_encode($qIds),
    'total_violations'   => 0,
    'status'             => 'in_progress',
    'score'              => 0.00,
    'extra_time_minutes' => 0,
]);
$examSessionId = (int)Database::lastInsertId();

// ── 2. Simpan ke practice_sessions (tracking latihan khusus)
Database::insert('practice_sessions', [
    'tenant_id'       => $tenantId,
    'student_id'      => (int)$student['id'],
    'bank_id'         => $bankId,
    'total_questions' => count($qIds),
]);
// Tautkan FK praktik ke exam_session agar bisa dilacak balik (opsional, hanya catatan)
$practiceSessionId = (int)Database::lastInsertId();

// ── 3. Buat baris jawaban awal untuk setiap soal
$insertQ = Database::getConnection()->prepare(
    "INSERT INTO exam_answers (tenant_id, session_id, question_id, student_answer, is_doubt, is_correct) VALUES (?, ?, ?, NULL, 0, ?)"
);
foreach ($qIds as $qid) {
    $insertQ->execute([$tenantId, $examSessionId, $qid, ($qTypes[$qid] ?? 'pg') === 'pg' ? 0 : null]);
}

$_SESSION['practice_mode']    = true;
$_SESSION['practice_bank_id'] = $bankId;
$_SESSION['practice_session_id'] = $practiceSessionId;
Helper::redirect("exam.php?session_id={$examSessionId}&practice=1");
exit;
