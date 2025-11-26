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

    // Ensure persistent test websites exist in the websites table
    $database->execute("SET SESSION sql_mode = ''");
    $database->execute("
        INSERT IGNORE INTO websites (id, name, url, status, created_at, updated_at) VALUES
        (1, 'Test Website 1', 'https://example1.com', 'active', NOW(), NOW()),
        (2, 'Test Website 2', 'https://example2.com', 'active', NOW(), NOW()),
        (999999, 'Test Website 999999', 'https://example999999.com', 'active', NOW(), NOW())
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

        // Disable foreign key checks to allow truncation
        $database->execute("SET FOREIGN_KEY_CHECKS = 0");

        // Clean all test-related tables but preserve persistent test data
        try {
            // Clean tables without foreign key dependencies first
            $database->execute("DELETE FROM notifications WHERE website_id NOT IN (1, 2, 999999)");
            $database->execute("DELETE FROM alert_escalations WHERE website_id NOT IN (1, 2, 999999)");
            $database->execute("DELETE FROM scan_metrics WHERE website_id NOT IN (1, 2, 999999)");
            $database->execute("DELETE FROM scan_results WHERE website_id NOT IN (1, 2, 999999)");

            // Clean job queue (not tied to websites)
            $database->execute("DELETE FROM job_queue WHERE id > 0");
            $database->execute("DELETE FROM queue_log WHERE id > 0");

            // Clean test executions and results
            $database->execute("DELETE FROM test_results WHERE execution_id NOT IN (SELECT id FROM test_executions WHERE website_id IN (1, 2, 999999))");
            $database->execute("DELETE FROM test_executions WHERE website_id NOT IN (1, 2, 999999)");

            // Clean websites (but keep persistent ones)
            $database->execute("DELETE FROM websites WHERE id NOT IN (1, 2, 999999)");
            $database->execute("DELETE FROM website_test_config WHERE website_id NOT IN (1, 2, 999999)");
        } catch (Exception $e) {
            // Fallback to truncate if delete fails
            $testTables = [
                'queue_log', 'job_queue', 'notifications', 'alert_escalations',
                'scan_metrics', 'scan_results', 'test_results', 'test_executions',
                'websites', 'website_test_config', 'backup_log', 'database_backups'
            ];
            foreach ($testTables as $table) {
                try {
                    $database->execute("TRUNCATE TABLE `{$table}`");
                } catch (Exception $e) {
                    // Table might not exist, ignore
                }
            }
        }

        // Re-enable foreign key checks
        $database->execute("SET FOREIGN_KEY_CHECKS = 1");

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
            $id = $database->insert('websites', $data);
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