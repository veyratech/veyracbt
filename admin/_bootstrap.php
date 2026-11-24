<?php
/**
 * Bootstrap admin: auth + context tanpa output HTML.
 * Include ini di paling atas halaman admin SEBELUM handler POST/ekspor.
 */

// Disable error display in production
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

Auth::requireTenantAdmin();
Auth::checkTimeout();
Auth::refreshActivity();
$user = Auth::user();
$school = Tenant::get();
$tenantId = (int)$school['id'];

// Force password change if using default credentials
if (Auth::isDefaultPassword($user['id'], $user['role'])) {
    $_SESSION['force_password_change'] = true;
}
