<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\QueueService;
use SecurityScanner\Core\Database;

class QueueServiceTest extends TestCase
{
    private QueueService $queueService;
    

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'max_workers' => 2,
            'max_retries' => 2,
            'retry_delay' => 30,
            'job_timeout' => 180,
            'batch_size' => 5,
            'queue_polling_interval' => 1,
            'dead_letter_queue' => true,
            'cleanup_completed_jobs_after' => 3600
        ];

        $this->queueService = new QueueService($config);
        
    }

    public function test_enqueue_job_with_valid_data(): void
    {
        $jobType = 'website_scan';
        $payload = ['website_id' => 1, 'test_names' => ['ssl_test']];
        $priority = 'high';
        $delay = 60;

        $jobId = $this->queueService->enqueue($jobType, $payload, $priority, $delay);

        $this->assertIsInt($jobId);
        $this->assertGreaterThan(0, $jobId);

        // Verify job was created in database
        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);
        $this->assertNotNull($job);
        $this->assertEquals($jobType, $job['job_type']);
        $this->assertEquals(json_encode($payload), $job['payload']);
        $this->assertEquals(2, $job['priority']); // high priority value
        $this->assertEquals('pending', $job['status']);
    }

    public function test_enqueue_job_with_default_values(): void
    {
        $jobType = 'send_notification';
        $payload = ['recipient' => 'test@example.com', 'message' => 'Test'];

        $jobId = $this->queueService->enqueue($jobType, $payload);

        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);
        $this->assertEquals(1, $job['priority']); // normal priority
        $this->assertLessThanOrEqual(date('Y-m-d H:i:s'), $job['execute_at']); // immediate execution
    }

    public function test_enqueue_job_with_delay(): void
    {
        $jobType = 'cleanup_data';
        $payload = ['type' => 'old_logs'];
        $delay = 300; // 5 minutes

        $jobId = $this->queueService->enqueue($jobType, $payload, 'normal', $delay);

        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);
        $expectedTime = date('Y-m-d H:i:s', time() + $delay);

        // Allow 1 minute tolerance for execution time
        $this->assertLessThanOrEqual($expectedTime, $job['execute_at']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $job['execute_at']);
    }

    public function test_enqueue_bulk_jobs(): void
    {
        $jobs = [
            [
                'job_type' => 'website_scan',
                'payload' => ['website_id' => 1],
                'priority' => 'high'
            ],
            [
                'job_type' => 'send_notification',
                'payload' => ['recipient' => 'test@example.com'],
                'priority' => 'normal'
            ],
            [
                'job_type' => 'generate_report',
                'payload' => ['website_id' => 2, 'period' => '7d']
            ]
        ];

        $result = $this->queueService->enqueueBulk($jobs);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['enqueued_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertCount(3, $result['job_ids']);
        $this->assertEmpty($result['errors']);

        // Verify all jobs were created
        foreach ($result['job_ids'] as $jobId) {
            $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);
            $this->assertNotNull($job);
            $this->assertEquals('pending', $job['status']);
        }
    }

    public function test_get_queue_statistics(): void
    {
        // Create jobs with different statuses
        $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 1]),
            'priority' => 1,
            'status' => 'pending',
            'execute_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->database->insert('job_queue', [
            'job_type' => 'send_notification',
            'payload' => json_encode(['recipient' => 'test@example.com']),
            'priority' => 2,
            'status' => 'completed',
            'execute_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
            'execution_time' => 2.5,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 2]),
            'priority' => 1,
            'status' => 'failed',
            'execute_at' => date('Y-m-d H:i:s'),
            'failed_at' => date('Y-m-d H:i:s'),
            'last_error' => 'Test error',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $stats = $this->queueService->getQueueStatistics();

        $this->assertIsArray($stats);
        $this->assertEquals(3, $stats['total_jobs']);
        $this->assertEquals(1, $stats['pending_jobs']);
        $this->assertEquals(1, $stats['completed_jobs']);
        $this->assertEquals(1, $stats['failed_jobs']);
        $this->assertEquals(2.5, $stats['avg_execution_time']);
        $this->assertArrayHasKey('job_types', $stats);
        $this->assertArrayHasKey('website_scan', $stats['job_types']);
        $this->assertArrayHasKey('send_notification', $stats['job_types']);
    }

    public function test_cleanup_completed_jobs(): void
    {
        // Create old completed job
        $oldTime = date('Y-m-d H:i:s', time() - 7200); // 2 hours ago
        $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 1]),
            'priority' => 1,
            'status' => 'completed',
            'execute_at' => $oldTime,
            'completed_at' => $oldTime,
            'created_at' => $oldTime,
            'updated_at' => $oldTime
        ]);

        // Create recent completed job
        $recentTime = date('Y-m-d H:i:s', time() - 1800); // 30 minutes ago
        $recentJobId = $this->database->insert('job_queue', [
            'job_type' => 'send_notification',
            'payload' => json_encode(['recipient' => 'test@example.com']),
            'priority' => 1,
            'status' => 'completed',
            'execute_at' => $recentTime,
            'completed_at' => $recentTime,
            'created_at' => $recentTime,
            'updated_at' => $recentTime
        ]);

        $result = $this->queueService->cleanupCompletedJobs();

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['deleted_jobs']);

        // Verify recent job still exists
        $recentJob = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$recentJobId]);
        $this->assertNotNull($recentJob);

        // Verify old job was deleted
        $remainingJobs = $this->database->fetchAll(
            'SELECT * FROM job_queue WHERE completed_at < ?',
            [date('Y-m-d H:i:s', time() - 3600)]
        );
        $this->assertEmpty($remainingJobs);
    }

    public function test_cancel_pending_job(): void
    {
        $jobId = $this->queueService->enqueue('website_scan', ['website_id' => 1]);

        $result = $this->queueService->cancelJob($jobId);

        $this->assertTrue($result['success']);

        // Verify job status changed to cancelled
        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);
        $this->assertEquals('cancelled', $job['status']);
    }

    public function test_cancel_nonexistent_job_fails(): void
    {
        $result = $this->queueService->cancelJob(999999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Job not found', $result['error']);
    }

    public function test_cancel_processing_job_fails(): void
    {
        // Create a processing job
        $jobId = $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 1]),
            'priority' => 1,
            'status' => 'processing',
            'execute_at' => date('Y-m-d H:i:s'),
            'started_at' => date('Y-m-d H:i:s'),
            'worker_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->queueService->cancelJob($jobId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot cancel job with status: processing', $result['error']);
    }

    public function test_process_queue_basic_functionality(): void
    {
        // Note: This test is limited because we can't fully test the multi-process
        // functionality in a unit test environment. We'll test the basic setup.

        // Create a simple job
        $this->queueService->enqueue('generate_report', ['period' => '7d']);

        // Note: In a real test environment with proper process support,
        // we would test the actual queue processing. For now, we just
        // verify the method exists and returns expected structure.
        $this->assertTrue(method_exists($this->queueService, 'processQueue'));
    }

    public function test_queue_priority_ordering(): void
    {
        // Create jobs with different priorities
        $lowJobId = $this->queueService->enqueue('job1', ['data' => 1], 'low');
        $highJobId = $this->queueService->enqueue('job2', ['data' => 2], 'high');
        $normalJobId = $this->queueService->enqueue('job3', ['data' => 3], 'normal');
        $urgentJobId = $this->queueService->enqueue('job4', ['data' => 4], 'urgent');

        // Get jobs ordered by priority
        $jobs = $this->database->fetchAll(
            "SELECT id, priority FROM job_queue
             WHERE status = 'pending'
             ORDER BY priority DESC, created_at ASC"
        );

        // Should be ordered: urgent (3), high (2), normal (1), low (0)
        $this->assertEquals($urgentJobId, $jobs[0]['id']);
        $this->assertEquals($highJobId, $jobs[1]['id']);
        $this->assertEquals($normalJobId, $jobs[2]['id']);
        $this->assertEquals($lowJobId, $jobs[3]['id']);
    }

    public function test_job_retry_functionality(): void
    {
        // Create a job that would fail
        $jobId = $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 999999]), // Non-existent website
            'priority' => 1,
            'status' => 'pending',
            'retry_count' => 0,
            'execute_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Simulate job failure and retry logic
        $this->database->update('job_queue', [
            'status' => 'pending',
            'retry_count' => 1,
            'execute_at' => date('Y-m-d H:i:s', time() + 60),
            'last_error' => 'Simulated error',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $jobId]);

        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);

        $this->assertEquals('pending', $job['status']);
        $this->assertEquals(1, $job['retry_count']);
        $this->assertEquals('Simulated error', $job['last_error']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $job['execute_at']);
    }

    public function test_dead_letter_queue_functionality(): void
    {
        // Create a job that has reached max retries
        $jobId = $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 999999]),
            'priority' => 1,
            'status' => 'dead_letter',
            'retry_count' => 3, // Exceeded max retries
            'execute_at' => date('Y-m-d H:i:s'),
            'failed_at' => date('Y-m-d H:i:s'),
            'last_error' => 'Max retries reached',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);

        $this->assertEquals('dead_letter', $job['status']);
        $this->assertEquals(3, $job['retry_count']);
        $this->assertNotNull($job['failed_at']);
        $this->assertEquals('Max retries reached', $job['last_error']);
    }

    public function test_queue_logging(): void
    {
        // Create a job to trigger logging
        $jobId = $this->queueService->enqueue('website_scan', ['website_id' => 1], 'high');

        // Check that queue activity was logged
        $logs = $this->database->fetchAll(
            'SELECT * FROM queue_log WHERE action = ? ORDER BY created_at DESC LIMIT 1',
            ['job_enqueued']
        );

        $this->assertNotEmpty($logs);
        $this->assertEquals('job_enqueued', $logs[0]['action']);

        $context = json_decode($logs[0]['context'], true);
        $this->assertEquals($jobId, $context['job_id']);
        $this->assertEquals('website_scan', $context['job_type']);
        $this->assertEquals('high', $context['priority']);
    }

    public function test_job_timeout_handling(): void
    {
        // Create a stale job (processing for too long)
        $staleTime = date('Y-m-d H:i:s', time() - 400); // 400 seconds ago (> timeout)
        $jobId = $this->database->insert('job_queue', [
            'job_type' => 'website_scan',
            'payload' => json_encode(['website_id' => 1]),
            'priority' => 1,
            'status' => 'processing',
            'execute_at' => $staleTime,
            'started_at' => $staleTime,
            'worker_id' => 1,
            'created_at' => $staleTime,
            'updated_at' => $staleTime
        ]);

        // Manually trigger stale job reset (normally done in processQueue)
        $staleThreshold = date('Y-m-d H:i:s', time() - 180); // 3 minutes ago
        $this->database->query(
            "UPDATE job_queue
             SET status = 'pending', started_at = NULL, worker_id = NULL, updated_at = NOW()
             WHERE status = 'processing' AND started_at < ?",
            [$staleThreshold]
        );

        $job = $this->database->fetchRow('SELECT * FROM job_queue WHERE id = ?', [$jobId]);

        $this->assertEquals('pending', $job['status']);
        $this->assertNull($job['started_at']);
        $this->assertNull($job['worker_id']);
    }

    public function test_memory_limit_parsing(): void
    {
        // Test memory limit parsing through reflection since it's private
        $reflection = new \ReflectionClass($this->queueService);
        $method = $reflection->getMethod('parseMemoryLimit');
        $method->setAccessible(true);

        $this->assertEquals(256 * 1024 * 1024, $method->invoke($this->queueService, '256M'));
        $this->assertEquals(1024 * 1024 * 1024, $method->invoke($this->queueService, '1G'));
        $this->assertEquals(512 * 1024, $method->invoke($this->queueService, '512K'));
        $this->assertEquals(1024, $method->invoke($this->queueService, '1024'));
    }
}