<?php

namespace SecurityScanner\Core;

class InputSanitizer
{
    private array $config;
    private array $customFilters = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_string_filter' => 'html',
            'allow_html_tags' => [],
            'allowed_protocols' => ['http', 'https', 'ftp', 'mailto'],
            'max_input_length' => 10000,
            'strict_mode' => true,
            'encoding' => 'UTF-8',
            'remove_null_bytes' => true,
            'normalize_unicode' => true
        ], $config);
    }

    /**
     * Sanitize input data recursively
     */
    public function sanitize($data, array $rules = []): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitizedKey = $this->sanitizeKey($key);
                $fieldRules = $rules[$key] ?? $rules['*'] ?? [];
                $sanitized[$sanitizedKey] = $this->sanitize($value, $fieldRules);
            }
            return $sanitized;
        }

        if (!is_string($data)) {
            return $data;
        }

        // Apply length limits
        if (isset($rules['max_length'])) {
            $data = substr($data, 0, $rules['max_length']);
        } elseif (strlen($data) > $this->config['max_input_length']) {
            $data = substr($data, 0, $this->config['max_input_length']);
        }

        // Remove null bytes if configured
        if ($this->config['remove_null_bytes']) {
            $data = str_replace("\0", '', $data);
        }

        // Normalize encoding
        if ($this->config['normalize_unicode'] && function_exists('mb_convert_encoding')) {
            $data = mb_convert_encoding($data, $this->config['encoding'], 'auto');
        }

        // Apply sanitization filter
        $filter = $rules['filter'] ?? $this->config['default_string_filter'];
        return $this->applyFilter($data, $filter, $rules);
    }

    /**
     * Apply specific filter to data
     */
    public function applyFilter(string $data, string $filter, array $options = []): string
    {
        switch ($filter) {
            case 'html':
                return $this->sanitizeHtml($data, $options);
            case 'url':
                return $this->sanitizeUrl($data);
            case 'email':
                return $this->sanitizeEmail($data);
            case 'filename':
                return $this->sanitizeFilename($data);
            case 'sql':
                return $this->sanitizeSql($data);
            case 'xss':
                return $this->sanitizeXss($data, $options);
            case 'alphanumeric':
                return $this->sanitizeAlphaNumeric($data);
            case 'numeric':
                return $this->sanitizeNumeric($data);
            case 'raw':
                return $data; // No sanitization
            case 'strip_tags':
                return $this->stripTags($data, $options);
            default:
                if (isset($this->customFilters[$filter])) {
                    return call_user_func($this->customFilters[$filter], $data, $options);
                }
                return $this->sanitizeHtml($data, $options);
        }
    }

    /**
     * Sanitize HTML content
     */
    public function sanitizeHtml(string $data, array $options = []): string
    {
        $allowedTags = $options['allowed_tags'] ?? $this->config['allow_html_tags'];

        if (empty($allowedTags)) {
            // Strip all HTML tags
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, $this->config['encoding']);
        }

        // Use HTMLPurifier if available, otherwise basic filtering
        if (class_exists('HTMLPurifier')) {
            return $this->purifyHtml($data, $allowedTags);
        }

        return $this->basicHtmlFilter($data, $allowedTags);
    }

    /**
     * Advanced XSS sanitization
     */
    public function sanitizeXss(string $data, array $options = []): string
    {
        // Remove potentially dangerous patterns
        $patterns = [
            // JavaScript protocols
            '/javascript:/i',
            '/data:/i',
            '/vbscript:/i',
            // Event handlers
            '/on\w+\s*=/i',
            // Script tags
            '/<script[^>]*>.*?<\/script>/is',
            // Style with expressions
            '/<style[^>]*>.*?<\/style>/is',
            // Meta redirects
            '/<meta[^>]*http-equiv[^>]*>/i',
            // Object/embed tags
            '/<(object|embed|applet|iframe)[^>]*>.*?<\/\1>/is',
        ];

        foreach ($patterns as $pattern) {
            $data = preg_replace($pattern, '', $data);
        }

        // Encode remaining HTML entities
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, $this->config['encoding']);
    }

    /**
     * SQL injection prevention
     */
    public function sanitizeSql(string $data): string
    {
        // Remove SQL keywords and dangerous characters
        $sqlPatterns = [
            '/(\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b|\bCREATE\b|\bALTER\b|\bTRUNCATE\b)/i',
            '/(\bUNION\b|\bJOIN\b|\bWHERE\b|\bORDER\b|\bGROUP\b|\bHAVING\b|\bLIMIT\b)/i',
            '/(\bEXEC\b|\bEXECUTE\b|\bSP_\w+)/i',
            '/[\'";\\\\]/',
            '/-{2,}/', // SQL comments
            '/\/\*.*?\*\//', // Multi-line comments
        ];

        foreach ($sqlPatterns as $pattern) {
            $data = preg_replace($pattern, '', $data);
        }

        return trim($data);
    }

    /**
     * Sanitize URL
     */
    public function sanitizeUrl(string $data): string
    {
        $data = trim($data);

        // Basic URL validation
        if (!filter_var($data, FILTER_VALIDATE_URL)) {
            return '';
        }

        // Check protocol whitelist
        $parsedUrl = parse_url($data);
        if (!isset($parsedUrl['scheme']) ||
            !in_array(strtolower($parsedUrl['scheme']), $this->config['allowed_protocols'])) {
            return '';
        }

        return $data;
    }

    /**
     * Sanitize email
     */
    public function sanitizeEmail(string $data): string
    {
        $email = filter_var(trim($data), FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Sanitize filename
     */
    public function sanitizeFilename(string $data): string
    {
        // Remove directory traversal attempts
        $data = str_replace(['../', '../', '..\\', '..\\\\'], '', $data);

        // Remove null bytes and control characters
        $data = preg_replace('/[\x00-\x1F\x7F]/', '', $data);

        // Remove dangerous characters
        $data = preg_replace('/[<>:"|?*]/', '', $data);

        // Limit length
        return substr(trim($data), 0, 255);
    }

    /**
     * Sanitize to alphanumeric only
     */
    public function sanitizeAlphaNumeric(string $data): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $data);
    }

    /**
     * Sanitize to numeric only
     */
    public function sanitizeNumeric(string $data): string
    {
        return preg_replace('/[^0-9.-]/', '', $data);
    }

    /**
     * Strip HTML tags with whitelist
     */
    public function stripTags(string $data, array $options = []): string
    {
        $allowedTags = $options['allowed_tags'] ?? [];

        if (empty($allowedTags)) {
            return strip_tags($data);
        }

        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        return strip_tags($data, $allowedTagsString);
    }

    /**
     * Sanitize array key
     */
    private function sanitizeKey(string $key): string
    {
        // Only allow alphanumeric and underscores in keys
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
    }

    /**
     * Basic HTML filtering without HTMLPurifier
     */
    private function basicHtmlFilter(string $data, array $allowedTags): string
    {
        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        $data = strip_tags($data, $allowedTagsString);

        // Remove dangerous attributes
        $dangerousAttrs = ['onclick', 'onload', 'onerror', 'onmouseover', 'onfocus', 'onblur', 'style'];
        foreach ($dangerousAttrs as $attr) {
            $data = preg_replace('/' . $attr . '\s*=\s*["\'][^"\']*["\']/i', '', $data);
        }

        return $data;
    }

    /**
     * HTML Purifier integration (if available)
     */
    private function purifyHtml(string $data, array $allowedTags): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', implode(',', $allowedTags));
        $config->set('HTML.TidyLevel', 'medium');

        $purifier = new \HTMLPurifier($config);
        return $purifier->purify($data);
    }

    /**
     * Register custom filter
     */
    public function addCustomFilter(string $name, callable $filter): void
    {
        $this->customFilters[$name] = $filter;
    }

    /**
     * Create sanitization middleware
     */
    public function middleware(array $rules = []): \Closure
    {
        return function(Request $request, \Closure $next) use ($rules) {
            // Sanitize request data
            $sanitizedPost = $this->sanitize($request->all(), $rules['post'] ?? []);
            $sanitizedGet = $this->sanitize($request->query(), $rules['get'] ?? []);

            // Create new request with sanitized data
            $sanitizedRequest = new Request(
                $sanitizedGet,
                $sanitizedPost,
                $request->server(),
                $request->files(),
                $request->cookies()
            );

            return $next($sanitizedRequest);
        };
    }

    /**
     * Batch sanitize multiple values
     */
    public function sanitizeMany(array $data, array $rules): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $fieldRules = $rules[$key] ?? [];
            $sanitized[$key] = $this->sanitize($value, $fieldRules);
        }

        return $sanitized;
    }

    /**
     * Quick sanitize for common cases
     */
    public static function clean(string $data, string $type = 'html'): string
    {
        $sanitizer = new self();
        return $sanitizer->applyFilter($data, $type);
    }

    /**
     * Validate that data is safe after sanitization
     */
    public function isSafe(string $data, array $checks = []): bool
    {
        $defaultChecks = [
            'no_scripts' => true,
            'no_sql_injection' => true,
            'no_xss_patterns' => true,
            'length_limit' => true
        ];

        $checks = array_merge($defaultChecks, $checks);

        if ($checks['no_scripts'] && $this->containsScripts($data)) {
            return false;
        }

        if ($checks['no_sql_injection'] && $this->containsSqlInjection($data)) {
            return false;
        }

        if ($checks['no_xss_patterns'] && $this->containsXssPatterns($data)) {
            return false;
        }

        if ($checks['length_limit'] && strlen($data) > $this->config['max_input_length']) {
            return false;
        }

        return true;
    }

    /**
     * Check for script tags
     */
    private function containsScripts(string $data): bool
    {
        return preg_match('/<script[^>]*>.*?<\/script>/is', $data) === 1;
    }

    /**
     * Check for SQL injection patterns
     */
    private function containsSqlInjection(string $data): bool
    {
        $patterns = [
            '/(\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b)/i',
            '/(\bUNION\b.*\bSELECT\b)/i',
            '/(\'|\")[^\'\"]*(\bOR\b|\bAND\b)[^\'\"]*(\1)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for XSS patterns
     */
    private function containsXssPatterns(string $data): bool
    {
        $patterns = [
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe[^>]*>/i',
            '/<object[^>]*>/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }
}