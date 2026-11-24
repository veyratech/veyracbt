<?php
/**
 * Auth Manager (Multi-Role: Superadmin, Tenant Admin / Guru, Siswa)
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/Helper.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/../config/session.php';

class Auth {

    private static function rateKey(string $scope, string $username): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        return $scope . '|' . $ip . '|' . strtolower($username);
    }

    private static function assertNotLocked(string $key): void {
        $lock = Helper::loginRateStatus($key);
        if ($lock > 0) {
            http_response_code(429);
            $minutes = (int)floor($lock / 60);
            $seconds = $lock % 60;
            if ($minutes > 0) {
                $msg = "Akun dikunci sementara sekitar {$minutes} menit {$seconds} detik lagi.";
            } else {
                $msg = "Akun dikunci sementara sekitar {$seconds} detik lagi.";
            }
            die('Terlalu banyak percobaan gagal. ' . $msg . ' Coba lagi nanti.');
        }
    }

    public static function getTimeout(): int {
        $timeoutConfig = require __DIR__ . '/../config/session.php';
        $role = self::role();
        if ($role && isset($timeoutConfig[$role])) {
            return (int)$timeoutConfig[$role];
        }
        return 1800;
    }

    public static function getWarningBefore(): int {
        $timeoutConfig = require __DIR__ . '/../config/session.php';
        return (int)($timeoutConfig['warning_before'] ?? 120);
    }

    public static function refreshActivity(bool $forceDbSync = false): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!self::check()) return;
        $now = time();
        $_SESSION['last_activity'] = $now;

        $lastDbSync = (int)($_SESSION['last_db_activity_sync'] ?? 0);
        if (!$forceDbSync && ($now - $lastDbSync) < 60) {
            return;
        }
        $_SESSION['last_db_activity_sync'] = $now;

        $role = self::role();
        $id = self::id();
        $tenantId = (int)($_SESSION['auth_user']['tenant_id'] ?? 0);
        if ($role === 'superadmin' && $id) {
            Database::query("UPDATE superadmins SET last_activity = NOW() WHERE id = ?", [$id]);
        } elseif ($role === 'student' && $id) {
            Database::query("UPDATE students SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        } elseif (in_array($role, ['admin', 'teacher']) && $id) {
            Database::query("UPDATE users SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        }
    }

    public static function checkTimeout(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!self::check()) return;
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if ($lastActivity === 0) {
            self::refreshActivity();
            return;
        }
        $elapsed = time() - $lastActivity;
        $timeout = self::getTimeout();
        if ($elapsed >= $timeout) {
            $role = self::role() ?? 'student';
            self::logout();
            Helper::setFlash('warning', 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan login kembali.');
            if ($role === 'superadmin') {
                Helper::redirect('../superadmin/login.php');
            } elseif (in_array($role, ['admin', 'teacher'])) {
                Helper::redirect('../admin/login.php');
            } else {
                Helper::redirect('../index.php');
            }
        }
        self::refreshActivity();
    }

    public static function is2faRequired(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['pending_2fa_user'])) return true;
        if (empty($_SESSION['auth_user'])) return false;
        if (!empty($_SESSION['2fa_verified'])) return false;
        $role = self::role();
        $id = self::id();
        if ($role === 'superadmin' && $id) {
            $user = Database::fetch("SELECT totp_verified FROM superadmins WHERE id = ?", [$id]);
            return !empty($user['totp_verified']);
        }
        if (in_array($role, ['admin', 'teacher']) && $id) {
            $tenantId = (int)($_SESSION['auth_user']['tenant_id'] ?? 0);
            $user = Database::fetch("SELECT totp_verified FROM users WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
            return !empty($user['totp_verified']);
        }
        if ($role === 'student' && $id) {
            $tenantId = (int)($_SESSION['auth_user']['tenant_id'] ?? 0);
            $user = Database::fetch("SELECT totp_verified FROM students WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
            return !empty($user['totp_verified']);
        }
        return false;
    }

    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                @ini_set('session.cookie_httponly', '1');
                @ini_set('session.cookie_samesite', 'Lax');
                @ini_set('session.use_only_cookies', '1');
                if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                    @ini_set('session.cookie_secure', '1');
                }
            }
            session_start();
        }
        return !empty($_SESSION['auth_user']);
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        return $_SESSION['auth_user'];
    }

    public static function id(): ?int {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function role(): ?string {
        $user = self::user();
        return $user ? $user['role'] : null;
    }

    public static function loginSuperadmin(string $username, string $password): bool {
        $key = self::rateKey('sa', $username);
        self::assertNotLocked($key);

        $user = Database::fetch("SELECT * FROM superadmins WHERE username = ? OR email = ? LIMIT 1", [$username, $username]);
        if ($user && password_verify($password, $user['password'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            session_regenerate_id(true);
            Helper::loginRateClear($key);
            $_SESSION['auth_user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'name'     => $user['name'],
                'email'    => $user['email'],
                'role'     => 'superadmin'
            ];
            $_SESSION['last_activity'] = time();
            Database::query("UPDATE superadmins SET last_activity = NOW() WHERE id = ?", [$user['id']]);
            if (!empty($user['totp_verified'])) {
                $_SESSION['pending_2fa_user'] = $_SESSION['auth_user'];
                unset($_SESSION['auth_user']);
                return true;
            }
            return true;
        }
        Helper::loginRateFail($key);
        return false;
    }

    public static function loginTenantUser(int $tenantId, string $username, string $password): bool {
        $key = self::rateKey('tu' . $tenantId, $username);
        self::assertNotLocked($key);

        $user = Database::fetch(
            "SELECT * FROM users WHERE tenant_id = ? AND (username = ? OR email = ?) AND is_active = 1 LIMIT 1",
            [$tenantId, $username, $username]
        );
        if ($user && password_verify($password, $user['password'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            session_regenerate_id(true);
            Helper::loginRateClear($key);
            $_SESSION['auth_user'] = [
                'id'        => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'username'  => $user['username'],
                'name'      => $user['name'],
                'role'      => $user['role'],
                'email'     => $user['email']
            ];
            $_SESSION['last_activity'] = time();
            Database::query("UPDATE users SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$user['id'], $tenantId]);
            if (!empty($user['totp_verified'])) {
                $_SESSION['pending_2fa_user'] = $_SESSION['auth_user'];
                unset($_SESSION['auth_user']);
                return true;
            }
            return true;
        }
        Helper::loginRateFail($key);
        return false;
    }

    public static function loginStudent(int $tenantId, string $nis, string $password): bool {
        $key = self::rateKey('st' . $tenantId, $nis);
        self::assertNotLocked($key);

        $student = Database::fetch(
            "SELECT s.*, c.name as class_name 
             FROM students s 
             LEFT JOIN classes c ON s.class_id = c.id 
             WHERE s.tenant_id = ? AND s.nis = ? AND s.is_active = 1",
            [$tenantId, $nis]
        );
        if ($student && password_verify($password, $student['password'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            session_regenerate_id(true);
            Helper::loginRateClear($key);
            $_SESSION['auth_user'] = [
                'id'         => $student['id'],
                'tenant_id'  => $student['tenant_id'],
                'nis'        => $student['nis'],
                'name'       => $student['name'],
                'class_id'   => $student['class_id'],
                'class_name' => $student['class_name'] ?? 'Umum',
                'role'       => 'student'
            ];
            $_SESSION['last_activity'] = time();
            Database::query("UPDATE students SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$student['id'], $tenantId]);
            if (!empty($student['totp_verified'])) {
                $_SESSION['pending_2fa_user'] = $_SESSION['auth_user'];
                unset($_SESSION['auth_user']);
                return true;
            }
            return true;
        }
        Helper::loginRateFail($key);
        return false;
    }

    public static function requireSuperadmin(string $redirectUrl = '../superadmin/login.php'): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        // Pulihkan sesi superadmin jika kembali dari mode impersonate
        if (isset($_GET['exit_impersonate']) && !empty($_SESSION['impersonator_superadmin'])) {
            $_SESSION['auth_user'] = $_SESSION['impersonator_superadmin'];
            unset($_SESSION['impersonator_superadmin']);
            Helper::setFlash('success', 'Berhasil kembali ke Superadmin Control Center.');
            Helper::redirect('../superadmin/index.php');
        }
        if (!self::check() || self::role() !== 'superadmin') {
            header("Location: {$redirectUrl}");
            exit;
        }
        self::enforce2Fa();
    }

    public static function requireTenantAdmin(string $redirectUrl = '../admin/login.php'): void {
        if (!self::check() || !in_array(self::role(), ['admin', 'teacher'])) {
            header("Location: {$redirectUrl}");
            exit;
        }
        $user = self::user();
        if ($user['tenant_id'] != Tenant::id()) {
            $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [$user['tenant_id']]);
            if ($tenant) {
                Tenant::setTenant($tenant);
            }
        }
        self::enforce2Fa();
    }

    public static function requireStudent(string $redirectUrl = '../index.php', bool $allowAdminPreview = false): void {
        if (!self::check()) {
            header("Location: {$redirectUrl}");
            exit;
        }
        $role = self::role();
        if ($role !== 'student') {
            if ($allowAdminPreview && in_array($role, ['admin', 'teacher', 'superadmin'])) {
                // Izinkan akses pratinjau untuk peran admin / guru / superadmin
            } else {
                header("Location: {$redirectUrl}");
                exit;
            }
        }
        self::enforce2Fa();
        $user = self::user();
        // Force password change if using default credentials
        if (self::isDefaultPassword($user['id'], $user['role'])) {
            $_SESSION['force_password_change'] = true;
        }
        if ($user && isset($user['tenant_id']) && $user['tenant_id'] != Tenant::id()) {
            $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [$user['tenant_id']]);
            if ($tenant) {
                Tenant::setTenant($tenant);
            }
        }
    }

    public static function isDefaultSuperadminPassword(?int $id = null): bool {
        $id = $id ?? self::id();
        if (!$id) return false;
        $sa = Database::fetch("SELECT password FROM superadmins WHERE id = ?", [$id]);
        if (!$sa) return false;
        return password_verify('admin123', $sa['password']);
    }

    public static function isDefaultTenantAdminPassword(?int $userId = null, ?int $tenantId = null): bool {
        $user = self::user();
        $uid = $userId ?? ($user['id'] ?? null);
        $tid = $tenantId ?? ($user['tenant_id'] ?? null);
        if (!$uid || !$tid) return false;
        $row = Database::fetch("SELECT password FROM users WHERE id = ? AND tenant_id = ?", [$uid, $tid]);
        if (!$row) return false;
        return password_verify('password', $row['password']) || password_verify('admin123', $row['password']);
    }

    /**
     * Check if current user has default password and needs to change it
     */
    public static function isDefaultPassword(?int $userId = null, ?string $role = null): bool {
        $user = self::user();
        $uid = $userId ?? ($user['id'] ?? null);
        $role = $role ?? self::role();
        if (!$uid || !$role) return false;

        if ($role === 'superadmin') {
            $row = Database::fetch("SELECT password FROM superadmins WHERE id = ?", [$uid]);
            if ($row) return password_verify('admin123', $row['password']);
        } elseif ($role === 'student') {
            $row = Database::fetch("SELECT password FROM students WHERE id = ?", [$uid]);
            if ($row) return password_verify('password', $row['password']);
        } else {
            $tid = $user['tenant_id'] ?? null;
            if ($tid) {
                $row = Database::fetch("SELECT password FROM users WHERE id = ? AND tenant_id = ?", [$uid, $tid]);
                if ($row) return password_verify('password', $row['password']) || password_verify('admin123', $row['password']);
            }
        }
        return false;
    }

    public static function enforce2Fa(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (self::is2faRequired() && empty($_SESSION['2fa_verified'])) {
            $role = self::role();
            if ($role === 'superadmin') {
                Helper::redirect('../superadmin/totp-verify.php');
            } elseif ($role === 'student') {
                Helper::redirect('../student/totp-verify.php');
            } else {
                Helper::redirect('../admin/totp-verify.php');
            }
        }
    }

    public static function authenticateByToken(string $token): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($token)) return null;

        $tableExists = Database::fetch("SHOW TABLES LIKE 'api_tokens'");
        if (!$tableExists) return null;

        $record = Database::fetch(
            "SELECT at.*, u.tenant_id, u.role, u.name, u.username, u.email
             FROM api_tokens at
             JOIN users u ON at.user_id = u.id
             WHERE at.token = ? AND at.is_active = 1 AND u.is_active = 1",
            [$token]
        );

        if (!$record) {
            $record = Database::fetch(
                "SELECT at.*, u.tenant_id, u.role, u.name, u.username, u.email
                 FROM api_tokens at
                 JOIN students u ON at.user_id = u.id
                 WHERE at.token = ? AND at.is_active = 1 AND u.is_active = 1",
                [$token]
            );
        }

        if (!$record) return null;

        $user = [
            'id'        => (int)$record['user_id'],
            'tenant_id' => (int)$record['tenant_id'],
            'username'  => $record['username'] ?? '',
            'name'      => $record['name'],
            'role'      => $record['role'] ?? 'api',
            'email'     => $record['email'] ?? null,
        ];

        $_SESSION['auth_user'] = $user;
        $_SESSION['last_activity'] = time();
        return $user;
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
