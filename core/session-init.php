<?php
/**
 * Cross-platform session save path initialization
 * Set session.save_path to project's .sessions/ directory before session_start()
 *
 * Usage: require_once __DIR__ . '/core/session-init.php';
 * Place this require BEFORE any session_start() call
 */

if (session_status() === PHP_SESSION_NONE) {
    $sessionDir = dirname(__DIR__) . '/.sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }
    ini_set('session.save_path', $sessionDir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}
