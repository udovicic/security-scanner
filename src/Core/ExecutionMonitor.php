<?php

namespace SecurityScanner\Core;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;

class ExecutionMonitor
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private array $activeExecutions = [];
    private float $startTime;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();
        $this->startTime = microtime(true);

        $this->config = array_merge([
            'max_execution_time' => 3600, // 1 hour
            'memory_limit_warning' => 80, // 80% of memory limit
            'log_interval' => 300, // Log status every 5 minutes
            'metrics_retention_days' => 30,
            'alert_thresholds' => [
                'failure_rate' => 50, // Alert if failure rate > 50%
                'avg_execution_time' => 300, // Alert if avg execution > 5 minutes
                'memory_usage' => 90 // Alert if memory usage > 90%
            ]
        ], $config);
    }

    /**
     * Start monitoring an execution
     */
    public function startExecution(string $executionId, array $metadata = []): void
    {
        $execution = [
            'id' => $executionId,
            'start_time' => microtime(true),
            'metadata' => $metadata,
            'checkpoints' => [],
            'warnings' => [],
            'errors' => []
        ];

        $this->activeExecutions[$executionId] = $execution;

        // Log to database
        $sql = "INSERT INTO execution_monitoring (execution_id, type, start_time, status, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?)";
        $this->db->query($sql, [
            $executionId,
            $metadata['type'] ?? 'general',
            date('Y-m-d H:i:s'),
            'started',
            json_encode($metadata),
            date('Y-m-d H:i:s')
        ]);

        $this->logger->info('Execution monitoring started', [
            'execution_id' => $executionId,
            'metadata' => $metadata
        ]);
    }

    /**
     * Add a checkpoint to track progress
     */
    public function checkpoint(string $executionId, string $checkpoint, array $data = []): void
    {
        if (!isset($this->activeExecutions[$executionId])) {
            $this->logger->warning('Checkpoint for non-existent execution', [
                'execution_id' => $executionId,
                'checkpoint' => $checkpoint
            ]);
            return;
        }

        $checkpointData = [
            'name' => $checkpoint,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'data' => $data
        ];

        $this->activeExecutions[$executionId]['checkpoints'][] = $checkpointData;

        // Log significant checkpoints
        if (in_array($checkpoint, ['batch_started', 'batch_completed', 'scan_completed', 'cleanup_started'])) {
            $sql = "INSERT INTO execution_checkpoints (execution_id, checkpoint_name, timestamp, memory_usage, data, created_at) VALUES (?, ?, ?, ?, ?, ?)";
            $this->db->query($sql, [
                $executionId,
                $checkpoint,
                date('Y-m-d H:i:s'),
                $checkpointData['memory_usage'],
                json_encode($data),
                date('Y-m-d H:i:s')
            ]);
        }

        $this->logger->debug('Execution checkpoint', [
            'execution_id' => $executionId,
            'checkpoint' => $checkpoint,
            'memory_mb' => round($checkpointData['memory_usage'] / (1024*1024), 2),
            'data' => $data
        ]);
    }

    /**
     * Record a warning during execution
     */
    public function warning(string $executionId, string $message, array $context = []): void
    {
        if (!isset($this->activeExecutions[$executionId])) {
            return;
        }

        $warning = [
            'message' => $message,
            'timestamp' => microtime(true),
            'context' => $context
        ];

        $this->activeExecutions[$executionId]['warnings'][] = $warning;

        $this->logger->warning($message, array_merge($context, [
            'execution_id' => $executionId
        ]));
    }

    /**
     * Record an error during execution
     */
    public function error(string $executionId, string $message, array $context = []): void
    {
        if (!isset($this->activeExecutions[$executionId])) {
            return;
        }

        $error = [
            'message' => $message,
            'timestamp' => microtime(true),
            'context' => $context
        ];

        $this->activeExecutions[$executionId]['errors'][] = $error;

        $this->logger->error($message, array_merge($context, [
            'execution_id' => $executionId
        ]));
    }

    /**
     * Complete an execution
     */
    public function completeExecution(string $executionId, bool $success = true, array $finalData = []): array
    {
        if (!isset($this->activeExecutions[$executionId])) {
            $this->logger->warning('Completing non-existent execution', [
                'execution_id' => $executionId
            ]);
            return [];
        }

        $execution = $this->activeExecutions[$executionId];
        $endTime = microtime(true);
        $totalTime = $endTime - $execution['start_time'];

        $summary = [
            'execution_id' => $executionId,
            'success' => $success,
            'total_time' => round($totalTime, 3),
            'checkpoints_count' => count($execution['checkpoints']),
            'warnings_count' => count($execution['warnings']),
            'errors_count' => count($execution['errors']),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / (1024*1024), 2),
            'final_data' => $finalData
        ];

        // Update database record
        $sql = "UPDATE execution_monitoring SET end_time = ?, status = ?, execution_time = ?, checkpoints_count = ?, warnings_count = ?, errors_count = ?, peak_memory = ?, final_data = ?, updated_at = ? WHERE execution_id = ?";
        $this->db->query($sql, [
            date('Y-m-d H:i:s'),
            $success ? 'completed' : 'failed',
            $totalTime,
            $summary['checkpoints_count'],
            $summary['warnings_count'],
            $summary['errors_count'],
            $summary['peak_memory_mb'],
            json_encode($finalData),
            date('Y-m-d H:i:s'),
            $executionId
        ]);

        $this->logger->info('Execution monitoring completed', $summary);

        // Remove from active executions
        unset($this->activeExecutions[$executionId]);

        return $summary;
    }

    /**
     * Monitor system resources during execution
     */
    public function monitorResources(string $executionId): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryUsagePercent = ($currentMemory / $memoryLimit) * 100;

        $cpuUsage = $this->getCpuUsage();
        $executionTime = microtime(true) - $this->startTime;

        $resourceData = [
            'memory_usage_mb' => round($currentMemory / (1024*1024), 2),
            'peak_memory_mb' => round($peakMemory / (1024*1024), 2),
            'memory_usage_percent' => round($memoryUsagePercent, 1),
            'cpu_usage_percent' => $cpuUsage,
            'execution_time' => round($executionTime, 2),
            'timestamp' => microtime(true)
        ];

        // Check for resource warnings
        if ($memoryUsagePercent > $this->config['memory_limit_warning']) {
            $this->warning($executionId, 'High memory usage detected', $resourceData);
        }

        if ($executionTime > $this->config['max_execution_time'] * 0.8) {
            $this->warning($executionId, 'Execution time approaching limit', $resourceData);
        }

        return $resourceData;
    }

    /**
     * Get execution statistics
     */
    public function getExecutionStatistics(int $days = 7): array
    {
        $sql = "
            SELECT
                DATE(start_time) as date,
                COUNT(*) as total_executions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_executions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_executions,
                AVG(execution_time) as avg_execution_time,
                AVG(peak_memory) as avg_memory_usage,
                SUM(warnings_count) as total_warnings,
                SUM(errors_count) as total_errors
            FROM execution_monitoring
            WHERE start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(start_time)
            ORDER BY date DESC
        ";

        $stmt = $this->db->query($sql, [$days]);
        $dailyStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Calculate overall statistics
        $sql = "
            SELECT
                COUNT(*) as total_executions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed,
                AVG(execution_time) as overall_avg_time,
                MAX(execution_time) as max_execution_time,
                AVG(peak_memory) as avg_memory,
                MAX(peak_memory) as max_memory
            FROM execution_monitoring
            WHERE start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        $stmt = $this->db->query($sql, [$days]);
        $overallStats = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'period_days' => $days,
            'daily_statistics' => $dailyStats,
            'overall_statistics' => $overallStats,
            'success_rate' => $overallStats['total_executions'] > 0
                ? round(($overallStats['total_successful'] / $overallStats['total_executions']) * 100, 1)
                : 0,
            'failure_rate' => $overallStats['total_executions'] > 0
                ? round(($overallStats['total_failed'] / $overallStats['total_executions']) * 100, 1)
                : 0
        ];
    }

    /**
     * Get current active executions
     */
    public function getActiveExecutions(): array
    {
        $active = [];
        foreach ($this->activeExecutions as $executionId => $execution) {
            $active[] = [
                'execution_id' => $executionId,
                'runtime' => round(microtime(true) - $execution['start_time'], 2),
                'checkpoints' => count($execution['checkpoints']),
                'warnings' => count($execution['warnings']),
                'errors' => count($execution['errors']),
                'metadata' => $execution['metadata']
            ];
        }
        return $active;
    }

    /**
     * Cleanup old monitoring data
     */
    public function cleanup(): int
    {
        $sql = "DELETE FROM execution_monitoring WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $this->db->query($sql, [$this->config['metrics_retention_days']]);
        $deletedMonitoring = $stmt->rowCount();

        $sql = "DELETE FROM execution_checkpoints WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $this->db->query($sql, [$this->config['metrics_retention_days']]);
        $deletedCheckpoints = $stmt->rowCount();

        $this->logger->info('Execution monitoring cleanup completed', [
            'deleted_monitoring_records' => $deletedMonitoring,
            'deleted_checkpoint_records' => $deletedCheckpoints,
            'retention_days' => $this->config['metrics_retention_days']
        ]);

        return $deletedMonitoring + $deletedCheckpoints;
    }

    /**
     * Check if alerts should be triggered
     */
    public function checkAlerts(): array
    {
        $alerts = [];
        $stats = $this->getExecutionStatistics(1); // Last 24 hours

        if (!empty($stats['overall_statistics'])) {
            $overall = $stats['overall_statistics'];

            // Check failure rate
            if ($stats['failure_rate'] > $this->config['alert_thresholds']['failure_rate']) {
                $alerts[] = [
                    'type' => 'high_failure_rate',
                    'message' => "High failure rate detected: {$stats['failure_rate']}%",
                    'severity' => 'critical',
                    'data' => ['failure_rate' => $stats['failure_rate']]
                ];
            }

            // Check average execution time
            if ($overall['overall_avg_time'] > $this->config['alert_thresholds']['avg_execution_time']) {
                $alerts[] = [
                    'type' => 'slow_execution',
                    'message' => "Slow execution time detected: {$overall['overall_avg_time']}s",
                    'severity' => 'warning',
                    'data' => ['avg_time' => $overall['overall_avg_time']]
                ];
            }

            // Check memory usage
            if ($overall['avg_memory'] > $this->config['alert_thresholds']['memory_usage']) {
                $alerts[] = [
                    'type' => 'high_memory_usage',
                    'message' => "High memory usage detected: {$overall['avg_memory']}MB",
                    'severity' => 'warning',
                    'data' => ['avg_memory' => $overall['avg_memory']]
                ];
            }
        }

        return $alerts;
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $memoryLimit;
        }
    }

    /**
     * Get CPU usage percentage (approximation)
     */
    private function getCpuUsage(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return round($load[0] * 100, 1);
        }
        return 0.0;
    }
}