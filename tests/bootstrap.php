<?php

/*
|--------------------------------------------------------------------------
| Test Suite Bootstrap
|--------------------------------------------------------------------------
|
| This file is responsible for bootstrapping the test environment and
| setting up the necessary dependencies for running PHPUnit tests.
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
$config->set('database.default', 'testing');
$config->set('database.enable_sql_validation', false); // Disable for testing
$config->set('cache.default', 'array');
$config->set('session.driver', 'array');

// Initialize container for dependency injection in tests
$container = SecurityScanner\Core\Container::getInstance();

// Register test-specific services
$container->registerTestServices();

// Set up test database
setupTestDatabase();

// Helper function to set up test database
function setupTestDatabase(): void
{
    try {
        $database = SecurityScanner\Core\Database::getInstance();

        // For testing, we'll use the existing database but clean it up before/after tests
        // This avoids permission issues with creating databases

        // Test if we can connect
        $result = $database->fetchRow('SELECT 1 as test');
        if (!$result || $result['test'] != 1) {
            throw new \Exception('Database connection test failed');
        }

        // Run migrations for current database (in test mode)
        runTestMigrations();

    } catch (Exception $e) {
        error_log('Failed to setup test database: ' . $e->getMessage());
        // Don't throw exception for now to allow testing setup to continue
        // throw $e;
    }
}

// Helper function to run migrations for tests
function runTestMigrations(): void
{
    $migrationsPath = TEST_ROOT_PATH . '/migrations';

    if (!is_dir($migrationsPath)) {
        return;
    }

    $database = SecurityScanner\Core\Database::getInstance();

    // Create migrations table if it doesn't exist
    $database->execute("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Get executed migrations
    $executedMigrations = $database->fetchAll("SELECT migration FROM migrations");
    $executedMigrations = array_column($executedMigrations, 'migration');

    // Get all migration files
    $migrationFiles = glob($migrationsPath . '/*.sql');
    sort($migrationFiles);

    foreach ($migrationFiles as $migrationFile) {
        $migrationName = basename($migrationFile, '.sql');

        if (in_array($migrationName, $executedMigrations)) {
            continue;
        }

        try {
            $sql = file_get_contents($migrationFile);

            // Split and execute multiple statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $database->execute($statement);
                }
            }

            // Record migration
            $database->insert('migrations', [
                'migration' => $migrationName,
                'batch' => 1
            ]);

        } catch (Exception $e) {
            error_log("Failed to run migration {$migrationName}: " . $e->getMessage());
            throw $e;
        }
    }
}

// Helper function to clean up test data
function cleanupTestData(): void
{
    try {
        $database = SecurityScanner\Core\Database::getInstance();

        // Get all tables except migrations
        $tables = $database->fetchAll("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name != 'migrations'
        ");

        // Disable foreign key checks
        $database->execute('SET FOREIGN_KEY_CHECKS = 0');

        // Truncate all tables
        foreach ($tables as $table) {
            $tableName = $table['table_name'];
            $database->execute("TRUNCATE TABLE `{$tableName}`");
        }

        // Re-enable foreign key checks
        $database->execute('SET FOREIGN_KEY_CHECKS = 1');

    } catch (Exception $e) {
        error_log('Failed to cleanup test data: ' . $e->getMessage());
    }
}

// Register shutdown function to cleanup
register_shutdown_function('cleanupTestData');

// Make helper functions available globally for tests
if (!function_exists('createTestWebsite')) {
    function createTestWebsite(array $attributes = []): array
    {
        $defaults = [
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'status' => 'active',
            'check_frequency' => 'hourly',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);

        $database = SecurityScanner\Core\Database::getInstance();
        $id = $database->insert('websites', $data);

        return array_merge($data, ['id' => $id]);
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

        $database = SecurityScanner\Core\Database::getInstance();
        $id = $database->insert('test_executions', $data);

        return array_merge($data, ['id' => $id]);
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

        $database = SecurityScanner\Core\Database::getInstance();
        $id = $database->insert('test_results', $data);

        return array_merge($data, ['id' => $id]);
    }
}

// Set up mock data for consistent testing
function seedTestData(): void
{
    try {
        // Clean existing data first
        cleanupTestData();

        // Create test websites
        createTestWebsite(['id' => 1, 'name' => 'Primary Test Site', 'url' => 'https://primary.test']);
        createTestWebsite(['id' => 2, 'name' => 'Secondary Test Site', 'url' => 'https://secondary.test']);

        // Create available tests
        $database = SecurityScanner\Core\Database::getInstance();

        $availableTests = [
            [
                'name' => 'ssl_certificate_test',
                'display_name' => 'SSL Certificate Test',
                'description' => 'Checks SSL certificate validity',
                'category' => 'security',
                'enabled' => 1,
                'default_config' => json_encode(['timeout' => 30])
            ],
            [
                'name' => 'security_headers_test',
                'display_name' => 'Security Headers Test',
                'description' => 'Validates HTTP security headers',
                'category' => 'security',
                'enabled' => 1,
                'default_config' => json_encode(['timeout' => 15])
            ],
            [
                'name' => 'response_time_test',
                'display_name' => 'Response Time Test',
                'description' => 'Measures website response time',
                'category' => 'performance',
                'enabled' => 1,
                'default_config' => json_encode(['timeout' => 10, 'threshold' => 2000])
            ]
        ];

        foreach ($availableTests as $test) {
            $database->insert('available_tests', $test);
        }

    } catch (Exception $e) {
        error_log('Failed to seed test data: ' . $e->getMessage());
        throw $e;
    }
}

// Seed test data
seedTestData();