<?php

return [
    'headers' => [
        'hsts' => [
            'enabled' => filter_var($_ENV['SECURITY_HSTS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'max_age' => (int)($_ENV['SECURITY_HSTS_MAX_AGE'] ?? 31536000), // 1 year
            'include_subdomains' => filter_var($_ENV['SECURITY_HSTS_SUBDOMAINS'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'preload' => filter_var($_ENV['SECURITY_HSTS_PRELOAD'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ],

        'csp' => [
            'enabled' => filter_var($_ENV['SECURITY_CSP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'policy' => $_ENV['SECURITY_CSP_POLICY'] ?? "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'",
            'report_only' => filter_var($_ENV['SECURITY_CSP_REPORT_ONLY'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ],

        'x_frame_options' => $_ENV['SECURITY_X_FRAME_OPTIONS'] ?? 'DENY',
        'x_content_type_options' => $_ENV['SECURITY_X_CONTENT_TYPE_OPTIONS'] ?? 'nosniff',
        'x_xss_protection' => $_ENV['SECURITY_X_XSS_PROTECTION'] ?? '1; mode=block',
        'referrer_policy' => $_ENV['SECURITY_REFERRER_POLICY'] ?? 'strict-origin-when-cross-origin',
    ],

    'csrf' => [
        'enabled' => filter_var($_ENV['SECURITY_CSRF_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'token_lifetime' => (int)($_ENV['SECURITY_CSRF_LIFETIME'] ?? 3600), // 1 hour
        'regenerate_on_login' => filter_var($_ENV['SECURITY_CSRF_REGENERATE'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],

    'rate_limiting' => [
        'enabled' => filter_var($_ENV['SECURITY_RATE_LIMIT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'requests_per_minute' => (int)($_ENV['SECURITY_RATE_LIMIT_RPM'] ?? 60),
        'burst_limit' => (int)($_ENV['SECURITY_RATE_LIMIT_BURST'] ?? 100),
        'whitelist' => explode(',', $_ENV['SECURITY_RATE_LIMIT_WHITELIST'] ?? '127.0.0.1,::1'),
    ],

    'session' => [
        'secure' => filter_var($_ENV['SESSION_SECURE'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'http_only' => filter_var($_ENV['SESSION_HTTP_ONLY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'same_site' => $_ENV['SESSION_SAME_SITE'] ?? 'Lax',
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 7200), // 2 hours
    ],

    'encryption' => [
        'key' => $_ENV['ENCRYPTION_KEY'] ?? '',
        'cipher' => $_ENV['ENCRYPTION_CIPHER'] ?? 'AES-256-CBC',
    ],

    'api' => [
        'key_required' => filter_var($_ENV['API_KEY_REQUIRED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'rate_limit_per_hour' => (int)($_ENV['API_RATE_LIMIT'] ?? 1000),
        'allowed_origins' => explode(',', $_ENV['API_ALLOWED_ORIGINS'] ?? '*'),
    ],
];