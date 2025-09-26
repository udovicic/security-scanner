<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;

class QueueService
{
    private Database $db;
    private array $config;
    private array $workers = [];

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'max_workers' => 5,
            'max_retries' => 3,
            'retry_delay' => 60, // seconds
            'job_timeout' => 300, // 5 minutes
            'batch_size' => 10,
            'worker_memory_limit' => '256M',
            'queue_polling_interval' => 5, // seconds
            'dead_letter_queue' => true,
            'priority_levels' => ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3],
            'queue_stats_interval' => 60, // seconds
            'cleanup_completed_jobs_after' => 86400 // 24 hours
        ], $config);
    }

    /**
     * Add job to queue
     */
    public function enqueue(string $jobType, array $payload, string $priority = 'normal', int $delay = 0): int
    {
        $priorityValue = $this->config['priority_levels'][$priority] ?? 1;
        $executeAt = $delay > 0 ? date('Y-m-d H:i:s', time() + $delay) : date('Y-m-d H:i:s');

        $jobId = $this->db->insert('job_queue', [
            'job_type' => $jobType,
            'payload' => json_encode($payload),
            'priority' => $priorityValue,
            'status' => 'pending',
            'execute_at' => $executeAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->logQueueActivity('job_enqueued', [
            'job_id' => $jobId,
            'job_type' => $jobType,
            'priority' => $priority,
            'delay' => $delay
        ]);

        return $jobId;
    }

    /**
     * Process queue with multiple workers
     */
    public function processQueue(): array
    {
        $results = [
            'success' => true,
            'workers_started' => 0,
            'jobs_processed' => 0,
            'jobs_failed' => 0,
            'execution_time' => 0,
            'errors' => []
        ];

        $startTime = microtime(true);

        try {
            // Check for stale jobs and reset them
            $this->resetStaleJobs();

            // Start worker processes
            for ($i = 0; $i < $this->config['max_workers']; $i++) {
                if ($this->startWorker($i)) {
                    $results['workers_started']++;
                }
            }

            // Monitor workers and collect results
            $this->monitorWorkers($results);

            $results['execution_time'] = round(microtime(true) - $startTime, 3);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $results['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logQueueActivity('queue_processing_failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Start a worker process
     */
    private function startWorker(int $workerId): bool
    {
        try {
            $workerPid = pcntl_fork();

            if ($workerPid === -1) {
                throw new \Exception("Failed to fork worker process");
            } elseif ($workerPid === 0) {
                // Child process - worker
                $this->runWorker($workerId);
                exit(0);
            } else {
                // Parent process
                $this->workers[$workerId] = [
                    'pid' => $workerPid,
                    'started_at' => time(),
                    'jobs_processed' => 0,
                    'status' => 'running'
                ];

                $this->logQueueActivity('worker_started', [
                    'worker_id' => $workerId,
                    'pid' => $workerPid
                ]);

                return true;
            }

        } catch (\Exception $e) {
            $this->logQueueActivity('worker_start_failed', [
                'worker_id' => $workerId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Worker process main loop
     */
    private function runWorker(int $workerId): void
    {
        // Set memory limit for worker
        ini_set('memory_limit', $this->config['worker_memory_limit']);

        $startTime = time();
        $jobsProcessed = 0;

        $this->logQueueActivity('worker_running', [
            'worker_id' => $workerId,
            'pid' => getmypid()
        ]);

        while (true) {
            try {
                // Check if we should stop (parent process killed, etc.)
                if (!$this->shouldWorkerContinue($workerId, $startTime)) {
                    break;
                }

                // Get next job
                $job = $this->getNextJob();

                if (!$job) {
                    // No jobs available, sleep and continue
                    sleep($this->config['queue_polling_interval']);
                    continue;
                }

                // Process the job
                $result = $this->processJob($job, $workerId);

                if ($result['success']) {
                    $jobsProcessed++;
                } else {
                    $this->handleJobFailure($job, $result['error']);
                }

                // Check memory usage
                if (memory_get_usage(true) > $this->parseMemoryLimit($this->config['worker_memory_limit']) * 0.8) {
                    $this->logQueueActivity('worker_memory_warning', [
                        'worker_id' => $workerId,
                        'memory_usage' => memory_get_usage(true)
                    ]);
                    break;
                }

            } catch (\Exception $e) {
                $this->logQueueActivity('worker_error', [
                    'worker_id' => $workerId,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }

        $this->logQueueActivity('worker_finished', [
            'worker_id' => $workerId,
            'jobs_processed' => $jobsProcessed,
            'runtime' => time() - $startTime
        ]);
    }

    /**
     * Get next job from queue
     */
    private function getNextJob(): ?array
    {
        // Lock and get highest priority job that's ready to execute
        return $this->db->fetchRow(
            "SELECT * FROM job_queue
             WHERE status = 'pending'
             AND execute_at <= NOW()
             ORDER BY priority DESC, created_at ASC
             LIMIT 1
             FOR UPDATE"
        );
    }

    /**
     * Process a single job
     */
    private function processJob(array $job, int $workerId): array
    {
        $startTime = microtime(true);

        try {
            // Mark job as processing
            $this->db->update('job_queue', [
                'status' => 'processing',
                'started_at' => date('Y-m-d H:i:s'),
                'worker_id' => $workerId,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $job['id']]);

            // Execute the job based on type
            $result = $this->executeJob($job);

            $executionTime = round(microtime(true) - $startTime, 3);

            if ($result['success']) {
                // Mark job as completed
                $this->db->update('job_queue', [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'execution_time' => $executionTime,
                    'result' => json_encode($result['data'] ?? []),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $job['id']]);

                $this->logQueueActivity('job_completed', [
                    'job_id' => $job['id'],
                    'job_type' => $job['job_type'],
                    'worker_id' => $workerId,
                    'execution_time' => $executionTime
                ]);

                return ['success' => true, 'execution_time' => $executionTime];
            } else {
                throw new \Exception($result['error'] ?? 'Job execution failed');
            }

        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 3);

            $this->logQueueActivity('job_failed', [
                'job_id' => $job['id'],
                'job_type' => $job['job_type'],
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ];
        }
    }

    /**
     * Execute job based on type
     */
    private function executeJob(array $job): array
    {
        $payload = json_decode($job['payload'], true);

        switch ($job['job_type']) {
            case 'website_scan':
                return $this->executeWebsiteScan($payload);

            case 'send_notification':
                return $this->executeSendNotification($payload);

            case 'cleanup_data':
                return $this->executeCleanupData($payload);

            case 'generate_report':
                return $this->executeGenerateReport($payload);

            case 'backup_database':
                return $this->executeBackupDatabase($payload);

            default:
                return [
                    'success' => false,
                    'error' => "Unknown job type: {$job['job_type']}"
                ];
        }
    }

    /**
     * Execute website scan job
     */
    private function executeWebsiteScan(array $payload): array
    {
        try {
            $testService = new TestService();
            $result = $testService->executeWebsiteTests(
                $payload['website_id'],
                $payload['test_names'] ?? []
            );

            return [
                'success' => $result['success'],
                'data' => $result,
                'error' => $result['success'] ? null : 'Scan execution failed'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute send notification job
     */
    private function executeSendNotification(array $payload): array
    {
        try {
            $notificationService = new NotificationService();

            switch ($payload['type']) {
                case 'email':
                    $result = $notificationService->sendEmail(
                        $payload['recipient'],
                        $payload['subject'],
                        $payload['body'],
                        $payload['metadata'] ?? []
                    );
                    break;

                case 'webhook':
                    $result = $notificationService->sendWebhookNotification(
                        $payload['webhook_url'],
                        $payload['payload']
                    );
                    break;

                default:
                    return [
                        'success' => false,
                        'error' => "Unknown notification type: {$payload['type']}"
                    ];
            }

            return [
                'success' => $result['success'],
                'data' => $result,
                'error' => $result['success'] ? null : ($result['error'] ?? 'Notification failed')
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute cleanup data job
     */
    private function executeCleanupData(array $payload): array
    {
        try {
            $archiveService = new ArchiveService();
            $result = $archiveService->performArchive();

            return [
                'success' => $result['success'],
                'data' => $result,
                'error' => $result['success'] ? null : implode(', ', $result['errors'])
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute generate report job
     */
    private function executeGenerateReport(array $payload): array
    {
        try {
            $metricsService = new MetricsService();

            if (isset($payload['website_id'])) {
                $result = $metricsService->getWebsiteMetrics(
                    $payload['website_id'],
                    $payload['period'] ?? '7d'
                );
            } else {
                $result = $metricsService->getSystemMetrics($payload['period'] ?? '7d');
            }

            return [
                'success' => true,
                'data' => $result
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute backup database job
     */
    private function executeBackupDatabase(array $payload): array
    {
        try {
            $backupService = new BackupService();
            $result = $backupService->createBackup($payload['backup_type'] ?? 'full');

            return [
                'success' => $result['success'],
                'data' => $result,
                'error' => $result['success'] ? null : ($result['error'] ?? 'Backup failed')
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle job failure
     */
    private function handleJobFailure(array $job, string $error): void
    {
        $retryCount = ($job['retry_count'] ?? 0) + 1;

        if ($retryCount < $this->config['max_retries']) {
            // Schedule retry with exponential backoff
            $retryDelay = $this->config['retry_delay'] * pow(2, $retryCount - 1);
            $executeAt = date('Y-m-d H:i:s', time() + $retryDelay);

            $this->db->update('job_queue', [
                'status' => 'pending',
                'retry_count' => $retryCount,
                'execute_at' => $executeAt,
                'last_error' => $error,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $job['id']]);

            $this->logQueueActivity('job_retry_scheduled', [
                'job_id' => $job['id'],
                'retry_count' => $retryCount,
                'retry_delay' => $retryDelay,
                'error' => $error
            ]);

        } else {
            // Max retries reached, move to dead letter queue or mark as failed
            $status = $this->config['dead_letter_queue'] ? 'dead_letter' : 'failed';

            $this->db->update('job_queue', [
                'status' => $status,
                'failed_at' => date('Y-m-d H:i:s'),
                'last_error' => $error,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $job['id']]);

            $this->logQueueActivity('job_max_retries_reached', [
                'job_id' => $job['id'],
                'final_status' => $status,
                'error' => $error
            ]);
        }
    }

    /**
     * Reset stale jobs (jobs stuck in processing state)
     */
    private function resetStaleJobs(): void
    {
        $staleThreshold = date('Y-m-d H:i:s', time() - $this->config['job_timeout']);

        $staleJobs = $this->db->query(
            "UPDATE job_queue
             SET status = 'pending',
                 started_at = NULL,
                 worker_id = NULL,
                 updated_at = NOW()
             WHERE status = 'processing'
             AND started_at < ?",
            [$staleThreshold]
        );

        if ($staleJobs > 0) {
            $this->logQueueActivity('stale_jobs_reset', [
                'job_count' => $staleJobs,
                'threshold' => $staleThreshold
            ]);
        }
    }

    /**
     * Monitor worker processes
     */
    private function monitorWorkers(array &$results): void
    {
        $maxRuntime = 300; // 5 minutes max runtime
        $startTime = time();

        while (!empty($this->workers) && (time() - $startTime) < $maxRuntime) {
            foreach ($this->workers as $workerId => $worker) {
                $status = pcntl_waitpid($worker['pid'], $exitStatus, WNOHANG);

                if ($status > 0) {
                    // Worker finished
                    unset($this->workers[$workerId]);

                    $this->logQueueActivity('worker_finished', [
                        'worker_id' => $workerId,
                        'pid' => $worker['pid'],
                        'exit_status' => $exitStatus,
                        'runtime' => time() - $worker['started_at']
                    ]);

                } elseif ($status === -1) {
                    // Error occurred
                    unset($this->workers[$workerId]);

                    $this->logQueueActivity('worker_error', [
                        'worker_id' => $workerId,
                        'pid' => $worker['pid']
                    ]);
                }
            }

            // Short sleep to prevent busy waiting
            usleep(100000); // 0.1 seconds
        }

        // Clean up any remaining workers
        foreach ($this->workers as $workerId => $worker) {
            posix_kill($worker['pid'], SIGTERM);
            pcntl_waitpid($worker['pid'], $exitStatus);

            $this->logQueueActivity('worker_terminated', [
                'worker_id' => $workerId,
                'pid' => $worker['pid']
            ]);
        }
    }

    /**
     * Check if worker should continue running
     */
    private function shouldWorkerContinue(int $workerId, int $startTime): bool
    {
        // Stop if running too long
        if ((time() - $startTime) > 3600) { // 1 hour max
            return false;
        }

        // Stop if parent process is gone (simple check)
        if (getppid() === 1) {
            return false;
        }

        return true;
    }

    /**
     * Get queue statistics
     */
    public function getQueueStatistics(): array
    {
        $stats = $this->db->fetchRow(
            "SELECT
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = 'dead_letter' THEN 1 ELSE 0 END) as dead_letter_jobs,
                AVG(CASE WHEN execution_time IS NOT NULL THEN execution_time ELSE NULL END) as avg_execution_time,
                MAX(created_at) as latest_job,
                MIN(CASE WHEN status = 'pending' THEN created_at ELSE NULL END) as oldest_pending
             FROM job_queue"
        ) ?: [];

        // Get job types breakdown
        $jobTypes = $this->db->fetchAll(
            "SELECT job_type, status, COUNT(*) as count
             FROM job_queue
             GROUP BY job_type, status
             ORDER BY job_type, status"
        );

        $stats['job_types'] = [];
        foreach ($jobTypes as $jobType) {
            if (!isset($stats['job_types'][$jobType['job_type']])) {
                $stats['job_types'][$jobType['job_type']] = [];
            }
            $stats['job_types'][$jobType['job_type']][$jobType['status']] = (int)$jobType['count'];
        }

        return $stats;
    }

    /**
     * Cleanup completed jobs
     */
    public function cleanupCompletedJobs(): array
    {
        $cutoffTime = date('Y-m-d H:i:s', time() - $this->config['cleanup_completed_jobs_after']);

        $deletedJobs = $this->db->query(
            "DELETE FROM job_queue
             WHERE status IN ('completed', 'failed')
             AND (completed_at < ? OR failed_at < ?)",
            [$cutoffTime, $cutoffTime]
        );

        $this->logQueueActivity('cleanup_completed_jobs', [
            'deleted_jobs' => $deletedJobs,
            'cutoff_time' => $cutoffTime
        ]);

        return [
            'success' => true,
            'deleted_jobs' => $deletedJobs
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$limit;
        }
    }

    /**
     * Log queue activity
     */
    private function logQueueActivity(string $action, array $context = []): void
    {
        $this->db->insert('queue_log', [
            'action' => $action,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Add bulk jobs to queue
     */
    public function enqueueBulk(array $jobs): array
    {
        $results = [
            'success' => true,
            'enqueued_count' => 0,
            'failed_count' => 0,
            'job_ids' => [],
            'errors' => []
        ];

        foreach ($jobs as $job) {
            try {
                $jobId = $this->enqueue(
                    $job['job_type'],
                    $job['payload'],
                    $job['priority'] ?? 'normal',
                    $job['delay'] ?? 0
                );

                $results['job_ids'][] = $jobId;
                $results['enqueued_count']++;

            } catch (\Exception $e) {
                $results['failed_count']++;
                $results['errors'][] = $e->getMessage();
            }
        }

        if ($results['failed_count'] > 0) {
            $results['success'] = false;
        }

        return $results;
    }

    /**
     * Cancel pending job
     */
    public function cancelJob(int $jobId): array
    {
        $job = $this->db->fetchRow("SELECT * FROM job_queue WHERE id = ?", [$jobId]);

        if (!$job) {
            return [
                'success' => false,
                'error' => 'Job not found'
            ];
        }

        if ($job['status'] !== 'pending') {
            return [
                'success' => false,
                'error' => "Cannot cancel job with status: {$job['status']}"
            ];
        }

        $this->db->update('job_queue', [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $jobId]);

        $this->logQueueActivity('job_cancelled', [
            'job_id' => $jobId,
            'job_type' => $job['job_type']
        ]);

        return ['success' => true];
    }
}