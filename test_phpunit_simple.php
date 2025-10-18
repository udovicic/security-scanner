<?php

/**
 * Simple test script to validate PHPUnit setup
 */

echo "Testing Simple PHPUnit Setup\n";
echo "=============================\n\n";

try {
    require_once __DIR__ . '/tests/bootstrap_simple.php';
    echo "✅ Simple bootstrap loaded successfully\n";

    // Test configuration
    $config = SecurityScanner\Core\Config::getInstance();
    if ($config->get('app.environment') === 'testing') {
        echo "✅ Test environment configured correctly\n";
    }

    // Test database connection
    $database = SecurityScanner\Core\Database::getInstance();
    $result = $database->fetchRow('SELECT 1 as test');
    if ($result && $result['test'] == 1) {
        echo "✅ Database connection working\n";
    }

    // Test helper functions
    $website = createTestWebsite(['name' => 'PHPUnit Test']);
    if (isset($website['name']) && $website['name'] === 'PHPUnit Test') {
        echo "✅ Helper functions working\n";
    }

    // Test container
    $container = SecurityScanner\Core\Container::getInstance();
    if ($container) {
        echo "✅ Dependency injection container working\n";
    }

    echo "\n✅ PHPUnit setup is ready!\n\n";

    echo "Run tests with:\n";
    echo "- php vendor/bin/phpunit (if installed via Composer)\n";
    echo "- ./vendor/bin/phpunit\n";
    echo "- phpunit (if installed globally)\n\n";

    echo "Sample commands:\n";
    echo "- phpunit tests/Unit/Core/ConfigTest.php\n";
    echo "- phpunit tests/Unit/Core/DatabaseTest.php\n";
    echo "- phpunit --coverage-text\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);