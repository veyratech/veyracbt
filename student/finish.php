<?php
/**
 * Finish Exam Handler
 * - in_progress → kumpulkan & nilai
 * - completed   → langsung ke halaman hasil
 * - blocked     → tampilkan layar blokir (dibuka kembali oleh pengawas)
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Kumpulkan Ujian';
$pageKey   = 'exam';

// ── Practice Mode: selesai di api-save.php via finish_exam action.
//    Finish.php hanya menangani exam session normal.
if (!empty($_SESSION['practice_mode']) && $_SESSION['practice_mode'] === true) {
    // Redirect ke history latihan
    Helper::redirect('history.php?tab=latihan');
    exit;
}

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) { Helper::redirect('dashboard.php'); exit; }

$session = Database::fetch(
    "SELECT es.*, e.title as exam_title, e.max_violations FROM exam_sessions es JOIN exams e ON es.exam_id = e.id WHERE es.id = ? AND es.student_id = ? AND es.tenant_id = ?",
    [$sessionId, (int)$student['id'], (int)$tenantId]
);
if (!$session) { Helper::redirect('dashboard.php'); exit; }

// Sudah selesai → halaman hasil
if ($session['status'] === 'completed') {
    Helper::redirect('result.php?session_id=' . $sessionId);
    exit;
}

// Diblokir → tampilkan info (tanpa menyelesaikan sesi)
if ($session['status'] === 'blocked') {
    require_once __DIR__ . '/header.php'; ?>
    <style>
    .blocked-card { max-width:560px;margin:48px auto;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 28px;text-align:center; }
    .blocked-icon { width:64px;height:64px;border-radius:50%;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px; }
    .blocked-title { font-size:19px;font-weight:900;color:var(--text);margin-bottom:8px; }
    .blocked-text { font-size:13.5px;color:var(--muted);line-height:1.7;margin-bottom:22px; }
    </style>
    <div class="blocked-card">
        <div class="blocked-icon">&#9888;</div>
        <div class="blocked-title">Ujian Dihentikan Sementara</div>
        <div class="blocked-text">
            Sesi ujian Anda dihentikan (pelanggaran
            <?= (int)$session['total_violations'] ?>/<?= (int)$session['max_violations'] ?>).
            Nilai belum dikumpulkan. Hubungi pengawas ruang untuk membuka kembali sesi Anda.
        </div>
        <a href="dashboard.php" class="btn btn-primary" style="padding:11px 26px;font-weight:700;">Kembali ke Dashboard</a>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

// Masih berjalan → kumpulkan
// Hitung nilai: benar / terjawab × 100 (essay dinilai guru terpisah)
$row = Database::fetch(
    "SELECT
        SUM(CASE WHEN student_answer IS NOT NULL THEN 1 ELSE 0 END) AS answered,
        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
     FROM exam_answers WHERE session_id = ?",
    [$sessionId]
);
$answered = (int)($row['answered'] ?? 0);
$correct  = (int)($row['correct'] ?? 0);
$score    = $answered > 0 ? round(($correct / $answered) * 100, 2) : 0;

Database::update('exam_sessions', [
    'status'      => 'completed',
    'score'       => $score,
    'finish_time' => date('Y-m-d H:i:s'),
    'last_activity' => date('Y-m-d H:i:s'),
], 'id = ? AND tenant_id = ? AND status = ?', [$sessionId, (int)$tenantId, 'in_progress']);

Database::insert('activity_logs', [
    'tenant_id' => $tenantId,
    'session_id' => $sessionId,
    'event_type' => 'finish_exam',
    'message' => "Siswa {$student['name']} menyelesaikan ujian '{$session['exam_title']}' dengan nilai $score."
]);

Helper::redirect('result.php?session_id=' . $sessionId);
exit;
