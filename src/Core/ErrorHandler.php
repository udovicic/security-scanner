<?php

namespace SecurityScanner\Core;

class ErrorHandler
{
    private static bool $registered = false;
    private static Config $config;
    private static Logger $errorLogger;
    private static Logger $securityLogger;

    public static function register(Config $config): void
    {
        if (self::$registered) {
            return;
        }

        self::$config = $config;
        self::$errorLogger = Logger::errors();
        self::$securityLogger = Logger::security();

        // Register error handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Ignore errors that are suppressed with @
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $severityName = self::getSeverityName($severity);
        $errorMessage = "PHP {$severityName}: {$message}";

        $context = [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
            'severity_name' => $severityName,
            'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            'ip' => self::getClientIp(),
        ];

        // Log based on severity
        if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE)) {
            self::$errorLogger->critical($errorMessage, $context);
        } elseif ($severity & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_ERROR)) {
            self::$errorLogger->error($errorMessage, $context);
        } elseif ($severity & (E_NOTICE | E_USER_WARNING)) {
            self::$errorLogger->warning($errorMessage, $context);
        } else {
            self::$errorLogger->info($errorMessage, $context);
        }

        // Check for potential security issues
        self::checkSecurityConcerns($message, $context);

        // In debug mode, don't suppress the error
        if (self::$config->isDebug()) {
            return false;
        }

        // For fatal errors in production, show generic error page
        if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE)) {
            self::showErrorPage(500);
            exit(1);
        }

        return true;
    }

    public static function handleException(\Throwable $exception): void
    {
        $message = get_class($exception) . ': ' . $exception->getMessage();

        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            'ip' => self::getClientIp(),
        ];

        self::$errorLogger->critical("Uncaught exception: {$message}", $context);

        // Check for potential security issues
        self::checkSecurityConcerns($exception->getMessage(), $context);

        if (self::$config->isDebug()) {
            // In debug mode, show detailed error information
            echo "<h1>Uncaught Exception</h1>\n";
            echo "<h2>" . htmlspecialchars($message) . "</h2>\n";
            echo "<h3>File: " . htmlspecialchars($exception->getFile()) . " (Line: " . $exception->getLine() . ")</h3>\n";
            echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>\n";
        } else {
            // In production, show generic error page
            self::showErrorPage(500);
        }

        exit(1);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            $message = "Fatal error: {$error['message']}";

            $context = [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type'],
                'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'ip' => self::getClientIp(),
            ];

            self::$errorLogger->critical($message, $context);

            if (!self::$config->isDebug()) {
                self::showErrorPage(500);
            }
        }
    }

    public static function logSecurityEvent(string $event, string $description, array $context = []): void
    {
        $context = array_merge([
            'event' => $event,
            'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            'ip' => self::getClientIp(),
            'timestamp' => time(),
        ], $context);

        self::$securityLogger->warning("SECURITY EVENT [{$event}]: {$description}", $context);
    }

    private static function checkSecurityConcerns(string $message, array $context): void
    {
        $securityPatterns = [
            'SQL injection' => '/sql|mysql|select|insert|update|delete|drop|union|script/i',
            'XSS attempt' => '/<script|javascript:|onerror|onload|alert\(/i',
            'Path traversal' => '/\.\.\/|\.\.\\\|etc\/passwd|boot\.ini/i',
            'Command injection' => '/system\(|exec\(|shell_exec|passthru|eval\(/i',
        ];

        foreach ($securityPatterns as $threatType => $pattern) {
            if (preg_match($pattern, $message)) {
                self::logSecurityEvent($threatType, "Potential {$threatType} detected in error message", [
                    'error_message' => $message,
                    'context' => $context,
                ]);
                break;
            }
        }
    }

    private static function getSeverityName(int $severity): string
    {
        $severityNames = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            // E_STRICT => 'Strict Standards', // Deprecated in PHP 8.4
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        return $severityNames[$severity] ?? 'Unknown Error';
    }

    private static function getClientIp(): string
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
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private static function showErrorPage(int $statusCode): void
    {
        http_response_code($statusCode);

        if (php_sapi_name() === 'cli') {
            echo "An error occurred. Please check the error logs.\n";
            return;
        }

        $title = $statusCode === 500 ? 'Internal Server Error' : 'Error';
        $message = $statusCode === 500 ? 'An internal server error occurred.' : 'An error occurred.';

        echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <title>{$title}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; }
        .error-container { max-width: 600px; margin: 0 auto; text-align: center; }
        .error-code { font-size: 72px; color: #dc3545; margin-bottom: 20px; }
        .error-message { font-size: 24px; color: #6c757d; margin-bottom: 30px; }
        .error-description { color: #6c757d; }
    </style>
</head>
<body>
    <div class='error-container'>
        <div class='error-code'>{$statusCode}</div>
        <div class='error-message'>{$title}</div>
        <div class='error-description'>{$message}</div>
    </div>
</body>
</html>";
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }
}