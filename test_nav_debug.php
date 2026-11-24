<?php
// Clear OPcache and fetch fresh page
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared\n";
} else {
    echo "OPcache not available\n";
}

// Now test fetch
chdir("C:/xampp/htdocs/cbtonline/student");
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["PHP_SELF"] = "/cbtonline/student/dashboard.php";
$_SERVER["SCRIPT_NAME"] = "/cbtonline/student/dashboard.php";
$_SERVER["HTTP_HOST"] = "localhost";
if (session_status() === PHP_SESSION_NONE) session_start();
$c = require "C:/xampp/htdocs/cbtonline/config/database.php";
$p = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
$s = $p->query("SELECT id,tenant_id,class_id,name FROM students WHERE id=6")->fetch(PDO::FETCH_ASSOC);
$_SESSION["auth_user"] = ["id" => $s["id"], "tenant_id" => $s["tenant_id"], "role" => "student", "name" => $s["name"], "email" => "[EMAIL_REDACTED]"];
$_SESSION["last_activity"] = time();
$_SESSION["2fa_verified"] = true;

ob_start();
include "dashboard.php";
$o = ob_get_clean();

echo "Rendered size: " . strlen($o) . " bytes\n";
echo "Contains ./values.php: " . (strpos($o, './values.php') !== false ? 'YES' : 'NO') . "\n";
echo "Contains localhost href: " . (preg_match('/href="http:\/\/localhost/', $o) ? 'YES-BAD' : 'NO') . "\n";
echo "Link count: " . substr_count($o, '<a ') . "\n";
echo "Mobile tile: " . (strpos($o, 'mobile-menu-tile') !== false ? 'FOUND' : 'MISSING') . "\n";

// Show all nav links
preg_match_all('/<a[^>]*class="student-nav-item[^"]*"[^>]*href="([^"]*)"/', $o, $m);
echo "\nNav links:\n";
foreach ($m[1] as $l) echo "  -> $l\n";
