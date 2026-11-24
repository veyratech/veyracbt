<?php
/** Unit tests for Tenant class - requires database */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
unset($_SESSION['tenant_id'], $_SESSION['tenant_code'], $_SESSION['tenant_name'], $_SESSION['tenant_slug']);

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Tenant.php';

$__TH = 0;
$__TF = 0;
$__TR = [];

function tAssertEqual($actual, $expected, string $name): void {
    global $__TH, $__TF, $__TR;
    if ($actual === $expected || ($actual == $expected && gettype($actual) === gettype($expected))) {
        $__TH++;
        echo "  PASS: $name\n";
    } else {
        $__TF++;
        $__TR[] = $name;
        echo "  FAIL: $name (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function tAssertTrue(bool $cond, string $name): void { tAssertEqual($cond, true, $name); }
function tAssertFalse(bool $cond, string $name): void { tAssertEqual($cond, false, $name); }
function tAssertNotNull($val, string $name): void { tAssertEqual($val !== null, true, $name); }
function tAssertNull($val, string $name): void { tAssertEqual($val === null, true, $name); }

echo "=== unit-tenant.php ===\n";

function tResetState(): void {
    // Clear session first
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_start();
    unset($_SESSION['tenant_id'], $_SESSION['tenant_code'], $_SESSION['tenant_name'], $_SESSION['tenant_slug']);
    // Also clear cookie
    if (isset($_COOKIE['veyra_cbt_school'])) {
        unset($_COOKIE['veyra_cbt_school']);
    }
    session_write_close();
    // Then clear static property
    Tenant::reset();
}

// ── findByIdentifier: by code ───────────────────────────────────────────────
echo "\n[findByIdentifier - by code]\n";

tResetState();
$tenantA = Tenant::findByIdentifier('SMATEL');
tAssertNotNull($tenantA, 'findByIdentifier finds tenant by code SMATEL');
tAssertEqual($tenantA['code'], 'SMATEL', 'findByIdentifier returns correct code');
tAssertEqual($tenantA['name'], 'SMA Teladan Unggulan', 'findByIdentifier returns correct name');
tAssertTrue((bool)$tenantA['is_active'], 'findByIdentifier returns active tenant');

tResetState();
$tenantB = Tenant::findByIdentifier('SMKNUS');
tAssertNotNull($tenantB, 'findByIdentifier finds tenant by code SMKNUS');
tAssertEqual($tenantB['code'], 'SMKNUS', 'findByIdentifier returns correct code for SMKNUS');

tResetState();
$missing = Tenant::findByIdentifier('NONEXISTENT');
tAssertNull($missing, 'findByIdentifier returns null for non-existent code');

// ── findByIdentifier: by slug ───────────────────────────────────────────────
echo "\n[findByIdentifier - by slug]\n";

tResetState();
$bySlug = Tenant::findByIdentifier('sma-teladan');
tAssertNotNull($bySlug, 'findByIdentifier finds tenant by slug sma-teladan');
tAssertEqual($bySlug['code'], 'SMATEL', 'findByIdentifier by slug returns correct tenant');

// ── findByIdentifier: by subdomain ──────────────────────────────────────────
echo "\n[findByIdentifier - by subdomain]\n";

tResetState();
$allTenants = Database::fetchAll("SELECT id, code, subdomain FROM tenants WHERE is_active = 1");
$foundBySub = false;
foreach ($allTenants as $t) {
    if (!empty($t['subdomain'])) {
        $subResult = Tenant::findByIdentifier((string)$t['subdomain']);
        tAssertNotNull($subResult, "findByIdentifier finds tenant by subdomain '{$t['subdomain']}'");
        tAssertEqual($subResult['id'], (int)$t['id'], "findByIdentifier by subdomain returns correct tenant");
        $foundBySub = true;
        break;
    }
}
if (!$foundBySub) {
    echo "  [SKIP] No tenant with subdomain found in database\n";
}

// ── setTenant / getTenant persistence ───────────────────────────────────────
echo "\n[setTenant / getTenant - session persistence]\n";

tResetState();
$tenantData = Tenant::findByIdentifier('SMATEL');
tAssertNotNull($tenantData, 'Found SMATEL tenant for setTenant test');

Tenant::setTenant($tenantData);
$getResult = Tenant::get();
tAssertNotNull($getResult, 'get() returns tenant after setTenant');
tAssertEqual($getResult['id'], $tenantData['id'], 'get() returns same tenant id');
tAssertEqual($getResult['code'], 'SMATEL', 'get() returns same tenant code');
tAssertEqual($getResult['name'], 'SMA Teladan Unggulan', 'get() returns same tenant name');

// Session persistence
$sessionId = session_id();
session_write_close();

session_start();
tAssertEqual((int)($_SESSION['tenant_id'] ?? 0), (int)$tenantData['id'], 'Tenant ID persists in session');
tAssertEqual($_SESSION['tenant_code'] ?? '', 'SMATEL', 'Tenant code persists in session');
tAssertEqual($_SESSION['tenant_name'] ?? '', 'SMA Teladan Unggulan', 'Tenant name persists in session');
session_write_close();

// ── reset() clears state ────────────────────────────────────────────────────
echo "\n[reset()]\n";

tResetState();
Tenant::setTenant(Tenant::findByIdentifier('SMKNUS'));
tAssertNotNull(Tenant::get(), 'get() returns tenant before reset');

Tenant::reset();
// After reset, static property is null, but get() will call init() which may
// find tenant from session/other sources. So we test the internal property directly.
$reflection = new ReflectionClass(Tenant::class);
$prop = $reflection->getProperty('currentTenant');
$prop->setAccessible(true);
tAssertNull($prop->getValue(), 'Internal currentTenant is null after reset()');

// Also verify get() returns null when session is also clean
tResetState();
// With completely clean session and no GET/cookie/subdomain cues, get() should return null
tAssertNull(Tenant::get(), 'get() returns null when fully reset and no session data');

// ── id() / name() / subdomain() helpers ─────────────────────────────────────
echo "\n[id / name / subdomain helpers]\n";

tResetState();
$idBefore = Tenant::id();
tAssertNull($idBefore, 'id() returns null when no tenant set');

$nameBefore = Tenant::name();
tAssertEqual($nameBefore, 'CBT Online Multi-Tenant', 'name() returns default when no tenant set');

$subBefore = Tenant::subdomain();
tAssertNull($subBefore, 'subdomain() returns null when no tenant set');

Tenant::setTenant(Tenant::findByIdentifier('SMATEL'));
tAssertEqual(Tenant::id(), 1, 'id() returns tenant id after setTenant');
tAssertEqual(Tenant::name(), 'SMA Teladan Unggulan', 'name() returns tenant name');

$smatelInfo = Tenant::findByIdentifier('SMATEL');
$expectedSub = $smatelInfo['subdomain'] ?? null;
tAssertEqual(Tenant::subdomain(), $expectedSub, 'subdomain() returns tenant subdomain');

Tenant::setTenant(Tenant::findByIdentifier('SMKNUS'));
tAssertEqual(Tenant::id(), 2, 'id() updates after setting different tenant');
tAssertEqual(Tenant::name(), 'SMK Nusantara Teknologi', 'name() updates after setting different tenant');

// ── setTenant with full array ───────────────────────────────────────────────
echo "\n[setTenant - full array]\n";

tResetState();
$fullTenant = Tenant::findByIdentifier('SMATEL');
Tenant::setTenant($fullTenant);

$getFull = Tenant::get();
tAssertEqual($getFull['id'], $fullTenant['id'], 'get() preserves full tenant array id');
tAssertEqual($getFull['code'], $fullTenant['code'], 'get() preserves full tenant array code');
tAssertEqual($getFull['slug'], $fullTenant['slug'], 'get() preserves full tenant array slug');
tAssertEqual($getFull['name'], $fullTenant['name'], 'get() preserves full tenant array name');

// ─── Summary ────────────────────────────────────────────────────────────────
echo "\n============================\n";
$totalT = $__TH + $__TF;
echo "Results: {$__TH} passed, {$__TF} failed, {$totalT} total\n";
exit($__TF > 0 ? 1 : 0);
