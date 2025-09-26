<?php

namespace SecurityScanner\Core;

class CorsHandler
{
    private array $config;
    private array $allowedOrigins = [];
    private array $allowedMethods = [];
    private array $allowedHeaders = [];
    private array $exposedHeaders = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],
            'exposed_headers' => [],
            'allow_credentials' => false,
            'max_age' => 86400, // 24 hours
            'supports_credentials' => false,
            'origin_patterns' => [],
            'paths' => [],
            'exclude_paths' => []
        ], $config);

        $this->allowedOrigins = $this->config['allowed_origins'];
        $this->allowedMethods = array_map('strtoupper', $this->config['allowed_methods']);
        $this->allowedHeaders = array_map('strtolower', $this->config['allowed_headers']);
        $this->exposedHeaders = $this->config['exposed_headers'];
    }

    /**
     * Handle CORS for a request
     */
    public function handle(Request $request): ?Response
    {
        // Check if CORS should be applied to this path
        if (!$this->shouldApplyCors($request)) {
            return null;
        }

        // Handle preflight requests
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflight($request);
        }

        // For actual requests, we'll add headers in middleware
        return null;
    }

    /**
     * Create CORS middleware
     */
    public function middleware(): \Closure
    {
        return function(Request $request, \Closure $next) {
            // Check if CORS should be applied
            if (!$this->shouldApplyCors($request)) {
                return $next($request);
            }

            // Handle preflight requests
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflight($request);
            }

            // Process the actual request
            $response = $next($request);

            // Add CORS headers to the response
            return $this->addCorsHeaders($request, $response);
        };
    }

    /**
     * Handle preflight OPTIONS request
     */
    private function handlePreflight(Request $request): Response
    {
        $origin = $request->getHeader('Origin');

        if (!$this->isOriginAllowed($origin)) {
            return Response::create('', 403);
        }

        $requestMethod = $request->getHeader('Access-Control-Request-Method');
        $requestHeaders = $request->getHeader('Access-Control-Request-Headers');

        // Check if the method is allowed
        if ($requestMethod && !$this->isMethodAllowed($requestMethod)) {
            return Response::create('', 405);
        }

        // Check if headers are allowed
        if ($requestHeaders && !$this->areHeadersAllowed($requestHeaders)) {
            return Response::create('', 400);
        }

        $response = Response::create('', 204);

        // Add CORS headers
        $response->setHeader('Access-Control-Allow-Origin', $this->getAllowedOriginHeader($origin))
                 ->setHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
                 ->setHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
                 ->setHeader('Access-Control-Max-Age', (string)$this->config['max_age']);

        if ($this->config['allow_credentials']) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->getHeader('Origin');

        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        $response->setHeader('Access-Control-Allow-Origin', $this->getAllowedOriginHeader($origin));

        if ($this->config['allow_credentials']) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($this->exposedHeaders)) {
            $response->setHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        // Add Vary header to indicate that response varies by Origin
        $varyHeader = $response->getHeader('Vary');
        $varyValues = $varyHeader ? explode(',', $varyHeader) : [];
        $varyValues = array_map('trim', $varyValues);

        if (!in_array('Origin', $varyValues)) {
            $varyValues[] = 'Origin';
            $response->setHeader('Vary', implode(', ', $varyValues));
        }

        return $response;
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }

        // Check for wildcard
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }

        // Check exact matches
        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }

        // Check pattern matches
        foreach ($this->config['origin_patterns'] as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if HTTP method is allowed
     */
    private function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), $this->allowedMethods);
    }

    /**
     * Check if headers are allowed
     */
    private function areHeadersAllowed(string $headers): bool
    {
        $requestedHeaders = array_map('trim', explode(',', strtolower($headers)));

        foreach ($requestedHeaders as $header) {
            if (!in_array($header, $this->allowedHeaders)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the allowed origin header value
     */
    private function getAllowedOriginHeader(?string $origin): string
    {
        if (in_array('*', $this->allowedOrigins) && !$this->config['allow_credentials']) {
            return '*';
        }

        return $origin ?? '*';
    }

    /**
     * Check if CORS should be applied to this request
     */
    private function shouldApplyCors(Request $request): bool
    {
        $path = $request->getPath();

        // Check if path is excluded
        foreach ($this->config['exclude_paths'] as $excludePath) {
            if ($this->pathMatches($path, $excludePath)) {
                return false;
            }
        }

        // If specific paths are configured, check if current path matches
        if (!empty($this->config['paths'])) {
            foreach ($this->config['paths'] as $allowedPath) {
                if ($this->pathMatches($path, $allowedPath)) {
                    return true;
                }
            }
            return false;
        }

        // Check if request has Origin header (indicates cross-origin request)
        return $request->getHeader('Origin') !== null;
    }

    /**
     * Check if path matches pattern (supports wildcards)
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $pattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
            return (bool)preg_match('/^' . $pattern . '$/i', $path);
        }

        return $path === $pattern || str_starts_with($path, $pattern);
    }

    /**
     * Create CORS handler for API endpoints
     */
    public static function forApi(array $overrides = []): self
    {
        return new self(array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => [
                'accept',
                'authorization',
                'content-type',
                'x-requested-with',
                'x-api-key',
                'x-csrf-token'
            ],
            'exposed_headers' => [
                'x-ratelimit-limit',
                'x-ratelimit-remaining',
                'x-ratelimit-reset'
            ],
            'allow_credentials' => true,
            'max_age' => 86400,
            'paths' => ['/api/*']
        ], $overrides));
    }

    /**
     * Create restrictive CORS handler
     */
    public static function restrictive(array $allowedOrigins, array $overrides = []): self
    {
        return new self(array_merge([
            'allowed_origins' => $allowedOrigins,
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['accept', 'content-type'],
            'allow_credentials' => true,
            'max_age' => 3600
        ], $overrides));
    }

    /**
     * Create permissive CORS handler (development use only)
     */
    public static function permissive(array $overrides = []): self
    {
        return new self(array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['*'],
            'allowed_headers' => ['*'],
            'allow_credentials' => false,
            'max_age' => 86400
        ], $overrides));
    }

    /**
     * Add allowed origin
     */
    public function addOrigin(string $origin): self
    {
        if (!in_array($origin, $this->allowedOrigins)) {
            $this->allowedOrigins[] = $origin;
        }
        return $this;
    }

    /**
     * Add multiple allowed origins
     */
    public function addOrigins(array $origins): self
    {
        foreach ($origins as $origin) {
            $this->addOrigin($origin);
        }
        return $this;
    }

    /**
     * Add allowed method
     */
    public function addMethod(string $method): self
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods)) {
            $this->allowedMethods[] = $method;
        }
        return $this;
    }

    /**
     * Add allowed header
     */
    public function addHeader(string $header): self
    {
        $header = strtolower($header);
        if (!in_array($header, $this->allowedHeaders)) {
            $this->allowedHeaders[] = $header;
        }
        return $this;
    }

    /**
     * Add exposed header
     */
    public function exposeHeader(string $header): self
    {
        if (!in_array($header, $this->exposedHeaders)) {
            $this->exposedHeaders[] = $header;
        }
        return $this;
    }

    /**
     * Set credentials support
     */
    public function allowCredentials(bool $allow = true): self
    {
        $this->config['allow_credentials'] = $allow;
        return $this;
    }

    /**
     * Set max age for preflight caching
     */
    public function setMaxAge(int $maxAge): self
    {
        $this->config['max_age'] = $maxAge;
        return $this;
    }

    /**
     * Validate CORS configuration
     */
    public function validateConfig(): array
    {
        $issues = [];

        // Check for wildcard origin with credentials
        if (in_array('*', $this->allowedOrigins) && $this->config['allow_credentials']) {
            $issues[] = 'Cannot use wildcard origin (*) with credentials enabled';
        }

        // Check for security headers
        $securityHeaders = ['authorization', 'x-csrf-token'];
        $hasSecurityHeaders = false;
        foreach ($securityHeaders as $header) {
            if (in_array($header, $this->allowedHeaders)) {
                $hasSecurityHeaders = true;
                break;
            }
        }

        if (!$hasSecurityHeaders) {
            $issues[] = 'Consider adding security headers like authorization or x-csrf-token';
        }

        return $issues;
    }

    /**
     * Get current CORS configuration
     */
    public function getConfig(): array
    {
        return [
            'allowed_origins' => $this->allowedOrigins,
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders,
            'exposed_headers' => $this->exposedHeaders,
            'allow_credentials' => $this->config['allow_credentials'],
            'max_age' => $this->config['max_age'],
            'paths' => $this->config['paths'],
            'exclude_paths' => $this->config['exclude_paths']
        ];
    }

    /**
     * Check if request is a CORS preflight request
     */
    public function isPreflight(Request $request): bool
    {
        return $request->getMethod() === 'OPTIONS' &&
               $request->getHeader('Origin') !== null &&
               $request->getHeader('Access-Control-Request-Method') !== null;
    }

    /**
     * Get CORS information for debugging
     */
    public function getCorsInfo(Request $request): array
    {
        $origin = $request->getHeader('Origin');
        $method = $request->getMethod();
        $requestedMethod = $request->getHeader('Access-Control-Request-Method');
        $requestedHeaders = $request->getHeader('Access-Control-Request-Headers');

        return [
            'is_cors_request' => $origin !== null,
            'is_preflight' => $this->isPreflight($request),
            'origin' => $origin,
            'method' => $method,
            'requested_method' => $requestedMethod,
            'requested_headers' => $requestedHeaders,
            'origin_allowed' => $this->isOriginAllowed($origin),
            'method_allowed' => $this->isMethodAllowed($requestedMethod ?: $method),
            'headers_allowed' => $requestedHeaders ? $this->areHeadersAllowed($requestedHeaders) : true,
            'should_apply_cors' => $this->shouldApplyCors($request)
        ];
    }
}