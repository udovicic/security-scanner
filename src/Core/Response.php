<?php

namespace SecurityScanner\Core;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';
    private array $cookies = [];
    private bool $sent = false;

    private static array $statusTexts = [
        200 => 'OK',
        201 => 'Created',
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
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function appendBody(string $content): self
    {
        $this->body .= $content;
        return $this;
    }

    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];

        return $this;
    }

    public function json(array $data, int $statusCode = 200): self
    {
        $this->setStatusCode($statusCode)
             ->setHeader('Content-Type', 'application/json')
             ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this;
    }

    public function html(string $html, int $statusCode = 200): self
    {
        $this->setStatusCode($statusCode)
             ->setHeader('Content-Type', 'text/html; charset=utf-8')
             ->setBody($html);

        return $this;
    }

    public function text(string $text, int $statusCode = 200): self
    {
        $this->setStatusCode($statusCode)
             ->setHeader('Content-Type', 'text/plain; charset=utf-8')
             ->setBody($text);

        return $this;
    }

    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->setStatusCode($statusCode)
             ->setHeader('Location', $url);

        return $this;
    }

    public function error(int $statusCode, string $message = ''): self
    {
        $statusText = self::$statusTexts[$statusCode] ?? 'Unknown Error';

        if (empty($message)) {
            $message = $statusText;
        }

        $this->setStatusCode($statusCode);

        // Check if this is an AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

            $this->json([
                'error' => true,
                'message' => $message,
                'status' => $statusCode,
            ]);
        } else {
            $this->html($this->renderErrorPage($statusCode, $statusText, $message));
        }

        return $this;
    }

    public function notFound(string $message = 'Page not found'): self
    {
        return $this->error(404, $message);
    }

    public function unauthorized(string $message = 'Unauthorized'): self
    {
        return $this->error(401, $message);
    }

    public function forbidden(string $message = 'Forbidden'): self
    {
        return $this->error(403, $message);
    }

    public function badRequest(string $message = 'Bad request'): self
    {
        return $this->error(400, $message);
    }

    public function validationError(array $errors): self
    {
        $this->setStatusCode(422);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

            $this->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $errors,
            ]);
        } else {
            // For non-AJAX requests, you might want to redirect back with errors
            $this->html($this->renderErrorPage(422, 'Validation Error', 'Please check your input and try again.'));
        }

        return $this;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        // Send status line
        $statusText = self::$statusTexts[$this->statusCode] ?? 'Unknown';
        header("HTTP/1.1 {$this->statusCode} {$statusText}");

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Send cookies
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly']
            );

            // Set SameSite attribute (PHP 7.3+)
            if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
                $cookieOptions = [
                    'expires' => $cookie['expire'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ];

                setcookie($cookie['name'], $cookie['value'], $cookieOptions);
            }
        }

        // Send body
        echo $this->body;

        $this->sent = true;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    private function renderErrorPage(int $statusCode, string $statusText, string $message): string
    {
        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>{$statusCode} - {$statusText}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 50px;
            background-color: #f8f9fa;
            color: #333;
        }
        .error-container {
            max-width: 600px;
            margin: 0 auto;
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-code {
            font-size: 72px;
            color: #dc3545;
            margin-bottom: 20px;
            font-weight: 300;
        }
        .error-title {
            font-size: 28px;
            color: #495057;
            margin-bottom: 20px;
            font-weight: 400;
        }
        .error-message {
            font-size: 16px;
            color: #6c757d;
            line-height: 1.5;
        }
        .back-link {
            margin-top: 30px;
        }
        .back-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class='error-container'>
        <div class='error-code'>{$statusCode}</div>
        <div class='error-title'>{$statusText}</div>
        <div class='error-message'>{$message}</div>
        <div class='back-link'>
            <a href='/'>← Back to Home</a>
        </div>
    </div>
</body>
</html>";
    }
}