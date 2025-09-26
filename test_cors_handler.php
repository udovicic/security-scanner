<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{CorsHandler, Request, Response};

echo "🌐 Testing CORS Handling System (Task 32)\n";
echo "========================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing CORS Handler Creation:\n";

    // Test default configuration
    $totalTests++;
    $cors = new CorsHandler();
    if ($cors instanceof CorsHandler) {
        echo "   ✅ CORS handler creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ CORS handler creation failed\n";
    }

    // Test preset configurations
    $totalTests++;
    $apiCors = CorsHandler::forApi();
    $restrictiveCors = CorsHandler::restrictive(['https://example.com']);
    $permissiveCors = CorsHandler::permissive();

    if ($apiCors instanceof CorsHandler &&
        $restrictiveCors instanceof CorsHandler &&
        $permissiveCors instanceof CorsHandler) {
        echo "   ✅ Preset configurations: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Preset configurations failed\n";
    }

    echo "\n2. Testing CORS Request Detection:\n";

    // Test non-CORS request
    $totalTests++;
    $regularRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/users'
    ]);

    $corsInfo = $cors->getCorsInfo($regularRequest);
    if (!$corsInfo['is_cors_request']) {
        echo "   ✅ Non-CORS request detection: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Should not detect CORS without Origin header\n";
    }

    // Test CORS request
    $totalTests++;
    $corsRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com'
    ]);

    $corsInfo = $cors->getCorsInfo($corsRequest);
    if ($corsInfo['is_cors_request'] && !$corsInfo['is_preflight']) {
        echo "   ✅ CORS request detection: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ CORS request detection failed\n";
    }

    // Test preflight request
    $totalTests++;
    $preflightRequest = new Request([], [], [
        'REQUEST_METHOD' => 'OPTIONS',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'
    ]);

    $corsInfo = $cors->getCorsInfo($preflightRequest);
    if ($corsInfo['is_cors_request'] && $corsInfo['is_preflight']) {
        echo "   ✅ Preflight request detection: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Preflight request detection failed\n";
    }

    echo "\n3. Testing Origin Validation:\n";

    // Test wildcard origin (default configuration)
    $totalTests++;
    $wildcardCors = new CorsHandler(['allowed_origins' => ['*']]);
    $wildcardInfo = $wildcardCors->getCorsInfo($corsRequest);

    if ($wildcardInfo['origin_allowed']) {
        echo "   ✅ Wildcard origin validation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Wildcard origin should allow all origins\n";
    }

    // Test specific origin validation
    $totalTests++;
    $specificCors = new CorsHandler(['allowed_origins' => ['https://example.com', 'https://app.example.com']]);
    $allowedInfo = $specificCors->getCorsInfo($corsRequest);

    if ($allowedInfo['origin_allowed']) {
        echo "   ✅ Allowed origin validation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Allowed origin validation failed\n";
    }

    // Test disallowed origin
    $totalTests++;
    $disallowedRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://malicious.com'
    ]);

    $disallowedInfo = $specificCors->getCorsInfo($disallowedRequest);
    if (!$disallowedInfo['origin_allowed']) {
        echo "   ✅ Disallowed origin validation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Should reject disallowed origin\n";
    }

    echo "\n4. Testing Preflight Handling:\n";

    // Test successful preflight
    $totalTests++;
    $preflightCors = new CorsHandler([
        'allowed_origins' => ['https://example.com'],
        'allowed_methods' => ['GET', 'POST', 'PUT'],
        'allowed_headers' => ['content-type', 'authorization']
    ]);

    $validPreflight = new Request([], [], [
        'REQUEST_METHOD' => 'OPTIONS',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization'
    ]);

    $preflightResponse = $preflightCors->handle($validPreflight);

    if ($preflightResponse instanceof Response &&
        $preflightResponse->getStatusCode() === 204 &&
        $preflightResponse->getHeader('Access-Control-Allow-Origin') === 'https://example.com') {
        echo "   ✅ Valid preflight handling: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Valid preflight handling failed\n";
    }

    // Test preflight with disallowed method
    $totalTests++;
    $invalidMethodPreflight = new Request([], [], [
        'REQUEST_METHOD' => 'OPTIONS',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE'
    ]);

    $methodResponse = $preflightCors->handle($invalidMethodPreflight);

    if ($methodResponse instanceof Response && $methodResponse->getStatusCode() === 405) {
        echo "   ✅ Disallowed method preflight: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Should reject disallowed method in preflight\n";
    }

    // Test preflight with disallowed headers
    $totalTests++;
    $invalidHeadersPreflight = new Request([], [], [
        'REQUEST_METHOD' => 'OPTIONS',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-header, x-secret-header'
    ]);

    $headersResponse = $preflightCors->handle($invalidHeadersPreflight);

    if ($headersResponse instanceof Response && $headersResponse->getStatusCode() === 400) {
        echo "   ✅ Disallowed headers preflight: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Should reject disallowed headers in preflight\n";
    }

    echo "\n5. Testing Middleware Integration:\n";

    // Test middleware creation
    $totalTests++;
    $middleware = $cors->middleware();
    if ($middleware instanceof \Closure) {
        echo "   ✅ Middleware creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware should return closure\n";
    }

    // Test middleware with regular request
    $totalTests++;
    $middlewareCors = new CorsHandler(['allowed_origins' => ['https://example.com']]);
    $middlewareFunc = $middlewareCors->middleware();

    $nextCalled = false;
    $next = function($request) use (&$nextCalled) {
        $nextCalled = true;
        return Response::json(['data' => 'test']);
    };

    $response = $middlewareFunc($corsRequest, $next);

    if ($nextCalled &&
        $response instanceof Response &&
        $response->getHeader('Access-Control-Allow-Origin') === 'https://example.com') {
        echo "   ✅ Middleware with regular CORS request: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware with regular CORS request failed\n";
    }

    // Test middleware with preflight request
    $totalTests++;
    $nextCalled = false;
    $response = $middlewareFunc($validPreflight, $next);

    if (!$nextCalled &&
        $response instanceof Response &&
        $response->getStatusCode() === 204) {
        echo "   ✅ Middleware with preflight request: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware should handle preflight directly\n";
    }

    echo "\n6. Testing CORS Headers:\n";

    // Test credentials header
    $totalTests++;
    $credentialsCors = new CorsHandler([
        'allowed_origins' => ['https://example.com'],
        'allow_credentials' => true
    ]);

    $credentialsMiddleware = $credentialsCors->middleware();
    $response = $credentialsMiddleware($corsRequest, $next);

    if ($response->getHeader('Access-Control-Allow-Credentials') === 'true') {
        echo "   ✅ Credentials header: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Credentials header missing\n";
    }

    // Test exposed headers
    $totalTests++;
    $exposedCors = new CorsHandler([
        'allowed_origins' => ['https://example.com'],
        'exposed_headers' => ['x-custom-header', 'x-api-version']
    ]);

    $exposedMiddleware = $exposedCors->middleware();
    $response = $exposedMiddleware($corsRequest, $next);

    $exposedHeader = $response->getHeader('Access-Control-Expose-Headers');
    if ($exposedHeader && str_contains($exposedHeader, 'x-custom-header')) {
        echo "   ✅ Exposed headers: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Exposed headers not set correctly\n";
    }

    // Test Vary header
    $totalTests++;
    $varyHeader = $response->getHeader('Vary');
    if ($varyHeader && str_contains($varyHeader, 'Origin')) {
        echo "   ✅ Vary header: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Vary header should include Origin\n";
    }

    echo "\n7. Testing Path Filtering:\n";

    // Test path-specific CORS
    $totalTests++;
    $pathCors = new CorsHandler([
        'allowed_origins' => ['*'],
        'paths' => ['/api/*']
    ]);

    $apiRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/users',
        'HTTP_ORIGIN' => 'https://example.com'
    ]);

    $nonApiRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/admin/dashboard',
        'HTTP_ORIGIN' => 'https://example.com'
    ]);

    $apiInfo = $pathCors->getCorsInfo($apiRequest);
    $nonApiInfo = $pathCors->getCorsInfo($nonApiRequest);

    if ($apiInfo['should_apply_cors'] && !$nonApiInfo['should_apply_cors']) {
        echo "   ✅ Path filtering: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Path filtering failed\n";
    }

    echo "\n8. Testing Fluent Interface:\n";

    // Test fluent configuration
    $totalTests++;
    $fluentCors = new CorsHandler();
    $fluentCors->addOrigin('https://app.example.com')
              ->addMethod('PATCH')
              ->addHeader('x-api-key')
              ->exposeHeader('x-response-time')
              ->allowCredentials(true)
              ->setMaxAge(7200);

    $config = $fluentCors->getConfig();

    if (in_array('https://app.example.com', $config['allowed_origins']) &&
        in_array('PATCH', $config['allowed_methods']) &&
        in_array('x-api-key', $config['allowed_headers']) &&
        in_array('x-response-time', $config['exposed_headers']) &&
        $config['allow_credentials'] === true &&
        $config['max_age'] === 7200) {
        echo "   ✅ Fluent interface: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Fluent interface failed\n";
    }

    echo "\n9. Testing Configuration Validation:\n";

    // Test configuration validation
    $totalTests++;
    $invalidCors = new CorsHandler([
        'allowed_origins' => ['*'],
        'allow_credentials' => true
    ]);

    $issues = $invalidCors->validateConfig();

    if (!empty($issues) && str_contains($issues[0], 'wildcard origin')) {
        echo "   ✅ Configuration validation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Should detect wildcard + credentials issue\n";
    }

    echo "\n10. Testing API Preset:\n";

    // Test API preset functionality
    $totalTests++;
    $apiCorsHandler = CorsHandler::forApi();
    $apiConfig = $apiCorsHandler->getConfig();

    if (in_array('/api/*', $apiConfig['paths']) &&
        in_array('authorization', $apiConfig['allowed_headers']) &&
        $apiConfig['allow_credentials'] === true) {
        echo "   ✅ API preset configuration: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ API preset configuration failed\n";
    }

    echo "\nCORS Handling Test Summary:\n";
    echo "===========================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed === $totalTests) {
        echo "🎉 All CORS handling tests passed! System is working correctly.\n";
        echo "\nCORS Handling Features:\n";
        echo "- Comprehensive CORS support with full specification compliance\n";
        echo "- Preflight request handling with method and header validation\n";
        echo "- Origin validation with wildcards and pattern matching\n";
        echo "- Middleware integration for automatic CORS handling\n";
        echo "- Path-based CORS application with include/exclude patterns\n";
        echo "- Credentials support with proper security validation\n";
        echo "- Configurable exposed headers for client access\n";
        echo "- Vary header management for proper caching\n";
        echo "- Preset configurations for common use cases\n";
        echo "- Fluent interface for dynamic configuration\n";
        echo "- Configuration validation for security best practices\n";
        echo "- Detailed CORS information for debugging\n";
    } else {
        echo "⚠️  Some CORS handling tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}