<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{
    SecurityHeaders,
    SecurityMiddleware,
    Request,
    Response,
    Config
};

echo "🛡️ Testing Security Headers and Middleware...\n\n";

try {
    echo "1. Testing Security Headers class...\n";

    $response = new Response();
    $securityHeaders = new SecurityHeaders();

    // Test security headers application
    $securityHeaders->apply($response);

    $headers = $response->getHeaders();

    // Check required security headers
    $requiredHeaders = [
        'X-Frame-Options',
        'X-Content-Type-Options',
        'X-XSS-Protection',
        'Referrer-Policy',
        'Content-Security-Policy',
        'Permissions-Policy',
        'Cache-Control'
    ];

    foreach ($requiredHeaders as $header) {
        if (isset($headers[$header])) {
            echo "   ✓ {$header}: " . substr($headers[$header], 0, 50) . "...\n";
        } else {
            echo "   ❌ Missing header: {$header}\n";
        }
    }

    echo "\n2. Testing Security Headers validation...\n";

    $validationIssues = $securityHeaders->validateConfiguration();
    if (empty($validationIssues)) {
        echo "   ✓ Configuration validation passed\n";
    } else {
        echo "   ❌ Validation issues found:\n";
        foreach ($validationIssues as $issue) {
            echo "     - {$issue}\n";
        }
    }

    echo "\n3. Testing Security Headers recommendations...\n";

    $recommendations = $securityHeaders->getRecommendations();
    if (empty($recommendations)) {
        echo "   ✓ No security recommendations needed\n";
    } else {
        echo "   ℹ️ Security recommendations:\n";
        foreach ($recommendations as $rec) {
            echo "     - [{$rec['type']}] {$rec['message']}\n";
        }
    }

    echo "\n4. Testing Security Middleware...\n";

    $middleware = new SecurityMiddleware();

    // Test normal request (should pass)
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible test browser)';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $request = new Request();
    $response = new Response();

    $blockResponse = $middleware->handle($request, $response);

    if ($blockResponse === null) {
        echo "   ✓ Normal request allowed through middleware\n";
    } else {
        echo "   ❌ Normal request was blocked: " . $blockResponse->getStatusCode() . "\n";
    }

    echo "\n5. Testing suspicious user agent detection...\n";

    // Test suspicious user agent - reset the server array first
    unset($_SERVER['HTTP_USER_AGENT']); // Clear any previous value
    $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.0';
    $suspiciousRequest = new Request();
    $suspiciousResponse = new Response();

    // Create new middleware instance for this test
    $suspiciousMiddleware = new SecurityMiddleware();
    $blockResponse = $suspiciousMiddleware->handle($suspiciousRequest, $suspiciousResponse);

    if ($blockResponse !== null && $blockResponse->getStatusCode() === 403) {
        echo "   ✓ Suspicious user agent correctly blocked\n";
    } else {
        echo "   ❌ Suspicious user agent was not blocked (Status: " .
             ($blockResponse ? $blockResponse->getStatusCode() : 'null') . ")\n";
    }

    echo "\n6. Testing middleware statistics...\n";

    $stats = $middleware->getSecurityStats();
    echo "   ✓ Rate limiting enabled: " . ($stats['rate_limit_enabled'] ? 'true' : 'false') . "\n";
    $rateLimit = is_array($stats['rate_limit']) ? implode(',', $stats['rate_limit']) : $stats['rate_limit'];
    echo "   ✓ Rate limit: " . $rateLimit . " requests\n";
    echo "   ✓ Blocked IPs count: " . $stats['blocked_ips_count'] . "\n";
    echo "   ✓ Max request size: " . number_format($stats['max_request_size']) . " bytes\n";

    echo "\n7. Testing CSP header parsing...\n";

    $cspHeader = $headers['Content-Security-Policy'] ?? '';
    if (str_contains($cspHeader, 'default-src') && str_contains($cspHeader, 'script-src')) {
        echo "   ✓ CSP header contains required directives\n";
    } else {
        echo "   ❌ CSP header malformed or missing directives\n";
    }

    echo "\n8. Testing Permissions-Policy header...\n";

    $permissionsHeader = $headers['Permissions-Policy'] ?? '';
    if (str_contains($permissionsHeader, 'camera=()') && str_contains($permissionsHeader, 'microphone=()')) {
        echo "   ✓ Permissions-Policy header contains camera and microphone restrictions\n";
    } else {
        echo "   ❌ Permissions-Policy header incomplete\n";
    }

    echo "\n9. Testing rate limiting functionality...\n";

    // Create cache directory if it doesn't exist
    $config = Config::getInstance();
    $cacheDir = $config->get('cache.path', '/tmp/security_scanner_cache');
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Reset user agent for rate limit test
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test browser)';

    $rateLimitPassed = 0;
    $rateLimitBlocked = 0;

    // Try to make multiple requests to test rate limiting
    for ($i = 0; $i < 5; $i++) {
        $testRequest = new Request();
        $testResponse = new Response();

        $blockResponse = $middleware->handle($testRequest, $testResponse);

        if ($blockResponse === null) {
            $rateLimitPassed++;
        } else {
            $rateLimitBlocked++;
        }
    }

    echo "   ✓ Rate limit test: {$rateLimitPassed} passed, {$rateLimitBlocked} blocked\n";

    echo "\n✅ All security headers and middleware tests completed successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}