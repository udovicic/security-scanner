<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\DatabaseLock;
use SecurityScanner\Core\ExecutionMonitor;
use SecurityScanner\Core\SchedulerConfig;
use SecurityScanner\Services\TestService;
use SecurityScanner\Services\NotificationService;
use SecurityScanner\Services\ResourceMonitorService;

class SchedulerService
{
    private Database $db;
    private DatabaseLock $dbLock;
    private ExecutionMonitor $monitor;
    private SchedulerConfig $schedulerConfig;
    private TestService $testService;
    private NotificationService $notificationService;
    private ResourceMonitorService $resourceMonitor;
    private array $config;
    private string $lockName;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->dbLock = new DatabaseLock();
        $this->monitor = new ExecutionMonitor();
        $this->schedulerConfig = new SchedulerConfig($config);
        $this->testService = new TestService();
        $this->notificationService = new NotificationService();
        $this->resourceMonitor = new ResourceMonitorService();

        $this->config = array_merge([
            'max_concurrent_executions' => 5,
            'max_execution_time' => 3600, // 1 hour
            'memory_limit' => '512M',
            'batch_size' => 10,
            'retry_failed_after' => 300, // 5 minutes
            'max_retries' => 3,
            'cleanup_interval' => 86400, // 24 hours
            'health_check_interval' => 300, // 5 minutes
            'lock_timeout' => 3600 // 1 hour lock timeout
        ], $config);

