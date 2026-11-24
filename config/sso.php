<?php
/**
 * Konfigurasi SSO — Integrasi Veyra Ecosystem ↔ CBT Online
 */

// Otomatis baca file .env jika ada di root proyek
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $envLines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true) || (strpos($host, 'localhost:') === 0) || (substr($host, -5) === '.test');
$defaultSsoServer = $isLocal ? 'http://localhost/veyraeducate/public' : 'https://veyracreative.site';

// Secret key untuk verifikasi tanda tangan JWT token SSO
$defaultSsoSecret = '94e75c829da11a0ebfae57b328a994ef55e4b78912c30da765e9fa451296cd31';
$providedSsoSecret = getenv('SSO_SECRET_KEY') ?: ($_ENV['SSO_SECRET_KEY'] ?? '');
if ($providedSsoSecret === '' || $providedSsoSecret === $defaultSsoSecret) {
    if (php_sapi_name() !== 'cli') {
        error_log('[SSO SECURITY] SSO_SECRET_KEY belum dikonfigurasi di .env. Menggunakan default key adalah risiko keamanan fatal.');
    }
}
$ssoSecret = $providedSsoSecret ?: $defaultSsoSecret;

return [
    // Secret key untuk verifikasi tanda tangan JWT token SSO
    'sso_secret' => $ssoSecret,

    // URL Server SSO Veyra Utama
    'sso_server_url' => getenv('SSO_SERVER_URL') ?: ($_ENV['SSO_SERVER_URL'] ?? $defaultSsoServer),

    // URL Aplikasi CBT saat ini (kosong = auto-detect)
    'current_app_url' => getenv('CURRENT_APP_URL') ?: ($_ENV['CURRENT_APP_URL'] ?? ''),

    // Domain Veyra (untuk validasi origin / shared cookie)
    'veyra_domain' => getenv('SSO_DOMAIN') ?: ($_ENV['SSO_DOMAIN'] ?? 'veyracreative.site'),

    // Nama Cookie SSO
    'cookie_name' => getenv('SSO_COOKIE_NAME') ?: ($_ENV['SSO_COOKIE_NAME'] ?? 'veyra_sso'),

    // Masa berlaku token SSO (detik)
    'token_ttl' => 300,

    // Auto-create akun di CBT jika akun baru pertama kali masuk via SSO
    'auto_provision' => true,
];