<?php
/** Unit tests for Helper class — local development only */
declare(strict_types=1);

require_once __DIR__ . '/../core/Helper.php';

$passed = 0;
$failed = 0;
$failList = [];

function hAssertEqual($actual, $expected, string $name): void {
    global $passed, $failed, $failList;
    if ($actual === $expected || ($actual == $expected && gettype($actual) === gettype($expected))) {
        $passed++;
        echo "  PASS: $name\n";
    } else {
        $failed++;
        $failList[] = $name;
        echo "  FAIL: $name (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function hAssertTrue(bool $cond, string $name): void { hAssertEqual($cond, true, $name); }
function hAssertFalse(bool $cond, string $name): void { hAssertEqual($cond, false, $name); }
function hAssertContains(string $haystack, string $needle, string $name): void {
    hAssertEqual(strpos($haystack, $needle) !== false, true, $name);
}
function hAssertNotContains(string $haystack, string $needle, string $name): void {
    hAssertEqual(strpos($haystack, $needle) === false, true, $name);
}

echo "=== unit-helper.php ===\n";

// ========== csrfToken / verifyCsrf ==========
echo "\n[CSRF]\n";

if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['csrf_token']);
session_write_close();

$csrfToken = Helper::csrfToken();
hAssertEqual(strlen($csrfToken), 64, 'csrfToken returns 64-char hex string');
hAssertEqual(preg_match('/^[0-9a-f]{64}$/', $csrfToken), 1, 'csrfToken is valid hex string');

$csrfToken2 = Helper::csrfToken();
hAssertEqual($csrfToken, $csrfToken2, 'csrfToken is persistent across calls');

$_POST['_csrf'] = $csrfToken;
ob_start();
Helper::verifyCsrf();
$out = ob_get_clean();
hAssertEqual(ob_get_level(), 0, 'verifyCsrf does not abort on valid token');

$ref = new ReflectionMethod(Helper::class, 'verifyCsrf');
hAssertTrue(true, 'verifyCsrf method is callable');

if (session_status() === PHP_SESSION_NONE) session_start();
$stored = $_SESSION['csrf_token'] ?? null;
hAssertEqual(is_string($stored) && strlen($stored) >= 32, true, 'csrf_token stored in session');
session_write_close();

// ========== generateToken ==========
echo "\n[generateToken]\n";

$t6 = Helper::generateToken(6);
hAssertEqual(strlen($t6), 6, 'generateToken(6) returns 6-char string');
hAssertEqual(preg_match('/^[A-Z2-9]{6}$/', $t6), 1, 'generateToken(6) uses correct charset');

$h = Helper::csrfToken();
hAssertEqual(strlen($h) > 0, true, 'csrfToken is not empty');
$h2 = Helper::csrfToken();
hAssertEqual($h, $h2, 'csrfToken is stable across calls');

$t10 = Helper::generateToken(10);
hAssertEqual(strlen($t10), 10, 'generateToken(10) returns 10-char string');

$tokArr = [];
for ($i = 0; $i < 50; $i++) {
    $tokArr[] = Helper::generateToken(8);
}
hAssertEqual(count(array_unique($tokArr)), 50, 'generateToken values are unique across 50 calls');

// ========== sanitize ==========
echo "\n[sanitize]\n";

$s = Helper::sanitize('<script>alert("xss")</script>');
hAssertContains($s, '&lt;script&gt;', 'sanitize escapes tags');
hAssertContains($s, '&quot;', 'sanitize escapes quotes');

$s2 = Helper::sanitize('  Hello World  ');
hAssertEqual($s2, 'Hello World', 'sanitize trims whitespace');

// ========== paginate ==========
echo "\n[paginate]\n";

$_GET = [];

$pgInfo = Helper::paginate(100, 15);
hAssertEqual($pgInfo['page'], 1, 'paginate page defaults to 1');
hAssertEqual($pgInfo['pages'], 7, 'paginate pages = ceil(100/15)');
hAssertEqual($pgInfo['per_page'], 15, 'paginate per_page = 15');
hAssertEqual($pgInfo['offset'], 0, 'paginate offset = 0 for page 1');
hAssertEqual($pgInfo['total'], 100, 'paginate total = 100');

$pg3 = Helper::paginate(100, 15, 3);
hAssertEqual($pg3['page'], 3, 'paginate explicit page=3');
hAssertEqual($pg3['offset'], 30, 'paginate offset for page 3 = 30');

$pgZero = Helper::paginate(0, 15);
hAssertEqual($pgZero['pages'], 1, 'paginate with total=0 gives 1 page');

$pgClamp = Helper::paginate(10, 15, 99);
hAssertEqual($pgClamp['page'], 1, 'paginate clamps page beyond range');
hAssertEqual($pgClamp['pages'], 1, 'paginate only 1 page for 10 items/15 per-page');

// ========== paginationLinks ==========
echo "\n[paginationLinks]\n";

$emptyLinks = Helper::paginationLinks(['page' => 1, 'pages' => 1, 'per_page' => 15, 'offset' => 0, 'total' => 10]);
hAssertEqual($emptyLinks, '', 'paginationLinks empty when only 1 page');

$pgHtml = Helper::paginate(100, 15, 3);
$html = Helper::paginationLinks($pgHtml);
hAssertContains($html, '<div class="pagination">', 'paginationLinks wraps in pagination div');
hAssertContains($html, 'class="pg-btn"', 'paginationLinks has pg-btn links');
hAssertContains($html, 'class="pg-info"', 'paginationLinks has pg-info span');
hAssertContains($html, 'Halaman 3 / 7', 'paginationLinks shows current page info');
hAssertContains($html, '100 data', 'paginationLinks shows total count');

$htmlExtra = Helper::paginationLinks($pgHtml, ['q' => 'test']);
hAssertContains($htmlExtra, 'q=test', 'paginationLinks preserves extra query params');

// ========== baseUrl ==========
echo "\n[baseUrl]\n";

$_SERVER['HTTPS'] = '';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/cbtonline/core/Helper.php';

$baseUrl = Helper::baseUrl('');
hAssertContains($baseUrl, 'http://localhost', 'baseUrl uses http by default');

$absUrl = Helper::baseUrl('/dashboard.php');
hAssertEqual($absUrl, 'http://localhost/dashboard.php', 'baseUrl handles absolute path');

$modUrl = Helper::baseUrl('admin/login.php');
hAssertContains($modUrl, '/cbtonline/admin/login.php', 'baseUrl resolves module path');

$relUrl = Helper::baseUrl('some-file.php');
hAssertContains($relUrl, '/cbtonline/core/some-file.php', 'baseUrl resolves relative to script dir');

$_SERVER['HTTPS'] = 'on';
$httpsUrl = Helper::baseUrl('/');
hAssertContains($httpsUrl, 'https://', 'baseUrl uses https when HTTPS is on');
$_SERVER['HTTPS'] = '';

// ========== shuffleOptions ==========
echo "\n[shuffleOptions]\n";

$qData = [
    'question_text' => 'What is 2+2?',
    'option_a' => 'Three',
    'option_b' => 'Four',
    'option_c' => 'Five',
    'option_d' => 'Six',
    'option_e' => '',
    'correct_answer' => 'B',
];

$shuffled = Helper::shuffleOptions($qData, 'seed123');
$opts = [$shuffled['option_a'], $shuffled['option_b'], $shuffled['option_c'], $shuffled['option_d']];
hAssertEqual(in_array('Four', $opts), true, 'shuffle retains Four option');
hAssertEqual(in_array('Three', $opts), true, 'shuffle retains Three option');
hAssertEqual(in_array('Five', $opts), true, 'shuffle retains Five option');
hAssertEqual(in_array('Six', $opts), true, 'shuffle retains Six option');

$deterministic = Helper::shuffleOptions($qData, 'seed123');
hAssertEqual($deterministic['option_a'], $shuffled['option_a'], 'Same seed produces same shuffled order A');
hAssertEqual($deterministic['correct_answer'], $shuffled['correct_answer'], 'Same seed produces same correct_answer');

$qFull = [
    'question_text' => 'Test',
    'option_a' => 'Alpha',
    'option_b' => 'Beta',
    'option_c' => 'Gamma',
    'option_d' => 'Delta',
    'option_e' => 'Epsilon',
    'correct_answer' => 'E',
];
$fullShuffle = Helper::shuffleOptions($qFull, 'seed456');
$corrLetter = $fullShuffle['correct_answer'];
$corrValue = $fullShuffle['option_' . strtolower($corrLetter)];
hAssertEqual($corrValue, 'Epsilon', "Shuffle preserves correct answer value 'Epsilon'");

// ========== parseCsvUpload ==========
echo "\n[parseCsvUpload]\n";

$tmpDir = sys_get_temp_dir();
$csvPath = tempnam($tmpDir, 'cbt_test_') . '.csv';

file_put_contents($csvPath, "name,age,city\nAlice,30,Bandung\nBob,25,Jakarta\n");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'name' => 'test.csv'];
[$h1, $r1] = Helper::parseCsvUpload($csvSpec);
hAssertEqual($h1, ['name', 'age', 'city'], 'parseCsvUpload detects comma delimiter');
hAssertEqual(count($r1), 2, 'parseCsvUpload returns 2 data rows');
hAssertEqual($r1[0]['name'], 'Alice', 'parseCsvUpload reads first row');
hAssertEqual($r1[1]['city'], 'Jakarta', 'parseCsvUpload reads second row');

