<?php

namespace SecurityScanner\Core;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $post;
    private array $files;
    private array $server;
    private array $headers;
    private array $cookies;
    private array $parameters = [];
    private ?string $body;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->server = $_SERVER;

        // Parse URI to separate path and query
        $parsedUri = parse_url($this->uri);
        $this->path = $parsedUri['path'] ?? '/';

        // Parse query string
        $this->query = [];
        if (isset($parsedUri['query'])) {
            parse_str($parsedUri['query'], $this->query);
        }

        // Get POST data
        $this->post = $_POST ?? [];

        // Get uploaded files
        $this->files = $_FILES ?? [];

        // Get cookies
        $this->cookies = $_COOKIE ?? [];

        // Parse headers
        $this->headers = $this->parseHeaders();

        // Get raw body for PUT/PATCH requests
        $this->body = null;
        if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'])) {
            $this->body = file_get_contents('php://input');
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function getPost(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }

        return $this->post[$key] ?? $default;
    }

    public function getInput(?string $key = null, $default = null)
    {
        // Get from POST first, then query
        if ($key === null) {
            return array_merge($this->query, $this->post);
        }

        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function getFiles(?string $key = null)
    {
        if ($key === null) {
            return $this->files;
        }

        return $this->files[$key] ?? null;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCookie(string $name, $default = null)
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getJsonBody(): ?array
    {
        if ($this->body === null) {
            return null;
        }

        $decoded = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    public function isAjax(): bool
    {
        return strtolower($this->getHeader('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    public function getClientIp(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($this->server[$header])) {
                $ips = explode(',', $this->server[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? 'unknown';
    }

    public function getUserAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    public function getReferer(): ?string
    {
        return $this->server['HTTP_REFERER'] ?? null;
    }

    public function getHost(): string
    {
        return $this->server['HTTP_HOST'] ?? 'localhost';
    }

    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function getFullUrl(): string
    {
        return $this->getScheme() . '://' . $this->getHost() . $this->getUri();
    }

    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        // Add some non-HTTP_ headers that are important
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->server['CONTENT_TYPE'];
        }

        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public function validate(array $rules): array
    {
        $errors = [];
        $data = $this->getInput();

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $ruleParts = explode(':', $rule, 2);
                $ruleName = $ruleParts[0];
                $ruleValue = $ruleParts[1] ?? null;

                if (!$this->validateField($value, $ruleName, $ruleValue)) {
                    $errors[$field][] = $this->getValidationMessage($field, $ruleName, $ruleValue);
                }
            }
        }

        return $errors;
    }

    private function validateField($value, string $rule, ?string $parameter = null): bool
    {
        switch ($rule) {
            case 'required':
                return !empty($value);

            case 'string':
                return is_string($value);

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;

            case 'min':
                return is_numeric($value) ? (int)$value >= (int)$parameter : strlen((string)$value) >= (int)$parameter;

            case 'max':
                return is_numeric($value) ? (int)$value <= (int)$parameter : strlen((string)$value) <= (int)$parameter;

            case 'in':
                $allowedValues = explode(',', $parameter);
                return in_array($value, $allowedValues);

            default:
                return true;
        }
    }

    private function getValidationMessage(string $field, string $rule, ?string $parameter = null): string
    {
        $messages = [
            'required' => "The {$field} field is required.",
            'string' => "The {$field} field must be a string.",
            'integer' => "The {$field} field must be an integer.",
            'email' => "The {$field} field must be a valid email address.",
            'url' => "The {$field} field must be a valid URL.",
            'min' => "The {$field} field must be at least {$parameter} characters.",
            'max' => "The {$field} field must not exceed {$parameter} characters.",
            'in' => "The {$field} field must be one of: {$parameter}.",
        ];

        return $messages[$rule] ?? "The {$field} field is invalid.";
    }

    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function getParameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}