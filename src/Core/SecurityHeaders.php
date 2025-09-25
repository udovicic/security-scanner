<?php

namespace SecurityScanner\Core;

class SecurityHeaders
{
    private Config $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::security();
    }

    /**
     * Apply all security headers to response
     */
    public function apply(Response $response): void
    {
        $this->applyFrameOptions($response);
        $this->applyContentTypeOptions($response);
        $this->applyXSSProtection($response);
        $this->applyReferrerPolicy($response);
        $this->applyContentSecurityPolicy($response);
        $this->applyPermissionsPolicy($response);
        $this->applyCacheHeaders($response);
        $this->applyHTTPS($response);

        $this->logger->debug('Security headers applied', [
            'headers' => array_keys($response->getHeaders())
        ]);
    }

    /**
     * Apply X-Frame-Options header to prevent clickjacking
     */
    private function applyFrameOptions(Response $response): void
    {
        $frameOptions = $this->config->get('security.frame_options', 'DENY');

        // Valid options: DENY, SAMEORIGIN, ALLOW-FROM uri
        if (!in_array($frameOptions, ['DENY', 'SAMEORIGIN']) && !str_starts_with($frameOptions, 'ALLOW-FROM ')) {
            $frameOptions = 'DENY';
            $this->logger->warning('Invalid frame options, defaulting to DENY', [
                'provided' => $frameOptions
            ]);
        }

        $response->setHeader('X-Frame-Options', $frameOptions);
    }

    /**
     * Apply X-Content-Type-Options header to prevent MIME sniffing
     */
    private function applyContentTypeOptions(Response $response): void
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Apply X-XSS-Protection header
     */
    private function applyXSSProtection(Response $response): void
    {
        $xssProtection = $this->config->get('security.xss_protection', '1; mode=block');
        $response->setHeader('X-XSS-Protection', $xssProtection);
    }

    /**
     * Apply Referrer-Policy header
     */
    private function applyReferrerPolicy(Response $response): void
    {
        $referrerPolicy = $this->config->get('security.referrer_policy', 'strict-origin-when-cross-origin');

        $validPolicies = [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
            'unsafe-url'
        ];

        if (!in_array($referrerPolicy, $validPolicies)) {
            $referrerPolicy = 'strict-origin-when-cross-origin';
            $this->logger->warning('Invalid referrer policy, using default', [
                'provided' => $referrerPolicy,
                'default' => 'strict-origin-when-cross-origin'
            ]);
        }

        $response->setHeader('Referrer-Policy', $referrerPolicy);
    }

    /**
     * Apply Content Security Policy header
     */
    private function applyContentSecurityPolicy(Response $response): void
    {
        $cspConfig = $this->config->get('security.content_security_policy', []);

        $defaultCSP = [
            'default-src' => "'self'",
            'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
            'style-src' => "'self' 'unsafe-inline'",
            'img-src' => "'self' data: https:",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'form-action' => "'self'",
            'base-uri' => "'self'",
            'object-src' => "'none'",
            'media-src' => "'self'",
            'frame-ancestors' => "'none'",
        ];

        // Merge user config with defaults
        $csp = array_merge($defaultCSP, $cspConfig);

        // Build CSP string
        $cspParts = [];
        foreach ($csp as $directive => $sources) {
            if (is_array($sources)) {
                $sources = implode(' ', $sources);
            }
            $cspParts[] = "{$directive} {$sources}";
        }

        $cspHeader = implode('; ', $cspParts);

        // Apply both CSP and CSP-Report-Only if configured
        $reportOnly = $this->config->get('security.csp_report_only', false);

        if ($reportOnly) {
            $response->setHeader('Content-Security-Policy-Report-Only', $cspHeader);
        } else {
            $response->setHeader('Content-Security-Policy', $cspHeader);
        }

        // Add report-to if configured
        $reportUri = $this->config->get('security.csp_report_uri');
        if ($reportUri) {
            $cspHeader .= "; report-uri {$reportUri}";
            $response->setHeader($reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $cspHeader);
        }
    }

    /**
     * Apply Permissions-Policy header (formerly Feature-Policy)
     */
    private function applyPermissionsPolicy(Response $response): void
    {
        $permissionsConfig = $this->config->get('security.permissions_policy', []);

        $defaultPermissions = [
            'accelerometer' => '()',
            'ambient-light-sensor' => '()',
            'autoplay' => '()',
            'battery' => '()',
            'camera' => '()',
            'cross-origin-isolated' => '()',
            'display-capture' => '()',
            'document-domain' => '()',
            'encrypted-media' => '()',
            'execution-while-not-rendered' => '()',
            'execution-while-out-of-viewport' => '()',
            'fullscreen' => '(self)',
            'geolocation' => '()',
            'gyroscope' => '()',
            'keyboard-map' => '()',
            'magnetometer' => '()',
            'microphone' => '()',
            'midi' => '()',
            'navigation-override' => '()',
            'payment' => '()',
            'picture-in-picture' => '()',
            'publickey-credentials-get' => '()',
            'screen-wake-lock' => '()',
            'sync-xhr' => '()',
            'usb' => '()',
            'web-share' => '()',
            'xr-spatial-tracking' => '()'
        ];

        $permissions = array_merge($defaultPermissions, $permissionsConfig);

        $permissionsParts = [];
        foreach ($permissions as $directive => $allowlist) {
            $permissionsParts[] = "{$directive}={$allowlist}";
        }

        if (!empty($permissionsParts)) {
            $permissionsHeader = implode(', ', $permissionsParts);
            $response->setHeader('Permissions-Policy', $permissionsHeader);
        }
    }

    /**
     * Apply cache control headers
     */
    private function applyCacheHeaders(Response $response): void
    {
        $cacheControl = $this->config->get('security.cache_control', 'no-cache, no-store, must-revalidate');
        $pragma = $this->config->get('security.pragma', 'no-cache');
        $expires = $this->config->get('security.expires', '0');

        $response->setHeader('Cache-Control', $cacheControl);
        $response->setHeader('Pragma', $pragma);
        $response->setHeader('Expires', $expires);
    }

    /**
     * Apply HTTPS enforcement and HSTS
     */
    private function applyHTTPS(Response $response): void
    {
        $httpsEnforced = $this->config->get('security.https_enforced', $this->config->isProduction());

        if ($httpsEnforced) {
            // Check if request is already HTTPS
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                $_SERVER['SERVER_PORT'] == 443 ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            );

            if (!$isHttps) {
                // Redirect to HTTPS
                $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

                $this->logger->info('Redirecting HTTP to HTTPS', [
                    'original_url' => $_SERVER['REQUEST_URI'],
                    'https_url' => $httpsUrl,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                $response->redirect($httpsUrl, 301);
                return;
            }

            // Apply HSTS header for HTTPS requests
            $this->applyHSTS($response);
        }
    }

    /**
     * Apply HTTP Strict Transport Security header
     */
    private function applyHSTS(Response $response): void
    {
        $hstsMaxAge = $this->config->get('security.hsts_max_age', 31536000); // 1 year default
        $hstsIncludeSubDomains = $this->config->get('security.hsts_include_subdomains', true);
        $hstsPreload = $this->config->get('security.hsts_preload', false);

        $hstsHeader = "max-age={$hstsMaxAge}";

        if ($hstsIncludeSubDomains) {
            $hstsHeader .= '; includeSubDomains';
        }

        if ($hstsPreload) {
            $hstsHeader .= '; preload';
        }

        $response->setHeader('Strict-Transport-Security', $hstsHeader);
    }

    /**
     * Get security header recommendations based on current config
     */
    public function getRecommendations(): array
    {
        $recommendations = [];

        // Check CSP configuration
        if (!$this->config->get('security.content_security_policy')) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Content Security Policy not configured - using defaults',
                'suggestion' => 'Configure security.content_security_policy in your environment'
            ];
        }

        // Check HTTPS enforcement
        if (!$this->config->get('security.https_enforced') && $this->config->isProduction()) {
            $recommendations[] = [
                'type' => 'critical',
                'message' => 'HTTPS enforcement disabled in production',
                'suggestion' => 'Set security.https_enforced=true for production environments'
            ];
        }

        // Check HSTS settings
        $hstsMaxAge = $this->config->get('security.hsts_max_age', 31536000);
        if ($hstsMaxAge < 31536000) { // Less than 1 year
            $recommendations[] = [
                'type' => 'info',
                'message' => 'HSTS max-age less than recommended 1 year',
                'suggestion' => 'Consider increasing security.hsts_max_age to 31536000 (1 year)'
            ];
        }

        return $recommendations;
    }

    /**
     * Validate security configuration
     */
    public function validateConfiguration(): array
    {
        $issues = [];

        // Validate frame options
        $frameOptions = $this->config->get('security.frame_options', 'DENY');
        if (!in_array($frameOptions, ['DENY', 'SAMEORIGIN']) && !str_starts_with($frameOptions, 'ALLOW-FROM ')) {
            $issues[] = 'Invalid security.frame_options value: ' . $frameOptions;
        }

        // Validate referrer policy
        $referrerPolicy = $this->config->get('security.referrer_policy', 'strict-origin-when-cross-origin');
        $validPolicies = [
            'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
            'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url'
        ];
        if (!in_array($referrerPolicy, $validPolicies)) {
            $issues[] = 'Invalid security.referrer_policy value: ' . $referrerPolicy;
        }

        // Validate HSTS max age
        $hstsMaxAge = $this->config->get('security.hsts_max_age', 31536000);
        if (!is_numeric($hstsMaxAge) || $hstsMaxAge < 0) {
            $issues[] = 'Invalid security.hsts_max_age value: ' . $hstsMaxAge;
        }

        return $issues;
    }
}