<?php

namespace SecurityScanner\Core;

class SessionManager
{
    private array $config;
    private bool $started = false;
    private array $flashData = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'name' => 'security_scanner_session',
            'lifetime' => 3600, // 1 hour
            'path' => '/',
            'domain' => '',
            'secure' => true, // Always use secure cookies in production
            'httponly' => true,
            'samesite' => 'Strict',
            'regenerate_interval' => 300, // 5 minutes
            'gc_maxlifetime' => 1440, // 24 minutes
            'gc_probability' => 1,
            'gc_divisor' => 100,
            'use_strict_mode' => true,
            'cookie_httponly' => true,
            'cookie_secure' => true,
            'use_cookies' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cache_limiter' => 'nocache',
            'entropy_length' => 32,
            'hash_function' => 'sha256'
        ], $config);
    }

    /**
     * Start session with security configurations
     */
    public function start(): bool
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $this->configureSession();

        if (!session_start()) {
            return false;
        }

        $this->started = true;

        // Initialize session security measures
        $this->initializeSessionSecurity();

        // Load flash data
        $this->loadFlashData();

        return true;
    }

    /**
     * Configure session settings for security
     */
    private function configureSession(): void
    {
        // Session configuration
        ini_set('session.name', $this->config['name']);
        ini_set('session.cookie_lifetime', (string)$this->config['lifetime']);
        ini_set('session.cookie_path', $this->config['path']);
        ini_set('session.cookie_domain', $this->config['domain']);
        ini_set('session.cookie_secure', $this->config['secure'] ? '1' : '0');
        ini_set('session.cookie_httponly', $this->config['httponly'] ? '1' : '0');
        ini_set('session.cookie_samesite', $this->config['samesite']);
        ini_set('session.use_strict_mode', $this->config['use_strict_mode'] ? '1' : '0');
        ini_set('session.use_cookies', $this->config['use_cookies'] ? '1' : '0');
        ini_set('session.use_only_cookies', $this->config['use_only_cookies'] ? '1' : '0');
        ini_set('session.use_trans_sid', $this->config['use_trans_sid'] ? '1' : '0');
        ini_set('session.cache_limiter', $this->config['cache_limiter']);
        ini_set('session.gc_maxlifetime', (string)$this->config['gc_maxlifetime']);
        ini_set('session.gc_probability', (string)$this->config['gc_probability']);
        ini_set('session.gc_divisor', (string)$this->config['gc_divisor']);
        ini_set('session.entropy_length', (string)$this->config['entropy_length']);
        ini_set('session.hash_function', $this->config['hash_function']);

        // Additional security configurations
        ini_set('session.sid_length', '128');
        ini_set('session.sid_bits_per_character', '6');
    }

    /**
     * Initialize session security measures
     */
    private function initializeSessionSecurity(): void
    {
        // Initialize tracking data if not exists
        if (!isset($_SESSION['_security'])) {
            $_SESSION['_security'] = [
                'created_at' => time(),
                'last_activity' => time(),
                'last_regeneration' => time(),
                'ip_address' => $this->getClientIpAddress(),
                'user_agent' => $this->getUserAgent(),
                'fingerprint' => $this->generateFingerprint()
            ];
        }

        // Update last activity
        $_SESSION['_security']['last_activity'] = time();

        // Check session validity
        $this->validateSession();

        // Auto-regenerate session ID if needed
        $this->autoRegenerateId();
    }

    /**
     * Validate session security
     */
    private function validateSession(): void
    {
        $security = $_SESSION['_security'] ?? [];

        // Check session timeout
        if (isset($security['last_activity'])) {
            $inactive = time() - $security['last_activity'];
            if ($inactive > $this->config['lifetime']) {
                $this->destroy();
                return;
            }
        }

        // Check IP address consistency (optional security measure)
        if (isset($security['ip_address'])) {
            $currentIp = $this->getClientIpAddress();
            if ($security['ip_address'] !== $currentIp) {
                // Option to destroy session on IP change (strict mode)
                // $this->destroy();
                // For now, just log the change
                error_log("Session IP change detected: {$security['ip_address']} -> {$currentIp}");
            }
        }

        // Check user agent consistency
        if (isset($security['user_agent'])) {
            $currentUserAgent = $this->getUserAgent();
            if ($security['user_agent'] !== $currentUserAgent) {
                // Option to destroy session on user agent change (strict mode)
                // $this->destroy();
                error_log("Session user agent change detected");
            }
        }

        // Check session fingerprint
        if (isset($security['fingerprint'])) {
            $currentFingerprint = $this->generateFingerprint();
            if ($security['fingerprint'] !== $currentFingerprint) {
                error_log("Session fingerprint mismatch detected");
            }
        }
    }

    /**
     * Auto-regenerate session ID for security
     */
    private function autoRegenerateId(): void
    {
        $security = $_SESSION['_security'] ?? [];

        if (isset($security['last_regeneration'])) {
            $timeSinceRegeneration = time() - $security['last_regeneration'];
            if ($timeSinceRegeneration > $this->config['regenerate_interval']) {
                $this->regenerateId();
            }
        }
    }

    /**
     * Regenerate session ID
     */
    public function regenerateId(bool $deleteOldSession = true): bool
    {
        if (!$this->isStarted()) {
            return false;
        }

        if (session_regenerate_id($deleteOldSession)) {
            $_SESSION['_security']['last_regeneration'] = time();
            return true;
        }

        return false;
    }

    /**
     * Set session value
     */
    public function set(string $key, $value): void
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Get session value
     */
    public function get(string $key, $default = null)
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if session has key
     */
    public function has(string $key): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        return isset($_SESSION[$key]);
    }

    /**
     * Remove session value
     */
    public function remove(string $key): void
    {
        if (!$this->isStarted()) {
            return;
        }

        unset($_SESSION[$key]);
    }

    /**
     * Get all session data
     */
    public function all(): array
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        return $_SESSION;
    }

    /**
     * Clear all session data except security data
     */
    public function clear(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $security = $_SESSION['_security'] ?? [];
        $flashNext = $_SESSION['_flash_next'] ?? [];

        session_unset();

        $_SESSION['_security'] = $security;
        $_SESSION['_flash_next'] = $flashNext;
    }

    /**
     * Destroy session completely
     */
    public function destroy(): bool
    {
        if (!$this->isStarted()) {
            return true;
        }

        session_unset();

        if (session_destroy()) {
            $this->started = false;

            // Delete the session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Check if session is started
     */
    public function isStarted(): bool
    {
        return $this->started && session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Get session ID
     */
    public function getId(): string
    {
        return session_id();
    }

    /**
     * Set session ID
     */
    public function setId(string $id): void
    {
        session_id($id);
    }

    /**
     * Flash data functionality - set data for next request only
     */
    public function flash(string $key, $value): void
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        if (!isset($_SESSION['_flash_next'])) {
            $_SESSION['_flash_next'] = [];
        }

        $_SESSION['_flash_next'][$key] = $value;
    }

    /**
     * Get flash data
     */
    public function getFlash(string $key, $default = null)
    {
        return $this->flashData[$key] ?? $default;
    }

    /**
     * Check if flash data exists
     */
    public function hasFlash(string $key): bool
    {
        return isset($this->flashData[$key]);
    }

    /**
     * Load flash data from session
     */
    private function loadFlashData(): void
    {
        if (isset($_SESSION['_flash_current'])) {
            $this->flashData = $_SESSION['_flash_current'];
            unset($_SESSION['_flash_current']);
        }

        if (isset($_SESSION['_flash_next'])) {
            $_SESSION['_flash_current'] = $_SESSION['_flash_next'];
            unset($_SESSION['_flash_next']);
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIpAddress(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get user agent
     */
    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Generate session fingerprint
     */
    private function generateFingerprint(): string
    {
        $components = [
            $this->getUserAgent(),
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get session metadata
     */
    public function getMetadata(): array
    {
        if (!$this->isStarted()) {
            return [];
        }

        $security = $_SESSION['_security'] ?? [];

        return [
            'id' => $this->getId(),
            'created_at' => $security['created_at'] ?? null,
            'last_activity' => $security['last_activity'] ?? null,
            'last_regeneration' => $security['last_regeneration'] ?? null,
            'ip_address' => $security['ip_address'] ?? null,
            'user_agent' => $security['user_agent'] ?? null,
            'lifetime' => $this->config['lifetime'],
            'is_secure' => $this->config['secure'],
            'is_httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ];
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        if (!$this->isStarted()) {
            return true;
        }

        $security = $_SESSION['_security'] ?? [];

        if (!isset($security['last_activity'])) {
            return true;
        }

        $inactive = time() - $security['last_activity'];
        return $inactive > $this->config['lifetime'];
    }

    /**
     * Extend session lifetime
     */
    public function extend(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION['_security']['last_activity'] = time();
    }

    /**
     * Get remaining session time
     */
    public function getRemainingTime(): int
    {
        if (!$this->isStarted()) {
            return 0;
        }

        $security = $_SESSION['_security'] ?? [];

        if (!isset($security['last_activity'])) {
            return 0;
        }

        $elapsed = time() - $security['last_activity'];
        $remaining = $this->config['lifetime'] - $elapsed;

        return max(0, $remaining);
    }

    /**
     * Create session manager instance with default secure configuration
     */
    public static function createSecure(array $overrides = []): self
    {
        $defaultConfig = [
            'secure' => !empty($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443,
            'httponly' => true,
            'samesite' => 'Strict',
            'use_strict_mode' => true,
            'regenerate_interval' => 300, // 5 minutes
            'lifetime' => 3600 // 1 hour
        ];

        return new self(array_merge($defaultConfig, $overrides));
    }

    /**
     * Export session data for debugging (excluding sensitive data)
     */
    public function toArray(): array
    {
        if (!$this->isStarted()) {
            return [];
        }

        $data = $_SESSION;

        // Remove sensitive security data from export
        if (isset($data['_security'])) {
            $security = $data['_security'];
            unset($security['fingerprint'], $security['ip_address'], $security['user_agent']);
            $data['_security'] = $security;
        }

        return $data;
    }
}