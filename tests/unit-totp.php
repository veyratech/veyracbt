<?php
/** Unit tests for Totp class - pure functions, no DB required */
declare(strict_types=1);

require_once __DIR__ . '/../core/Totp.php';

$__TTP = 0;
$__TTD = 0;
$__TTR = [];

function ttAssertEqual($actual, $expected, string $name): void {
    global $__TTP, $__TTD, $__TTR;
    if ($actual === $expected || ($actual == $expected && gettype($actual) === gettype($expected))) {
        $__TTP++;
        echo "  PASS: $name\n";
    } else {
        $__TTD++;
        $__TTR[] = $name;
        echo "  FAIL: $name (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function ttAssertTrue(bool $cond, string $name): void { ttAssertEqual($cond, true, $name); }
function ttAssertFalse(bool $cond, string $name): void { ttAssertEqual($cond, false, $name); }
function ttAssertContains(string $haystack, string $needle, string $name): void {
    ttAssertEqual(strpos($haystack, $needle) !== false, true, $name);
}

echo "=== unit-totp.php ===\n";

// ========== generateSecret ==========
echo "\n[generateSecret]\n";

$secret = Totp::generateSecret();
ttAssertEqual(preg_match('/^[A-Z2-7]+=*$/', $secret), 1, 'generateSecret returns base32 string');
$secLen = strlen($secret);
ttAssertTrue($secLen >= 32 && $secLen <= 36, "generateSecret returns ~32 char secret (got $secLen)");

$secrets = [];
for ($i = 0; $i < 20; $i++) {
    $secrets[] = Totp::generateSecret();
}
ttAssertEqual(count(array_unique($secrets)), 20, 'generateSecret produces unique secrets');

// ========== generateCode ==========
echo "\n[generateCode]\n";

$code = Totp::generateCode('JBSWY3DPEHPK3PXP');
ttAssertEqual(preg_match('/^\d{6}$/', $code), 1, 'generateCode returns 6-digit numeric string');
ttAssertEqual(strlen($code), 6, 'generateCode returns exactly 6 characters');

$code2 = Totp::generateCode('JBSWY3DPEHPK3PXP');
ttAssertEqual($code, $code2, 'generateCode is deterministic for same secret');

$code3 = Totp::generateCode('GEZDGNBVGY3TQOJQ');
ttAssertEqual($code !== $code3, true, 'Different secrets likely produce different codes');

// ========== verifyCode ==========
echo "\n[verifyCode]\n";

$vrSecret = 'JBSWY3DPEHPK3PXP';
$currentCode = Totp::generateCode($vrSecret);

ttAssertTrue(Totp::verifyCode($vrSecret, $currentCode), 'verifyCode accepts current code');
ttAssertTrue(Totp::verifyCode($vrSecret, strtoupper($currentCode)), 'verifyCode accepts uppercase code');
ttAssertTrue(Totp::verifyCode($vrSecret, trim("  {$currentCode}  ")), 'verifyCode trims whitespace');

ttAssertFalse(Totp::verifyCode($vrSecret, '000000'), 'verifyCode rejects wrong code 000000');
ttAssertFalse(Totp::verifyCode($vrSecret, '999999'), 'verifyCode rejects wrong code 999999');
ttAssertFalse(Totp::verifyCode($vrSecret, 'abc'), 'verifyCode rejects non-numeric code');
ttAssertFalse(Totp::verifyCode($vrSecret, '12345'), 'verifyCode rejects 5-digit code');
ttAssertFalse(Totp::verifyCode($vrSecret, '1234567'), 'verifyCode rejects 7-digit code');
ttAssertFalse(Totp::verifyCode($vrSecret, ''), 'verifyCode rejects empty code');

// ========== verifyCode drift tolerance ==========
echo "\n[verifyCode drift tolerance]\n";

function buildTOTPCodeManual(string $secret, int $timeStep): string {
    $timeHex = str_pad(dechex($timeStep), 8, '0', STR_PAD_LEFT);
    $timeBytes = pack('H*', $timeHex);
    $decoded = base32DecodeManual($secret);
    $hash = hash_hmac('sha1', $timeBytes, $decoded, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
    $otp = $binary % 1000000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

function base32DecodeManual(string $base32): string {
    $base32 = strtoupper(str_replace(' ', '', $base32));
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($base32); $i++) {
        $idx = strpos($chars, $base32[$i]);
        if ($idx === false) continue;
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $result .= chr((int)bindec(substr($bits, $i, 8)));
    }
    return $result;
}

$driftSecret = 'JBSWY3DPEHPK3PXP';
$driftCurrent = Totp::generateCode($driftSecret);
ttAssertTrue(Totp::verifyCode($driftSecret, $driftCurrent), 'verifyCode accepts current time step code');

$stepTime = (int)floor(time() / 30);
$driftPrev = buildTOTPCodeManual($driftSecret, $stepTime - 1);
$driftNext = buildTOTPCodeManual($driftSecret, $stepTime + 1);
$driftFar = buildTOTPCodeManual($driftSecret, $stepTime - 2);

ttAssertTrue(Totp::verifyCode($driftSecret, $driftPrev), 'verifyCode accepts code from previous step (drift -1)');
ttAssertTrue(Totp::verifyCode($driftSecret, $driftNext), 'verifyCode accepts code from next step (drift +1)');
ttAssertFalse(Totp::verifyCode($driftSecret, $driftFar), 'verifyCode rejects code from 2 steps ago (drift -2)');

// ========== getQrCodeUrl ==========
echo "\n[getQrCodeUrl]\n";

$qr = Totp::getQrCodeUrl('JBSWY3DPEHPK3PXP', 'CBT Online', 'alice@example.com');
ttAssertContains($qr, 'otpauth://totp/', 'getQrCodeUrl starts with otpauth://totp/');
ttAssertContains($qr, 'secret=JBSWY3DPEHPK3PXP', 'getQrCodeUrl includes secret parameter');
// http_build_query encodes spaces as +
ttAssertContains($qr, 'issuer=CBT+Online', 'getQrCodeUrl includes encoded issuer');
ttAssertContains($qr, 'algorithm=SHA1', 'getQrCodeUrl sets algorithm to SHA1');
ttAssertContains($qr, 'digits=6', 'getQrCodeUrl sets digits to 6');
ttAssertContains($qr, 'period=30', 'getQrCodeUrl sets period to 30');
// Label uses urlencode which encodes : as %3A and @ as %4A
ttAssertContains($qr, 'CBT+Online%3Aalice%40example.com', 'getQrCodeUrl label contains issuer:account');

// Secret with padding chars (=) should be stripped
$qr2 = Totp::getQrCodeUrl('JBSWY3DPEHPK3PXP====', 'MyApp', 'user1');
ttAssertEqual(strpos($qr2, '===='), false, 'getQrCodeUrl strips = padding from secret in URL');

// ========== Summary ==========
echo "\n============================\n";
$totalT = $__TTP + $__TTD;
echo "Results: {$__TTP} passed, {$__TTD} failed, {$totalT} total\n";
exit($__TTD > 0 ? 1 : 0);
