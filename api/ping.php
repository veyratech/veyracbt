<?php
/**
 * API endpoint: Session ping – refreshes last_activity to prevent timeout
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!Auth::check()) {
    Helper::jsonResponse(['error' => 'Unauthorized'], 401);
}

Auth::refreshActivity();
Helper::jsonResponse(['ok' => true]);
