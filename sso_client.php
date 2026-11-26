<?php

/**
 * SSO Bootstrap for PHP Native Apps
 *
 * Include this file at the TOP of every PHP file that needs authentication:
 *   require_once __DIR__ . '/../sso_bootstrap.php';
 *
 * Or create a central index.php that includes this and routes requests.
 */

declare(strict_types=1);

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

require_once __DIR__ . '/core/session-init.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SsoClient
{
    private string $secretKey;

    private string $cookieName;

    private string $ssoServerUrl;

    private string $currentAppUrl;

    private ?array $user = null;

    public function __construct(array $config)
    {
        $this->secretKey = $config['secret_key'] ?? $config['sso_secret'] ?? '94e75c829da11a0ebfae57b328a994ef55e4b78912c30da765e9fa451296cd31';
        $this->cookieName = $config['cookie_name'] ?? 'veyra_sso';
        $this->ssoServerUrl = rtrim($config['sso_server_url'] ?? 'https://veyracreative.site', '/');
        $this->currentAppUrl = rtrim($config['current_app_url'] ?? '', '/');
    }

    /**
     * Get current authenticated user (from cookie or session)
     */
    public function getUser(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        // 1. Try cookie first (shared domain)
        $this->user = $this->validateCookie();
        if ($this->user) {
            $this->startLocalSession($this->user);

            return $this->user;
        }

        // 2. Try local session
        $this->user = $this->getFromLocalSession();
        if ($this->user) {
            return $this->user;
        }

        // 3. Try query param token (from SSO redirect)
        $this->user = $this->validateQueryToken();
        if ($this->user) {
            $this->startLocalSession($this->user);

            return $this->user;
        }

        return null;
    }

    /**
     * Validate SSO cookie
     */
    private function validateCookie(): ?array
    {
        $token = $_COOKIE[$this->cookieName] ?? null;
        if (! $token) {
            return null;
        }

        return $this->decodeToken($token);
    }

    /**
     * Validate token from query param (public for callback handler)
     */
    public function validateQueryToken(): ?array
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        if (! $token) {
            return null;
        }

        $user = $this->decodeToken($token);
        if ($user) {
            // Set cookie for future requests (if same domain)
            $this->setCookie($token);
        }

        return $user;
    }

    /**
     * Decode and validate JWT token
     */
    private function decodeToken(string $token): ?array
    {
        $candidateKeys = array_values(array_unique(array_filter([
            $this->secretKey,
        ])));

        $payload = null;

        foreach ($candidateKeys as $keyCandidate) {
            try {
                if (class_exists(JWT::class) && class_exists(Key::class)) {
                    JWT::$leeway = 300; // 5 menit toleransi perbedaan jam server
                    $decoded = JWT::decode($token, new Key($keyCandidate, 'HS256'));
                    $candidatePayload = json_decode((string) json_encode($decoded), true);
                    if (is_array($candidatePayload)) {
                        $payload = $candidatePayload;
                        break;
                    }
                } else {
                    // Fallback: validasi manual jika Firebase/JWT tidak tersedia
                    $parts = explode('.', $token);
                    if (count($parts) === 3) {
                        [$headerB64, $payloadB64, $sigB64] = $parts;
                        $decodedHeader = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
                        // Paksa alg harus HS256 — cegah algorithm confusion / none attack
                        if (is_array($decodedHeader) && strtoupper((string)($decodedHeader['alg'] ?? '')) !== 'HS256') {
                            continue;
                        }
                        $sig = base64_decode(strtr($sigB64, '-_', '+/'));
                        $expectedSig = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $keyCandidate, true);
                        if (hash_equals($expectedSig, $sig)) {
                            $candidatePayload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
                            if (is_array($candidatePayload)) {
                                $payload = $candidatePayload;
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Lanjut coba kandidat kunci berikutnya
            }
        }

        if (! is_array($payload)) {
            error_log('[SSO Debug] Token signature verification failed with all candidate keys.');

            return null;
        }

        // Check expiration dengan toleransi clock-skew 300 detik (5 menit)
        if (isset($payload['exp']) && $payload['exp'] < (time() - 300)) {
            error_log('[SSO Debug] Token expired! exp: '.$payload['exp'].', now: '.time());

            return null;
        }

        if (isset($payload['user']) && is_array($payload['user'])) {
            return array_merge($payload, $payload['user']);
        }

        return $payload;
    }

    /**
     * Start local PHP session with user data
     */
    private function startLocalSession(array $user): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['sso_user'] = $user;
        $_SESSION['sso_authenticated_at'] = time();
    }

    /**
     * Get user from local session
     */
    private function getFromLocalSession(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION['sso_user'] ?? null;
    }

    /**
     * Set SSO cookie
     */
    private function setCookie(string $token): void
    {
        $cookieDomain = $this->getCookieDomain();
        $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (! empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        $options = [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => $cookieDomain ?: '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($this->cookieName, $token, $options);
    }

    /**
     * Get cookie domain for current app
     */
    private function getCookieDomain(): ?string
    {
        $host = parse_url($this->currentAppUrl, PHP_URL_HOST);
        if (! $host) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $host = explode(':', $host)[0];
        }

        // Extract root domain (misal: veyracreative.site dari cbt.veyracreative.site)
        $parts = explode('.', $host);
        if (count($parts) >= 2 && ! filter_var($host, FILTER_VALIDATE_IP) && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return '.'.implode('.', array_slice($parts, -2));
        }

        return null;
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->getUser() !== null;
    }

    /**
     * Require authentication - redirect to SSO login if not authenticated
     */
    public function requireAuth(): array
    {
        $user = $this->getUser();

        if (! $user) {
            $this->redirectToSsoLogin();
        }

        return $user;
    }

    /**
     * Get SSO login URL
     */
    public function getLoginUrl(): string
    {
        $callbackUrl = urlencode($this->currentAppUrl.'/sso/callback.php');

        return $this->ssoServerUrl.'/sso/redirect?app='.$callbackUrl;
    }

    /**
     * Redirect to SSO server for login
     */
    public function redirectToSsoLogin(): never
    {
        header('Location: '.$this->getLoginUrl());
        exit;
    }

    /**
     * Logout - clear local session and redirect to SSO logout
     */
    public function logout(): never
    {
        // Clear local session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        session_destroy();

        // Clear cookie
        $cookieDomain = $this->getCookieDomain();
        $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (! empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        $options = [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => $cookieDomain ?: '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($this->cookieName, '', $options);

        // Redirect to SSO server logout
        $logoutUrl = $this->ssoServerUrl.'/sso/logout';
        header('Location: '.$logoutUrl);
        exit;
    }

    /**
     * Get user ID
     */
    public function getUserId(): ?int
    {
        $user = $this->getUser();

        return $user['id'] ?? null;
    }

    /**
     * Get user school ID
     */
    public function getSchoolId(): ?int
    {
        $user = $this->getUser();

        return $user['school_id'] ?? null;
    }

    /**
     * Check if user has specific role/permission (customize as needed)
     */
    public function hasRole(string $role): bool
    {
        // Implement based on your user data structure
        return false;
    }
}

// Helper function for easy access
function sso(): SsoClient
{
    static $instance = null;

    if ($instance === null) {
        $configFile = __DIR__.'/config/sso.php';
        $ssoCfg = file_exists($configFile) ? (require $configFile) : [];

        // Auto-detect current app URL if not provided
        $detectedAppUrl = '';
        if (! empty($_SERVER['HTTP_HOST'])) {
            $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $detectedAppUrl = $scheme.'://'.$_SERVER['HTTP_HOST'];
            $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '\\') {
                $baseDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
                if (in_array(basename($baseDir), ['sso', 'admin', 'superadmin', 'student', 'api'], true)) {
                    $baseDir = dirname($baseDir);
                }
                if ($baseDir && $baseDir !== '/' && $baseDir !== '.') {
                    $detectedAppUrl .= $baseDir;
                }
            }
        }

        $secret = getenv('SSO_SECRET_KEY') ?: ($_ENV['SSO_SECRET_KEY'] ?? ($ssoCfg['sso_secret'] ?? ($ssoCfg['secret_key'] ?? '94e75c829da11a0ebfae57b328a994ef55e4b78912c30da765e9fa451296cd31')));
        $cookieName = getenv('SSO_COOKIE_NAME') ?: ($_ENV['SSO_COOKIE_NAME'] ?? ($ssoCfg['cookie_name'] ?? 'veyra_sso'));
        $ssoServer = getenv('SSO_SERVER_URL') ?: ($_ENV['SSO_SERVER_URL'] ?? ($ssoCfg['sso_server_url'] ?? 'https://veyracreative.site'));
        $appUrl = getenv('CURRENT_APP_URL') ?: ($_ENV['CURRENT_APP_URL'] ?? (! empty($ssoCfg['current_app_url']) ? $ssoCfg['current_app_url'] : ($detectedAppUrl ?: 'https://cbt.veyracreative.site')));

        $config = [
            'secret_key' => $secret,
            'cookie_name' => $cookieName,
            'sso_server_url' => $ssoServer,
            'current_app_url' => $appUrl,
        ];

        $instance = new SsoClient($config);
    }

    return $instance;
}

// Auto-start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
