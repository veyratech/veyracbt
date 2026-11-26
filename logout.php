<?php
/**
 * Logout Handler for CBT Online
 * Membersihkan sesi CBT lokal dan cookie SSO dengan aman.
 */

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/session-init.php';
require_once __DIR__ . '/core/Helper.php';
require_once __DIR__ . '/sso_client.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$wasSso = !empty($_SESSION['is_sso']) || !empty($_COOKIE['veyra_sso']);

// 1. Bersihkan sesi CBT lokal
Auth::logout();

// 2. Bersihkan cookie SSO jika ada
if (isset($_COOKIE['veyra_sso'])) {
    setcookie('veyra_sso', '', time() - 3600, '/', '', false, true);
}

// 3. Jika berasal dari SSO dan ingin keluar dari portal pusat juga
if ($wasSso && isset($_GET['global']) && $_GET['global'] === '1') {
    $sso = sso();
    $sso->logout();
    exit;
}

// Redirect aman ke halaman depan CBT
Helper::setFlash('info', 'Anda telah berhasil keluar (logout).');
Helper::redirect('index.php');
exit;