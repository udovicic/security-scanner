<?php

// Security Scanner Tool - Single Entry Point
// All HTTP requests are routed through this file

// Start output buffering to handle potential errors gracefully
ob_start();

// Bootstrap the application
try {
    $config = require_once __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    // If bootstrap fails, show minimal error page
    ob_end_clean();
    http_response_code(500);

    if (($_ENV['APP_DEBUG'] ?? false) && ($_ENV['APP_ENV'] ?? 'production') !== 'production') {
        echo "<h1>Bootstrap Error</h1>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <title>Service Unavailable</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; text-align: center; }
        .error-container { max-width: 600px; margin: 0 auto; }
        .error-code { font-size: 72px; color: #dc3545; margin-bottom: 20px; }
        .error-message { font-size: 24px; color: #6c757d; }
    </style>
</head>
<body>
    <div class='error-container'>
        <div class='error-code'>503</div>
        <div class='error-message'>Service Temporarily Unavailable</div>
    </div>
</body>
</html>";
    }
    exit(1);
}

use SecurityScanner\Core\Logger;
use SecurityScanner\Core\Router;
use SecurityScanner\Core\Request;
use SecurityScanner\Core\Response;
use SecurityScanner\Core\ErrorHandler;
use SecurityScanner\Core\SecurityHeaders;
use SecurityScanner\Core\SecurityMiddleware;

// Initialize access logging
$accessLogger = Logger::access();

// Get request details
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$clientIp = getClientIp();

// Log access attempt
$accessLogger->info("HTTP request received", [
    'method' => $method,
    'uri' => $uri,
    'user_agent' => $userAgent,
    'ip' => $clientIp,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
]);

try {
    // Initialize router
    $router = new Router();

    // Create request and response objects
    $request = new Request();
    $response = new Response();

    // Apply security middleware
    $securityMiddleware = new SecurityMiddleware();
    $securityResponse = $securityMiddleware->handle($request, $response);

    // If security middleware blocked the request, send that response
    if ($securityResponse !== null) {
        $securityResponse->send();
        return;
    }

    // Route the request
    $result = $router->dispatch($request, $response);

    // Send response
    $response->send();

    // Log successful request
    $accessLogger->info("HTTP request completed", [
        'method' => $method,
        'uri' => $uri,
        'status_code' => $response->getStatusCode(),
        'ip' => $clientIp,
        'response_time_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
    ]);

} catch (Throwable $e) {
    // Clean output buffer
    ob_end_clean();

    // Log the error
    Logger::errors()->critical("Uncaught exception in request handler", [
        'method' => $method,
        'uri' => $uri,
        'ip' => $clientIp,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    // Let error handler deal with it
    throw $e;
}

/**
 * Get client IP address from various headers
 */
function getClientIp(): string
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

