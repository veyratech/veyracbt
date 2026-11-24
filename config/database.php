<?php
/**
 * Konfigurasi Database CBT Online Multi-Tenant
 */

// Otomatis baca file .env jika ada di root proyek (ramah cPanel / VPS)
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

return [
    'host'     => getenv('CBT_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int)(getenv('CBT_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
    'database' => getenv('CBT_DB_NAME') ?: getenv('DB_DATABASE') ?: 'cbtonline_db',
    'username' => getenv('CBT_DB_USER') ?: getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('CBT_DB_PASS') !== false ? getenv('CBT_DB_PASS') : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''),
    'charset'  => 'utf8mb4'
];