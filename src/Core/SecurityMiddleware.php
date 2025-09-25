<?php

namespace SecurityScanner\Core;

class SecurityMiddleware
{
    private Config $config;
    private Logger $logger;
    private SecurityHeaders $securityHeaders;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::security();
        $this->securityHeaders = new SecurityHeaders();
    }

    /**
     * Handle security middleware
     */
    public function handle(Request $request, Response $response): ?Response
    {
        // Apply security headers
        $this->securityHeaders->apply($response);

        // Rate limiting
        if ($this->shouldApplyRateLimit($request)) {
            $rateLimit = $this->checkRateLimit($request);
            if ($rateLimit !== null) {
                return $rateLimit;
            }
        }

        // IP blocking
        if ($this->isBlockedIP($request)) {
            return $this->blockResponse('IP address blocked');
        }

        // User agent filtering
        if ($this->isSuspiciousUserAgent($request)) {
            return $this->blockResponse('Suspicious user agent detected');
        }

        // Request size limiting
        if ($this->exceedsMaxRequestSize($request)) {
            return $this->blockResponse('Request size too large');
        }

        // SQL injection detection
        if ($this->detectSQLInjection($request)) {
            return $this->blockResponse('Potential SQL injection detected');
        }

        // XSS detection
        if ($this->detectXSS($request)) {
            return $this->blockResponse('Potential XSS attack detected');
        }

        return null; // Continue processing
    }

    /**
     * Check if rate limiting should be applied
     */
    private function shouldApplyRateLimit(Request $request): bool
    {
        return $this->config->get('security.rate_limiting_enabled', true);
    }

    /**
     * Check rate limit for request
     */
    private function checkRateLimit(Request $request): ?Response
    {
        $clientIP = $request->getClientIp();
        $limit = $this->config->get('security.rate_limit', 60);
        $window = $this->config->get('security.rate_window', 60); // seconds

        // Use simple file-based rate limiting (in production, use Redis/Memcached)
        $cacheDir = $this->config->get('cache.path', '/tmp/security_scanner_cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $rateFile = $cacheDir . '/rate_limit_' . md5($clientIP);

        $now = time();
        $requests = [];

        if (file_exists($rateFile)) {
            $data = file_get_contents($rateFile);
            $requests = json_decode($data, true) ?: [];
        }

        // Clean old requests
        $requests = array_filter($requests, function($timestamp) use ($now, $window) {
            return $now - $timestamp < $window;
        });

        if (count($requests) >= $limit) {
            $this->logger->warning('Rate limit exceeded', [
                'ip' => $clientIP,
                'requests' => count($requests),
                'limit' => $limit,
                'window' => $window,
                'user_agent' => $request->getHeader('User-Agent'),
                'uri' => $request->getUri()
            ]);

            $response = new Response();
            return $response->error(429, 'Too Many Requests')
                ->setHeader('Retry-After', (string)$window)
                ->setHeader('X-RateLimit-Limit', (string)$limit)
                ->setHeader('X-RateLimit-Remaining', '0')
                ->setHeader('X-RateLimit-Reset', (string)($now + $window));
        }

        // Add current request
        $requests[] = $now;

        // Save requests
        file_put_contents($rateFile, json_encode($requests));

        return null;
    }

    /**
     * Check if IP is blocked
     */
    private function isBlockedIP(Request $request): bool
    {
        $clientIP = $request->getClientIp();
        $blockedIPs = $this->config->get('security.blocked_ips', []);

        foreach ($blockedIPs as $blockedIP) {
            if ($this->ipMatches($clientIP, $blockedIP)) {
                $this->logger->warning('Blocked IP attempted access', [
                    'ip' => $clientIP,
                    'blocked_pattern' => $blockedIP,
                    'user_agent' => $request->getHeader('User-Agent'),
                    'uri' => $request->getUri()
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches pattern (supports CIDR notation)
     */
    private function ipMatches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        // CIDR notation support
        if (str_contains($pattern, '/')) {
            [$network, $prefixLength] = explode('/', $pattern);
            $prefixLength = (int)$prefixLength;

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $this->ipv4InRange($ip, $network, $prefixLength);
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $this->ipv6InRange($ip, $network, $prefixLength);
            }
        }

        return false;
    }

    /**
     * Check if IPv4 is in range
     */
    private function ipv4InRange(string $ip, string $network, int $prefixLength): bool
    {
        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);

        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefixLength);
        return ($ipLong & $mask) === ($networkLong & $mask);
    }

    /**
     * Check if IPv6 is in range (simplified)
     */
    private function ipv6InRange(string $ip, string $network, int $prefixLength): bool
    {
        // Simplified IPv6 range checking - in production use more robust implementation
        $ipBin = inet_pton($ip);
        $networkBin = inet_pton($network);

        if ($ipBin === false || $networkBin === false) {
            return false;
        }

        $byteLength = $prefixLength >> 3; // Number of full bytes
        $bitLength = $prefixLength & 7;   // Remaining bits

        // Compare full bytes
        if (substr($ipBin, 0, $byteLength) !== substr($networkBin, 0, $byteLength)) {
            return false;
        }

        // Compare remaining bits if any
        if ($bitLength > 0 && $byteLength < strlen($ipBin)) {
            $mask = 0xFF << (8 - $bitLength);
            $ipByte = ord($ipBin[$byteLength]);
            $networkByte = ord($networkBin[$byteLength]);

            return ($ipByte & $mask) === ($networkByte & $mask);
        }

        return true;
    }

    /**
     * Check for suspicious user agents
     */
    private function isSuspiciousUserAgent(Request $request): bool
    {
        $userAgent = $request->getHeader('user-agent', '');
        $suspiciousPatterns = $this->config->get('security.suspicious_user_agents', [
            'sqlmap',
            'nikto',
            'nmap',
            'masscan',
            'nessus',
            'burpsuite',
            'acunetix',
            'appscan',
            'paros',
            'havij',
            'sqlninja'
        ]);

        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                $this->logger->warning('Suspicious user agent detected', [
                    'user_agent' => $userAgent,
                    'pattern' => $pattern,
                    'ip' => $request->getClientIp(),
                    'uri' => $request->getUri()
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if request exceeds maximum size
     */
    private function exceedsMaxRequestSize(Request $request): bool
    {
        $maxSize = $this->config->get('security.max_request_size', 10485760); // 10MB default

        $contentLength = (int)$request->getHeader('Content-Length', 0);

        if ($contentLength > $maxSize) {
            $this->logger->warning('Request size exceeded limit', [
                'content_length' => $contentLength,
                'max_size' => $maxSize,
                'ip' => $request->getClientIp(),
                'uri' => $request->getUri()
            ]);
            return true;
        }

        return false;
    }

    /**
     * Detect potential SQL injection attempts
     */
    private function detectSQLInjection(Request $request): bool
    {
        $patterns = $this->config->get('security.sql_injection_patterns', [
            '/union\s+select/i',
            '/select\s+.+\s+from/i',
            '/insert\s+into/i',
            '/update\s+.+\s+set/i',
            '/delete\s+from/i',
            '/drop\s+table/i',
            '/create\s+table/i',
            '/alter\s+table/i',
            '/exec\s*\(/i',
            '/\'.*or.*1.*=.*1/i',
            '/\'.*or.*\'.*=.*\'/i',
            '/--/',
            '/\/\*.*\*\//s'
        ]);

        $input = array_merge($request->all(), [$request->getUri()]);

        foreach ($input as $value) {
            if (is_string($value)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $this->logger->warning('Potential SQL injection detected', [
                            'pattern' => $pattern,
                            'value' => substr($value, 0, 200), // Limit logged value length
                            'ip' => $request->getClientIp(),
                            'uri' => $request->getUri(),
                            'user_agent' => $request->getHeader('User-Agent')
                        ]);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Detect potential XSS attempts
     */
    private function detectXSS(Request $request): bool
    {
        $patterns = $this->config->get('security.xss_patterns', [
            '/<script/i',
            '/<\/script>/i',
            '/javascript:/i',
            '/onclick=/i',
            '/onload=/i',
            '/onerror=/i',
            '/onmouseover=/i',
            '/onfocus=/i',
            '/onblur=/i',
            '/<iframe/i',
            '/<embed/i',
            '/<object/i',
            '/expression\s*\(/i',
            '/vbscript:/i'
        ]);

        $input = array_merge($request->all(), [$request->getUri()]);

        foreach ($input as $value) {
            if (is_string($value)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $this->logger->warning('Potential XSS attack detected', [
                            'pattern' => $pattern,
                            'value' => substr($value, 0, 200),
                            'ip' => $request->getClientIp(),
                            'uri' => $request->getUri(),
                            'user_agent' => $request->getHeader('User-Agent')
                        ]);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Create block response
     */
    private function blockResponse(string $reason): Response
    {
        $response = new Response();
        $response->setStatusCode(403)
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('X-Security-Block-Reason', $reason)
            ->setBody(json_encode([
                'error' => true,
                'message' => 'Access Forbidden',
                'reason' => $reason
            ]));
        return $response;
    }

    /**
     * Get security statistics
     */
    public function getSecurityStats(): array
    {
        // This could be enhanced to read from persistent storage
        return [
            'rate_limit_enabled' => $this->config->get('security.rate_limiting_enabled', true),
            'rate_limit' => $this->config->get('security.rate_limit', 60),
            'blocked_ips_count' => count($this->config->get('security.blocked_ips', [])),
            'suspicious_patterns_count' => count($this->config->get('security.suspicious_user_agents', [])),
            'max_request_size' => $this->config->get('security.max_request_size', 10485760)
        ];
    }
}