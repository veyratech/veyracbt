<?php
/**
 * Cron Job: Auto-Finish Exam Sessions yang lewat batas waktu (end_time)
 *
 * Jalankan secara berkala melalui cron / Windows Task Scheduler.
 * Contoh cron (Linux):
 *   * * * * * /usr/bin/php -q /path/to/cbtonline/cron/auto-finish.php
 *
 * Contoh command (Windows Task Scheduler / CMD):
 *   C:\xampp\php\php.exe -q C:\xampp\htdocs\cbtonline\cron\auto-finish.php
 */

// Nonaktifkan output buffering agar log bisa terbaca
if (ob_get_level()) ob_end_flush();

// Disable error display for CLI cron
ini_set('display_errors', '0');
error_reporting(0);

// Load dependencies
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../core/Mailer.php';

// Jika dijalankan lewat web, batasi untuk CLI saja demi keamanan
if (php_sapi_name() !== 'cli') {
    echo "Script ini hanya boleh dijalankan via CLI (cron).";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Mulai proses auto-finish exam session...\n";

// Cek apakah kolom email tersedia di tabel students
$hasEmailCol = false;
try {
    $colCheck = Database::fetch("SHOW COLUMNS FROM `students` LIKE 'email'");
    $hasEmailCol = !empty($colCheck);
} catch (Exception $e) {}

$emailField = $hasEmailCol ? "st.email" : "NULL AS email";

// Ambil semua sesi yang masih in_progress namun sudah lewat end_time
$sessions = Database::fetchAll(
    "SELECT es.*, e.title, e.show_score_immediately, st.name AS student_name, {$emailField}, t.name AS school_name
     FROM exam_sessions es
     JOIN exams      e ON es.exam_id  = e.id
     JOIN students   st ON es.student_id = st.id
     JOIN tenants    t  ON es.tenant_id  = t.id
     WHERE es.status = 'in_progress'
       AND es.end_time < NOW()"
);

if (empty($sessions)) {
    echo "[" . date('Y-m-d H:i:s') . "] Tidak ada sesi yang perlu diakhiri.\n";
    exit(0);
}

$count = 0;
$errors = [];

foreach ($sessions as $session) {
    $id       = (int) $session['id'];
    $sid      = (int) $session['student_id'];
    $tenantId = (int) $session['tenant_id'];
    $name     = $session['student_name'] ?? 'Siswa';
    $email    = $session['email'] ?? '';
    $school   = $session['school_name'] ?? '';
    $examTitle = $session['title'] ?? 'Ujian';

    try {
        // Hitung skor dari jawaban yang sudah diisi
        $score = Database::fetchColumn(
            "SELECT COALESCE(SUM(CASE WHEN q.question_type = 'essay' THEN COALESCE(ea.essay_score, 0)
                                     WHEN ea.is_correct = 1 THEN q.points ELSE 0 END), 0)
             FROM exam_answers ea
             JOIN questions q ON ea.question_id = q.id
             WHERE ea.session_id = ?",
            [$id]
        );

        // Update status sesi
        Database::update('exam_sessions', [
            'status'      => 'completed',
            'score'       => (float) $score,
            'finish_time' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $id]);

        // Catat activity log
        Database::insert('activity_logs', [
            'tenant_id'  => $tenantId,
            'session_id' => $id,
            'event_type' => 'auto_finish_timeout',
            'message'    => "Ujian {$examTitle} siswa {$name} diakhiri otomatis karena waktu habis. Skor: {$score}",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        echo "[OK] Sesi #{$id} - {$name} | Skor: {$score}\n";

        // Kirim email notifikasi hasil jika email tersedia dan show_score_immediately aktif
        if (!empty($email) && ((int)($session['show_score_immediately'] ?? 0) === 1)) {
            $sent = Mailer::sendExamResult($email, $name, $school, $examTitle, (float) $score);
            if ($sent) {
                echo "  -> Email hasil dikirim ke {$email}\n";
            } else {
                echo "  -> Gagal mengirim email ke {$email}\n";
                $errors[] = "Email gagal dikirim ke {$email} (Sesi #{$id})";
            }
        }

        $count++;

    } catch (Exception $e) {
        echo "[ERR] Sesi #{$id} - {$name}: " . $e->getMessage() . "\n";
        $errors[] = "Sesi #{$id} - {$name}: " . $e->getMessage();
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "[" . date('Y-m-d H:i:s') . "] Selesai. {$count} sesi diakhiri otomatis.\n";

if (!empty($errors)) {
    echo "Ada " . count($errors) . " error:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    exit(2);
}

exit(0);
