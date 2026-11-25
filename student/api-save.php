<?php
/**
 * API — Exam engine endpoint (dipanggil assets/js/exam-engine.js selama ujian).
 *
 * Actions:
 *   save_answer    — simpan jawaban PG/essay (auto-koreksi PG di server)
 *   toggle_doubt   — tandai ragu-ragu
 *   log_violation  — catat pelanggaran; blokir otomatis saat mencapai batas
 *   heartbeat      — perbarui last_activity + status sesi + broadcast pengawas
 *   finish_exam    — penanda pengumpulan (penyelesaian dilakukan finish.php)
 *   webcam_status  — status izin kamera siswa
 *   upload_webcam  — simpan frame webcam terakhir untuk monitor pengawas
 *
 * Semua request membawa csrf_token dari engine; sesi harus milik siswa
 * yang login dan berstatus in_progress (kecuali heartbeat yang juga
 * melaporkan status blocked/completed).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

Auth::requireStudent();
Auth::checkTimeout();
Auth::refreshActivity();

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? 'save_answer');
$sessionId = (int)($data['session_id'] ?? 0);
$isPractice = !empty($_SESSION['practice_mode']) && $_SESSION['practice_mode'] === true;

// ── CSRF: token dari payload atau header ──
$csrf     = (string)($data['csrf_token'] ?? $data['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$expected = (string)($_SESSION['csrf_token'] ?? '');
if ($expected === '' || !hash_equals($expected, $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman.']);
    exit;
}

$me = Auth::user();
if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id wajib diisi']);
    exit;
}

$session = Database::fetch(
    "SELECT es.*, e.max_violations
     FROM exam_sessions es
     JOIN exams e ON es.exam_id = e.id
     WHERE es.id = ? AND es.student_id = ? AND es.tenant_id = ?",
    [$sessionId, (int)$me['id'], (int)$me['tenant_id']]
);
// Untuk mode latihan: cek juga via practice_sessions
if (!$session) {
    $ps = Database::fetch(
        "SELECT ps.id, ps.bank_id, ps.total_questions, ps.score,
                q.title as bank_title, e.title as exam_title
         FROM practice_sessions ps
         JOIN question_banks q ON ps.bank_id = q.id
         LEFT JOIN exams e ON e.id = ps.bank_id
         WHERE ps.id = ? AND ps.student_id = ? AND ps.tenant_id = ?",
        [$sessionId, (int)$me['id'], (int)$me['tenant_id']]
    );
    if ($ps) {
        $isPractice = true;
        $session = [
            'id' => $ps['id'], 'tenant_id' => (int)$me['tenant_id'],
            'status' => 'in_progress', 'max_violations' => 999,
            'exam_id' => 0, 'extra_time_minutes' => 0,
        ];
    }
}
if (!$session) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Sesi ujian tidak ditemukan']);
    exit;
}

$tenantId = (int)$session['tenant_id'];
$effEndTs = strtotime($session['end_time']) + ((int)$session['extra_time_minutes'] * 60);
$remaining = max(0, $effEndTs - time());
$status    = $session['status'];

$touch = function () use ($sessionId): void {
    Database::update('exam_sessions', ['last_activity' => date('Y-m-d H:i:s')], 'id = ?', [$sessionId]);
};

$blockedResp = function () use ($status) {
    echo json_encode(['success' => false, 'blocked' => true, 'session_status' => $status]);
    exit;
};

// Skor sementara (pola finish.php): benar / terjawab × 100
$currentScore = function () use ($sessionId): float {
    $row = Database::fetch(
        "SELECT
            SUM(CASE WHEN student_answer IS NOT NULL THEN 1 ELSE 0 END) AS answered,
            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
         FROM exam_answers WHERE session_id = ?",
        [$sessionId]
    );
    $answered = (int)($row['answered'] ?? 0);
    $correct  = (int)($row['correct'] ?? 0);
    return $answered > 0 ? round(($correct / $answered) * 100, 2) : 0.0;
};

/* ────────────────────────────────────────────────────────────────
 * HEARTBEAT — denyut jantung monitor live (setiap 10 detik)
 * Mode latihan: heartbeat tetap jalan tapi tanpa cek status ujian resmi
 * ──────────────────────────────────────────────────────────────── */
if ($action === 'heartbeat') {
    $touch();

    if ($isPractice) {
        // Mode latihan: heartbeat ringan, tidak perlu broadcast
        echo json_encode([
            'success'            => true,
            'session_status'     => 'in_progress',
            'extra_time_minutes' => 0,
            'remaining_seconds'  => -1,  // -1 = tak terbatas untuk latihan
            'time_expired'       => false,
            'announcements'      => [],
        ]);
        exit;
    }

    $announcements = [];
    if (Database::tableExists('exam_broadcasts')) {
        $lastBroadcastId = (int)($data['last_broadcast_id'] ?? 0);
        $announcements = Database::fetchAll(
            "SELECT id, sender_name, message
             FROM exam_broadcasts
             WHERE tenant_id = ? AND exam_id = ? AND id > ?
               AND (session_id IS NULL OR session_id = ?)
             ORDER BY id ASC LIMIT 20",
            [$tenantId, (int)$session['exam_id'], $lastBroadcastId, $sessionId]
        );
    }

    echo json_encode([
        'success'            => true,
        'session_status'     => $status,
        'extra_time_minutes' => (int)$session['extra_time_minutes'],
        'remaining_seconds'  => $remaining,
        'time_expired'       => $remaining <= 0 && $status === 'in_progress',
        'announcements'      => $announcements,
    ]);
    exit;
}

