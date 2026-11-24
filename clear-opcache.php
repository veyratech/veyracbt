<?php
/**
 * Clear OPcache untuk CBT Online
 * Akses halaman ini sekali setelah deploy file baru di hosting
 */
if (!function_exists('opcache_reset')) {
    die('OPcache tidak aktif di server ini. Tidak perlu menjalankan skrip ini.');
}

$before = opcache_get_status();
$result = opcache_reset();

// Coba reset opcode per-file juga
foreach (($before['scripts'] ?? []) as $file => $info) {
    @opcache_invalidate($file, true);
}

echo "<h2>OPcache Cleared ✓</h2>";
echo "<p>Status: " . ($result ? "Berhasil di-clear" : "Gagal") . "</p>";
echo "<p>Jumlah file yang di-cache sebelumnya: " . count($before['scripts'] ?? []) . "</p>";
echo "<p><a href='/cbtonline/admin/'>← Kembali ke Admin</a></p>";
echo "<style>body{font-family:sans-serif;padding:40px;}a{color:#0b63e5;}</style>";