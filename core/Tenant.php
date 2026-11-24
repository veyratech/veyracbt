<?php
/**
 * Tenant Resolver & Multi-Tenancy Context
 */

require_once __DIR__ . '/Database.php';

class Tenant {
    private static ?array $currentTenant = null;

    /**
     * Inisialisasi deteksi tenant (Session -> GET param -> Subdomain)
     */
    public static function init(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // 0. Reset tenant jika user klik ganti sekolah
        if (isset($_GET['ganti']) || isset($_GET['reset_sekolah'])) {
            unset($_SESSION['tenant_id'], $_SESSION['tenant_code'], $_SESSION['tenant_name'], $_SESSION['tenant_slug']);
            if (isset($_COOKIE['veyra_cbt_school'])) {
                setcookie('veyra_cbt_school', '', time() - 3600, '/');
            }
            self::$currentTenant = null;
            return null;
        }

        // 1. Cek parameter GET 'school' / 'tenant' / 'sekolah' / 'code' / 'sch' (misal: ?school=SMATEL atau ?sekolah=SMATEL)
        $reqTenant = trim((string)($_GET['school'] ?? $_GET['tenant'] ?? $_GET['sekolah'] ?? $_GET['code'] ?? $_GET['sch'] ?? ''));
        if ($reqTenant !== '') {
            $tenant = self::findByIdentifier($reqTenant);
            if ($tenant) {
                self::setTenant($tenant);
                return $tenant;
            }
        }

        // 2. Cek Session aktif
        if (!empty($_SESSION['tenant_id'])) {
            if (self::$currentTenant === null || self::$currentTenant['id'] != $_SESSION['tenant_id']) {
                $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ? AND is_active = 1", [$_SESSION['tenant_id']]);
                if ($tenant) {
                    self::$currentTenant = $tenant;
                    return $tenant;
                } else {
                    unset($_SESSION['tenant_id']);
                }
            } else {
                return self::$currentTenant;
            }
        }

        // 2b. Cek Cookie Auto-Remember di Browser Siswa
        if (empty($_SESSION['tenant_id']) && !empty($_COOKIE['veyra_cbt_school'])) {
            $tenant = self::findByIdentifier(trim((string)$_COOKIE['veyra_cbt_school']));
            if ($tenant) {
                self::setTenant($tenant);
                return $tenant;
            }
        }

        // 3. Cek Subdomain (jika ada host misal smk01.localhost atau smk01.domain.com)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);
        if (count($parts) >= 2 && !in_array(strtolower($parts[0]), ['www', 'cbt', 'cbtonline', 'localhost', 'portal', 'api', 'admin'], true)) {
            $subdomain = $parts[0];
            $tenant = self::findByIdentifier($subdomain);
            if ($tenant) {
                self::setTenant($tenant);
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Cari tenant berdasarkan code atau slug
     */
    public static function findByIdentifier(string $identifier): ?array {
        return Database::fetch(
            "SELECT * FROM tenants WHERE (code = ? OR slug = ? OR subdomain = ?) AND is_active = 1 LIMIT 1",
            [$identifier, $identifier, $identifier]
        );
    }

    /**
     * Set tenant aktif ke dalam session
     */
    public static function setTenant(array $tenant): void {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        self::$currentTenant = $tenant;
        $_SESSION['tenant_id']   = $tenant['id'];
        $_SESSION['tenant_code'] = $tenant['code'];
        $_SESSION['tenant_name'] = $tenant['name'];
        $_SESSION['tenant_slug'] = $tenant['slug'];
        if (!headers_sent()) {
            @setcookie('veyra_cbt_school', (string)$tenant['code'], time() + (30 * 86400), '/');
        }
    }

    /**
     * Ambil data tenant aktif saat ini
     */
    public static function get(): ?array {
        if (self::$currentTenant === null) {
            self::init();
        }
        return self::$currentTenant;
    }

    /**
     * Ambil ID tenant saat ini
     */
    public static function id(): ?int {
        $tenant = self::get();
        return $tenant ? (int) $tenant['id'] : null;
    }

    /**
     * Ambil Nama tenant saat ini
     */
    public static function name(): string {
        $tenant = self::get();
        return $tenant ? $tenant['name'] : 'CBT Online Multi-Tenant';
    }

    /**
     * Ambil subdomain tenant saat ini
     */
    public static function subdomain(): ?string {
        $tenant = self::get();
        return $tenant ? ($tenant['subdomain'] ?? null) : null;
    }

    /**
     * Reset state internal (hanya untuk testing).
     */
    public static function reset(): void {
        self::$currentTenant = null;
    }

    /**
     * Pastikan halaman memiliki tenant aktif (jika tidak, redirect ke pemilih tenant)
     */
    public static function requireTenant(string $redirectUrl = '../index.php'): array {
        $tenant = self::get();
        if (!$tenant) {
            header("Location: {$redirectUrl}");
            exit;
        }
        return $tenant;
    }
}
