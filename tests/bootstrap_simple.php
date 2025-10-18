<?php

/*
|--------------------------------------------------------------------------
| Simple Test Suite Bootstrap
|--------------------------------------------------------------------------
|
| Simplified bootstrap for PHPUnit testing without complex migrations
|
*/

// Set the root path
define('TEST_ROOT_PATH', dirname(__DIR__));

// Require the main bootstrap file
require_once TEST_ROOT_PATH . '/bootstrap.php';

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Set memory limit for tests
ini_set('memory_limit', '1G');

// Set timezone for consistent test results
date_default_timezone_set('UTC');

// Define SecurityException if not exists
if (!class_exists('SecurityException')) {
    class SecurityException extends \Exception {}
}

// Initialize test-specific configurations
$config = SecurityScanner\Core\Config::getInstance();

// Override configurations for testing
$config->set('app.environment', 'testing');
$config->set('app.debug', true);
$config->set('database.enable_sql_validation', false); // Disable for testing
$config->set('cache.default', 'array');
$config->set('session.driver', 'array');

// Initialize container for dependency injection in tests
$container = SecurityScanner\Core\Container::getInstance();

// Register test-specific services
$container->registerTestServices();

// Simple test database setup - just verify connection
try {
    $database = SecurityScanner\Core\Database::getInstance();

    // Test basic connection
    $result = $database->fetchRow('SELECT 1 as test');
    if (!$result || $result['test'] != 1) {
        throw new \Exception('Database connection test failed');
    }

    // Create minimal test tables if they don't exist
    $database->execute("
        CREATE TABLE IF NOT EXISTS test_websites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            url VARCHAR(500) NOT NULL,
            status VARCHAR(50) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $database->execute("
        CREATE TABLE IF NOT EXISTS test_executions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            website_id INT NOT NULL,
            test_name VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $database->execute("
        CREATE TABLE IF NOT EXISTS test_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            execution_id INT NOT NULL,
            test_name VARCHAR(255) NOT NULL,
            status VARCHAR(50) NOT NULL,
            message TEXT,
            details JSON,
            execution_time DECIMAL(8,3),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

} catch (Exception $e) {
    error_log('Test database setup warning: ' . $e->getMessage());
    // Continue anyway for now
}

// Helper function to clean up test data
function cleanupTestData(): void
{
    try {
        $database = SecurityScanner\Core\Database::getInstance();

        $testTables = ['test_websites', 'test_executions', 'test_results'];

        foreach ($testTables as $table) {
            try {
                $database->execute("TRUNCATE TABLE `{$table}`");
            } catch (Exception $e) {
                // Table might not exist, ignore
            }
        }

    } catch (Exception $e) {
        error_log('Failed to cleanup test data: ' . $e->getMessage());
    }
}

// Make helper functions available globally for tests
if (!function_exists('createTestWebsite')) {
    function createTestWebsite(array $attributes = []): array
    {
        $defaults = [
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);

        try {
            $database = SecurityScanner\Core\Database::getInstance();
            $id = $database->insert('test_websites', $data);
            return array_merge($data, ['id' => $id]);
        } catch (Exception $e) {
            // Return mock data for testing
            return array_merge($data, ['id' => 1]);
        }
    }
}

if (!function_exists('createTestExecution')) {
    function createTestExecution(array $attributes = []): array
    {
        $defaults = [
            'website_id' => 1,
            'test_name' => 'ssl_certificate_test',
            'status' => 'pending',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);

        // Convert boolean values to integers for database compatibility
        if (isset($data['success']) && is_bool($data['success'])) {
            $data['success'] = $data['success'] ? 1 : 0;
        }

        try {
            $database = SecurityScanner\Core\Database::getInstance();
            $id = $database->insert('test_executions', $data);
            return array_merge($data, ['id' => $id]);
        } catch (Exception $e) {
            // Return mock data for testing
            return array_merge($data, ['id' => 1]);
        }
    }
}

if (!function_exists('createTestResult')) {
    function createTestResult(array $attributes = []): array
    {
        $defaults = [
            'execution_id' => 1,
            'test_name' => 'ssl_certificate_test',
            'status' => 'passed',
            'message' => 'Test passed successfully',
            'details' => json_encode(['score' => 100]),
            'execution_time' => 1.5,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);

        try {
            $database = SecurityScanner\Core\Database::getInstance();
            $id = $database->insert('test_results', $data);
            return array_merge($data, ['id' => $id]);
        } catch (Exception $e) {
            // Return mock data for testing
            return array_merge($data, ['id' => 1]);
        }
    }
}

// Register shutdown function to cleanup
register_shutdown_function('cleanupTestData');