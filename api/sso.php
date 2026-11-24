<?php
/**
 * CBT Online — SSO API Endpoint
 *
 * Endpoint ini dipanggil oleh Veyra Educate untuk:
 *   1. Membuat tenant baru otomatis saat sekolah mendaftar di Veyra
 *   2. Membuat akun admin/operator untuk tenant tersebut
 *   3. Menghasilkan SSO link agar user langsung masuk tanpa login ulang
 *
 * Request body (JSON):
 *   {
 *     "action": "create_tenant",
 *     "school_name": "SMK Telkom Indonesia",
 *     "school_address": "Jl. Contoh No. 1",
 *     "admin_email": "admin@sekolah.sch.id",
 *     "admin_name": "Budi Santoso",
 *     "admin_password": "rahasia123",
 *     "package": "basic|pro|enterprise"
 *   }
 */

require_once __DIR__ . '/bootstrap.php';

$ssoConfig = file_exists(__DIR__ . '/../config/sso.php') ? (require __DIR__ . '/../config/sso.php') : [];
$ssoSecret = defined('CBT_SSO_SECRET') ? CBT_SSO_SECRET : ($ssoConfig['sso_secret'] ?? '');

/**
 * Generate cryptographically secure SSO token
 */
function generateSsoToken(int $userId, int $tenantId, string $role): string {
    global $ssoSecret;
    $payload = base64_encode(json_encode([
        'u' => $userId,
        't' => $tenantId,
        'r' => $role,
        'i' => time(),
        'e' => time() + 300, // token valid 5 menit
    ]));
    // Sign with HMAC using app key
    $signature = hash_hmac('sha256', $payload, $ssoSecret);
    return $payload . '.' . $signature;
}

/**
 * Verify and decode SSO token
 */
function decodeSsoToken(string $token): ?array {
    global $ssoSecret;
    if ($ssoSecret === '') return null;
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;

    [$payload, $signature] = $parts;
    $expected = hash_hmac('sha256', $payload, $ssoSecret);
    if (!hash_equals($expected, $signature)) return null;

    $data = json_decode(base64_decode($payload), true);
    if (!$data || !isset($data['e']) || $data['e'] < time()) return null;

    return $data;
}

header('Content-Type: application/json; charset=utf-8');

// Allow Veyra domain via CORS
$allowedOrigins = [
    'https://veyracreative.site',
    'https://www.veyracreative.site',
    'http://veyracreative.localhost',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: {$origin}");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-SSO-Secret');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Allow POST (and GET for tenant_stats)
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']]);
    exit;
}

// Get request body or query params
$rawBody = file_get_contents('php://input');
$jsonInput = ($rawBody !== false && trim($rawBody) !== '') ? json_decode($rawBody, true) : [];
$input = is_array($jsonInput) ? array_merge($_GET, $jsonInput) : $_GET;
if (empty($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ['message' => 'Invalid request parameters']]);
    exit;
}

// Verify caller authorization via X-SSO-Secret, Authorization: Bearer, or sso_secret payload
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$bearerToken = '';
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $bearerToken = $matches[1];
}
$providedSecret = (string)($_SERVER['HTTP_X_SSO_SECRET'] ?? $bearerToken ?: ($input['sso_secret'] ?? ''));
if ($ssoSecret === '' || $providedSecret === '' || !hash_equals($ssoSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['message' => 'Unauthorized: SSO secret tidak valid atau tidak disertakan.']]);
    exit;
}

$action = $input['action'] ?? '';

