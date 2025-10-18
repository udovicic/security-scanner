<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\SchedulerService;
use SecurityScanner\Core\Database;

class SchedulerServiceTest extends TestCase
{
    private SchedulerService $schedulerService;
    

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedulerService = new SchedulerService();
        
    }

    public function test_get_websites_due_for_scan(): void
    {
        // Create websites with different next_scan_at times
        $pastTime = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
        $futureTime = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now

        $dueSite = $this->createTestWebsite([
            'name' => 'Due Website',
            'next_scan_at' => $pastTime,
            'status' => 'active'
        ]);

        $notDueSite = $this->createTestWebsite([
            'name' => 'Not Due Website',
            'next_scan_at' => $futureTime,
            'status' => 'active'
        ]);

        $inactiveSite = $this->createTestWebsite([
            'name' => 'Inactive Website',
            'next_scan_at' => $pastTime,
            'status' => 'inactive'
        ]);

        $dueWebsites = $this->schedulerService->getWebsitesDueForScan();

        $this->assertIsArray($dueWebsites);
        $this->assertCount(1, $dueWebsites);
        $this->assertEquals('Due Website', $dueWebsites[0]['name']);
    }

    public function test_schedule_scan_for_website(): void
    {
        $website = $this->createTestWebsite();

        $result = $this->schedulerService->scheduleScanForWebsite($website['id']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('execution_id', $result);

        // Verify execution was created
        $execution = $this->database->fetchRow(
            'SELECT * FROM test_executions WHERE id = ?',
            [$result['execution_id']]
        );

        $this->assertNotNull($execution);
        $this->assertEquals($website['id'], $execution['website_id']);
        $this->assertEquals('scheduled', $execution['status']);
    }

    public function test_schedule_scan_for_nonexistent_website_fails(): void
    {
        $result = $this->schedulerService->scheduleScanForWebsite(999999);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Website not found', $result['error']);
    }

    public function test_execute_scheduled_scans(): void
    {
        $website = $this->createTestWebsite();

        // Create a scheduled execution
        $executionId = $this->createTestExecution([
            'website_id' => $website['id'],
            'status' => 'scheduled',
            'test_name' => 'ssl_certificate_test'
        ])['id'];

        $result = $this->schedulerService->executeScheduledScans();

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['executions_processed']);

        // Verify execution status changed
        $execution = $this->database->fetchRow(
            'SELECT * FROM test_executions WHERE id = ?',
            [$executionId]
        );

        $this->assertNotEquals('scheduled', $execution['status']);
    }

    public function test_update_next_scan_time(): void
    {
        $website = $this->createTestWebsite([
            'scan_frequency' => 'daily'
        ]);

        $result = $this->schedulerService->updateNextScanTime($website['id']);

        $this->assertTrue($result['success']);

        // Verify next_scan_at was updated
        $updated = $this->database->fetchRow(
            'SELECT next_scan_at FROM test_websites WHERE id = ?',
            [$website['id']]
        );

        $nextScanTime = strtotime($updated['next_scan_at']);
        $expectedTime = time() + 86400; // 24 hours from now

        $this->assertGreaterThan(time(), $nextScanTime);
        $this->assertLessThanOrEqual($expectedTime + 60, $nextScanTime); // Allow 1 minute tolerance
    }

    public function test_get_scheduler_statistics(): void
    {
        // Create test data
        $this->createTestExecution(['status' => 'completed']);
        $this->createTestExecution(['status' => 'failed']);
        $this->createTestExecution(['status' => 'running']);

        $stats = $this->schedulerService->getSchedulerStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys([
            'total_executions',
            'executions_by_status',
            'success_rate',
            'average_execution_time'
        ], $stats);

        $this->assertEquals(3, $stats['total_executions']);
        $this->assertArrayHasKey('completed', $stats['executions_by_status']);
        $this->assertArrayHasKey('failed', $stats['executions_by_status']);
        $this->assertArrayHasKey('running', $stats['executions_by_status']);
    }

    public function test_get_running_executions(): void
    {
        $website = $this->createTestWebsite();

        // Create running and completed executions
        $this->createTestExecution([
            'website_id' => $website['id'],
            'status' => 'running',
            'test_name' => 'ssl_test'
        ]);
        $this->createTestExecution([
            'website_id' => $website['id'],
            'status' => 'completed',
            'test_name' => 'headers_test'
        ]);

        $runningExecutions = $this->schedulerService->getRunningExecutions();

        $this->assertIsArray($runningExecutions);
        $this->assertCount(1, $runningExecutions);
        $this->assertEquals('running', $runningExecutions[0]['status']);
        $this->assertEquals('ssl_test', $runningExecutions[0]['test_name']);
    }

    public function test_check_for_stuck_executions(): void
    {
        // Create a stuck execution (running for more than timeout)
        $stuckTime = date('Y-m-d H:i:s', time() - 7200); // 2 hours ago
        $stuckExecution = $this->createTestExecution([
            'status' => 'running',
            'started_at' => $stuckTime
        ]);

        $result = $this->schedulerService->checkForStuckExecutions();

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['stuck_executions_found']);

        // Verify stuck execution was marked as failed
        $updated = $this->database->fetchRow(
            'SELECT status FROM test_executions WHERE id = ?',
            [$stuckExecution['id']]
        );

        $this->assertEquals('failed', $updated['status']);
    }

    public function test_cleanup_old_executions(): void
    {
        // Create old executions
        $oldTime = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60)); // 35 days ago
        $this->createTestExecution([
            'status' => 'completed',
            'created_at' => $oldTime
        ]);

        // Create recent execution
        $this->createTestExecution([
            'status' => 'completed',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->schedulerService->cleanupOldExecutions(30);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['deleted_count']);

        // Verify only recent execution remains
        $remaining = $this->database->fetchAll('SELECT * FROM test_executions');
        $this->assertCount(1, $remaining);
    }

    public function test_pause_scheduler(): void
    {
        $result = $this->schedulerService->pauseScheduler();

        $this->assertTrue($result['success']);
        $this->assertTrue($this->schedulerService->isSchedulerPaused());
    }

    public function test_resume_scheduler(): void
    {
        // First pause the scheduler
        $this->schedulerService->pauseScheduler();

        $result = $this->schedulerService->resumeScheduler();

        $this->assertTrue($result['success']);
        $this->assertFalse($this->schedulerService->isSchedulerPaused());
    }

    public function test_get_scheduler_status(): void
    {
        $status = $this->schedulerService->getSchedulerStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKeys([
            'is_running',
            'is_paused',
            'last_run_at',
            'next_run_at',
            'pending_executions'
        ], $status);

        $this->assertIsBool($status['is_running']);
        $this->assertIsBool($status['is_paused']);
    }

    public function test_schedule_bulk_scans(): void
    {
        $website1 = $this->createTestWebsite(['name' => 'Site 1']);
        $website2 = $this->createTestWebsite(['name' => 'Site 2']);
        $website3 = $this->createTestWebsite(['name' => 'Site 3']);

        $websiteIds = [$website1['id'], $website2['id'], $website3['id']];

        $result = $this->schedulerService->scheduleBulkScans($websiteIds);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['scheduled_count']);
        $this->assertCount(3, $result['execution_ids']);

        // Verify all executions were created
        foreach ($result['execution_ids'] as $executionId) {
            $execution = $this->database->fetchRow(
                'SELECT * FROM test_executions WHERE id = ?',
                [$executionId]
            );
            $this->assertNotNull($execution);
            $this->assertEquals('scheduled', $execution['status']);
        }
    }

    public function test_cancel_scheduled_execution(): void
    {
        $execution = $this->createTestExecution([
            'status' => 'scheduled'
        ]);

        $result = $this->schedulerService->cancelScheduledExecution($execution['id']);

        $this->assertTrue($result['success']);

        // Verify execution was cancelled
        $updated = $this->database->fetchRow(
            'SELECT status FROM test_executions WHERE id = ?',
            [$execution['id']]
        );

        $this->assertEquals('cancelled', $updated['status']);
    }

    public function test_get_execution_queue(): void
    {
        // Create executions with different priorities and statuses
        $this->createTestExecution([
            'status' => 'scheduled',
            'priority' => 'high',
            'test_name' => 'priority_test'
        ]);
        $this->createTestExecution([
            'status' => 'scheduled',
            'priority' => 'normal',
            'test_name' => 'normal_test'
        ]);
        $this->createTestExecution([
            'status' => 'running',
            'priority' => 'high',
            'test_name' => 'running_test'
        ]);

        $queue = $this->schedulerService->getExecutionQueue();

        $this->assertIsArray($queue);
        $this->assertCount(2, $queue); // Only scheduled executions

        // Verify high priority comes first
        $this->assertEquals('priority_test', $queue[0]['test_name']);
        $this->assertEquals('normal_test', $queue[1]['test_name']);
    }

    public function test_get_scheduler_performance_metrics(): void
    {
        // Create test executions with timing data
        $this->createTestExecution([
            'status' => 'completed',
            'started_at' => '2023-01-01 10:00:00',
            'completed_at' => '2023-01-01 10:02:00' // 2 minutes
        ]);
        $this->createTestExecution([
            'status' => 'completed',
            'started_at' => '2023-01-01 11:00:00',
            'completed_at' => '2023-01-01 11:01:00' // 1 minute
        ]);

        $metrics = $this->schedulerService->getPerformanceMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'average_execution_time',
            'total_executions',
            'executions_per_hour',
            'success_rate'
        ], $metrics);

        $this->assertIsNumeric($metrics['average_execution_time']);
        $this->assertEquals(2, $metrics['total_executions']);
    }

    public function test_optimize_scan_schedule(): void
    {
        // Create websites with different scan frequencies
        $website1 = $this->createTestWebsite(['scan_frequency' => 'hourly']);
        $website2 = $this->createTestWebsite(['scan_frequency' => 'daily']);

        $result = $this->schedulerService->optimizeScanSchedule();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('optimizations_applied', $result);
        $this->assertIsArray($result['optimizations_applied']);
    }

    public function test_get_scheduler_health(): void
    {
        $health = $this->schedulerService->getSchedulerHealth();

        $this->assertIsArray($health);
        $this->assertArrayHasKeys([
            'status',
            'uptime',
            'memory_usage',
            'cpu_usage',
            'disk_space',
            'database_connection'
        ], $health);

        $this->assertContains($health['status'], ['healthy', 'warning', 'critical']);
        $this->assertIsBool($health['database_connection']);
    }

    public function test_set_scheduler_configuration(): void
    {
        $config = [
            'max_concurrent_executions' => 5,
            'execution_timeout' => 300,
            'retry_failed_executions' => true,
            'cleanup_interval' => 24
        ];

        $result = $this->schedulerService->setSchedulerConfiguration($config);

        $this->assertTrue($result['success']);

        // Verify configuration was saved
        $savedConfig = $this->schedulerService->getSchedulerConfiguration();
        $this->assertEquals(5, $savedConfig['max_concurrent_executions']);
        $this->assertEquals(300, $savedConfig['execution_timeout']);
        $this->assertTrue($savedConfig['retry_failed_executions']);
    }

    public function test_estimate_scan_completion_time(): void
    {
        $website1 = $this->createTestWebsite();
        $website2 = $this->createTestWebsite();

        $websiteIds = [$website1['id'], $website2['id']];

        $estimate = $this->schedulerService->estimateScanCompletionTime($websiteIds);

        $this->assertIsArray($estimate);
        $this->assertArrayHasKeys([
            'estimated_duration_seconds',
            'estimated_completion_time',
            'total_tests'
        ], $estimate);

        $this->assertIsNumeric($estimate['estimated_duration_seconds']);
        $this->assertIsValidTimestamp($estimate['estimated_completion_time']);
    }
}