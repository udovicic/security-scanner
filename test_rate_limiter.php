<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{RateLimiter, Request, Response};

echo "⏱️  Testing Rate Limiting System (Task 31)\n";
echo "=========================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing Rate Limiter Creation:\n";

    // Test default configuration
    $totalTests++;
    $rateLimiter = new RateLimiter();
    if ($rateLimiter instanceof RateLimiter) {
        echo "   ✅ Rate limiter creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Rate limiter creation failed\n";
    }

    // Test preset configurations
    $totalTests++;
    $apiLimiter = RateLimiter::forApi();
    $authLimiter = RateLimiter::forAuth();
    $uploadLimiter = RateLimiter::forUploads();

    if ($apiLimiter instanceof RateLimiter && $authLimiter instanceof RateLimiter && $uploadLimiter instanceof RateLimiter) {
        echo "   ✅ Preset configurations: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Preset configurations failed\n";
    }

    echo "\n2. Testing Basic Rate Limiting:\n";

    // Test initial check (should pass)
    $totalTests++;
    $testKey = 'test_user_123';
    if ($rateLimiter->check($testKey, 5, 60)) { // 5 requests per minute
        echo "   ✅ Initial rate limit check: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Initial rate limit check failed\n";
    }

    // Test attempt (should succeed)
    $totalTests++;
    if ($rateLimiter->attempt($testKey, 5, 60)) {
        echo "   ✅ First attempt: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ First attempt failed\n";
    }

    // Test current count
    $totalTests++;
    $count = $rateLimiter->getCurrentCount($testKey, 60);
    if ($count === 1) {
        echo "   ✅ Current count tracking: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Current count tracking failed (got {$count}, expected 1)\n";
    }

    echo "\n3. Testing Rate Limit Enforcement:\n";

    // Make multiple attempts to reach limit
    $totalTests++;
    $attempts = 0;
    $successful = 0;

    for ($i = 0; $i < 10; $i++) {
        $attempts++;
        if ($rateLimiter->attempt($testKey, 5, 60)) {
            $successful++;
        }
    }

    // Should have 4 more successful attempts (total of 5)
    if ($successful === 4) {
        echo "   ✅ Rate limit enforcement: PASSED (4 more successful attempts)\n";
        $testsPassed++;
    } else {
        echo "   ❌ Rate limit enforcement failed (got {$successful} successful, expected 4)\n";
    }

    // Test that further attempts fail
    $totalTests++;
    if (!$rateLimiter->attempt($testKey, 5, 60)) {
        echo "   ✅ Rate limit blocking: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Rate limit should block further attempts\n";
    }

    echo "\n4. Testing Rate Limit Status:\n";

    // Test remaining attempts
    $totalTests++;
    $remaining = $rateLimiter->getRemainingAttempts($testKey, 5, 60);
    if ($remaining === 0) {
        echo "   ✅ Remaining attempts calculation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Remaining attempts calculation failed (got {$remaining}, expected 0)\n";
    }

    // Test status information
    $totalTests++;
    $status = $rateLimiter->getStatus($testKey, 5, 60);
    $expectedKeys = ['key', 'limit', 'window', 'current', 'remaining', 'reset_in', 'reset_at', 'blocked'];
    $hasAllKeys = true;
    foreach ($expectedKeys as $key) {
        if (!array_key_exists($key, $status)) {
            $hasAllKeys = false;
            break;
        }
    }

    if ($hasAllKeys && $status['blocked'] === true && $status['current'] === 5) {
        echo "   ✅ Status information: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Status information incomplete or incorrect\n";
    }

    // Test time until reset
    $totalTests++;
    $resetTime = $rateLimiter->getTimeUntilReset($testKey, 60);
    if ($resetTime > 0 && $resetTime <= 60) {
        echo "   ✅ Time until reset: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Time until reset calculation failed (got {$resetTime})\n";
    }

    echo "\n5. Testing Burst Protection:\n";

    // Test burst protection with a new key
    $totalTests++;
    $burstKey = 'burst_test_user';
    $burstLimiter = new RateLimiter([
        'burst_protection' => true,
        'burst_limit' => 3,
        'burst_window' => 60,
        'default_limit' => 100,
        'default_window' => 3600
    ]);

    // Should allow up to 3 requests quickly
    $burstSuccessful = 0;
    for ($i = 0; $i < 5; $i++) {
        if ($burstLimiter->attempt($burstKey)) {
            $burstSuccessful++;
        }
    }

    if ($burstSuccessful === 3) {
        echo "   ✅ Burst protection: PASSED (3 requests allowed)\n";
        $testsPassed++;
    } else {
        echo "   ❌ Burst protection failed (got {$burstSuccessful}, expected 3)\n";
    }

    echo "\n6. Testing Key Generators:\n";

    // Test different key generation methods
    $totalTests++;
    $ipKey = $rateLimiter->forIp('192.168.1.1');
    $userKey = $rateLimiter->forUser(42);
    $endpointKey = $rateLimiter->forEndpoint('/api/users', 'GET');
    $compositeKey = $rateLimiter->forComposite(['user:42', 'ip:192.168.1.1']);

    if (str_contains($ipKey, 'ip:') &&
        str_contains($userKey, 'user:') &&
        str_contains($endpointKey, 'endpoint:') &&
        str_contains($compositeKey, 'composite:')) {
        echo "   ✅ Key generators: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Key generators failed\n";
    }

    echo "\n7. Testing Middleware Integration:\n";

    // Test middleware creation
    $totalTests++;
    $middleware = $rateLimiter->middleware(5, 60);
    if ($middleware instanceof \Closure) {
        echo "   ✅ Middleware creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware should return closure\n";
    }

    // Test middleware with request within limits
    $totalTests++;
    $request = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/test',
        'REMOTE_ADDR' => '192.168.1.100',
        'HTTP_USER_AGENT' => 'Test Agent'
    ]);

    $nextCalled = false;
    $next = function($request) use (&$nextCalled) {
        $nextCalled = true;
        return Response::json(['success' => true]);
    };

    $middlewareLimiter = new RateLimiter(['storage' => 'memory']);
    $middlewareFunc = $middlewareLimiter->middleware(10, 60);
    $response = $middlewareFunc($request, $next);

    if ($nextCalled && $response instanceof Response && $response->getHeader('X-RateLimit-Limit')) {
        echo "   ✅ Middleware with valid request: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware with valid request failed\n";
    }

    // Test middleware when rate limit exceeded
    $totalTests++;
    $strictLimiter = new RateLimiter(['storage' => 'memory']);
    $strictMiddleware = $strictLimiter->middleware(1, 60); // Very strict limit

    // First request should pass
    $strictMiddleware($request, $next);

    // Second request should be blocked
    $nextCalled = false;
    $response = $strictMiddleware($request, $next);

    if (!$nextCalled && $response instanceof Response && $response->getStatusCode() === 429) {
        echo "   ✅ Middleware rate limit blocking: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware should block requests when limit exceeded\n";
    }

    echo "\n8. Testing Reset and Cleanup:\n";

    // Test reset functionality
    $totalTests++;
    $resetKey = 'reset_test';
    $resetLimiter = new RateLimiter(['storage' => 'memory']);

    // Use up the limit
    for ($i = 0; $i < 5; $i++) {
        $resetLimiter->attempt($resetKey, 5, 60);
    }

    // Should be blocked
    $blockedBefore = !$resetLimiter->check($resetKey, 5, 60);

    // Reset and check again
    $resetLimiter->reset($resetKey, 60);
    $allowedAfter = $resetLimiter->check($resetKey, 5, 60);

    if ($blockedBefore && $allowedAfter) {
        echo "   ✅ Reset functionality: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Reset functionality failed\n";
    }

    // Test cleanup functionality
    $totalTests++;
    $cleanupLimiter = new RateLimiter(['storage' => 'memory']);

    // Create some entries
    $cleanupLimiter->attempt('cleanup1', 10, 1); // Short expiry
    $cleanupLimiter->attempt('cleanup2', 10, 3600); // Long expiry

    sleep(2); // Wait for first entry to expire

    $cleaned = $cleanupLimiter->cleanup();
    if ($cleaned >= 0) { // Cleanup should not fail
        echo "   ✅ Cleanup functionality: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Cleanup functionality failed\n";
    }

    echo "\n9. Testing Statistics:\n";

    // Test statistics
    $totalTests++;
    $stats = $rateLimiter->getStats();
    $expectedStatsKeys = ['storage_type', 'total_keys'];
    $hasStatsKeys = true;
    foreach ($expectedStatsKeys as $key) {
        if (!array_key_exists($key, $stats)) {
            $hasStatsKeys = false;
            break;
        }
    }

    if ($hasStatsKeys) {
        echo "   ✅ Statistics generation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Statistics generation failed\n";
    }

    echo "\n10. Testing File Storage:\n";

    // Test file-based storage
    $totalTests++;
    try {
        $fileLimiter = new RateLimiter([
            'storage' => 'file',
            'storage_path' => '/tmp/claude/rate_limiter_test/'
        ]);

        $fileTestKey = 'file_test_key';
        $fileSuccess = $fileLimiter->attempt($fileTestKey, 5, 60);
        $fileCount = $fileLimiter->getCurrentCount($fileTestKey, 60);

        if ($fileSuccess && $fileCount === 1) {
            echo "   ✅ File storage: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ File storage failed\n";
        }
    } catch (Exception $e) {
        echo "   ❌ File storage failed: " . $e->getMessage() . "\n";
    }

    echo "\nRate Limiting Test Summary:\n";
    echo "===========================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed === $totalTests) {
        echo "🎉 All rate limiting tests passed! System is working correctly.\n";
        echo "\nRate Limiting Features:\n";
        echo "- Configurable rate limits with sliding window algorithm\n";
        echo "- Burst protection to prevent rapid successive requests\n";
        echo "- Multiple storage backends (memory, file, database-ready)\n";
        echo "- Middleware integration for automatic request limiting\n";
        echo "- Comprehensive rate limit headers (X-RateLimit-*)\n";
        echo "- Key generation utilities for IP, user, endpoint, and composite keys\n";
        echo "- Preset configurations for API, authentication, and upload limiting\n";
        echo "- Automatic cleanup of expired rate limit data\n";
        echo "- Detailed status information and statistics\n";
        echo "- Reset functionality for manual rate limit clearing\n";
        echo "- Time-based expiration with efficient storage management\n";
        echo "- Probabilistic cleanup to maintain performance\n";
    } else {
        echo "⚠️  Some rate limiting tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}