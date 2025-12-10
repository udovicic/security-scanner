<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use SecurityScanner\Core\Container;
use SecurityScanner\Core\Database;
use SecurityScanner\Core\Config;

/**
 * Base test case class providing common functionality for all tests
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;
    protected Database $database;
    protected Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = Container::getInstance();
        $this->database = Database::getInstance();
        $this->config = Config::getInstance();

        // Ensure we're in test environment
        $this->config->set('app.environment', 'testing');

        // Clean up any previous test data
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanDatabase();

        parent::tearDown();
    }

    /**
     * Clean the database for fresh test state
     */
    protected function cleanDatabase(): void
    {
        try {
            // Disable foreign key checks
            $this->database->execute('SET FOREIGN_KEY_CHECKS = 0');

            // Clean tables but preserve persistent test data (website IDs 1, 2, 999999)
            try {
                // Clean tables without foreign key dependencies first
                $this->database->execute("DELETE FROM backup_log WHERE id > 0");
                $this->database->execute("DELETE FROM notifications WHERE website_id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM notification_preferences WHERE website_id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM alert_escalations WHERE website_id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM scan_metrics WHERE website_id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM scan_results WHERE website_id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM database_backups WHERE id > 0");

                // Clean job queue (not tied to websites)
                $this->database->execute("DELETE FROM job_queue WHERE id > 0");
                $this->database->execute("DELETE FROM queue_log WHERE id > 0");

                // Clean test executions and results
                $this->database->execute("DELETE FROM test_results WHERE execution_id NOT IN (SELECT id FROM test_executions WHERE website_id IN (1, 2, 999999))");
                $this->database->execute("DELETE FROM test_executions WHERE website_id NOT IN (1, 2, 999999)");

                // Clean websites (but keep persistent ones)
                $this->database->execute("DELETE FROM websites WHERE id NOT IN (1, 2, 999999)");
                $this->database->execute("DELETE FROM website_test_config WHERE website_id NOT IN (1, 2, 999999)");
            } catch (\Exception $e) {
                // Log error but continue
                error_log("Warning: Could not clean some tables: " . $e->getMessage());
            }

            // Re-enable foreign key checks
            $this->database->execute('SET FOREIGN_KEY_CHECKS = 1');

        } catch (\Exception $e) {
            // Log error but don't fail test setup
            error_log('Failed to clean test database: ' . $e->getMessage());
        }
    }

    /**
     * Create a test website with default or custom attributes
     */
    protected function createTestWebsite(array $attributes = []): array
    {
        return createTestWebsite($attributes);
    }

    /**
     * Create a test execution with default or custom attributes
     */
    protected function createTestExecution(array $attributes = []): array
    {
        return createTestExecution($attributes);
    }

    /**
     * Create a test result with default or custom attributes
     */
    protected function createTestResult(array $attributes = []): array
    {
        return createTestResult($attributes);
    }

    /**
     * Assert that a database table contains specific data
     */
    protected function assertDatabaseHas(string $table, array $data): void
    {
        $conditions = [];
        $params = [];

        foreach ($data as $key => $value) {
            $conditions[] = "`{$key}` = ?";
            $params[] = $value;
        }

        $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE " . implode(' AND ', $conditions);
        $result = $this->database->fetchRow($sql, $params);

        $this->assertGreaterThan(0, $result['count'], "Failed asserting that table [{$table}] contains matching record.");
    }

    /**
     * Assert that a database table does not contain specific data
     */
    protected function assertDatabaseMissing(string $table, array $data): void
    {
        $conditions = [];
        $params = [];

        foreach ($data as $key => $value) {
            $conditions[] = "`{$key}` = ?";
            $params[] = $value;
        }

        $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE " . implode(' AND ', $conditions);
        $result = $this->database->fetchRow($sql, $params);

        $this->assertEquals(0, $result['count'], "Failed asserting that table [{$table}] does not contain matching record.");
    }

    /**
     * Assert that a database table has a specific number of records
     */
    protected function assertDatabaseCount(string $table, int $expectedCount): void
    {
        $result = $this->database->fetchRow("SELECT COUNT(*) as count FROM `{$table}`");
        $this->assertEquals($expectedCount, $result['count'], "Failed asserting that table [{$table}] has {$expectedCount} records.");
    }

    /**
     * Get the count of records in a table
     */
    protected function getTableCount(string $table): int
    {
        $result = $this->database->fetchRow("SELECT COUNT(*) as count FROM `{$table}`");
        return (int) $result['count'];
    }

    /**
     * Mock a service in the container
     */
    protected function mockService(string $abstract, $mock): void
    {
        $this->container->instance($abstract, $mock);
    }

    /**
     * Create a mock HTTP response for testing
     */
    protected function createMockHttpResponse(int $statusCode = 200, array $headers = [], string $body = ''): array
    {
        return [
            'status_code' => $statusCode,
            'headers' => array_merge([
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Length' => strlen($body)
            ], $headers),
            'body' => $body,
            'response_time' => rand(100, 2000) / 1000 // Random response time between 0.1-2s
        ];
    }

    /**
     * Create test configuration for tests
     */
    protected function createTestConfig(array $overrides = []): array
    {
        return array_merge([
            'timeout' => 30,
            'retries' => 3,
            'enabled' => true,
            'priority' => 'normal'
        ], $overrides);
    }

    /**
     * Assert that an array contains all specified keys
     */
    protected function assertArrayHasKeys(array $expectedKeys, array $array, string $message = ''): void
    {
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array does not contain expected key: {$key}");
        }
    }

    /**
     * Assert that a value is a valid timestamp
     */
    protected function assertIsValidTimestamp($value, string $message = ''): void
    {
        $this->assertIsString($value, $message ?: 'Expected value to be a string timestamp');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value, $message ?: 'Expected value to be a valid timestamp format');
    }

    /**
     * Assert that a URL is valid
     */
    protected function assertIsValidUrl(string $url, string $message = ''): void
    {
        $this->assertNotFalse(filter_var($url, FILTER_VALIDATE_URL), $message ?: "Expected '{$url}' to be a valid URL");
    }

    /**
     * Assert that a JSON string is valid
     */
    protected function assertIsValidJson(string $json, string $message = ''): void
    {
        json_decode($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error(), $message ?: 'Expected valid JSON string');
    }

    /**
     * Assert that execution time is within acceptable range
     */
    protected function assertExecutionTimeWithin(float $executionTime, float $minTime, float $maxTime, string $message = ''): void
    {
        $this->assertGreaterThanOrEqual($minTime, $executionTime, $message ?: "Execution time {$executionTime}s is below minimum {$minTime}s");
        $this->assertLessThanOrEqual($maxTime, $executionTime, $message ?: "Execution time {$executionTime}s exceeds maximum {$maxTime}s");
    }

    /**
     * Skip test if not in CI environment (for slow tests)
     */
    protected function skipIfNotInCI(string $reason = 'Test skipped outside CI environment'): void
    {
        if (!getenv('CI')) {
            $this->markTestSkipped($reason);
        }
    }

    /**
     * Skip test if external dependencies are not available
     */
    protected function skipIfExternalDependenciesUnavailable(): void
    {
        // Check if we can make HTTP requests
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL extension is not available');
        }

        // Check if we can resolve DNS
        if (!gethostbyname('example.com')) {
            $this->markTestSkipped('Cannot resolve DNS - external network may be unavailable');
        }
    }
}