<?php
/**
 * Database Singleton & Query Helper (PHP Native PDO)
 */

// Set timezone ke Asia/Jakarta (WIB, UTC+7) untuk semua operasi waktu CBT di Indonesia
if (!defined('CBT_TIMEZONE')) {
    define('CBT_TIMEZONE', 'Asia/Jakarta');
}
if (function_exists('date_default_timezone_set') && date_default_timezone_get() !== CBT_TIMEZONE) {
    @date_default_timezone_set(CBT_TIMEZONE);
}

class Database {

    private static ?PDO $instance = null;

    /**
     * Escape identifier (table/column names) dengan backtick.
     * Menolak karakter berbahaya untuk mencegah SQL injection via identifier.
     */
    public static function escapeColumn(string $col): string {
        return '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $col) . '`';
    }

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/database.php';
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], $options);
                // Sinkronkan timezone MySQL dengan timezone aplikasi (WIB = UTC+7)
                self::$instance->exec("SET time_zone = '+07:00'");
            } catch (PDOException $e) {
                // If database doesn't exist, we might be running installer
                $installUrl = class_exists('Helper') ? Helper::baseUrl('install.php') : '/cbtonline/install.php';
                die("Koneksi Database Gagal: " . htmlspecialchars($e->getMessage()) . "<br><br><a href='" . htmlspecialchars($installUrl) . "' style='padding: 8px 16px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 6px; font-family: sans-serif; display: inline-block;'>Jalankan Auto Installer Disini</a>");
            }
        }
        return self::$instance;
    }

    /**
     * Helper eksekusi query dengan parameter
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Ambil 1 baris
     */
    public static function fetch(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Ambil semua baris
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Ambil 1 kolom nilai (misal COUNT(*))
     */
    public static function fetchColumn(string $sql, array $params = []): mixed {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function fetchValue(string $sql, array $params = []): mixed {
        return self::fetchColumn($sql, $params);
    }

    /**
     * Insert dan kembalikan ID baru
     */
    public static function insert(string $table, array $data): int {
        if (empty($data)) {
            throw new InvalidArgumentException('Data insert tidak boleh kosong');
        }
        $safeTable = self::escapeColumn($table);
        $columns = implode(', ', array_map(fn($k) => self::escapeColumn($k), array_keys($data)));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO {$safeTable} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, $data);
        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * Cek apakah tabel ada di database
     */
    public static function tableExists(string $table): bool {
        try {
            // SHOW TABLES LIKE tidak mendukung bound parameter di MySQL/MariaDB,
            // gunakan information_schema yang aman dari SQL injection untuk nama tabel tetap
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            if ($safeTable !== $table) return false;
            $row = self::getConnection()->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$safeTable' LIMIT 1"
            )->fetch();
            return $row !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get PDO instance untuk raw access (misal SHOW VARIABLES)
     */
    public static function pdo(): PDO {
        return self::getConnection();
    }

    public static function lastInsertId(): string {
        return self::getConnection()->lastInsertId();
    }

    /**
     * Update data berdasarkan kondisi
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        if (empty($data)) return 0;
        $safeTable = self::escapeColumn($table);
        $fields = [];
        $params = [];
        foreach ($data as $key => $val) {
            $safeKey = self::escapeColumn($key);
            $fields[] = "{$safeKey} = :set_{$key}";
            $params["set_{$key}"] = $val;
        }
        $fieldsStr = implode(', ', $fields);

        // Convert positional ? in WHERE to named placeholders to avoid mixed-param error
        $idx = 0;
        $namedWhere = preg_replace_callback('/\?/', function() use (&$idx) {
            return ':w' . $idx++;
        }, $where);
        foreach ($whereParams as $i => $p) {
            $params['w' . $i] = $p;
        }

        $sql = "UPDATE {$safeTable} SET {$fieldsStr} WHERE {$namedWhere}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete data
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $safeTable = self::escapeColumn($table);
        $sql = "DELETE FROM {$safeTable} WHERE {$where}";
        return self::query($sql, $params)->rowCount();
    }
}
