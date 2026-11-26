<?php
/**
 * SSO Callback Handler for Veyra Ecosystem – CBT Online
 * 
 * Menerima redirect dari Veyra Utama: {cbt_url}/sso/callback.php?token={jwt_token}
 * Memvalidasi token, melakukan auto-provisioning jika diperlukan, 
 * dan mengaktifkan sesi CBT internal (Auth & Tenant) secara aman.
 */

declare(strict_types=1);

require_once __DIR__ . '/../sso_client.php';
require_once __DIR__ . '/../core/session-init.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // 1. Ambil token dari query parameter
    $token = trim((string)($_GET['token'] ?? ''));

    if (!$token) {
        Helper::setFlash('danger', 'Token autentikasi SSO tidak ditemukan.');
        Helper::redirect('../index.php');
        exit;
    }

    // 2. Validasi token via SSO Client
    $sso = sso();
    $userData = $sso->validateQueryToken();

    if (!$userData) {
        Helper::setFlash('danger', 'Token SSO tidak valid atau sudah kadaluarsa. Silakan masuk kembali dari Portal Veyra.');
        Helper::redirect('../index.php');
        exit;
    }

    // Ambil konfigurasi SSO
    $configFile = __DIR__ . '/../config/sso.php';
    $ssoConfig = file_exists($configFile) ? (require $configFile) : [];
    $autoProvision = $ssoConfig['auto_provision'] ?? true;

    // 3. Normalisasi data user & peran
    $rawRole = strtolower(trim((string)($userData['role'] ?? 'student')));
    if (in_array($rawRole, ['superadmin', 'super_admin'])) {
        $role = 'superadmin';
    } elseif (in_array($rawRole, ['admin', 'school', 'school_admin', 'operator', 'proktor', 'proctor'])) {
        $role = 'admin';
    } elseif (in_array($rawRole, ['teacher', 'guru'])) {
        $role = 'teacher';
    } else {
        $role = 'student';
    }

    $email    = trim((string)($userData['email'] ?? ''));
    $name     = trim((string)($userData['name'] ?? $userData['full_name'] ?? 'Pengguna Veyra'));
    $username = trim((string)($userData['username'] ?? ($email ? explode('@', $email)[0] : 'user_' . ($userData['sub'] ?? rand(1000, 9999)))));
    $nis      = trim((string)($userData['nis'] ?? $userData['student_id'] ?? $username));
    $gender   = in_array(strtoupper((string)($userData['gender'] ?? '')), ['L', 'P']) ? strtoupper((string)$userData['gender']) : 'L';

    // 4. Skenario: Superadmin
    if ($role === 'superadmin') {
        $sa = null;
        if ($email) {
            $sa = Database::fetch("SELECT * FROM superadmins WHERE email = ?", [$email]);
        }
        if (!$sa && $username) {
            $sa = Database::fetch("SELECT * FROM superadmins WHERE username = ?", [$username]);
        }

        // Fallback cerdas: Jika token terverifikasi valid dari Admin Utama Veyra,
        // hubungkan ke akun superadmin utama yang ada di basis data
        if (!$sa) {
            $primarySa = Database::fetch("SELECT * FROM superadmins ORDER BY id ASC LIMIT 1");
            if ($primarySa) {
                $sa = $primarySa;
                if ($email && (empty($sa['email']) || $sa['email'] === 'admin@cbtplatform.test')) {
                    Database::query("UPDATE superadmins SET email = ? WHERE id = ?", [$email, $sa['id']]);
                }
            }
        }

        if (!$sa) {
            Helper::setFlash('danger', 'Akses Superadmin via SSO ditolak: Akun Superadmin tidak terdaftar di platform CBT.');
            Helper::redirect('../index.php');
            exit;
        }

        session_regenerate_id(true);
        $authPayload = [
            'id'       => (int)$sa['id'],
            'username' => $sa['username'],
            'name'     => $sa['name'],
            'email'    => $sa['email'],
            'role'     => 'superadmin'
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['is_sso'] = true;
        Database::query("UPDATE superadmins SET last_activity = NOW() WHERE id = ?", [$sa['id']]);

        if (!empty($sa['totp_verified'])) {
            $_SESSION['pending_2fa_user'] = $authPayload;
            unset($_SESSION['auth_user']);
            Helper::redirect('../superadmin/totp-verify.php');
            exit;
        }

        $_SESSION['auth_user'] = $authPayload;
        Helper::setFlash('success', "Selamat datang, {$sa['name']}! Anda masuk sebagai Superadmin via Veyra SSO.");
        Helper::redirect('../superadmin/index.php');
        exit;
    }

    // 5. Resolusi Tenant (Lembaga / Sekolah)
    $tenantId      = (int)($userData['tenant_id'] ?? $userData['school_id'] ?? 0);
    $tenantCode    = trim((string)($userData['tenant_code'] ?? $userData['school_code'] ?? ''));
    $tenantSlug    = trim((string)($userData['tenant_slug'] ?? $userData['school_slug'] ?? ''));
    $tenantName    = trim((string)($userData['tenant_name'] ?? $userData['school_name'] ?? ''));
    $tenantPackage = strtolower(trim((string)($userData['package'] ?? $userData['plan_code'] ?? '')));
    if ($tenantPackage === '') {
        $tenantPackage = 'semester';
    }

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

    // Jika belum ada di CBT & autoProvision aktif, buatkan tenant sekolah baru secara otomatis (dimulai dari 0 data)
    if (!$tenant && $autoProvision) {
        $schoolDisplayName = $tenantName !== '' ? $tenantName : 'Sekolah Baru';
        $baseCode = $tenantCode !== '' ? $tenantCode : (strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $schoolDisplayName), 0, 8)) ?: 'SCH' . rand(100, 999));
        $baseSlug = $tenantSlug !== '' ? $tenantSlug : (strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $schoolDisplayName), '-')) ?: 'sekolah-' . rand(100, 999));
        $cleanCode = $baseCode;
        $cleanSlug = $baseSlug;

        // Pastikan kode dan slug benar-benar unik di basis data
        $counter = 0;
        while (Database::fetch("SELECT id FROM tenants WHERE code = ? OR slug = ?", [$cleanCode, $cleanSlug])) {
            $counter++;
            $cleanCode = substr($baseCode, 0, 5) . rand(100, 999);
            $cleanSlug = $baseSlug . '-' . rand(100, 999);
            if ($counter > 10) break;
        }

        Database::query(
            "INSERT INTO tenants (code, slug, name, package, student_quota, is_active, created_at) VALUES (?, ?, ?, ?, 500, 1, NOW())",
            [$cleanCode, $cleanSlug, $schoolDisplayName, $tenantPackage]
        );
        $newTenantId = (int)Database::lastInsertId();
        $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [$newTenantId]);
    }

    if (!$tenant) {
        Helper::setFlash('danger', 'Lembaga/Sekolah akun Anda belum terdaftar di CBT.');
        Helper::redirect('../index.php');
        exit;
    }

    // Sinkronkan paket sekolah di CBT dengan paket aktif yang dikirim dari Veyra Educate
    if ($tenantPackage !== '' && strtolower((string)($tenant['package'] ?? '')) !== $tenantPackage) {
        try {
            Database::query("UPDATE tenants SET package = ? WHERE id = ?", [$tenantPackage, $tenant['id']]);
            $tenant['package'] = $tenantPackage;
        } catch (Throwable $e) {}
    }

    // Sinkronkan logo sekolah jika dikirim dari Veyra Educate
    $schoolLogo = trim((string)($userData['school_logo'] ?? $userData['logo_url'] ?? ''));
    if ($schoolLogo !== '') {
        $_SESSION['school_logo'] = $schoolLogo;
        try {
            Database::query("UPDATE tenants SET logo = ? WHERE id = ?", [$schoolLogo, $tenant['id']]);
            $tenant['logo'] = $schoolLogo;
        } catch (Throwable $e) {}
    }

    Tenant::setTenant($tenant);
    $activeTenantId = (int)$tenant['id'];

    // 6. Skenario: Admin / Guru / Proktor
    if (in_array($role, ['admin', 'teacher'])) {
        $user = null;
        if ($email) {
            $user = Database::fetch(
                "SELECT * FROM users WHERE tenant_id = ? AND email = ? AND is_active = 1",
                [$activeTenantId, $email]
            );
        }
        if (!$user && $username) {
            $user = Database::fetch(
                "SELECT * FROM users WHERE tenant_id = ? AND username = ? AND is_active = 1",
                [$activeTenantId, $username]
            );
        }
        if (!$user && $name) {
            $user = Database::fetch(
                "SELECT * FROM users WHERE tenant_id = ? AND name = ? AND is_active = 1",
                [$activeTenantId, $name]
            );
        }

        if (!$user && $autoProvision) {
            // Pastikan username unik di tenant ini
            $baseUser = $username;
            $uCount = 0;
            while (Database::fetch("SELECT id FROM users WHERE tenant_id = ? AND username = ?", [$activeTenantId, $username])) {
                $uCount++;
                $username = $baseUser . rand(10, 99);
                if ($uCount > 10) break;
            }

            $dummyPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            Database::query(
                "INSERT INTO users (tenant_id, username, password, name, role, email, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())",
                [$activeTenantId, $username, $dummyPass, $name, $role, $email ?: null]
            );
            $newUserId = (int)Database::lastInsertId();
            $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$newUserId]);
        }

        if (!$user) {
            Helper::setFlash('danger', 'Akun Guru/Admin Anda belum terdaftar di CBT untuk lembaga ' . htmlspecialchars($tenant['name']) . '.');
            Helper::redirect('../index.php');
            exit;
        }

        session_regenerate_id(true);
        $authPayload = [
            'id'        => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'username'  => $user['username'],
            'name'      => $user['name'],
            'role'      => $user['role'],
            'email'     => $user['email']
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['is_sso'] = true;
        Database::query("UPDATE users SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$user['id'], $activeTenantId]);

        if (!empty($user['totp_verified'])) {
            $_SESSION['pending_2fa_user'] = $authPayload;
            unset($_SESSION['auth_user']);
            Helper::redirect('../admin/totp-verify.php');
            exit;
        }

        $_SESSION['auth_user'] = $authPayload;
        Helper::setFlash('success', "Selamat datang, {$user['name']}! Anda masuk melalui Veyra SSO.");
        Helper::redirect('../admin/index.php');
        exit;
    }

    // 7. Skenario: Siswa (Student)
    if ($role === 'student') {
        $student = Database::fetch(
            "SELECT s.*, c.name as class_name 
             FROM students s 
             LEFT JOIN classes c ON s.class_id = c.id 
             WHERE s.tenant_id = ? AND s.nis = ? AND s.is_active = 1",
            [$activeTenantId, $nis]
        );

        if (!$student && $name) {
            $student = Database::fetch(
                "SELECT s.*, c.name as class_name 
                 FROM students s 
                 LEFT JOIN classes c ON s.class_id = c.id 
                 WHERE s.tenant_id = ? AND s.name = ? AND s.is_active = 1",
                [$activeTenantId, $name]
            );
        }

        if (!$student && $autoProvision) {
            // Pastikan NIS unik di tenant ini
            $baseNis = $nis;
            $nCount = 0;
            while (Database::fetch("SELECT id FROM students WHERE tenant_id = ? AND nis = ?", [$activeTenantId, $nis])) {
                $nCount++;
                $nis = $baseNis . rand(10, 99);
                if ($nCount > 10) break;
            }

            $dummyPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            Database::query(
                "INSERT INTO students (tenant_id, nis, name, password, gender, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())",
                [$activeTenantId, $nis, $name, $dummyPass, $gender]
            );
            $newStudentId = (int)Database::lastInsertId();
            $student = Database::fetch(
                "SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ?",
                [$newStudentId]
            );
        }

        if (!$student) {
            Helper::setFlash('danger', 'Data siswa dengan NIS ' . htmlspecialchars($nis) . ' belum terdaftar di lembaga ' . htmlspecialchars($tenant['name']) . '.');
            Helper::redirect('../index.php');
            exit;
        }

        session_regenerate_id(true);
        $authPayload = [
            'id'         => (int)$student['id'],
            'tenant_id'  => (int)$student['tenant_id'],
            'nis'        => $student['nis'],
            'name'       => $student['name'],
            'class_id'   => $student['class_id'],
            'class_name' => $student['class_name'] ?? 'Umum',
            'role'       => 'student'
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['is_sso'] = true;
        Database::query("UPDATE students SET last_activity = NOW() WHERE id = ? AND tenant_id = ?", [$student['id'], $activeTenantId]);

        if (!empty($student['totp_verified'])) {
            $_SESSION['pending_2fa_user'] = $authPayload;
            unset($_SESSION['auth_user']);
            Helper::redirect('../admin/totp-verify.php');
            exit;
        }

        $_SESSION['auth_user'] = $authPayload;
        Helper::setFlash('success', "Selamat datang, {$student['name']}! Anda masuk melalui Veyra SSO.");
        Helper::redirect('../student/dashboard.php');
        exit;
    }

    // Fallback jika tidak sesuai
    Helper::redirect('../index.php');
    exit;

} catch (\Throwable $e) {
    error_log('[CBT SSO Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Helper::setFlash('danger', 'Terjadi kesalahan sistem saat proses SSO: ' . htmlspecialchars($e->getMessage()));
    Helper::redirect('../index.php');
    exit;
}