// =====================================================
// ACTION: create_tenant
// =====================================================
if ($action === 'create_tenant') {
    // Validate required fields
    $required = ['school_name', 'admin_email', 'admin_password', 'package'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['message' => "Field '{$field}' wajib diisi"]]);
            exit;
        }
    }

    $schoolName   = trim($input['school_name']);
    $schoolAddress= trim($input['school_address'] ?? '');
    $adminEmail   = trim(strtolower($input['admin_email']));
    $adminName    = trim($input['admin_name'] ?? $adminEmail);
    $adminPass    = $input['admin_password'];
    $package      = in_array($input['package'], ['basic', 'pro', 'enterprise']) ? $input['package'] : 'basic';

    // Generate unique slug & subdomain
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($schoolName)));
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = rtrim($slug, '-');

    // Make subdomain safe (max 20 chars, alphanumeric + hyphen)
    $subdomain = strtolower(preg_replace('/[^a-z0-9-]/', '', substr($slug, 0, 20)));
    $subdomain = rtrim($subdomain, '-');

    // Check uniqueness
    $existing = Database::fetch("SELECT id FROM tenants WHERE slug = ? OR subdomain = ?", [$slug, $subdomain]);
    if ($existing) {
        $slug .= '-' . random_int(100, 999);
        $subdomain .= '-' . random_int(100, 999);
    }

    // Check email uniqueness
    $emailExists = Database::fetch("SELECT id FROM users WHERE email = ?", [$adminEmail]);
    if ($emailExists) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => ['message' => "Email '{$adminEmail}' sudah terdaftar di CBT"]]);
        exit;
    }

    try {
        // Insert tenant
        $tenantId = Database::insert('tenants', [
            'name'       => $schoolName,
            'code'       => strtoupper(substr($slug, 0, 4)) . random_int(1000, 9999),
            'slug'       => $slug,
            'subdomain'  => $subdomain,
            'address'    => $schoolAddress,
            'package'    => $package,
            'student_quota' => $package === 'basic' ? 100 : ($package === 'pro' ? 500 : 0),
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Insert admin user
        $adminUserId = Database::insert('users', [
            'tenant_id' => $tenantId,
            'name'      => $adminName,
            'username'  => $slug,
            'email'     => $adminEmail,
            'password'  => password_hash($adminPass, PASSWORD_DEFAULT),
            'role'      => 'admin',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Generate SSO token for immediate login
        $ssoToken = generateSsoToken($adminUserId, $tenantId, 'admin');
        $ssoUrl = Helper::baseUrl("sso.php?token={$ssoToken}");

        // Return response
        echo json_encode([
            'success' => true,
            'data' => [
                'tenant_id'  => $tenantId,
                'slug'       => $slug,
                'subdomain'  => $subdomain,
                'cbt_url'    => "https://{$subdomain}.veyracreative.site",
                'sso_url'    => $ssoUrl,
                'credentials' => [
                    'username' => $slug,
                    'email'    => $adminEmail,
                ],
                'message' => "Tenant berhasil dibuat. Admin bisa langsung masuk via SSO.",
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Gagal membuat tenant: ' . $e->getMessage()]
        ]);
    }
    exit;
}

// =====================================================
// ACTION: get_sso_url (for existing tenant)
// =====================================================
if ($action === 'get_sso_url') {
    $tenantSlug = trim($input['tenant_slug'] ?? '');
    $adminEmail = trim(strtolower($input['admin_email'] ?? ''));

    if (empty($tenantSlug) || empty($adminEmail)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['message' => 'tenant_slug dan admin_email wajib diisi']]);
        exit;
    }

    $tenant = Database::fetch("SELECT * FROM tenants WHERE slug = ? AND is_active = 1", [$tenantSlug]);
    if (!$tenant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['message' => 'Tenant tidak ditemukan']]);
        exit;
    }

    $user = Database::fetch(
        "SELECT id, username, name FROM users WHERE tenant_id = ? AND email = ? AND role IN ('admin', 'teacher') AND is_active = 1 LIMIT 1",
        [$tenant['id'], $adminEmail]
    );

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['message' => 'Admin tidak ditemukan di tenant ini']]);
        exit;
    }

    $ssoToken = generateSsoToken((int)$user['id'], (int)$tenant['id'], $user['role']);
    $ssoUrl = Helper::baseUrl("sso.php?token={$ssoToken}");

    echo json_encode([
        'success' => true,
        'data' => [
            'sso_url' => $ssoUrl,
            'tenant_name' => $tenant['name'],
            'subdomain' => $tenant['subdomain'],
        ]
    ]);
    exit;
}

