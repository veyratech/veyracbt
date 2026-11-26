<?php
/**
 * API Bootstrap – CORS, JSON envelope, auth
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/session-init.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

header('Content-Type: application/json; charset=utf-8');
// Allow only same-origin requests to prevent cross-site data theft
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedHost = parse_url((string)(Helper::baseUrl() ?: ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');
if ($origin && preg_match('/^[a-zA-Z0-9._-]+$/', (string)$origin) && (parse_url($origin, PHP_URL_HOST) === $allowedHost)) {
    header("Access-Control-Allow-Origin: " . preg_replace('/[^\x20-\x7e]/', '', $origin));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Push a JSON envelope and exit.
 */
function apiResponse(array $data, int $status = 200, ?array $meta = null): void {
    $payload = [
        'success' => true,
        'data'    => $data,
    ];
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    Helper::jsonResponse($payload, $status);
}

/**
 * Push an error envelope and exit.
 */
function apiError(string $message, int $status = 400, array $details = []): void {
    $payload = [
        'success' => false,
        'error'   => [
            'message' => $message,
            'status'  => $status,
        ],
    ];
    if (!empty($details)) {
        $payload['error']['details'] = $details;
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/**
 * Ensure the request is authenticated (session or Bearer token).
 * Sets $GLOBALS['apiUser'] and $GLOBALS['apiTenantId'] on success.
 */
function apiAuth(): void {
    $user = Auth::user();

    if (!$user) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $user = Auth::authenticateByToken($matches[1]);
        }
    }

    if (!$user) {
        apiError('Unauthorized. Provide a valid Bearer token or session.', 401);
    }

    $GLOBALS['apiUser']       = $user;
    $GLOBALS['apiTenantId']   = (int)($user['tenant_id'] ?? 0);
    $GLOBALS['apiUserRole']   = $user['role'] ?? 'unknown';
    $GLOBALS['apiUserId']     = (int)$user['id'];
}

/**
 * Require the caller to hold one of the given roles.
 */
function apiRequireRole(string ...$roles): void {
    global $apiUserRole;
    if (!in_array($apiUserRole, $roles, true)) {
        apiError('Forbidden. Required role(s): ' . implode(', ', $roles), 403);
    }
}

/**
 * Enforce tenant isolation for tenant-scoped resources.
 */
function apiRequireTenant(): void {
    global $apiTenantId;
    if ($apiTenantId <= 0) {
        apiError('Tenant context missing.', 400);
    }
    Tenant::setTenant(Database::fetch("SELECT * FROM tenants WHERE id = ?", [$apiTenantId]));
}
