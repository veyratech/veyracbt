<?php
/**
 * TOTP (Time-based One-Time Password) – Google Authenticator compatible
 */

class Totp {

    private static function base32Encode(string $data): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $pad = (8 - strlen($bits) % 8) % 8;
        $bits .= str_repeat('0', $pad);
        $result = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $result .= $chars[(int)bindec(substr($bits, $i, 5))];
        }
        return $result;
    }

    private static function base32Decode(string $base32): string {
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

    public static function generateSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    public static function generateCode(string $secret): string {
        $time = time();
        $step = 30;
        $timeStep = (int)floor($time / $step);
        $timeHex = str_pad(dechex($timeStep), 8, '0', STR_PAD_LEFT);
        $timeBytes = pack('H*', $timeHex);
        $hash = hash_hmac('sha1', $timeBytes, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
                | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8)
                | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    public static function verifyCode(string $secret, string $code): bool {
        $code = strtoupper(trim((string)$code));
        if (!preg_match('/^[0-9]{6}$/', $code)) return false;
        for ($drift = -1; $drift <= 1; $drift++) {
            $timeStep = (int)floor((time() + ($drift * 30)) / 30);
            $timeHex = str_pad(dechex($timeStep), 8, '0', STR_PAD_LEFT);
            $timeBytes = pack('H*', $timeHex);
            $hash = hash_hmac('sha1', $timeBytes, self::base32Decode($secret), true);
            $offset = ord($hash[19]) & 0x0f;
            $binary = ((ord($hash[$offset]) & 0x7f) << 24)
                    | ((ord($hash[$offset + 1]) & 0xff) << 16)
                    | ((ord($hash[$offset + 2]) & 0xff) << 8)
                    | (ord($hash[$offset + 3]) & 0xff);
            $otp = $binary % 1000000;
            $expected = str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    public static function getQrCodeUrl(string $secret, string $issuer, string $account): string {
        $encodedSecret = str_replace('=', '', $secret);
        $label = urlencode($issuer . ':' . $account);
        $params = http_build_query([
            'secret' => $encodedSecret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }
}