        $this->lockName = 'scheduler_execution';
    }

    /**
     * Main scheduler execution method
     */
    public function run(): array
    {
        // Try to acquire database lock with metadata
        $lockMetadata = [
            'hostname' => gethostname(),
            'start_time' => date('Y-m-d H:i:s'),
            'max_execution_time' => $this->config['max_execution_time']
        ];

        if (!$this->dbLock->acquire($this->lockName, $this->config['lock_timeout'], $lockMetadata)) {
            return [
                'success' => false,
                'message' => 'Another scheduler instance is already running',
                'lock_info' => $this->dbLock->getLockInfo($this->lockName)
            ];
        }

        try {
            $startTime = microtime(true);
            $executionId = 'scheduler_' . date('Y-m-d_H-i-s') . '_' . getmypid();

            // Start execution monitoring
            $this->monitor->startExecution($executionId, [
                'type' => 'scheduler_execution',
                'pid' => getmypid(),
                'hostname' => gethostname(),
                'max_execution_time' => $this->config['max_execution_time'],
                'memory_limit' => $this->config['memory_limit']
            ]);

            $this->logSchedulerActivity('started', ['pid' => getmypid(), 'execution_id' => $executionId]);

            // Set memory and time limits
            ini_set('memory_limit', $this->config['memory_limit']);
            set_time_limit($this->config['max_execution_time']);

            $this->monitor->checkpoint($executionId, 'initialization_complete');

            $results = [
                'success' => true,
                'execution_time' => 0,
                'processed_websites' => 0,
                'successful_scans' => 0,
                'failed_scans' => 0,
                'skipped_websites' => 0,
                'activities' => []
            ];

            // Monitor system resources
            $resourceStatus = $this->resourceMonitor->monitorResources();
            $results['activities'][] = $resourceStatus;
            $this->monitor->checkpoint($executionId, 'resource_monitoring_complete', $resourceStatus);

            // Check if throttling is active
            if ($resourceStatus['analysis']['throttle_recommended']) {
                $this->monitor->warning($executionId, 'System throttling detected', $resourceStatus['analysis']);
                $this->logSchedulerActivity('throttling_detected', $resourceStatus['analysis']);
                $results['success'] = false;
                $results['message'] = 'System resources under pressure, throttling active';

                $this->monitor->completeExecution($executionId, false, $results);
                return $results;
            }

            // Check scheduler health
            $healthCheck = $this->performHealthCheck();
            $results['activities'][] = $healthCheck;
            $this->monitor->checkpoint($executionId, 'health_check_complete', $healthCheck);

            if (!$healthCheck['healthy']) {
                $this->monitor->error($executionId, 'Health check failed', $healthCheck);
                $this->logSchedulerActivity('health_check_failed', $healthCheck);
                $results['success'] = false;
                $results['message'] = 'Health check failed';

                $this->monitor->completeExecution($executionId, false, $results);
                return $results;
            }

            // Get websites due for scanning
            $websitesDue = $this->getWebsitesDueForScanning();
            $results['websites_due'] = count($websitesDue);
            $this->monitor->checkpoint($executionId, 'websites_fetched', ['count' => count($websitesDue)]);

            if (empty($websitesDue)) {
                $this->logSchedulerActivity('no_websites_due', ['count' => 0]);
                $results['message'] = 'No websites due for scanning';

                $this->monitor->completeExecution($executionId, true, $results);
                return $results;
            }

            // Process websites in batches
            $batches = array_chunk($websitesDue, $this->config['batch_size']);

            foreach ($batches as $batchIndex => $batch) {
                // Send heartbeat before processing each batch
                $this->sendHeartbeat();

                $this->monitor->checkpoint($executionId, 'batch_started', [
                    'batch_number' => $batchIndex + 1,
                    'batch_size' => count($batch),
                    'total_batches' => count($batches)
                ]);

                $batchResults = $this->processBatch($batch, $batchIndex + 1, $executionId);

                $results['processed_websites'] += $batchResults['processed'];
                $results['successful_scans'] += $batchResults['successful'];
                $results['failed_scans'] += $batchResults['failed'];
                $results['skipped_websites'] += $batchResults['skipped'];
                $results['activities'] = array_merge($results['activities'], $batchResults['activities']);

                $this->monitor->checkpoint($executionId, 'batch_completed', $batchResults);

                // Monitor resources after each batch
                $resourceData = $this->monitor->monitorResources($executionId);

                // Check if we should continue
                if (!$this->shouldContinueExecution()) {
                    $this->monitor->warning($executionId, 'Execution stopped due to resource limits', ['reason' => 'resource_limits']);
                    $this->logSchedulerActivity('execution_stopped', ['reason' => 'resource_limits']);
                    break;
                }
            }

            // Cleanup old data periodically
            if ($this->shouldPerformCleanup()) {
                $this->monitor->checkpoint($executionId, 'cleanup_started');
                $cleanupResults = $this->performCleanup();
                $results['activities'][] = $cleanupResults;
                $this->monitor->checkpoint($executionId, 'cleanup_completed', $cleanupResults);
            }

            // Retry failed scans
            $this->monitor->checkpoint($executionId, 'retry_started');
            $retryResults = $this->retryFailedScans();
            $results['activities'][] = $retryResults;
            $this->monitor->checkpoint($executionId, 'retry_completed', $retryResults);

            $results['execution_time'] = round(microtime(true) - $startTime, 3);
            $this->logSchedulerActivity('completed', $results);

            // Complete execution monitoring
            $this->monitor->completeExecution($executionId, true, $results);

            return $results;

        } catch (\Exception $e) {
            $this->logSchedulerActivity('error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];

            // Complete execution monitoring with error
            if (isset($executionId)) {
                $this->monitor->error($executionId, 'Scheduler execution failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->monitor->completeExecution($executionId, false, $errorResults);
            }

            return $errorResults;

        } finally {
            // Release the database lock
            $this->dbLock->release($this->lockName);
        }
    }

    /**
     * Get websites that are due for scanning
     */
    public function getWebsitesDueForScanning(): array
    {
        return $this->schedulerConfig->getWebsitesPrioritized($this->config['batch_size'] * 10);
    }

    /**
     * Process a batch of websites
     */
    private function processBatch(array $websites, int $batchNumber, string $executionId = null): array
    {
        $results = [
            'batch_number' => $batchNumber,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'activities' => []
        ];

        $this->logSchedulerActivity('batch_started', [
            'batch_number' => $batchNumber,
            'website_count' => count($websites)
        ]);

        foreach ($websites as $website) {
            if (!$this->shouldContinueExecution()) {
                $results['skipped']++;
                continue;
            }

            // Send heartbeat every 5 websites to keep lock alive
            if ($results['processed'] % 5 === 0) {
                $this->sendHeartbeat();
            }

            $scanResult = $this->executeWebsiteScan($website);
            $results['processed']++;

            if ($scanResult['success']) {
                $results['successful']++;
                $this->updateNextScanTime($website, true);
            } else {
                $results['failed']++;
                $this->handleScanFailure($website, $scanResult);
            }

            $results['activities'][] = [
                'type' => 'website_scan',
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'success' => $scanResult['success'],
                'execution_time' => $scanResult['execution_time'] ?? 0,
                'error' => $scanResult['error'] ?? null
            ];

            // Small delay between scans to prevent overwhelming
            usleep(100000); // 0.1 seconds
        }

        $this->logSchedulerActivity('batch_completed', $results);

        return $results;
    }

    /**
     * Execute scan for a single website
     */
    private function executeWebsiteScan(array $website): array
    {
        try {
            $startTime = microtime(true);

            // Mark scan as started
            $this->logSchedulerActivity('scan_started', [
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'website_url' => $website['url']
            ]);

            // Execute tests
            $testResult = $this->testService->executeWebsiteTests($website['id']);

            $executionTime = round(microtime(true) - $startTime, 3);

            if ($testResult['success']) {
                // Send notifications if needed
                if (!$testResult['overall_success'] && !empty($website['notification_email'])) {
                    $this->notificationService->sendTestFailureNotification(
                        $website,
                        $testResult
                    );
                }

                $this->logSchedulerActivity('scan_completed', [
                    'website_id' => $website['id'],
                    'scan_id' => $testResult['scan_id'],
                    'overall_success' => $testResult['overall_success'],
                    'execution_time' => $executionTime
                ]);

                return [
                    'success' => true,
                    'scan_id' => $testResult['scan_id'],
                    'overall_success' => $testResult['overall_success'],
                    'execution_time' => $executionTime
                ];
            } else {
                throw new \Exception('Test execution failed: ' . json_encode($testResult['errors'] ?? []));
            }

        } catch (\Exception $e) {
            $this->logSchedulerActivity('scan_failed', [
                'website_id' => $website['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        }
    }

    /**
     * Update next scan time for a website
     */
    private function updateNextScanTime(array $website, bool $scanSuccessful = true, int $retryCount = 0): void
    {
        $nextScanTime = $this->schedulerConfig->calculateNextScanTime($website, $scanSuccessful, $retryCount);

        $sql = "UPDATE websites SET next_scan_at = ?, last_scan_at = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, [
            $nextScanTime,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $website['id']
        ]);
    }


    /**
     * Handle scan failure with comprehensive retry mechanism
     */
    private function handleScanFailure(array $website, array $scanResult): void
    {
        $errorType = $this->categorizeError($scanResult['error'] ?? 'Unknown error');
        $retryCount = $website['failed_attempts'] ?? 0;
        $maxRetries = $this->schedulerConfig->getRetryAttempts($website);

        // Record detailed failure information
        $sql = "INSERT INTO scheduler_log (level, message, context, created_at) VALUES (?, ?, ?, ?)";
        $this->db->query($sql, [
            'error',
            'Website scan failed',
            json_encode([
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'website_url' => $website['url'],
                'error' => $scanResult['error'] ?? 'Unknown error',
                'error_type' => $errorType,
                'retry_count' => $retryCount + 1,
                'max_retries' => $maxRetries,
                'execution_time' => $scanResult['execution_time'] ?? 0,
                'scan_timestamp' => date('Y-m-d H:i:s')
            ]),
            date('Y-m-d H:i:s')
        ]);

        // Update website failure statistics
        $sql = "UPDATE websites SET
                consecutive_failures = COALESCE(consecutive_failures, 0) + 1,
                total_failures = COALESCE(total_failures, 0) + 1,
                last_failure_at = ?,
                last_error_type = ?,
                last_error_message = ?,
                updated_at = ?
                WHERE id = ?";

        $this->db->query($sql, [
            date('Y-m-d H:i:s'),
            $errorType,
            substr($scanResult['error'] ?? 'Unknown error', 0, 500),
            date('Y-m-d H:i:s'),
            $website['id']
        ]);

        // Get current failure count
        $sql = "SELECT consecutive_failures FROM websites WHERE id = ?";
        $stmt = $this->db->query($sql, [$website['id']]);
        $failureCount = $stmt->fetchColumn();

        // Determine if retry should be scheduled
        $shouldRetry = $this->shouldRetryFailedScan($website, $errorType, $failureCount, $maxRetries);

        if ($shouldRetry) {
            $this->scheduleRetry($website, $failureCount, $errorType);
        } else {
            $this->handleMaxRetriesExceeded($website, $failureCount, $maxRetries);
        }
    }

    /**
     * Categorize error type for retry logic
     */
    private function categorizeError(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        if (strpos($errorMessage, 'timeout') !== false || strpos($errorMessage, 'connection timeout') !== false) {
            return 'timeout';
        }

        if (strpos($errorMessage, 'connection refused') !== false || strpos($errorMessage, 'connection failed') !== false) {
            return 'connection_refused';
        }

        if (strpos($errorMessage, 'dns') !== false || strpos($errorMessage, 'host not found') !== false) {
            return 'dns_error';
        }

        if (strpos($errorMessage, '404') !== false || strpos($errorMessage, 'not found') !== false) {
            return 'not_found';
        }

        if (strpos($errorMessage, '500') !== false || strpos($errorMessage, '502') !== false || strpos($errorMessage, '503') !== false) {
            return 'server_error';
        }

        if (strpos($errorMessage, '403') !== false || strpos($errorMessage, 'forbidden') !== false) {
            return 'forbidden';
        }

        if (strpos($errorMessage, 'ssl') !== false || strpos($errorMessage, 'certificate') !== false) {
            return 'ssl_error';
        }

        return 'unknown';
    }

    /**
     * Determine if a failed scan should be retried
     */
    private function shouldRetryFailedScan(array $website, string $errorType, int $failureCount, int $maxRetries): bool
    {
        // Don't retry if max retries exceeded
        if ($failureCount >= $maxRetries) {
            return false;
        }

        // Don't retry certain error types
        $nonRetryableErrors = ['not_found', 'forbidden'];
        if (in_array($errorType, $nonRetryableErrors)) {
            return false;
        }

        // Check if we're within daily retry limits
        $sql = "SELECT COUNT(*) FROM scan_results
                WHERE website_id = ?
                AND success = 0
                AND DATE(created_at) = CURDATE()";
        $stmt = $this->db->query($sql, [$website['id']]);
        $dailyFailures = $stmt->fetchColumn();

        $maxDailyRetries = $this->schedulerConfig->get('max_retries_per_day', 5);
        if ($dailyFailures >= $maxDailyRetries) {
            return false;
        }

        return true;
    }

    /**
     * Schedule a retry for a failed scan
     */
    private function scheduleRetry(array $website, int $failureCount, string $errorType): void
    {
        // Calculate retry delay based on error type and failure count
        $baseDelay = $this->schedulerConfig->get('retry_delay_minutes', 15);
        $retryDelay = $this->calculateRetryDelay($errorType, $failureCount, $baseDelay);

        // Update next scan time with retry delay
        $this->updateNextScanTime($website, false, $failureCount);

        // Log retry scheduling
        $sql = "INSERT INTO scheduler_log (level, message, context, created_at) VALUES (?, ?, ?, ?)";
        $this->db->query($sql, [
            'info',
            'Retry scheduled for failed website scan',
            json_encode([
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'retry_count' => $failureCount,
                'error_type' => $errorType,
                'retry_delay_minutes' => $retryDelay,
                'next_retry_at' => date('Y-m-d H:i:s', time() + ($retryDelay * 60))
            ]),
            date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Calculate retry delay based on error type and failure count
     */
    private function calculateRetryDelay(string $errorType, int $failureCount, int $baseDelay): int
    {
        // Different delay strategies based on error type
        $multipliers = [
            'timeout' => 1.5,           // Gradual backoff for timeouts
            'connection_refused' => 2.0, // Longer delay for connection issues
            'server_error' => 1.2,      // Short delay for server errors
            'dns_error' => 3.0,         // Long delay for DNS issues
            'ssl_error' => 2.5,         // Medium delay for SSL issues
            'unknown' => 1.5            // Default backoff
        ];

        $multiplier = $multipliers[$errorType] ?? 1.5;

        // Exponential backoff with jitter
        $delay = $baseDelay * pow($multiplier, min($failureCount - 1, 4)); // Cap exponential growth

        // Add jitter (±20%)
        $jitter = $delay * 0.2 * (rand(-100, 100) / 100);
        $delay += $jitter;

        // Ensure reasonable bounds (5 minutes to 4 hours)
        return max(5, min(240, (int)$delay));
    }

    /**
     * Handle case when max retries are exceeded
     */
    private function handleMaxRetriesExceeded(array $website, int $failureCount, int $maxRetries): void
    {
        // Mark website as temporarily disabled or for manual review
        $sql = "UPDATE websites SET
                status = 'failed',
                retry_after = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                needs_manual_review = 1,
                updated_at = ?
                WHERE id = ?";

        $this->db->query($sql, [
            date('Y-m-d H:i:s'),
            $website['id']
        ]);

        // Log critical failure
        $sql = "INSERT INTO scheduler_log (level, message, context, created_at) VALUES (?, ?, ?, ?)";
        $this->db->query($sql, [
            'critical',
            'Website marked for manual review after max retries exceeded',
            json_encode([
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'website_url' => $website['url'],
                'failure_count' => $failureCount,
                'max_retries' => $maxRetries,
                'retry_after' => date('Y-m-d H:i:s', time() + 86400) // 24 hours
            ]),
            date('Y-m-d H:i:s')
        ]);

        // Trigger alert for critical failure
        $this->triggerCriticalFailureAlert($website, $failureCount);
    }

    /**
     * Trigger alert for critical failures
     */
    private function triggerCriticalFailureAlert(array $website, int $failureCount): void
    {
        try {
            $alertData = [
                'type' => 'critical_scan_failure',
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'website_url' => $website['url'],
                'failure_count' => $failureCount,
                'timestamp' => date('Y-m-d H:i:s'),
                'severity' => 'critical'
            ];

            // This would integrate with the notification service
            // For now, just log it
            $sql = "INSERT INTO scheduler_log (level, message, context, created_at) VALUES (?, ?, ?, ?)";
            $this->db->query($sql, [
                'critical',
                'Critical failure alert triggered',
                json_encode($alertData),
                date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            // Don't let alert failures break the main process
            error_log("Failed to trigger critical failure alert: " . $e->getMessage());
        }
    }

    /**
     * Retry failed scans
     */
    private function retryFailedScans(): array
    {
        $failedScans = $this->db->fetchAll(
            "SELECT sr.*, w.name as website_name, w.url as website_url
             FROM scan_results sr
             JOIN websites w ON sr.website_id = w.id
             WHERE sr.status = 'failed'
             AND sr.retry_count < ?
             AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             AND sr.next_retry_at <= NOW()
             LIMIT 10",
            [$this->config['max_retries']]
        );

        $retryResults = [
            'type' => 'retry_failed_scans',
            'attempted' => count($failedScans),
            'successful' => 0,
            'failed' => 0
        ];

        foreach ($failedScans as $scan) {
            try {
                $website = $this->db->fetchRow("SELECT * FROM websites WHERE id = ?", [$scan['website_id']]);
                if (!$website) continue;

                $result = $this->executeWebsiteScan($website);

                if ($result['success']) {
                    $retryResults['successful']++;

                    // Update original scan record
                    $this->db->update('scan_results', [
                        'status' => 'completed',
                        'retry_count' => $scan['retry_count'] + 1,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $scan['id']]);
                } else {
                    $retryResults['failed']++;

                    // Schedule next retry
                    $nextRetry = date('Y-m-d H:i:s', time() + ($this->config['retry_failed_after'] * pow(2, $scan['retry_count'])));
                    $this->db->update('scan_results', [
                        'retry_count' => $scan['retry_count'] + 1,
                        'next_retry_at' => $nextRetry,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $scan['id']]);
                }

            } catch (\Exception $e) {
                $retryResults['failed']++;
                $this->logSchedulerActivity('retry_failed', [
                    'scan_id' => $scan['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $retryResults;
    }

    /**
     * Perform health check
     */
    private function performHealthCheck(): array
    {
        $health = [
            'type' => 'health_check',
            'healthy' => true,
            'checks' => []
        ];

        // Check database connection
        try {
            $this->db->fetchColumn("SELECT 1");
            $health['checks']['database'] = ['status' => 'ok'];
        } catch (\Exception $e) {
            $health['healthy'] = false;
            $health['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit($this->config['memory_limit']);
        $memoryPercent = ($memoryUsage / $memoryLimit) * 100;

        $health['checks']['memory'] = [
            'status' => $memoryPercent < 80 ? 'ok' : 'warning',
            'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'percent' => round($memoryPercent, 2)
        ];

        if ($memoryPercent > 90) {
            $health['healthy'] = false;
        }

        // Check running scans
        $runningScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE status = 'running' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );

        $health['checks']['running_scans'] = [
            'status' => $runningScans < $this->config['max_concurrent_executions'] ? 'ok' : 'warning',
            'count' => (int)$runningScans,
            'limit' => $this->config['max_concurrent_executions']
        ];

        // Check disk space (if accessible)
        $diskFree = disk_free_space('.');
        $diskTotal = disk_total_space('.');

        if ($diskFree !== false && $diskTotal !== false) {
            $diskPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
            $health['checks']['disk_space'] = [
                'status' => $diskPercent < 90 ? 'ok' : 'warning',
                'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                'used_percent' => round($diskPercent, 2)
            ];
        }

        return $health;
    }

    /**
     * Check if cleanup should be performed
     */
    private function shouldPerformCleanup(): bool
    {
        $lastCleanup = $this->db->fetchColumn(
            "SELECT MAX(created_at) FROM scheduler_log WHERE message = 'cleanup_performed'"
        );

        if (!$lastCleanup) {
            return true;
        }

        return strtotime($lastCleanup) < (time() - $this->config['cleanup_interval']);
    }

    /**
     * Perform cleanup of old data
     */
    private function performCleanup(): array
    {
        $cleanupResults = [
            'type' => 'cleanup',
            'cleaned_items' => []
        ];

        // Cleanup old scheduler logs (keep 30 days)
        $oldLogs = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scheduler_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if ($oldLogs > 0) {
            $this->db->query("DELETE FROM scheduler_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $cleanupResults['cleaned_items']['scheduler_logs'] = (int)$oldLogs;
        }

        // Cleanup orphaned test executions
        $orphanedExecutions = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM test_executions te
             LEFT JOIN scan_results sr ON te.scan_id = sr.id
             WHERE sr.id IS NULL"
        );

        if ($orphanedExecutions > 0) {
            $this->db->query(
                "DELETE te FROM test_executions te
                 LEFT JOIN scan_results sr ON te.scan_id = sr.id
                 WHERE sr.id IS NULL"
            );
            $cleanupResults['cleaned_items']['orphaned_executions'] = (int)$orphanedExecutions;
        }

        // Reset consecutive failures for websites that haven't failed recently
        $resetFailures = $this->db->query(
            "UPDATE websites SET consecutive_failures = 0
             WHERE consecutive_failures > 0
             AND last_failure_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $cleanupResults['cleaned_items']['reset_failure_counts'] = $resetFailures;

        $this->logSchedulerActivity('cleanup_performed', $cleanupResults);

        return $cleanupResults;
    }

    /**
     * Check if execution should continue
     */
    private function shouldContinueExecution(): bool
    {
        // Check if throttling is active
        $resourceStatus = $this->resourceMonitor->getCurrentResourceStatus();

        if ($resourceStatus['throttling_active'] || $resourceStatus['severity_level'] >= 3) {
            $this->logSchedulerActivity('execution_stopped_resources', [
                'reason' => 'resource_constraints',
                'throttling_active' => $resourceStatus['throttling_active'],
                'severity_level' => $resourceStatus['severity_level']
            ]);
            return false;
        }

        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit($this->config['memory_limit']);

        if ($memoryUsage > ($memoryLimit * 0.9)) {
            $this->logSchedulerActivity('execution_stopped_memory', [
                'memory_usage' => $memoryUsage,
                'memory_limit' => $memoryLimit
            ]);
            return false;
        }

        // Check execution time
        $maxTime = $this->config['max_execution_time'];
        if ($maxTime > 0 && (time() - $_SERVER['REQUEST_TIME']) > ($maxTime * 0.9)) {
            $this->logSchedulerActivity('execution_stopped_time', [
                'execution_time' => time() - $_SERVER['REQUEST_TIME'],
                'time_limit' => $maxTime
            ]);
            return false;
        }

        return true;
    }

    /**
     * Send heartbeat to keep lock alive during long operations
     */
    private function sendHeartbeat(): bool
    {
        return $this->dbLock->heartbeat($this->lockName);
    }

    /**
     * Extend lock timeout if needed
     */
    private function extendLockTimeout(int $additionalSeconds): bool
    {
        return $this->dbLock->extend($this->lockName, $additionalSeconds);
    }

    /**
     * Check if current process owns the lock
     */
    private function ownsLock(): bool
    {
        $lockInfo = $this->dbLock->getLockInfo($this->lockName);
        if (!$lockInfo) {
            return false;
        }

        // Simple ownership check - in production you might want more sophisticated logic
        return !$this->dbLock->isLocked($this->lockName) ||
               strpos($lockInfo['owner'], (string)getmypid()) !== false;
    }

    /**
     * Log scheduler activity
     */
    private function logSchedulerActivity(string $message, array $context = []): void
    {
        $level = 'info';

        if (strpos($message, 'error') !== false || strpos($message, 'failed') !== false) {
            $level = 'error';
        } elseif (strpos($message, 'warning') !== false) {
            $level = 'warning';
        }

        $this->db->insert('scheduler_log', [
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
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
     * Get scheduler statistics
     */
    public function getSchedulerStatistics(int $days = 7): array
    {
        return [
            'recent_executions' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM scheduler_log WHERE message = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'successful_scans' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM scan_results WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'failed_scans' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM scan_results WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'avg_execution_time' => $this->db->fetchColumn(
                "SELECT AVG(execution_time) FROM scan_results WHERE execution_time IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'websites_due_now' => count($this->getWebsitesDueForScanning())
        ];
    }
}