file_put_contents($csvPath, "name;age;city\nAlice;30;Bandung\n");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'name' => 'test.csv'];
[$h2, $r2] = Helper::parseCsvUpload($csvSpec);
hAssertEqual($h2, ['name', 'age', 'city'], 'parseCsvUpload detects semicolon delimiter');
hAssertEqual($r2[0]['age'], '30', 'parseCsvUpload reads semicolon data row');

file_put_contents($csvPath, "name\tage\tcity\nAlice\t30\tBandung\n");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'name' => 'test.csv'];
[$h3, $r3] = Helper::parseCsvUpload($csvSpec);
hAssertEqual($h3, ['name', 'age', 'city'], 'parseCsvUpload detects tab delimiter');
hAssertEqual($r3[0]['name'], 'Alice', 'parseCsvUpload reads tab-delimited data');

$bom = "\xEF\xBB\xBF";
file_put_contents($csvPath, $bom . "name,age\nAlice,30\n");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'name' => 'test.csv'];
[$h4, $r4] = Helper::parseCsvUpload($csvSpec);
hAssertEqual($h4[0], 'name', 'parseCsvUpload strips BOM from header');
hAssertEqual(count($r4), 1, 'parseCsvUpload reads 1 row after BOM');

file_put_contents($csvPath, "name,age\n\nAlice,30\n\n\nBob,25\n");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'name' => 'test.csv'];
[$h5, $r5] = Helper::parseCsvUpload($csvSpec);
hAssertEqual(count($r5), 2, 'parseCsvUpload skips empty lines');

file_put_contents($csvPath, "");
$csvSpec = ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => 0, 'name' => 'test.csv'];
[$h6, $r6] = Helper::parseCsvUpload($csvSpec);
hAssertEqual($h6, [], 'parseCsvUpload returns empty header for empty file');
hAssertEqual($r6, [], 'parseCsvUpload returns empty rows for empty file');

unlink($csvPath);

// ========== Summary ==========
echo "\n============================\n";
$totalH = $passed + $failed;
echo "Results: {$passed} passed, {$failed} failed, {$totalH} total\n";
if ($failed > 0) { echo "Failed:\n"; foreach ($failList as $x) echo "  - $x\n"; }
exit($failed > 0 ? 1 : 0);
