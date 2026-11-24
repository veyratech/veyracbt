<?php
/**
 * Cron Job: Daily Backup of CBT Database
 *
 * Jalankan secara berkala via crontab atau Windows Task Scheduler.
 * Contoh crontab (Linux):
 *   0 2 * * * /usr/bin/php -q /path/to/cbtonline/cron/daily-backup.php >> /var/log/cbt_backup.log 2>&1
 *
 * Contoh command (Windows Task Scheduler):
 *   C:\xampp\php\php.exe -q C:\xampp\htdocs\cbtonline\cron\daily-backup.php
 */

// Nonaktifkan output buffering agar log bisa terbaca
if (ob_get_level()) ob_end_flush();

// Disable error display for CLI
ini_set('display_errors', '0');
error_reporting(0);

// Load dependencies
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';

$backupDir = __DIR__ . '/../storage/backups';
$logFile = __DIR__ . '/../storage/logs/backup.log';

// Buat direktori backup jika belum ada
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

function writeLog(string $message): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

writeLog("=== BACKUP START ===");

try {
    // Dapatkan semua tenant
    $tenants = Database::fetchAll("SELECT id, code, name FROM tenants WHERE is_active = 1 ORDER BY id");
    
    $totalBackedUp = 0;
    $errors = [];
    
    foreach ($tenants as $tenant) {
        $tenantId = (int)$tenant['id'];
        $tenantCode = $tenant['code'];
        $timestamp = date('Ymd_His');
        $filename = "cbt_backup_{$tenantCode}_{$timestamp}.sql";
        $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;
        
        writeLog("Processing tenant: {$tenant['name']} (ID: {$tenantId})");
        
        try {
            // Ekspor database untuk tenant ini
            $pdo = Database::getConnection();
            
            // Ambil daftar tabel
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            
            $sql = "-- CBT Online Database Backup\n";
            $sql .= "-- Tenant: {$tenant['name']} ({$tenantCode})\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
            
            foreach ($tables as $table) {
                // Skip tables yang tidak perlu di-backup per-tenant
                if (in_array($table, ['tenants', 'migrations'])) continue;
                
                $sql .= "-- Table: {$table}\n";
                
                // Drop table jika ada
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                
                // Create table structure
                $createSql = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                if (isset($createSql['Create Table'])) {
                    $sql .= $createSql['Create Table'] . ";\n\n";
                }
                
                // Export data dengan filter tenant_id
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $values = [];
                    foreach ($rows as $row) {
                        // Filter by tenant_id jika kolom ada
                        $filteredRow = [];
                        foreach ($row as $key => $value) {
                            if ($key === 'tenant_id' && $value != $tenantId) {
                                continue; // Lewati baris untuk tenant lain
                            }
                            $filteredRow[$key] = $value;
                        }
                        if (!empty($filteredRow)) {
                            $cols = array_keys($filteredRow);
                            $escapedValues = array_map(function($v) use ($pdo) {
                                return $v === null ? 'NULL' : $pdo->quote($v);
                            }, array_values($filteredRow));
                            $values[] = '(' . implode(', ', $escapedValues) . ')';
                        }
                    }
                    
                    if (!empty($values)) {
                        $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', array_keys($rows[0])) . "`) VALUES\n";
                        $sql .= implode(",\n", $values) . ";\n\n";
                    }
                }
                $sql .= "\n";
            }
            
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            // Simpan file backup
            if (file_put_contents($filepath, $sql, LOCK_EX) !== false) {
                $size = filesize($filepath);
                writeLog("SUCCESS: {$filename} ({$size} bytes)");
                $totalBackedUp++;
            } else {
                $errorMsg = "Gagal menulis file: {$filename}";
                writeLog("ERROR: {$errorMsg}");
                $errors[] = $errorMsg;
            }
            
        } catch (\Exception $e) {
            $errorMsg = "Tenant {$tenant['name']} (ID: {$tenantId}): " . $e->getMessage();
            writeLog("ERROR: {$errorMsg}");
            $errors[] = $errorMsg;
        }
    }
    
    // Cleanup backup lama (>30 hari)
    $cutoffDate = strtotime('-30 days');
    $deleted = 0;
    foreach (glob($backupDir . '/cbt_backup_*.sql') as $file) {
        if (filemtime($file) < $cutoffDate) {
            @unlink($file);
            $deleted++;
        }
    }
    if ($deleted > 0) {
        writeLog("Cleaned up {$deleted} old backup(s) older than 30 days");
    }
    
    writeLog("=== BACKUP COMPLETE ===");
    writeLog("Total backed up: {$totalBackedUp} tenant(s)");
    if (!empty($errors)) {
        writeLog("Errors encountered: " . count($errors));
        exit(2);
    }
    exit(0);
    
} catch (\Exception $e) {
    writeLog("FATAL ERROR: " . $e->getMessage());
    exit(2);
}
