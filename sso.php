<?php
/**
 * SSO Login Handler — Handle SSO token from Veyra Educate
 *
 * URL: https://cbt.veyracreative.site/sso.php?token=...
 *
 * Token format: base64(payload).hmac(payload, secret)
 * Payload: {u: userId, t: tenantId, r: role, i: issuedAt, e: expiresAt}
 */

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Tenant.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if already logged in
if (Auth::check()) {
    $role = Auth::role();
    if ($role === 'superadmin') {
        Helper::redirect('superadmin/index.php');
    } elseif (in_array($role, ['admin', 'teacher'])) {
        Helper::redirect('admin/index.php');
    } elseif ($role === 'student') {
        Helper::redirect('student/dashboard.php');
    }
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    Helper::setFlash('danger', 'Token SSO tidak ditemukan. Silakan klik link dari dashboard Veyra Educate.');
    Helper::redirect('index.php');
}

// Decode and verify token
$ssoConfig = file_exists(__DIR__ . '/config/sso.php') ? (require __DIR__ . '/config/sso.php') : [];
$secret = defined('CBT_SSO_SECRET') ? CBT_SSO_SECRET : ($ssoConfig['sso_secret'] ?? '');
if ($secret === '') {
    Helper::setFlash('danger', 'Konfigurasi SSO belum diatur dengan benar.');
    Helper::redirect('index.php');
}
$parts = explode('.', $token);
if (count($parts) !== 2) {
    Helper::setFlash('danger', 'Token SSO tidak valid.');
    Helper::redirect('index.php');
}

[$payload, $signature] = $parts;

// Verify signature
$expected = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    Helper::setFlash('danger', 'Token SSO tidak valid atau telah dimanipulasi.');
    Helper::redirect('index.php');
}

// Decode payload
$data = json_decode(base64_decode($payload), true);
if (!$data || !isset($data['u'], $data['t'], $data['r'])) {
    Helper::setFlash('danger', 'Token SSO tidak valid.');
    Helper::redirect('index.php');
}

$userId    = (int)$data['u'];
$tenantId  = (int)$data['t'];
$userRole  = $data['r'];
$issuedAt  = (int)($data['i'] ?? 0);
$expiresAt = (int)($data['e'] ?? 0);

// Check expiration
if ($expiresAt < time()) {
    Helper::setFlash('danger', 'Token SSO sudah kadaluarsa. Silakan minta link baru dari Veyra Educate.');
    Helper::redirect('index.php');
}

// Check if 5 minutes have passed since issuance (prevent replay)
if (time() - $issuedAt > 300) {
    Helper::setFlash('danger', 'Token SSO sudah terlalu lama. Silakan minta link baru.');
    Helper::redirect('index.php');
}

// Fetch tenant
$tenant = Database::fetch("SELECT * FROM tenants WHERE id = ? AND is_active = 1", [$tenantId]);
if (!$tenant) {
    Helper::setFlash('danger', 'Tenant tidak ditemukan atau tidak aktif.');
    Helper::redirect('index.php');
}

Tenant::setTenant($tenant);

// Authenticate user based on role
$loggedIn = false;

if ($userRole === 'admin' || $userRole === 'teacher') {
    $user = Database::fetch(
        "SELECT * FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$userId, $tenantId]
    );
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = [
            'id'        => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'username'  => $user['username'],
            'name'      => $user['name'],
            'role'      => $user['role'],
            'email'     => $user['email']
        ];
        $_SESSION['last_activity'] = time();
        Database::query("UPDATE users SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
        $loggedIn = true;
    }
} elseif ($userRole === 'student') {
    $student = Database::fetch(
        "SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.tenant_id = ? AND s.is_active = 1",
        [$userId, $tenantId]
    );
    if ($student) {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = [
            'id'         => (int)$student['id'],
            'tenant_id'  => (int)$student['tenant_id'],
            'nis'        => $student['nis'],
            'name'       => $student['name'],
            'class_id'   => $student['class_id'],
            'class_name' => $student['class_name'] ?? 'Umum',
            'role'       => 'student'
        ];
        $_SESSION['last_activity'] = time();
        Database::query("UPDATE students SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
        $loggedIn = true;
    }
}

if (!$loggedIn) {
    Helper::setFlash('danger', 'Akun tidak ditemukan atau tidak aktif di tenant ini.');
    Helper::redirect('index.php');
}

// Success — redirect based on role
Helper::setFlash('success', "Selamat datang, {$tenant['name']}! Anda masuk via SSO Veyra Educate.");

if ($userRole === 'admin' || $userRole === 'teacher') {
    // Check 2FA
    $userData = Database::fetch("SELECT totp_verified FROM users WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
    if (!empty($userData['totp_verified'])) {
        $_SESSION['pending_2fa_user'] = $_SESSION['auth_user'];
        unset($_SESSION['auth_user']);
        Helper::redirect('admin/totp-verify.php');
    }
    Helper::redirect('admin/index.php');
} elseif ($userRole === 'student') {
    $studentData = Database::fetch("SELECT totp_verified FROM students WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
    if (!empty($studentData['totp_verified'])) {
        $_SESSION['pending_2fa_user'] = $_SESSION['auth_user'];
        unset($_SESSION['auth_user']);
        Helper::redirect('student/totp-verify.php');
    }
    Helper::redirect('student/dashboard.php');
}
