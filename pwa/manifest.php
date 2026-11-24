<?php
/**
 * PWA Manifest — CBT Online Student Portal
 * Menyediakan data instalasi aplikasi untuk Android / iOS
 */
header('Content-Type: application/manifest+json');
header('Cache-Control: no-store, max-age=0');

// Ambil nama sekolah dari config tenant
$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schoolName = $pdo->query("SELECT name FROM tenants LIMIT 1")->fetchColumn() ?: 'CBT Online';
} catch (\Throwable $e) {
    $schoolName = 'CBT Online';
}

$shortName = mb_substr($schoolName, 0, 12);

$platform = @include __DIR__ . '/../config/platform.php';
$appDesc  = ($platform && isset($platform['app_tagline'])) ? $platform['app_tagline'] : "Portal Ujian Siswa {$shortName}";

echo json_encode([
    'name'             => $schoolName . ' — CBT Online',
    'short_name'       => $shortName,
    'description'      => $appDesc,
    'start_url'        => '/cbtonline/student/dashboard.php',
    'display'          => 'standalone',
    'background_color' => '#f8fafc',
    'theme_color'      => '#0284c7',
    'orientation'      => 'portrait-primary',
    'scope'            => '/cbtonline/student/',
    'lang'             => 'id',
    'categories'       => ['education', 'productivity'],
    'icons'            => [
        [
            'src'   => '/cbtonline/pwa/icon-192.png',
            'sizes' => '192x192',
            'type'  => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'   => '/cbtonline/pwa/icon-512.png',
            'sizes' => '512x512',
            'type'  => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_UNICODE), "\n";