// =====================================================
// ACTION: tenant_stats (Real-time sync to Veyra Educate Dashboard)
// =====================================================
if ($action === 'tenant_stats') {
    $tenantId   = (int)($input['tenant_id'] ?? $input['school_id'] ?? 0);
    $tenantCode = trim((string)($input['tenant_code'] ?? $input['school_code'] ?? ''));
    $tenantSlug = trim((string)($input['tenant_slug'] ?? $input['school_slug'] ?? ''));
    $tenantName = trim((string)($input['tenant_name'] ?? $input['school_name'] ?? ''));

    $tenant = null;
    if ($tenantCode !== '') {
        $tenant = Database::fetch("SELECT * FROM tenants WHERE code = ? AND is_active = 1", [$tenantCode]);
    }
    if (!$tenant && $tenantSlug !== '') {
        $tenant = Database::fetch("SELECT * FROM tenants WHERE slug = ? AND is_active = 1", [$tenantSlug]);
    }
    if (!$tenant && $tenantName !== '') {
        $tenant = Database::fetch("SELECT * FROM tenants WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1", [$tenantName]);
    }
    if (!$tenant && $tenantId > 0) {
        $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ? AND is_active = 1", [$tenantId]);
    }

    if (!$tenant) {
        echo json_encode([
            'success' => true,
            'data' => [
                'tenant_found'           => false,
                'tenant'                 => null,
                'students_active'        => 0,
                'students_total'         => 0,
                'student_quota'          => 500,
                'classes_count'          => 0,
                'teachers_count'         => 0,
                'subjects_count'         => 0,
                'question_banks_count'   => 0,
                'questions_count'        => 0,
                'active_exams_count'     => 0,
                'ongoing_sessions_count' => 0,
                'completed_sessions_count' => 0,
                'active_exams'           => [],
                'synced_at'              => date('c'),
            ],
        ]);
        exit;
    }

    $tid = (int)$tenant['id'];
    $studentsActive = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ? AND is_active = 1", [$tid]);
    $studentsTotal  = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ?", [$tid]);
    $classesCount   = (int)Database::fetchColumn("SELECT COUNT(*) FROM classes WHERE tenant_id = ?", [$tid]);
    $teachersCount  = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role IN ('admin', 'teacher', 'proctor') AND is_active = 1", [$tid]);
    $subjectsCount  = (int)Database::fetchColumn("SELECT COUNT(*) FROM subjects WHERE tenant_id = ?", [$tid]);
    $banksCount     = (int)Database::fetchColumn("SELECT COUNT(*) FROM question_banks WHERE tenant_id = ?", [$tid]);
    $questionsCount = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM questions q JOIN question_banks qb ON q.bank_id = qb.id WHERE qb.tenant_id = ?",
        [$tid]
    );
    $activeExamsCount       = (int)Database::fetchColumn("SELECT COUNT(*) FROM exams WHERE tenant_id = ? AND is_active = 1", [$tid]);
    $ongoingSessionsCount   = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'in_progress'", [$tid]);
    $completedSessionsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM exam_sessions WHERE tenant_id = ? AND status = 'completed'", [$tid]);

    $activeExams = Database::fetchAll(
        "SELECT e.id, e.title, e.token, e.duration_minutes, e.start_time, e.end_time, e.is_active,
                qb.title AS bank_title,
                s.name AS subject_name,
                COALESCE(c.name, 'Semua Kelas') AS class_name,
                (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id AND es.status = 'in_progress') AS in_progress_count,
                (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id AND es.status = 'completed') AS completed_count
         FROM exams e
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         LEFT JOIN classes c ON e.class_id = c.id
         WHERE e.tenant_id = ? AND e.is_active = 1
         ORDER BY e.start_time ASC, e.id DESC
         LIMIT 5",
        [$tid]
    );

    echo json_encode([
        'success' => true,
        'data' => [
            'tenant_found' => true,
            'tenant' => [
                'id'            => $tid,
                'code'          => $tenant['code'] ?? '',
                'slug'          => $tenant['slug'] ?? '',
                'name'          => $tenant['name'] ?? '',
                'package'       => $tenant['package'] ?? 'semester',
                'student_quota' => (int)($tenant['student_quota'] ?? 500),
            ],
            'students_active'          => $studentsActive,
            'students_total'           => $studentsTotal,
            'student_quota'            => (int)($tenant['student_quota'] ?? 500),
            'classes_count'            => $classesCount,
            'teachers_count'           => $teachersCount,
            'subjects_count'           => $subjectsCount,
            'question_banks_count'     => $banksCount,
            'questions_count'          => $questionsCount,
            'active_exams_count'       => $activeExamsCount,
            'ongoing_sessions_count'   => $ongoingSessionsCount,
            'completed_sessions_count' => $completedSessionsCount,
            'active_exams'             => $activeExams,
            'synced_at'                => date('c'),
        ],
    ]);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['success' => false, 'error' => ['message' => 'Action tidak dikenal. Gunakan: create_tenant, get_sso_url, atau tenant_stats']]);
