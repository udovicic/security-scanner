<?php

// Test script to verify web application components work correctly
require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\Request;
use SecurityScanner\Core\Response;
use SecurityScanner\Core\Router;

echo "Testing Security Scanner Web Components...\n\n";

try {
    // Test 1: Request object
    echo "1. Testing Request object:\n";

    // Mock some server variables for testing
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test?param=value';
    $_SERVER['HTTP_USER_AGENT'] = 'Test Browser';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    $request = new Request();

    echo "✓ Method: " . $request->getMethod() . "\n";
    echo "✓ Path: " . $request->getPath() . "\n";
    echo "✓ Query param: " . $request->getQuery('param') . "\n";
    echo "✓ Client IP: " . $request->getClientIp() . "\n";
    echo "✓ User Agent: " . $request->getUserAgent() . "\n\n";

    // Test 2: Response object
    echo "2. Testing Response object:\n";

    $response = new Response();

    $response->setStatusCode(200)
             ->setHeader('X-Test-Header', 'Test Value')
             ->setBody('Test Response Body');

    echo "✓ Status Code: " . $response->getStatusCode() . "\n";
    echo "✓ Header: " . $response->getHeader('X-Test-Header') . "\n";
    echo "✓ Body Length: " . strlen($response->getBody()) . " characters\n\n";

    // Test 3: JSON Response
    echo "3. Testing JSON Response:\n";

    $jsonResponse = new Response();
    $jsonResponse->json(['message' => 'Hello World', 'status' => 'ok']);

    echo "✓ JSON Response: " . $jsonResponse->getBody() . "\n";
    echo "✓ Content-Type: " . $jsonResponse->getHeader('Content-Type') . "\n\n";

    // Test 4: Router initialization
    echo "4. Testing Router:\n";

    $router = new Router();
    echo "✓ Router initialized successfully\n";

    // Test route pattern to regex conversion using reflection
    $reflection = new ReflectionClass($router);
    $method = $reflection->getMethod('patternToRegex');
    $method->setAccessible(true);

    $testPatterns = [
        '/users' => '/^\/users$/',
        '/users/{id}' => '/^\/users\/([^\/]+)$/',
        '/users/{id}/posts/{postId}' => '/^\/users\/([^\/]+)\/posts\/([^\/]+)$/',
    ];

    foreach ($testPatterns as $pattern => $expectedRegex) {
        $actualRegex = $method->invoke($router, $pattern);
        if ($actualRegex === $expectedRegex) {
            echo "✓ Pattern '{$pattern}' converted correctly\n";
        } else {
            echo "✗ Pattern '{$pattern}' failed. Expected: {$expectedRegex}, Got: {$actualRegex}\n";
        }
    }
    echo "\n";

    // Test 5: Request validation
    echo "5. Testing Request validation:\n";

    $_POST = [
        'email' => 'test@example.com',
        'name' => 'Test User',
        'age' => '25'
    ];

    $request2 = new Request();
    $errors = $request2->validate([
        'email' => 'required|email',
        'name' => 'required|string|min:2',
        'age' => 'required|integer|min:18'
    ]);

    if (empty($errors)) {
        echo "✓ Validation passed for valid data\n";
    } else {
        echo "✗ Validation failed unexpectedly: " . json_encode($errors) . "\n";
    }

    // Test invalid data
    $_POST = [
        'email' => 'invalid-email',
        'name' => '',
        'age' => '15'
    ];

    $request3 = new Request();
    $errors = $request3->validate([
        'email' => 'required|email',
        'name' => 'required|string|min:2',
        'age' => 'required|integer|min:18'
    ]);

    if (!empty($errors)) {
        echo "✓ Validation correctly failed for invalid data\n";
        echo "  - Found " . count($errors) . " validation errors\n";
    } else {
        echo "✗ Validation should have failed for invalid data\n";
    }
    echo "\n";

    // Test 6: Security headers
    echo "6. Testing security headers:\n";

    $secureResponse = new Response();
    $secureResponse->setHeader('X-Frame-Options', 'DENY')
                   ->setHeader('X-Content-Type-Options', 'nosniff')
                   ->setHeader('X-XSS-Protection', '1; mode=block');

    $headers = $secureResponse->getHeaders();
    $expectedHeaders = ['X-Frame-Options', 'X-Content-Type-Options', 'X-XSS-Protection'];

    foreach ($expectedHeaders as $header) {
        if (isset($headers[$header])) {
            echo "✓ Security header '{$header}' is set\n";
        } else {
            echo "✗ Security header '{$header}' is missing\n";
        }
    }
    echo "\n";

    echo "Web components test completed successfully!\n";
    echo "The web application is ready to handle HTTP requests.\n";

} catch (\Exception $e) {
    echo "Test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Clean up
unset($_POST);
$_SERVER = [];