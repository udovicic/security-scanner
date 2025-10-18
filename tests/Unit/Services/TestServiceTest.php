<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\TestService;
use SecurityScanner\Core\Database;
use SecurityScanner\Tests\TestExecutionEngine;
use SecurityScanner\Tests\TestRegistry;

class TestServiceTest extends TestCase
{
    private TestService $testService;
    

    protected function setUp(): void
    {
        parent::setUp();
        $this->testService = new TestService();
        
    }

    public function test_configure_website_tests_with_valid_data(): void
    {
        $website = $this->createTestWebsite();

        $testConfigurations = [
            'ssl_certificate_test' => [
                'enabled' => true,
                'configuration' => ['timeout' => 30],
                'invert_result' => false,
                'timeout' => 60,
                'retry_count' => 3
            ],
            'security_headers_test' => [
                'enabled' => true,
                'configuration' => ['check_hsts' => true],
                'invert_result' => false,
                'timeout' => 30,
                'retry_count' => 2
            ]
        ];

        $result = $this->testService->configureWebsiteTests($website['id'], $testConfigurations);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['summary']['total']);
        $this->assertEquals(2, $result['summary']['successful']);
        $this->assertEquals(0, $result['summary']['failed']);
        $this->assertArrayHasKey('results', $result);
    }

    public function test_configure_website_tests_fails_for_nonexistent_website(): void
    {
        $testConfigurations = [
            'ssl_certificate_test' => [
                'enabled' => true,
                'configuration' => [],
                'timeout' => 30
            ]
        ];

        $result = $this->testService->configureWebsiteTests(999999, $testConfigurations);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('website', $result['errors']);
    }

    public function test_configure_website_test_with_valid_data(): void
    {
        $website = $this->createTestWebsite();

        $config = [
            'enabled' => true,
            'configuration' => ['timeout' => 30, 'verify_chain' => true],
            'invert_result' => false,
            'timeout' => 60,
            'retry_count' => 3
        ];

        $result = $this->testService->configureWebsiteTest($website['id'], 'ssl_certificate_test', $config);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('test_config_id', $result);
    }

    public function test_configure_website_test_fails_for_invalid_test(): void
    {
        $website = $this->createTestWebsite();

        $config = [
            'enabled' => true,
            'configuration' => [],
            'timeout' => 30
        ];

        $result = $this->testService->configureWebsiteTest($website['id'], 'nonexistent_test', $config);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('test', $result['errors']);
    }

    public function test_configure_website_test_validates_configuration(): void
    {
        $website = $this->createTestWebsite();

        $config = [
            'enabled' => 'invalid_boolean',
            'timeout' => -1, // Invalid negative timeout
            'retry_count' => 10 // Exceeds max retry count
        ];

        $result = $this->testService->configureWebsiteTest($website['id'], 'ssl_certificate_test', $config);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_execute_test_for_website(): void
    {
        $website = $this->createTestWebsite(['url' => 'https://example.com']);

        // Configure the test first
        $this->testService->configureWebsiteTest($website['id'], 'ssl_certificate_test', [
            'enabled' => true,
            'configuration' => ['timeout' => 30],
            'timeout' => 60
        ]);

        $result = $this->testService->executeTestForWebsite($website['id'], 'ssl_certificate_test');

        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['success', 'execution_id'], $result);
    }

    public function test_execute_all_tests_for_website(): void
    {
        $website = $this->createTestWebsite(['url' => 'https://example.com']);

        // Configure multiple tests
        $this->testService->configureWebsiteTest($website['id'], 'ssl_certificate_test', [
            'enabled' => true,
            'configuration' => ['timeout' => 30]
        ]);
        $this->testService->configureWebsiteTest($website['id'], 'security_headers_test', [
            'enabled' => true,
            'configuration' => ['check_hsts' => true]
        ]);

        $result = $this->testService->executeAllTestsForWebsite($website['id']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('execution_id', $result);
        $this->assertArrayHasKey('tests_executed', $result);
        $this->assertGreaterThan(0, $result['tests_executed']);
    }

    public function test_get_test_configurations_for_website(): void
    {
        $website = $this->createTestWebsite();

        // Configure some tests
        $this->testService->configureWebsiteTest($website['id'], 'ssl_certificate_test', [
            'enabled' => true,
            'configuration' => ['timeout' => 30]
        ]);

        $configurations = $this->testService->getTestConfigurationsForWebsite($website['id']);

        $this->assertIsArray($configurations);
        $this->assertGreaterThan(0, count($configurations));
        $this->assertArrayHasKeys(['test_name', 'enabled', 'configuration'], $configurations[0]);
    }

    public function test_get_available_tests(): void
    {
        $availableTests = $this->testService->getAvailableTests();

        $this->assertIsArray($availableTests);
        $this->assertGreaterThan(0, count($availableTests));

        foreach ($availableTests as $test) {
            $this->assertArrayHasKeys(['name', 'display_name', 'description', 'category'], $test);
        }
    }

    public function test_get_test_execution_history(): void
    {
        $website = $this->createTestWebsite();

        // Create some test executions
        $this->createTestExecution([
            'website_id' => $website['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'completed'
        ]);
        $this->createTestExecution([
            'website_id' => $website['id'],
            'test_name' => 'security_headers_test',
            'status' => 'completed'
        ]);

        $history = $this->testService->getTestExecutionHistory($website['id']);

        $this->assertIsArray($history);
        $this->assertEquals(2, count($history));
        $this->assertArrayHasKeys(['id', 'test_name', 'status', 'started_at'], $history[0]);
    }

    public function test_get_test_execution_by_id(): void
    {
        $execution = $this->createTestExecution([
            'test_name' => 'ssl_certificate_test',
            'status' => 'completed'
        ]);

        $retrieved = $this->testService->getTestExecutionById($execution['id']);

        $this->assertNotNull($retrieved);
        $this->assertEquals($execution['id'], $retrieved['id']);
        $this->assertEquals('ssl_certificate_test', $retrieved['test_name']);
    }

    public function test_get_test_results_by_execution(): void
    {
        $execution = $this->createTestExecution();

        // Create some test results
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'passed'
        ]);

        $results = $this->testService->getTestResultsByExecution($execution['id']);

        $this->assertIsArray($results);
        $this->assertEquals(1, count($results));
        $this->assertEquals('ssl_certificate_test', $results[0]['test_name']);
        $this->assertEquals('passed', $results[0]['status']);
    }

    public function test_cancel_test_execution(): void
    {
        $execution = $this->createTestExecution([
            'status' => 'running'
        ]);

        $result = $this->testService->cancelTestExecution($execution['id']);

        $this->assertTrue($result['success']);

        // Verify execution status changed
        $updated = $this->testService->getTestExecutionById($execution['id']);
        $this->assertEquals('cancelled', $updated['status']);
    }

    public function test_retry_failed_test(): void
    {
        $execution = $this->createTestExecution([
            'status' => 'failed'
        ]);

        $result = $this->testService->retryFailedTest($execution['id']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('new_execution_id', $result);
    }

    public function test_get_test_statistics(): void
    {
        $website = $this->createTestWebsite();

        // Create test executions with different statuses
        $this->createTestExecution(['website_id' => $website['id'], 'status' => 'completed']);
        $this->createTestExecution(['website_id' => $website['id'], 'status' => 'failed']);
        $this->createTestExecution(['website_id' => $website['id'], 'status' => 'completed']);

        $stats = $this->testService->getTestStatistics($website['id']);

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys(['total_executions', 'success_rate', 'average_execution_time'], $stats);
        $this->assertEquals(3, $stats['total_executions']);
        $this->assertIsNumeric($stats['success_rate']);
    }

    public function test_bulk_enable_tests(): void
    {
        $website = $this->createTestWebsite();

        $testNames = ['ssl_certificate_test', 'security_headers_test', 'response_time_test'];

        $result = $this->testService->bulkEnableTests($website['id'], $testNames);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['enabled_count']);

        // Verify tests are enabled
        $configurations = $this->testService->getTestConfigurationsForWebsite($website['id']);
        $enabledTests = array_filter($configurations, fn($config) => $config['enabled']);
        $this->assertCount(3, $enabledTests);
    }

    public function test_bulk_disable_tests(): void
    {
        $website = $this->createTestWebsite();

        // First enable some tests
        $this->testService->bulkEnableTests($website['id'], ['ssl_certificate_test', 'security_headers_test']);

        // Then disable them
        $result = $this->testService->bulkDisableTests($website['id'], ['ssl_certificate_test', 'security_headers_test']);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['disabled_count']);

        // Verify tests are disabled
        $configurations = $this->testService->getTestConfigurationsForWebsite($website['id']);
        $enabledTests = array_filter($configurations, fn($config) => $config['enabled']);
        $this->assertCount(0, $enabledTests);
    }

    public function test_validate_test_configuration(): void
    {
        $validConfig = [
            'timeout' => 30,
            'verify_chain' => true,
            'check_revocation' => false
        ];

        $schema = [
            'timeout' => 'integer|min:1|max:300',
            'verify_chain' => 'boolean',
            'check_revocation' => 'boolean'
        ];

        $result = $this->testService->validateTestConfiguration($validConfig, $schema);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_test_configuration_with_invalid_data(): void
    {
        $invalidConfig = [
            'timeout' => -1, // Invalid
            'verify_chain' => 'not_boolean', // Invalid type
            'unknown_field' => 'value' // Unknown field
        ];

        $schema = [
            'timeout' => 'integer|min:1|max:300',
            'verify_chain' => 'boolean'
        ];

        $result = $this->testService->validateTestConfiguration($invalidConfig, $schema);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_get_test_execution_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test executions with various execution times
        $this->createTestExecution([
            'website_id' => $website['id'],
            'started_at' => '2023-01-01 10:00:00',
            'completed_at' => '2023-01-01 10:01:30' // 90 seconds
        ]);
        $this->createTestExecution([
            'website_id' => $website['id'],
            'started_at' => '2023-01-01 11:00:00',
            'completed_at' => '2023-01-01 11:02:00' // 120 seconds
        ]);

        $metrics = $this->testService->getTestExecutionMetrics($website['id']);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys(['average_execution_time', 'total_executions', 'executions_by_status'], $metrics);
        $this->assertIsNumeric($metrics['average_execution_time']);
        $this->assertEquals(2, $metrics['total_executions']);
    }

    public function test_schedule_test_execution(): void
    {
        $website = $this->createTestWebsite();
        $scheduleTime = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now

        $result = $this->testService->scheduleTestExecution($website['id'], 'ssl_certificate_test', $scheduleTime);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('scheduled_execution_id', $result);

        // Verify the execution is scheduled
        $execution = $this->testService->getTestExecutionById($result['scheduled_execution_id']);
        $this->assertEquals('scheduled', $execution['status']);
    }
}