<?php
/**
 * Unit Tests — Auth Class (requires database connection)
 * Jalankan: php tests/unit-auth.php
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) @session_start();

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../core/Tenant.php';
require_once __DIR__ . '/../core/Auth.php';

$TP = 0; $TF = 0; $FT = [];
function chk($pass, string $n): void {
    global $TP, $TF, $FT;
    if ($pass) { $TP++; echo "  PASS: $n\n"; }
    else { $TF++; $FT[] = $n; echo "  FAIL: $n\n"; }
}
function eq($a, $b, string $n): void { chk(($a===$b || $a==$b), $n); }
function isT(bool $c, string $n): void { chk(true===$c, $n); }
function isF(bool $c, string $n): void { chk(false===$c, $n); }

echo "=== Unit Test: Auth Class ===\n\n";
$db = Database::getConnection();

// ---- Initial state ----
echo "[Initial State]\n";
isF(Auth::check(), "not logged in initially");
eq(Auth::role(), null, "role null when not logged in");
eq(Auth::id(), null, "id null when not logged in");

// ---- loginSuperadmin ----
echo "\n[loginSuperadmin]\n";
$_SESSION = []; // fresh session
$saRow = $db->query("SELECT id, password FROM superadmins WHERE username='admin' LIMIT 1")->fetch();
isT($saRow !== false, "superadmin 'admin' exists in DB");

Auth::loginSuperadmin('admin', 'admin123');
isT(Auth::check(), "check returns true after superadmin login");
eq(Auth::role(), 'superadmin', "role is superadmin");
isT(Auth::id() > 0, "id is positive int after login");
$user = Auth::user();
eq($user['username'], 'admin', "user username is admin");
eq($user['role'], 'superadmin', "user role is superadmin");

Auth::logout();

// ---- loginTenantUser ----
echo "\n[loginTenantUser]\n";
$_SESSION = [];
Auth::loginTenantUser(1, 'admin_smatel', 'password');
isT(Auth::check(), "check true after tenant user login");
eq(Auth::role(), 'admin', "role is admin");
isT(Auth::id() > 0, "id positive after tenant login");
isT(Auth::user()['tenant_id'] === 1, "tenant_id matches tenant 1");
Auth::logout();

// teacher login
Auth::loginTenantUser(1, 'guru_smatel', 'password');
eq(Auth::role(), 'teacher', "role is teacher");
Auth::logout();

// wrong password
Auth::loginTenantUser(1, 'admin_smatel', 'wrongpassword');
isF(Auth::check(), "check false after wrong password");

// ---- loginStudent ----
echo "\n[loginStudent]\n";
$_SESSION = [];
Auth::loginStudent(1, '2024001', 'password');
isT(Auth::check(), "check true after student login");
eq(Auth::role(), 'student', "role is student");
isT(Auth::id() > 0, "student id positive");
isT(Auth::user()['nis'] === '2024001', "NIS is 2024001");
Auth::logout();

// wrong password student
Auth::loginStudent(1, '2024001', 'wrongpwd');
isF(Auth::check(), "check false after wrong student password");

// ---- isDefaultPassword ----
echo "\n[isDefaultPassword]\n";
// Login as superadmin with default password
$_SESSION = [];
Auth::loginSuperadmin('admin', 'admin123');
isT(Auth::isDefaultPassword(Auth::id(), 'superadmin'), "isDefaultPassword true for superadmin default");
Auth::logout();

// Login as tenant admin with default password
$_SESSION = [];
Auth::loginTenantUser(1, 'admin_smatel', 'password');
isT(Auth::isDefaultPassword(Auth::id(), 'admin'), "isDefaultPassword true for tenant admin default");
Auth::logout();

// Login as student with default password
$_SESSION = [];
Auth::loginStudent(1, '2024001', 'password');
isT(Auth::isDefaultPassword(Auth::id(), 'student'), "isDefaultPassword true for student default");
Auth::logout();

// Not logged in
isF(Auth::isDefaultPassword(), "isDefaultPassword false when not logged in");

// ---- Method existence ----
echo "\n[method existence]\n";
isT(method_exists(Auth::class, 'requireSuperadmin'), "requireSuperadmin exists");
isT(method_exists(Auth::class, 'requireTenantAdmin'), "requireTenantAdmin exists");
isT(method_exists(Auth::class, 'requireStudent'), "requireStudent exists");
isT(method_exists(Auth::class, 'enforce2Fa'), "enforce2Fa exists");
isT(method_exists(Auth::class, 'authenticateByToken'), "authenticateByToken exists");
isT(method_exists(Auth::class, 'getTimeout'), "getTimeout exists");
isT(method_exists(Auth::class, 'getWarningBefore'), "getWarningBefore exists");

// ---- is2faRequired ----
echo "\n[2FA]\n";
isF(Auth::is2faRequired(), "is2faRequired false when not logged in");

// ---- authenticateByToken ----
echo "\n[authenticateByToken]\n";
eq(Auth::authenticateByToken('bad-token'), null, "returns null for bad token");

// ---- timeout config ----
echo "\n[timeout]\n";
isT(Auth::getTimeout() > 0, "getTimeout returns positive value");
isT(Auth::getWarningBefore() >= 0, "getWarningBefore returns non-negative");

// Summary
$total = $TP + $TF;
echo "\n=== RESULTS ===\n";
echo "Passed: $TP | Failed: $TF | Total: $total\n";
if ($TF > 0) { foreach ($FT as $x) echo "  - $x\n"; exit(1); }
echo "All auth tests passed!\n";
exit(0);