/* ────────────────────────────────────────────────────────────────
 * LOG PELANGGARAN — dihitung di server, blokir saat mencapai batas
 * Mode latihan: abaikan pelanggaran (latihan tidak ada proctoring)
 * ──────────────────────────────────────────────────────────────── */
if ($action === 'log_violation') {
    if ($isPractice) {
        echo json_encode(['success' => true, 'ignored' => true, 'session_status' => $status]);
        exit;
    }
    if ($status === 'blocked') $blockedResp();
    if ($status !== 'in_progress') {
        echo json_encode(['success' => true, 'ignored' => true, 'session_status' => $status]);
        exit;
    }

    $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 255) ?: 'Pelanggaran terdeteksi';
    $maxV   = (int)$session['max_violations'];

    Database::query(
        "UPDATE exam_sessions SET total_violations = total_violations + 1, last_activity = NOW() WHERE id = ? AND status = 'in_progress'",
        [$sessionId]
    );
    $newCount = (int)Database::fetchColumn("SELECT total_violations FROM exam_sessions WHERE id = ?", [$sessionId]);

    $nowBlocked = false;
    if ($maxV > 0 && $newCount >= $maxV) {
        $nowBlocked = Database::update(
            'exam_sessions',
            ['status' => 'blocked'],
            'id = ? AND status = ?',
            [$sessionId, 'in_progress']
        ) > 0;
    }

    if (Database::tableExists('activity_logs')) {
        Database::insert('activity_logs', [
            'tenant_id'  => $tenantId,
            'session_id' => $sessionId,
            'event_type' => $nowBlocked ? 'violation_blocked' : 'violation',
            'message'    => "Siswa {$me['name']} pelanggaran ke-{$newCount}: {$reason}"
                . ($nowBlocked ? " — otomatis diblokir (batas {$maxV})" : ''),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    echo json_encode([
        'success'        => !$nowBlocked,
        'blocked'        => $nowBlocked,
        'violations'     => $newCount,
        'max_violations' => $maxV,
    ]);
    exit;
}

/* ────────────────────────────────────────────────────────────────
 * Aksi berikut butuh sesi masih berjalan
 * ──────────────────────────────────────────────────────────────── */
$requireRunning = function () use ($status, $blockedResp, $remaining, $currentScore, $isPractice) {
    if ($status === 'blocked') $blockedResp();
    if ($status !== 'in_progress') {
        echo json_encode(['success' => false, 'blocked' => true, 'session_status' => $status]);
        exit;
    }
    // Mode latihan: tidak ada batasan waktu
    if (!$isPractice && $remaining <= 0) {
        echo json_encode(['success' => false, 'time_expired' => true, 'score' => $currentScore()]);
        exit;
    }
};

/* ────────────────────────────────────────────────────────────────
 * SIMPAN JAWABAN — auto-koreksi pilihan ganda di server
 * ──────────────────────────────────────────────────────────────── */
if ($action === 'save_answer' || $action === 'toggle_doubt') {
    $requireRunning();

    $questionId = (int)($data['question_id'] ?? 0);
    if ($questionId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'question_id wajib diisi']);
        exit;
    }

    $q = Database::fetch(
        "SELECT id, question_type, correct_answer FROM questions WHERE id = ? AND tenant_id = ?",
        [$questionId, $tenantId]
    );
    if (!$q) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Soal tidak ditemukan']);
        exit;
    }

    $exists = Database::fetch(
        "SELECT id FROM exam_answers WHERE session_id = ? AND question_id = ?",
        [$sessionId, $questionId]
    );
    if (!$exists) {
        // Baris dibuat saat sesi dimulai; buat darurat bila hilang
        Database::insert('exam_answers', [
            'tenant_id'     => $tenantId,
            'session_id'    => $sessionId,
            'question_id'   => $questionId,
            'student_answer'=> null,
            'is_doubt'      => 0,
            'is_correct'    => $q['question_type'] === 'pg' ? 0 : null,
        ]);
    }

    $touch();

    if ($action === 'toggle_doubt') {
        $isDoubt = !empty($data['is_doubt']) ? 1 : 0;
        Database::update(
            'exam_answers',
            ['is_doubt' => $isDoubt],
            'session_id = ? AND question_id = ?',
            [$sessionId, $questionId]
        );
        echo json_encode(['success' => true, 'is_doubt' => $isDoubt]);
        exit;
    }

    // save_answer
    $answerRaw  = strtoupper(trim((string)($data['answer'] ?? '')));
    $answerText = isset($data['answer_text']) ? trim((string)$data['answer_text']) : null;
    $isDoubt    = !empty($data['is_doubt']) ? 1 : 0;

    $update = ['is_doubt' => $isDoubt];

    if ($q['question_type'] === 'pg') {
        if ($answerRaw !== '' && !in_array($answerRaw, ['A', 'B', 'C', 'D', 'E'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Jawaban tidak valid']);
            exit;
        }
        $update['student_answer'] = $answerRaw !== '' ? $answerRaw : null;
        $update['answer_text']    = null;
        // Koreksi PG selalu dihitung ulang di server (jawaban boleh berubah)
        $update['is_correct'] = ($answerRaw !== '' && $answerRaw === strtoupper($q['correct_answer'])) ? 1 : 0;
    } else {
        // Essay: simpan teks; penilaian oleh guru (is_correct tidak diubah)
        $update['answer_text']    = mb_substr((string)$answerText, 0, 8000);
        $update['student_answer'] = null;
    }

    Database::update('exam_answers', $update, 'session_id = ? AND question_id = ?', [$sessionId, $questionId]);

    echo json_encode(['success' => true]);
    exit;
}

/* ────────────────────────────────────────────────────────────────
 * FINISH — finish.php yang menyelesaikan & menilai sesi
 * Mode latihan: hitung skor → simpan ke practice_sessions
 * ──────────────────────────────────────────────────────────────── */
if ($action === 'finish_exam') {
    if ($isPractice) {
        // Hitung skor dan simpan ke practice_sessions
        $row = Database::fetch(
            "SELECT
                SUM(CASE WHEN student_answer IS NOT NULL AND is_correct = 1 THEN 1 ELSE 0 END) AS correct,
                COUNT(*) AS total
             FROM exam_answers WHERE session_id = ? AND student_answer IS NOT NULL",
            [$sessionId]
        );
        $total   = (int)($row['total'] ?? 0);
        $correct = (int)($row['correct'] ?? 0);
        $score   = $total > 0 ? round(($correct / $total) * 100, 2) : 0;

        // Simpan ke practice_sessions (bukan exam_sessions)
        $psId = (int)($_SESSION['practice_session_id'] ?? 0);
        if ($psId > 0) {
            Database::update('practice_sessions', [
                'score'         => $score,
                'correct_count' => $correct,
                'wrong_count'   => $total - $correct,
                'blank_count'   => $total > 0 ? $total - $correct : 0,
            ], 'id = ? AND tenant_id = ?', [$psId, $tenantId]);
        }

        $_SESSION['practice_mode'] = false;
        echo json_encode(['success' => true, 'session_status' => 'completed', 'score' => $score]);
        exit;
    }
    if ($status === 'blocked') $blockedResp();
    if ($status === 'completed') {
        echo json_encode(['success' => true, 'session_status' => 'completed']);
        exit;
    }
    if ($remaining <= 0) {
        echo json_encode(['success' => false, 'time_expired' => true, 'score' => $currentScore()]);
        exit;
    }
    echo json_encode(['success' => true, 'session_status' => 'in_progress']);
    exit;
}

/* ────────────────────────────────────────────────────────────────
 * WEBCAM — status izin & frame terakhir untuk monitor pengawas
 * Mode latihan: abaikan (tidak ada proctoring)
 * ──────────────────────────────────────────────────────────────── */
if ($action === 'webcam_status') {
    if ($isPractice) { echo json_encode(['success' => true, 'ignored' => true]); exit; }
    $st = (string)($data['status'] ?? '');
    if (!in_array($st, ['active', 'denied', 'disabled'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Status webcam tidak valid']);
        exit;
    }
    Database::update('exam_sessions', ['webcam_status' => $st], 'id = ? AND tenant_id = ?', [$sessionId, $tenantId]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'upload_webcam') {
    if ($isPractice || $status !== 'in_progress') {
        echo json_encode(['success' => false, 'ignored' => true]);
        exit;
    }
    $img = (string)($data['image_data'] ?? '');
    if (!preg_match('#^data:image/jpeg;base64,#', $img)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Format frame harus JPEG base64']);
        exit;
    }
    $bin = base64_decode(substr($img, strpos($img, ',') + 1), true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 600 * 1024 || substr($bin, 0, 2) !== "\xFF\xD8") {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Frame webcam tidak valid']);
        exit;
    }

    $dir = __DIR__ . '/../assets/uploads/webcam';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $relPath = 'assets/uploads/webcam/sess_' . $sessionId . '.jpg';
    if (@file_put_contents($dir . '/sess_' . $sessionId . '.jpg', $bin, LOCK_EX) === false) {
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan frame']);
        exit;
    }

    Database::update('exam_sessions', [
        'last_webcam_frame' => $relPath,
        'last_webcam_at'    => date('Y-m-d H:i:s'),
    ], 'id = ? AND tenant_id = ?', [$sessionId, $tenantId]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal: ' . $action]);
