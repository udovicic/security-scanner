<?php

namespace SecurityScanner\Core;

class Request
{
    private array $query;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private array $headers;
    private ?string $body;
    private array $attributes = [];
    private array $routeParams = [];

    public function __construct(
        array $query = null,
        array $post = null,
        array $server = null,
        array $files = null,
        array $cookies = null
    ) {
        $this->query = $query ?? $_GET;
        $this->post = $post ?? $_POST;
        $this->server = $server ?? $_SERVER;
        $this->files = $files ?? $_FILES;
        $this->cookies = $cookies ?? $_COOKIE;
        $this->headers = $this->parseHeaders();
        $this->body = $this->parseBody();
    }

    /**
     * Create Request from globals
     */
    public static function createFromGlobals(): self
    {
        return new self();
    }

    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * Get request path
     */
    public function getPath(): string
    {
        return parse_url($this->getUri(), PHP_URL_PATH) ?? '/';
    }

    /**
     * Get query string
     */
    public function getQueryString(): string
    {
        return parse_url($this->getUri(), PHP_URL_QUERY) ?? '';
    }

    /**
     * Check if request is HTTPS
     */
    public function isSecure(): bool
    {
        return (
            (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
            (!empty($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] == 443) ||
            (!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
    }

    /**
     * Check if request is AJAX
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeader('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Check if request expects JSON
     */
    public function expectsJson(): bool
    {
        $accept = $this->getHeader('Accept', '');
        return str_contains($accept, 'application/json') || str_contains($accept, 'text/json');
    }

    /**
     * Check if request content is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type', '');
        return str_contains($contentType, 'application/json');
    }

    /**
     * Get query parameter
     */
    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get POST data
     */
    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return array_merge($this->post, $this->getJsonInput());
        }

        $input = array_merge($this->post, $this->getJsonInput());
        return $input[$key] ?? $default;
    }

    /**
     * Get all input (query + post + json)
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->getJsonInput());
    }

    /**
     * Get cookies data
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get only specific input fields
     */
    public function only(array $keys): array
    {
        $input = $this->all();
        return array_intersect_key($input, array_flip($keys));
    }

    /**
     * Get all input except specific fields
     */
    public function except(array $keys): array
    {
        $input = $this->all();
        return array_diff_key($input, array_flip($keys));
    }

    /**
     * Check if input has key
     */
    public function has(string $key): bool
    {
        $input = $this->all();
        return array_key_exists($key, $input);
    }

    /**
     * Check if input has non-empty value
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && $value !== '';
    }

    /**
     * Get uploaded file
     */
    public function file(string $key): ?UploadedFile
    {
        if (!isset($this->files[$key])) {
            return null;
        }

        $file = $this->files[$key];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        return new UploadedFile(
            $file['tmp_name'],
            $file['name'],
            $file['type'],
            $file['size'],
            $file['error']
        );
    }

    /**
     * Get cookie value
     */
    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get header value
     */
    public function getHeader(string $name, $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get raw request body
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Get JSON input as array
     */
    public function getJsonInput(): array
    {
        if (!$this->isJson() || !$this->body) {
            return [];
        }

        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get server variable
     */
    public function server(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($this->server[$key])) {
                $ip = trim(explode(',', $this->server[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get user agent
     */
    public function getUserAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Get referer
     */
    public function getReferer(): string
    {
        return $this->server['HTTP_REFERER'] ?? '';
    }

    /**
     * Set route parameters
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get route parameter
     */
    public function route(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->routeParams;
        }

        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Set request attribute
     */
    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get request attribute
     */
    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Validate input against rules
     */
    public function validate(array $rules, array $messages = []): array
    {
        $validator = new Validator();
        return $validator->validate($this->all(), $rules, $messages);
    }

    /**
     * Parse HTTP headers
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    /**
     * Parse request body
     */
    private function parseBody(): ?string
    {
        return file_get_contents('php://input') ?: null;
    }

    /**
     * Sanitize input value
     */
    public function sanitize(string $key, string $filter = 'string')
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        switch ($filter) {
            case 'int':
            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT);

            case 'float':
                return filter_var($value, FILTER_VALIDATE_FLOAT);

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL);

            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL);

            case 'boolean':
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'string':
            default:
                return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Get request fingerprint for rate limiting
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->getClientIp() . '|' . $this->getUserAgent());
    }

    /**
     * Check if request matches pattern
     */
    public function is(string $pattern): bool
    {
        $path = $this->getPath();

        // Convert pattern to regex
        $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        $regex = '/^' . $regex . '$/';

        return preg_match($regex, $path) === 1;
    }

    /**
     * Convert request to array for debugging
     */
    public function toArray(): array
    {
        return [
            'method' => $this->getMethod(),
            'uri' => $this->getUri(),
            'path' => $this->getPath(),
            'query' => $this->query,
            'input' => $this->all(),
            'headers' => $this->headers,
            'client_ip' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'is_secure' => $this->isSecure(),
            'is_ajax' => $this->isAjax(),
            'expects_json' => $this->expectsJson(),
            'route_params' => $this->routeParams
        ];
    }
}