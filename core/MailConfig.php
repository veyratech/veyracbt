<?php
/**
 * Mail Config Loader
 */

class MailConfig {
    private static ?array $config = null;

    public static function get(string $key = null): mixed {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/mail.php';
        }
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }
}
