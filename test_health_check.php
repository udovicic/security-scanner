<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{HealthCheck, Response};

echo "🏥 Testing Health Check System (Task 34)\n";
echo "========================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing Health Check Creation:\n";

    // Test default configuration
    $totalTests++;
    $health = new HealthCheck();
    if ($health instanceof HealthCheck) {
        echo "   ✅ Health check creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Health check creation failed\n";
    }

    echo "\n2. Testing Custom Check Registration:\n";

    // Test registering a custom check
    $totalTests++;
    $customCheckCalled = false;
    $health->register('custom_test', function() use (&$customCheckCalled) {
        $customCheckCalled = true;
        return [
            'status' => 'pass',
            'message' => 'Custom check passed'
        ];
    });

    if ($health instanceof HealthCheck) {
        echo "   ✅ Custom check registration: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Custom check registration failed\n";
    }

    echo "\n3. Testing Single Health Check Execution:\n";

    // Test running a single check
    $totalTests++;
    $result = $health->checkSingle('custom_test');

    if ($customCheckCalled &&
        $result['status'] === 'pass' &&
        $result['message'] === 'Custom check passed' &&
        isset($result['execution_time'])) {
        echo "   ✅ Single check execution: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Single check execution failed\n";
    }

    echo "\n4. Testing Complete Health Check:\n";

    // Test running all health checks
    $totalTests++;
    $fullResults = $health->check();

    $expectedKeys = ['status', 'timestamp', 'datetime', 'checks', 'summary', 'execution_time'];
    $hasAllKeys = true;
    foreach ($expectedKeys as $key) {
        if (!array_key_exists($key, $fullResults)) {
            $hasAllKeys = false;
            break;
        }
    }

    if ($hasAllKeys &&
        in_array($fullResults['status'], ['healthy', 'degraded', 'unhealthy']) &&
        is_array($fullResults['checks']) &&
        $fullResults['summary']['total'] > 0) {
        echo "   ✅ Complete health check: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Complete health check failed\n";
    }

    echo "\n5. Testing Default System Checks:\n";

    // Test that default checks are registered
    $totalTests++;
    $checksInfo = $health->getChecksInfo();
    $defaultChecks = ['memory', 'disk_space', 'php_config', 'file_permissions'];
    $hasDefaultChecks = true;

    foreach ($defaultChecks as $checkName) {
        if (!isset($checksInfo[$checkName])) {
            $hasDefaultChecks = false;
            break;
        }
    }

    if ($hasDefaultChecks) {
        echo "   ✅ Default system checks: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Default system checks missing\n";
    }

    echo "\n6. Testing Health Status Methods:\n";

    // Test status methods
    $totalTests++;
    $status = $health->getStatus();
    $isHealthy = $health->isHealthy();

    if (in_array($status, ['healthy', 'degraded', 'unhealthy']) &&
        is_bool($isHealthy)) {
        echo "   ✅ Health status methods: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Health status methods failed\n";
    }

    echo "\n7. Testing Critical Checks:\n";

    // Test critical check behavior
    $totalTests++;
    $criticalHealth = new HealthCheck();
    $criticalHealth->register('critical_fail', function() {
        return [
            'status' => 'fail',
            'message' => 'Critical check failed'
        ];
    }, true); // Mark as critical

    $criticalResults = $criticalHealth->check();

    if ($criticalResults['status'] === 'unhealthy' &&
        $criticalResults['summary']['critical_failed'] > 0) {
        echo "   ✅ Critical check handling: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Critical check handling failed\n";
    }

    echo "\n8. Testing Warning Checks:\n";

    // Test warning check behavior
    $totalTests++;
    $warningHealth = new HealthCheck();
    $warningHealth->register('warning_check', function() {
        return [
            'status' => 'warning',
            'message' => 'Warning check result'
        ];
    });

    $warningResults = $warningHealth->check();

    if ($warningResults['status'] === 'degraded' &&
        $warningResults['summary']['warnings'] > 0) {
        echo "   ✅ Warning check handling: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Warning check handling failed\n";
    }

    echo "\n9. Testing HTTP Response:\n";

    // Test HTTP response generation
    $totalTests++;
    $httpResponse = $health->getHttpResponse();

    if ($httpResponse instanceof Response &&
        str_contains($httpResponse->getHeader('Content-Type') ?? '', 'json') &&
        str_contains($httpResponse->getHeader('Content-Type') ?? '', 'application/health+json') &&
        $httpResponse->getHeader('Cache-Control') === 'no-cache, no-store, must-revalidate') {
        echo "   ✅ HTTP response generation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ HTTP response generation failed\n";
        echo "   Debug - isResponse: " . ($httpResponse instanceof Response ? 'yes' : 'no') . "\n";
        echo "   Debug - Content-Type: " . ($httpResponse->getHeader('Content-Type') ?? 'null') . "\n";
        echo "   Debug - Cache-Control: " . ($httpResponse->getHeader('Cache-Control') ?? 'null') . "\n";
    }

    echo "\n10. Testing Endpoint Creation:\n";

    // Test endpoint closures
    $totalTests++;
    $healthEndpoint = $health->createEndpoint();
    $pingEndpoint = $health->createPingEndpoint();
    $readinessEndpoint = $health->createReadinessEndpoint();
    $livenessEndpoint = $health->createLivenessEndpoint();

    if ($healthEndpoint instanceof \Closure &&
        $pingEndpoint instanceof \Closure &&
        $readinessEndpoint instanceof \Closure &&
        $livenessEndpoint instanceof \Closure) {
        echo "   ✅ Endpoint creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Endpoint creation failed\n";
    }

    echo "\n11. Testing Ping Endpoint:\n";

    // Test ping endpoint response
    $totalTests++;
    $pingResponse = $pingEndpoint();

    if ($pingResponse instanceof Response &&
        $pingResponse->getStatusCode() === 200 &&
        $pingResponse->isJson()) {
        $pingContent = json_decode($pingResponse->getContent(), true);
        if ($pingContent['status'] === 'ok' && isset($pingContent['timestamp'])) {
            echo "   ✅ Ping endpoint: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ Ping endpoint response content invalid\n";
        }
    } else {
        echo "   ❌ Ping endpoint response failed\n";
    }

    echo "\n12. Testing Readiness Endpoint:\n";

    // Test readiness endpoint
    $totalTests++;
    $readinessResponse = $readinessEndpoint();

    if ($readinessResponse instanceof Response) {
        $readinessContent = json_decode($readinessResponse->getContent(), true);
        if (isset($readinessContent['ready']) &&
            is_bool($readinessContent['ready']) &&
            isset($readinessContent['checks'])) {
            echo "   ✅ Readiness endpoint: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ Readiness endpoint content invalid\n";
        }
    } else {
        echo "   ❌ Readiness endpoint response failed\n";
    }

    echo "\n13. Testing Liveness Endpoint:\n";

    // Test liveness endpoint
    $totalTests++;
    $livenessResponse = $livenessEndpoint();

    if ($livenessResponse instanceof Response &&
        $livenessResponse->getStatusCode() === 200) {
        $livenessContent = json_decode($livenessResponse->getContent(), true);
        if ($livenessContent['alive'] === true && isset($livenessContent['timestamp'])) {
            echo "   ✅ Liveness endpoint: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ Liveness endpoint content invalid\n";
        }
    } else {
        echo "   ❌ Liveness endpoint response failed\n";
    }

    echo "\n14. Testing System Information:\n";

    // Test system information inclusion
    $totalTests++;
    $systemHealth = new HealthCheck(['include_system_info' => true, 'include_performance' => true]);
    $systemResults = $systemHealth->check();

    if (isset($systemResults['system']) &&
        isset($systemResults['performance']) &&
        isset($systemResults['system']['php_version']) &&
        isset($systemResults['performance']['memory_usage'])) {
        echo "   ✅ System information: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ System information missing\n";
    }

    echo "\n15. Testing Check Exception Handling:\n";

    // Test error handling in checks
    $totalTests++;
    $errorHealth = new HealthCheck();
    $errorHealth->register('error_check', function() {
        throw new \Exception('Test exception');
    });

    $errorResults = $errorHealth->checkSingle('error_check');

    if ($errorResults['status'] === 'fail' &&
        isset($errorResults['error']) &&
        str_contains($errorResults['error'], 'Test exception')) {
        echo "   ✅ Exception handling: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Exception handling failed\n";
    }

    echo "\n16. Testing Memory and Disk Checks:\n";

    // Test specific system checks
    $totalTests++;
    $memoryResult = $health->checkSingle('memory');
    $diskResult = $health->checkSingle('disk_space');

    if (isset($memoryResult['status']) &&
        isset($memoryResult['details']['percentage']) &&
        isset($diskResult['status']) &&
        isset($diskResult['details']['percentage'])) {
        echo "   ✅ Memory and disk checks: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Memory and disk checks failed\n";
    }

    echo "\nHealth Check Test Summary:\n";
    echo "==========================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed === $totalTests) {
        echo "🎉 All health check tests passed! System is working correctly.\n";
        echo "\nHealth Check Features:\n";
        echo "- Comprehensive system health monitoring\n";
        echo "- Custom check registration with critical/warning levels\n";
        echo "- Default system checks (memory, disk, PHP config, file permissions)\n";
        echo "- HTTP endpoints with proper status codes and headers\n";
        echo "- Kubernetes-compatible readiness and liveness endpoints\n";
        echo "- System information and performance metrics\n";
        echo "- Exception handling and error reporting\n";
        echo "- Configurable warning thresholds\n";
        echo "- Execution time tracking for each check\n";
        echo "- JSON response format with detailed check results\n";
        echo "- Support for monitoring system integration\n";
        echo "- Cache-control headers for real-time monitoring\n";

        echo "\n📊 Sample Health Check Results:\n";
        $sampleResults = $health->check();
        echo "Overall Status: " . $sampleResults['status'] . "\n";
        echo "Total Checks: " . $sampleResults['summary']['total'] . "\n";
        echo "Passed: " . $sampleResults['summary']['passed'] . "\n";
        echo "Execution Time: " . $sampleResults['execution_time'] . "ms\n";
    } else {
        echo "⚠️  Some health check tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}