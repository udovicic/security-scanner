#!/usr/bin/env php
<?php

/**
 * Security Scanner Tool - Background Scheduler
 *
 * This script is designed to be executed every minute via cron.
 * It handles automated website scanning, resource monitoring, and system maintenance.
 *
 * Cron configuration example:
 * * * * * * /usr/bin/php /var/www/html/cron/scheduler.php >> /var/log/security-scanner-cron.log 2>&1
 *
 * Features:
 * - Single-minute execution with overlap prevention
 * - Resource monitoring and automatic throttling
 * - Comprehensive logging and error handling
 * - Health checks and system recovery
 * - Memory and execution time limits
 */

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be executed from the command line.');
}

// Set script start time for execution tracking
$scriptStartTime = microtime(true);
$scriptStartDateTime = date('Y-m-d H:i:s');

// Basic error handling setup
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Bootstrap the application
    require_once dirname(__DIR__) . '/bootstrap.php';

    use SecurityScanner\Services\{
        SchedulerService,
        ResourceMonitorService,
        NotificationService,
        MetricsService
    };
    use SecurityScanner\Core\{
        Logger,
        Database,
        DatabaseLock
    };

    // Initialize logger for scheduler operations
    $logger = Logger::scheduler();
    $logger->info('Scheduler cron job started', [
        'pid' => getmypid(),
        'start_time' => $scriptStartDateTime,
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit')
    ]);

    // Configuration
    $config = [
        'max_execution_time' => 55, // Leave 5 seconds buffer for 1-minute cron
        'memory_limit' => '256M',
        'lock_timeout' => 50, // Maximum seconds to wait for lock
        'enable_resource_monitoring' => true,
        'enable_health_checks' => true,
        'enable_notifications' => true,
        'log_level' => 'info'
    ];

    // Set resource limits
    ini_set('memory_limit', $config['memory_limit']);
    set_time_limit($config['max_execution_time']);

    // Initialize database lock for preventing overlapping executions
    $dbLock = new DatabaseLock();
    $lockName = 'cron_scheduler_execution';

    // Metadata for lock tracking
    $lockMetadata = [
        'pid' => getmypid(),
        'start_time' => $scriptStartDateTime,
        'hostname' => gethostname(),
        'script_path' => __FILE__
    ];

    // Try to acquire database lock with timeout
    $lockAcquired = $dbLock->acquire($lockName, $config['lock_timeout'], $lockMetadata);

    if (!$lockAcquired) {
        $lockInfo = $dbLock->getLockInfo($lockName);
        $logger->warning('Could not acquire database lock - another instance may be running', [
            'lock_name' => $lockName,
            'existing_lock' => $lockInfo
        ]);
        exit(1);
    }

    $logger->info('Database lock acquired successfully', [
        'lock_name' => $lockName,
        'pid' => getmypid(),
        'timeout' => $config['lock_timeout']
    ]);

    // Initialize services
    $resourceMonitor = new ResourceMonitorService();
    $schedulerService = new SchedulerService();
    $notificationService = new NotificationService();
    $metricsService = new MetricsService();

    $executionResults = [
        'success' => true,
        'start_time' => $scriptStartDateTime,
        'activities' => [],
        'errors' => [],
        'warnings' => [],
        'metrics' => []
    ];

    // Step 1: Resource Monitoring
    if ($config['enable_resource_monitoring']) {
        $logger->info('Starting resource monitoring');

        try {
            $resourceStatus = $resourceMonitor->monitorResources();
            $executionResults['activities'][] = [
                'step' => 'resource_monitoring',
                'status' => 'completed',
                'data' => $resourceStatus
            ];

            // Check if system is under pressure
            if ($resourceStatus['analysis']['throttle_recommended']) {
                $logger->warning('System resources under pressure - throttling recommended', [
                    'severity_level' => $resourceStatus['analysis']['severity_level'],
                    'critical_issues' => $resourceStatus['analysis']['critical_issues']
                ]);

                $executionResults['warnings'][] = 'System throttling active due to resource constraints';

                // Skip intensive operations if system is under severe pressure
                if ($resourceStatus['analysis']['severity_level'] >= 3) {
                    $logger->error('System under severe pressure - skipping scheduler execution');
                    $executionResults['success'] = false;
                    $executionResults['errors'][] = 'Execution skipped due to critical resource constraints';

                    // Send alert if notifications enabled
                    if ($config['enable_notifications']) {
                        // Note: In production, this would send actual notifications
                        $logger->critical('Critical resource alert triggered');
                    }

                    throw new Exception('Critical resource constraints detected');
                }
            }

        } catch (Exception $e) {
            $logger->error('Resource monitoring failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $executionResults['errors'][] = 'Resource monitoring failed: ' . $e->getMessage();
        }
    }

    // Step 2: Health Checks
    if ($config['enable_health_checks']) {
        $logger->info('Performing health checks');

        try {
            // Database connectivity check
            $db = Database::getInstance();
            $db->fetchColumn("SELECT 1");

            $executionResults['activities'][] = [
                'step' => 'health_check_database',
                'status' => 'healthy'
            ];

            // Check disk space
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;

            if ($diskUsagePercent > 90) {
                $logger->warning('Low disk space detected', [
                    'usage_percent' => round($diskUsagePercent, 2),
                    'free_gb' => round($diskFree / (1024**3), 2)
                ]);
                $executionResults['warnings'][] = 'Low disk space: ' . round($diskUsagePercent, 1) . '% used';
            }

            $executionResults['activities'][] = [
                'step' => 'health_check_disk',
                'status' => $diskUsagePercent > 95 ? 'critical' : ($diskUsagePercent > 90 ? 'warning' : 'healthy'),
                'usage_percent' => round($diskUsagePercent, 2)
            ];

        } catch (Exception $e) {
            $logger->error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $executionResults['errors'][] = 'Health check failed: ' . $e->getMessage();
        }
    }

    // Step 3: Main Scheduler Execution
    $logger->info('Starting main scheduler execution');

    try {
        $schedulerResults = $schedulerService->run();

        $executionResults['activities'][] = [
            'step' => 'scheduler_execution',
            'status' => $schedulerResults['success'] ? 'completed' : 'failed',
            'data' => $schedulerResults
        ];

        if (!$schedulerResults['success']) {
            $logger->error('Scheduler execution failed', $schedulerResults);
            $executionResults['errors'][] = 'Scheduler execution failed: ' . ($schedulerResults['message'] ?? 'Unknown error');
        } else {
            $logger->info('Scheduler execution completed successfully', [
                'processed_websites' => $schedulerResults['processed_websites'] ?? 0,
                'successful_scans' => $schedulerResults['successful_scans'] ?? 0,
                'failed_scans' => $schedulerResults['failed_scans'] ?? 0,
                'execution_time' => $schedulerResults['execution_time'] ?? 0
            ]);
        }

    } catch (Exception $e) {
        $logger->error('Scheduler execution exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $executionResults['errors'][] = 'Scheduler execution exception: ' . $e->getMessage();
        $executionResults['success'] = false;
    }

    // Step 4: Metrics Collection
    try {
        $logger->info('Collecting metrics');

        // Record scheduler execution metrics
        $metricsService->recordScanMetrics([
            'execution_time' => microtime(true) - $scriptStartTime,
            'memory_usage' => memory_get_peak_usage(true),
            'success_rate' => $executionResults['success'] ? 1.0 : 0.0,
            'total_tests' => 1, // The scheduler execution itself
            'passed_tests' => $executionResults['success'] ? 1 : 0,
            'failed_tests' => $executionResults['success'] ? 0 : 1,
            'website_id' => 0, // Special ID for scheduler metrics
            'scan_id' => 0
        ]);

        $executionResults['activities'][] = [
            'step' => 'metrics_collection',
            'status' => 'completed'
        ];

    } catch (Exception $e) {
        $logger->error('Metrics collection failed', [
            'error' => $e->getMessage()
        ]);
        $executionResults['warnings'][] = 'Metrics collection failed: ' . $e->getMessage();
    }

    // Step 5: Cleanup and Maintenance
    try {
        $logger->info('Performing maintenance tasks');

        // Cleanup old log files (once per hour)
        $currentMinute = (int)date('i');
        if ($currentMinute === 0) { // Top of the hour
            $logger->info('Performing hourly maintenance');

            // Clean up old temporary files
            $tempFiles = glob(sys_get_temp_dir() . '/security_scanner_*');
            $cleanedFiles = 0;

            foreach ($tempFiles as $file) {
                if (filemtime($file) < (time() - 3600)) { // Older than 1 hour
                    if (unlink($file)) {
                        $cleanedFiles++;
                    }
                }
            }

            if ($cleanedFiles > 0) {
                $logger->info('Cleaned up temporary files', ['count' => $cleanedFiles]);
            }
        }

        $executionResults['activities'][] = [
            'step' => 'maintenance',
            'status' => 'completed'
        ];

    } catch (Exception $e) {
        $logger->warning('Maintenance tasks failed', [
            'error' => $e->getMessage()
        ]);
        $executionResults['warnings'][] = 'Maintenance failed: ' . $e->getMessage();
    }

    // Calculate final execution time and memory usage
    $finalExecutionTime = microtime(true) - $scriptStartTime;
    $peakMemoryUsage = memory_get_peak_usage(true);

    $executionResults['end_time'] = date('Y-m-d H:i:s');
    $executionResults['execution_time'] = round($finalExecutionTime, 3);
    $executionResults['peak_memory_mb'] = round($peakMemoryUsage / (1024 * 1024), 2);

    // Log final results
    $logLevel = $executionResults['success'] ? 'info' : 'error';
    $logger->log($logLevel, 'Scheduler cron job completed', $executionResults);

    // Output summary for cron logs
    echo "Security Scanner Cron Job Summary\n";
    echo "================================\n";
    echo "Start Time: {$scriptStartDateTime}\n";
    echo "End Time: {$executionResults['end_time']}\n";
    echo "Execution Time: {$finalExecutionTime}s\n";
    echo "Peak Memory: {$executionResults['peak_memory_mb']}MB\n";
    echo "Status: " . ($executionResults['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "Activities: " . count($executionResults['activities']) . "\n";
    echo "Warnings: " . count($executionResults['warnings']) . "\n";
    echo "Errors: " . count($executionResults['errors']) . "\n";

    if (!empty($executionResults['warnings'])) {
        echo "\nWarnings:\n";
        foreach ($executionResults['warnings'] as $warning) {
            echo "- {$warning}\n";
        }
    }

    if (!empty($executionResults['errors'])) {
        echo "\nErrors:\n";
        foreach ($executionResults['errors'] as $error) {
            echo "- {$error}\n";
        }
    }

    echo "\n";

    // Exit with appropriate code
    exit($executionResults['success'] ? 0 : 1);

} catch (Throwable $e) {
    // Handle any uncaught exceptions
    $errorMessage = "Fatal error in scheduler cron job: " . $e->getMessage();
    $errorDetails = [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => microtime(true) - $scriptStartTime
    ];

    // Try to log the error
    try {
        if (isset($logger)) {
            $logger->critical($errorMessage, $errorDetails);
        } else {
            error_log("Security Scanner Cron Fatal Error: " . json_encode($errorDetails));
        }
    } catch (Throwable $logError) {
        // If logging fails, write to system error log
        error_log("Security Scanner Cron Fatal Error (logging failed): {$errorMessage}");
    }

    // Output error for cron logs
    echo "FATAL ERROR: {$errorMessage}\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
    echo "Memory: " . memory_get_peak_usage(true) . " bytes\n\n";

    exit(2);

} finally {
    // Always release the database lock
    if (isset($dbLock) && isset($lockName)) {
        $dbLock->release($lockName);
    }

    // Final memory cleanup
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}