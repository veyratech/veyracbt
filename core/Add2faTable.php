<?php
/**
 * Migration: tambah kolom 2FA, session timeout & tenant SSO ke tabel yang ada
 * Jalankan: php core/Add2faTable.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Skrip migrasi hanya dapat dijalankan melalui CLI.');
}

require_once __DIR__ . '/Database.php';

$alter = [
    "ALTER TABLE `tenants` MODIFY COLUMN `package` VARCHAR(50) NOT NULL DEFAULT 'pro'",
    "ALTER TABLE `tenants` ADD COLUMN `logo` VARCHAR(500) NULL AFTER `logo_url`",
    "ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) NULL, ADD COLUMN `totp_verified` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `last_activity` DATETIME NULL",
    "ALTER TABLE `students` ADD COLUMN `totp_secret` VARCHAR(64) NULL, ADD COLUMN `totp_verified` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `last_activity` DATETIME NULL",
    "ALTER TABLE `superadmins` ADD COLUMN `last_activity` DATETIME NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `ip_address` VARCHAR(45) NULL",
];

foreach ($alter as $sql) {
    try {
        Database::query($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false || stripos($e->getMessage(), 'Duplicate column') !== false) {
            echo "SKIP (sudah ada): " . $e->getMessage() . "\n";
        } else {
            echo "ERR: " . $e->getMessage() . "\n";
        }
    }
}
echo "Migration selesai.\n";
