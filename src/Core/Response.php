<?php

namespace SecurityScanner\Core;

class Response
{
    private string $content = '';
    private int $statusCode = 200;
    private array $headers = [];
    private array $cookies = [];

    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Create a new Response instance
     */
    public static function create(string $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    /**
     * Create a JSON response
     */
    public static function json($data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($content, $statusCode, $headers);
    }

    /**
     * Create a redirect response
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    /**
     * Create an error response
     */
    public static function error(string $message, int $statusCode = 500, array $headers = []): self
    {
        $data = [
            'error' => true,
            'message' => $message,
            'status_code' => $statusCode
        ];

        return self::json($data, $statusCode, $headers);
    }

    /**
     * Create a validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        $data = [
            'error' => true,
            'message' => $message,
            'validation_errors' => $errors,
            'status_code' => 422
        ];

        return self::json($data, 422);
    }

    /**
     * Create a success response
     */
    public static function success($data = null, string $message = null, int $statusCode = 200): self
    {
        $response = [
            'success' => true,
            'status_code' => $statusCode
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return self::json($response, $statusCode);
    }

    /**
     * Create a not found response
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return self::error($message, 404);
    }

    /**
     * Create an unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    /**
     * Create a forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    /**
     * Create a method not allowed response
     */
    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return self::error($message, 405);
    }

    /**
     * Set response content
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get response content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set status code
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Get status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set header
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get header
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Set multiple headers
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Remove header
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * Set cookie
     */
    public function setCookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ];

        return $this;
    }

    /**
     * Get cookies
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Check if response is successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response is a redirect (3xx)
     */
    public function isRedirection(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Check if response is a client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is a server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /**
     * Check if response is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type') ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * Send response to client
     */
    public function send(): void
    {
        // Send status code
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, false);
        }

        // Send cookies
        foreach ($this->cookies as $name => $cookie) {
            setcookie(
                $name,
                $cookie['value'],
                [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => $cookie['samesite']
                ]
            );
        }

        // Send content
        echo $this->content;
    }

    /**
     * Add security headers
     */
    public function withSecurityHeaders(): self
    {
        $this->setHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'"
        ]);

        return $this;
    }

    /**
     * Add CORS headers
     */
    public function withCors(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With']
    ): self {
        $this->setHeaders([
            'Access-Control-Allow-Origin' => implode(', ', $allowedOrigins),
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
            'Access-Control-Max-Age' => '86400' // 24 hours
        ]);

        return $this;
    }

    /**
     * Set cache headers
     */
    public function withCache(int $maxAge = 3600, bool $public = true): self
    {
        $cacheControl = $public ? 'public' : 'private';
        $cacheControl .= ', max-age=' . $maxAge;

        $this->setHeaders([
            'Cache-Control' => $cacheControl,
            'Expires' => gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT'
        ]);

        return $this;
    }

    /**
     * Disable caching
     */
    public function withoutCache(): self
    {
        $this->setHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);

        return $this;
    }

    /**
     * Set content type
     */
    public function withContentType(string $contentType, string $charset = 'utf-8'): self
    {
        $this->setHeader('Content-Type', $contentType . '; charset=' . $charset);
        return $this;
    }

    /**
     * Convert response to array for debugging
     */
    public function toArray(): array
    {
        return [
            'status_code' => $this->statusCode,
            'headers' => $this->headers,
            'cookies' => array_keys($this->cookies),
            'content_length' => strlen($this->content),
            'is_json' => $this->isJson(),
            'is_successful' => $this->isSuccessful(),
            'is_redirect' => $this->isRedirection(),
            'is_error' => $this->isClientError() || $this->isServerError()
        ];
    }

    /**
     * Get status text for status code
     */
    public function getStatusText(): string
    {
        $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable'
        ];

        return $statusTexts[$this->statusCode] ?? 'Unknown';
    }

    /**
     * Clone response with new content
     */
    public function withContent(string $content): self
    {
        $clone = clone $this;
        $clone->content = $content;
        return $clone;
    }

    /**
     * Clone response with new status code
     */
    public function withStatus(int $statusCode): self
    {
        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }
}