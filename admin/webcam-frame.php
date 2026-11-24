<?php
/**
 * Webcam Frame Proxy — menampilkan snapshot kamera terakhir peserta ujian.
 *
 * File asli di assets/uploads/webcam/ diblokir .htaccess (tidak boleh
 * diakses langsung); endpoint ini membukanya HANYA untuk admin/teacher
 * tenant yang terautentikasi, dengan validasi tenant + path.
 */
header('Content-Type: image/jpeg');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';

Auth::requireTenantAdmin();
Auth::checkTimeout();

$school   = Tenant::get();
$tenantId = (int)($school['id'] ?? 0);

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0 || $tenantId <= 0) {
    http_response_code(400);
    exit;
}

$frame = Database::fetchColumn(
    "SELECT last_webcam_frame FROM exam_sessions WHERE id = ? AND tenant_id = ?",
    [$sessionId, $tenantId]
);
$rel = $frame ?: ('assets/uploads/webcam/sess_' . $sessionId . '.jpg');
$rel = str_replace('\\', '/', (string)$rel);

// Tolak path traversal & paksa berada di dalam folder webcam
if (strpos($rel, '..') !== false || strpos($rel, 'assets/uploads/webcam/') !== 0) {
    http_response_code(400);
    exit;
}

$abs  = realpath(__DIR__ . '/../' . $rel);
$root = realpath(__DIR__ . '/../assets/uploads/webcam');
if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_file($abs)) {
    http_response_code(404);
    exit;
}

readfile($abs);
