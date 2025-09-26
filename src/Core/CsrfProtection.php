<?php

namespace SecurityScanner\Core;

class CsrfProtection
{
    private const TOKEN_NAME = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-TOKEN';
    private const SESSION_KEY = '_csrf_tokens';
    private const TOKEN_LENGTH = 32;
    private const MAX_TOKENS = 100;

    private array $exemptPaths = [];
    private bool $useSession = true;
    private int $tokenLifetime = 3600; // 1 hour in seconds

    public function __construct(bool $useSession = true, int $tokenLifetime = 3600)
    {
        $this->useSession = $useSession;
        $this->tokenLifetime = $tokenLifetime;
    }

    /**
     * Generate a new CSRF token
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $timestamp = time();

        if ($this->useSession) {
            $this->storeTokenInSession($token, $timestamp);
        }

        return $token;
    }

    /**
     * Validate CSRF token from request
     */
    public function validateToken(Request $request): bool
    {
        if ($this->isExemptPath($request->getPath())) {
            return true;
        }

        if (!$this->requiresCsrfProtection($request->getMethod())) {
            return true;
        }

        $token = $this->getTokenFromRequest($request);

        if (!$token) {
            return false;
        }

        return $this->isValidToken($token);
    }

    /**
     * Create middleware closure for CSRF protection
     */
    public function middleware(): \Closure
    {
        return function(Request $request, \Closure $next) {
            if (!$this->validateToken($request)) {
                return Response::error('CSRF token mismatch', 419);
            }

            return $next($request);
        };
    }

    /**
     * Add exempt path (path that doesn't require CSRF protection)
     */
    public function exemptPath(string $path): self
    {
        $this->exemptPaths[] = $this->normalizePath($path);
        return $this;
    }

    /**
     * Add multiple exempt paths
     */
    public function exemptPaths(array $paths): self
    {
        foreach ($paths as $path) {
            $this->exemptPath($path);
        }
        return $this;
    }

