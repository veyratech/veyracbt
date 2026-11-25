<?php
/**
 * Test Suite — CBT Online
 * Jalankan via CLI: php tests/run-tests.php
 */

$root = __DIR__ . '/..';
chdir($root);

$total   = 0;
$passed  = 0;
$failed  = 0;
$errors  = [];

require_once __DIR__ . '/../core/Database.php';

function runTest(&$total, &$passed, &$failed, &$errors, $name, $result) {
    $total++;
    if ($result === true || $result === null) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        $msg = is_string($result) ? $result : 'Assertion failed';
        $errors[] = "$name — $msg";
        echo "  FAIL  $name — $msg\n";
    }
}

echo "=== CBT Online Test Suite ===\n\n";

// ========== FILE EXISTENCE TESTS ==========
echo "[File Checks]\n";
runTest($total, $passed, $failed, $errors, "admin/analytics.php",
    file_exists(__DIR__ . '/../admin/analytics.php') ? true : "not found");
runTest($total, $passed, $failed, $errors, "admin/api-analytics.php",
    file_exists(__DIR__ . '/../admin/api-analytics.php') ? true : "not found");
runTest($total, $passed, $failed, $errors, "student/practice.php",
    file_exists(__DIR__ . '/../student/practice.php') ? true : "not found");
runTest($total, $passed, $failed, $errors, "student/practice-start.php",
    file_exists(__DIR__ . '/../student/practice-start.php') ? true : "not found");
runTest($total, $passed, $failed, $errors, "cron/daily-backup.php",
    file_exists(__DIR__ . '/../cron/daily-backup.php') ? true : "not found");
runTest($total, $passed, $failed, $errors, "admin/webcam-frame.php",
    file_exists(__DIR__ . '/../admin/webcam-frame.php') ? true : "not found");

// ========== SYNTAX TESTS ==========
echo "\n[Syntax Checks]\n";
$phpFiles = [
    'admin/analytics.php', 'admin/api-analytics.php',
    'student/practice.php', 'student/practice-start.php',
    'student/api-save.php', 'student/exam.php',
    'cron/daily-backup.php', 'admin/monitor.php', 'admin/header.php',
];
foreach ($phpFiles as $f) {
    $path = __DIR__ . '/../' . $f;
    $ret  = 0;
    exec('C:\\xampp\\php\\php.exe -l "' . $path . '" 2>&1', $_out, $ret);
    runTest($total, $passed, $failed, $errors, "Syntax: $f", $ret === 0 ? true : "lint error");
}

// ========== SCHEMA TESTS ==========
echo "\n[Schema Checks]\n";
$colsA = array_column(Database::fetchAll("SHOW COLUMNS FROM exam_answers"), 'Field');
runTest($total, $passed, $failed, $errors, "exam_answers: no question_number col",
    !in_array('question_number', $colsA) ? true : "column still exists");

$colsQ = array_column(Database::fetchAll("SHOW COLUMNS FROM questions"), 'Field');
runTest($total, $passed, $failed, $errors, "questions: has option_a..e",
    (in_array('option_a', $colsQ) && in_array('option_e', $colsQ)) ? true : "missing options");

runTest($total, $passed, $failed, $errors, "practice_sessions table",
    Database::tableExists('practice_sessions') ? true : "missing");

runTest($total, $passed, $failed, $errors, "exam_broadcasts table",
    Database::tableExists('exam_broadcasts') ? true : "missing");

// ========== SOURCE INTEGRITY TESTS ==========
echo "\n[Integrity Checks]\n";
$srcStart   = file_get_contents(__DIR__ . '/../student/start.php');
$srcApi     = file_get_contents(__DIR__ . '/../student/api-save.php');
$srcEngine  = file_get_contents(__DIR__ . '/../assets/js/exam-engine.js');
$srcAnalytics = file_get_contents(__DIR__ . '/../admin/api-analytics.php');
$srcWebcam  = file_get_contents(__DIR__ . '/../admin/webcam-frame.php');

// start.php: check no $exam['durasi_minutes'] variable access (comments OK)
$durasiFound = preg_match_all('/\$exam\s*\[\s*[\'"]durasi_minutes[\'"]\s*\]/', $srcStart, $_dur);
$durationFound = strpos($srcStart, "\$exam['duration_minutes']") !== false;
runTest($total, $passed, $failed, $errors, "start.php: duration_minutes not durasi_minutes",
    ($durationFound && $durasiFound === 0) ? true : "wrong column still accessed (found $durasiFound occurrence(s))");

runTest($total, $passed, $failed, $errors, "start.php: lastInsertId() not getLastInsertId",
    strpos($srcStart, 'lastInsertId()') !== false ? true : "wrong method");

runTest($total, $passed, $failed, $errors, "api-save.php: heartbeat handler",
    strpos($srcApi, "action === 'heartbeat'") !== false ? true : "missing");

runTest($total, $passed, $failed, $errors, "api-save.php: log_violation handler",
    strpos($srcApi, "action === 'log_violation'") !== false ? true : "missing");

runTest($total, $passed, $failed, $errors, "api-save.php: practice mode support",
    strpos($srcApi, '$isPractice') !== false ? true : "missing");

runTest($total, $passed, $failed, $errors, "exam-engine.js: isPractice flag",
    (strpos($srcEngine, 'isPractice') !== false && strpos($srcEngine, '!this.isPractice') !== false)
    ? true : "flag not checked");

runTest($total, $passed, $failed, $errors, "analytics API: expected JSON keys",
    (strpos($srcAnalytics, "'success'") !== false && strpos($srcAnalytics, "'kpi'") !== false
     && strpos($srcAnalytics, "'buckets'") !== false && strpos($srcAnalytics, "'student_ranking'") !== false)
    ? true : "missing keys");

runTest($total, $passed, $failed, $errors, "webcam-frame.php: tenant auth + path traversal guard",
    (strpos($srcWebcam, 'Auth::requireTenantAdmin') !== false
     && strpos($srcWebcam, 'realpath') !== false
     && strpos($srcWebcam, "'..'") !== false)
    ? true : "security checks missing");

// ========== SUMMARY ==========
echo "\n" . str_repeat('=', 50) . "\n";
echo "Total: $total | Passed: $passed | Failed: $failed\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) echo "  - $e\n";
}

exit($failed > 0 ? 1 : 0);
