<?php

/*
|--------------------------------------------------------------------------
| Test Runner
|--------------------------------------------------------------------------
|
| Simple test runner for SecurityScanner unit tests
|
*/

// Include bootstrap
require_once __DIR__ . '/tests/bootstrap_simple.php';

// Include the TestCase base class
require_once __DIR__ . '/tests/TestCase.php';

echo "SecurityScanner Test Runner\n";
echo "==========================\n\n";

// Function to run a specific test file
function runTestFile(string $testFile): bool
{
    if (!file_exists($testFile)) {
        echo "Test file not found: $testFile\n";
        return false;
    }

    echo "Running: " . basename($testFile) . "\n";

    try {
        require_once $testFile;

        // Get test class name from file
        $className = getTestClassName($testFile);

        if (!$className || !class_exists($className)) {
            echo "  ❌ Test class not found: $className\n";
            return false;
        }

        // Instantiate and run tests
        $testInstance = new $className();
        $reflection = new ReflectionClass($className);

        // Setup
        if (method_exists($testInstance, 'setUp')) {
            $testInstance->setUp();
        }

        $testMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn($method) => str_starts_with($method->name, 'test_')
        );

        $passed = 0;
        $failed = 0;

        foreach ($testMethods as $method) {
            try {
                $method->invoke($testInstance);
                echo "  ✅ " . $method->name . "\n";
                $passed++;
            } catch (Exception $e) {
                echo "  ❌ " . $method->name . " - " . $e->getMessage() . "\n";
                $failed++;
            }
        }

        // Teardown
        if (method_exists($testInstance, 'tearDown')) {
            $testInstance->tearDown();
        }

        echo "  Tests: $passed passed, $failed failed\n\n";

        return $failed === 0;

    } catch (Exception $e) {
        echo "  ❌ Error running test: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// Extract test class name from file path
function getTestClassName(string $filePath): ?string
{
    $fileName = basename($filePath, '.php');
    return "Tests\\Unit\\Services\\$fileName";
}

// Run all tests or specific test
$testArg = $argv[1] ?? null;

if ($testArg) {
    // Run specific test
    if (file_exists($testArg)) {
        $success = runTestFile($testArg);
    } else {
        $testFile = __DIR__ . "/tests/Unit/Services/{$testArg}Test.php";
        $success = runTestFile($testFile);
    }
} else {
    // Run all tests
    echo "Running all service tests...\n\n";

    $testDir = __DIR__ . '/tests/Unit/Services';
    $testFiles = glob($testDir . '/*Test.php');

    $totalPassed = 0;
    $totalFailed = 0;

    foreach ($testFiles as $testFile) {
        $success = runTestFile($testFile);
        if ($success) {
            $totalPassed++;
        } else {
            $totalFailed++;
        }
    }

    echo "\nSummary:\n";
    echo "========\n";
    echo "Test files: " . count($testFiles) . "\n";
    echo "Passed: $totalPassed\n";
    echo "Failed: $totalFailed\n";

    if ($totalFailed === 0) {
        echo "\n🎉 All tests passed!\n";
    } else {
        echo "\n❌ Some tests failed.\n";
        exit(1);
    }
}