    /**
     * Get token for forms (includes hidden input field)
     */
    public function getTokenField(): string
    {
        $token = $this->generateToken();
        return '<input type="hidden" name="' . self::TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Get token value only
     */
    public function getToken(): string
    {
        return $this->generateToken();
    }

    /**
     * Get token for meta tag (for AJAX requests)
     */
    public function getMetaTag(): string
    {
        $token = $this->generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * Verify if request method requires CSRF protection
     */
    private function requiresCsrfProtection(string $method): bool
    {
        $protectedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        return in_array(strtoupper($method), $protectedMethods);
    }

    /**
     * Get CSRF token from request
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // Try to get token from POST data first
        $token = $request->input(self::TOKEN_NAME);

        if (!$token) {
            // Try to get from custom header (for AJAX requests)
            $token = $request->getHeader(self::HEADER_NAME);
        }

        if (!$token) {
            // Try to get from Authorization header (Bearer format)
            $authHeader = $request->getHeader('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }

        return $token;
    }

    /**
     * Check if token is valid
     */
    private function isValidToken(string $token): bool
    {
        if (!$this->useSession) {
            // If not using session, implement your own storage mechanism
            // For now, we'll just validate the format
            return $this->isValidTokenFormat($token);
        }

        if (!$this->hasValidSession()) {
            return false;
        }

        $storedTokens = $_SESSION[self::SESSION_KEY] ?? [];

        foreach ($storedTokens as $storedToken => $timestamp) {
            if (hash_equals($storedToken, $token)) {
                // Check if token hasn't expired
                if (time() - $timestamp > $this->tokenLifetime) {
                    $this->removeExpiredTokens();
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Store token in session
     */
    private function storeTokenInSession(string $token, int $timestamp): void
    {
        if (!$this->hasValidSession()) {
            $this->startSession();
        }

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        // Store the new token
        $_SESSION[self::SESSION_KEY][$token] = $timestamp;

        // Limit the number of stored tokens to prevent memory issues
        if (count($_SESSION[self::SESSION_KEY]) > self::MAX_TOKENS) {
            $this->cleanupOldTokens();
        }
    }

    /**
     * Check if we have a valid session
     */
    private function hasValidSession(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Start session if not already started
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session configuration
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $this->isSecureRequest() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');

            session_start();
        }
    }

    /**
     * Check if current request is over HTTPS
     */
    private function isSecureRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               $_SERVER['SERVER_PORT'] == 443 ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Remove expired tokens from session
     */
    private function removeExpiredTokens(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $currentTime = time();
        $validTokens = [];

        foreach ($_SESSION[self::SESSION_KEY] as $token => $timestamp) {
            if ($currentTime - $timestamp <= $this->tokenLifetime) {
                $validTokens[$token] = $timestamp;
            }
        }

        $_SESSION[self::SESSION_KEY] = $validTokens;
    }

    /**
     * Clean up old tokens to prevent memory issues
     */
    private function cleanupOldTokens(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        // Remove expired tokens first
        $this->removeExpiredTokens();

        // If still too many tokens, remove the oldest ones
        if (count($_SESSION[self::SESSION_KEY]) > self::MAX_TOKENS) {
            asort($_SESSION[self::SESSION_KEY]); // Sort by timestamp
            $_SESSION[self::SESSION_KEY] = array_slice($_SESSION[self::SESSION_KEY], -self::MAX_TOKENS, null, true);
        }
    }

    /**
     * Check if path is exempt from CSRF protection
     */
    private function isExemptPath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);

        foreach ($this->exemptPaths as $exemptPath) {
            if ($this->pathMatches($normalizedPath, $exemptPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize path for comparison
     */
    private function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /**
     * Check if path matches pattern (supports wildcards)
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        // Simple wildcard matching
        if (str_contains($pattern, '*')) {
            $pattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
            return (bool)preg_match('/^' . $pattern . '$/i', $path);
        }

        return $path === $pattern;
    }

    /**
     * Validate token format
     */
    private function isValidTokenFormat(string $token): bool
    {
        return strlen($token) === (self::TOKEN_LENGTH * 2) && ctype_xdigit($token);
    }

    /**
     * Get current token configuration
     */
    public function getConfiguration(): array
    {
        return [
            'token_name' => self::TOKEN_NAME,
            'header_name' => self::HEADER_NAME,
            'use_session' => $this->useSession,
            'token_lifetime' => $this->tokenLifetime,
            'exempt_paths' => $this->exemptPaths,
            'session_active' => $this->hasValidSession()
        ];
    }

    /**
     * Generate token for API responses
     */
    public function getTokenForApi(): array
    {
        return [
            'csrf_token' => $this->generateToken(),
            'token_name' => self::TOKEN_NAME,
            'header_name' => self::HEADER_NAME
        ];
    }

    /**
     * Invalidate all tokens for current session
     */
    public function invalidateTokens(): void
    {
        if ($this->hasValidSession() && isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * Double submit cookie CSRF protection (alternative to session-based)
     */
    public function generateDoubleSubmitCookie(): string
    {
        $token = $this->generateToken();

        // Set secure cookie with the token
        $secure = $this->isSecureRequest();
        setcookie('csrf_token', $token, [
            'expires' => time() + $this->tokenLifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false, // Needs to be accessible via JavaScript for AJAX
            'samesite' => 'Strict'
        ]);

        return $token;
    }

    /**
     * Validate double submit cookie
     */
    public function validateDoubleSubmitCookie(Request $request): bool
    {
        $cookieToken = $_COOKIE['csrf_token'] ?? null;
        $requestToken = $this->getTokenFromRequest($request);

        if (!$cookieToken || !$requestToken) {
            return false;
        }

        return hash_equals($cookieToken, $requestToken);
    }

    /**
     * Create Response with CSRF token header
     */
    public function withTokenHeader(Response $response): Response
    {
        return $response->setHeader('X-CSRF-TOKEN', $this->generateToken());
    }
}