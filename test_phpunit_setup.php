<?php

/**
 * Test script to validate PHPUnit setup
 */

echo "Testing PHPUnit Setup\n";
echo "====================\n\n";

// Check if PHPUnit configuration exists
if (!file_exists(__DIR__ . '/phpunit.xml')) {
    echo "❌ PHPUnit configuration file (phpunit.xml) not found\n";
    exit(1);
}
echo "✅ PHPUnit configuration file found\n";

// Check if test directories exist
$testDirs = ['tests', 'tests/Unit', 'tests/Integration', 'tests/Feature'];
foreach ($testDirs as $dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) {
        echo "❌ Test directory '{$dir}' not found\n";
        exit(1);
    }
    echo "✅ Test directory '{$dir}' exists\n";
}

// Check if bootstrap file exists
if (!file_exists(__DIR__ . '/tests/bootstrap.php')) {
    echo "❌ Test bootstrap file not found\n";
    exit(1);
}
echo "✅ Test bootstrap file found\n";

// Check if base TestCase exists
if (!file_exists(__DIR__ . '/tests/TestCase.php')) {
    echo "❌ Base TestCase class not found\n";
    exit(1);
}
echo "✅ Base TestCase class found\n";

// Test the bootstrap file
echo "\nTesting bootstrap file...\n";
try {
    require_once __DIR__ . '/tests/bootstrap.php';
    echo "✅ Bootstrap file loaded successfully\n";
} catch (Exception $e) {
    echo "❌ Bootstrap file failed to load: " . $e->getMessage() . "\n";
    exit(1);
}

// Test database connection in test environment
try {
    $database = SecurityScanner\Core\Database::getInstance();
    $result = $database->fetchRow('SELECT 1 as test');
    if ($result['test'] == 1) {
        echo "✅ Test database connection working\n";
    } else {
        echo "❌ Test database connection failed\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Test database connection error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test configuration in test environment
try {
    $config = SecurityScanner\Core\Config::getInstance();
    if ($config->get('app.environment') === 'testing') {
        echo "✅ Test environment configuration correct\n";
    } else {
        echo "❌ Test environment not set correctly\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Configuration error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test helper functions
try {
    $website = createTestWebsite(['name' => 'PHPUnit Test Website']);
    if (isset($website['id']) && $website['name'] === 'PHPUnit Test Website') {
        echo "✅ Test helper functions working\n";
    } else {
        echo "❌ Test helper functions failed\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Test helper functions error: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if sample tests exist
$sampleTests = [
    'tests/Unit/Core/ConfigTest.php',
    'tests/Unit/Core/DatabaseTest.php'
];

foreach ($sampleTests as $test) {
    if (!file_exists(__DIR__ . '/' . $test)) {
        echo "❌ Sample test '{$test}' not found\n";
        exit(1);
    }
    echo "✅ Sample test '{$test}' exists\n";
}

// Test if we can run a simple PHPUnit command (if PHPUnit is available)
echo "\nTesting PHPUnit execution...\n";

// Check if PHPUnit is available
$phpunitPaths = [
    __DIR__ . '/vendor/bin/phpunit',
    '/usr/local/bin/phpunit',
    '/usr/bin/phpunit'
];

$phpunitFound = false;
$phpunitPath = null;

foreach ($phpunitPaths as $path) {
    if (file_exists($path)) {
        $phpunitFound = true;
        $phpunitPath = $path;
        break;
    }
}

if (!$phpunitFound) {
    // Try global phpunit command
    exec('which phpunit 2>/dev/null', $output, $returnCode);
    if ($returnCode === 0 && !empty($output)) {
        $phpunitFound = true;
        $phpunitPath = trim($output[0]);
    }
}

if ($phpunitFound) {
    echo "✅ PHPUnit executable found at: {$phpunitPath}\n";

    // Try to run a simple validation
    $command = escapeshellcmd($phpunitPath) . ' --version 2>&1';
    $output = shell_exec($command);

    if (strpos($output, 'PHPUnit') !== false) {
        echo "✅ PHPUnit is working: " . trim($output) . "\n";
    } else {
        echo "⚠️  PHPUnit may not be working properly: {$output}\n";
    }
} else {
    echo "⚠️  PHPUnit executable not found - you may need to install it\n";
    echo "   To install PHPUnit, run: composer require --dev phpunit/phpunit\n";
}

echo "\n===================\n";
echo "PHPUnit Setup Test Complete!\n\n";

if ($phpunitFound) {
    echo "To run tests, use one of these commands:\n";
    echo "- Run all tests: {$phpunitPath}\n";
    echo "- Run unit tests only: {$phpunitPath} tests/Unit\n";
    echo "- Run with coverage: {$phpunitPath} --coverage-html tests/coverage/html\n";
    echo "- Run specific test: {$phpunitPath} tests/Unit/Core/ConfigTest.php\n\n";
} else {
    echo "Install PHPUnit first, then run:\n";
    echo "- ./vendor/bin/phpunit\n";
    echo "- ./vendor/bin/phpunit tests/Unit\n\n";
}

echo "Test configuration:\n";
echo "- Test environment: " . $config->get('app.environment') . "\n";
echo "- Test database: " . $config->get('database.default') . "\n";
echo "- Debug mode: " . ($config->isDebug() ? 'enabled' : 'disabled') . "\n";

exit(0);