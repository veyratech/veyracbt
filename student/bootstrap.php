<?php
/**
 * Student Portal Bootstrap — auth + shared vars (one-time load)
 */

// Enable error display for debugging (remove in production)
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Require core modules (Auth auto-loads Database/Tenant/Helper internally)
require_once __DIR__ . '/../core/Auth.php';

// Start session if not already started (save_path set via .htaccess)
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Auth check — skip redirect on the login page itself (avoid infinite loop)
if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
    Auth::requireStudent('login.php', true);
}
if (Auth::check()) {
    Auth::checkTimeout();
    Auth::refreshActivity();
}

// Shared variables for all student pages
$student        = Auth::user();
$tenantId       = (int)($student['tenant_id'] ?? Tenant::id());
// Refresh from DB so photo field is always available (session doesn't store it)
$freshStudent = Database::fetch(
    "SELECT s.*, c.name as class_name
     FROM students s LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.id = ? AND s.tenant_id = ?",
    [(int)$student['id'], $tenantId]
);
if ($freshStudent) {
    $freshStudent['role'] = 'student';
    $_SESSION['auth_user'] = $freshStudent;
    $student = $freshStudent;
}
$school         = Tenant::get();
$flash          = Helper::getFlash();
$pageKey        = $pageKey      ?? str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$isAdminPreview = in_array(Auth::role(), ['admin', 'teacher', 'superadmin']);
