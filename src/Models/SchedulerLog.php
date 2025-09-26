<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class SchedulerLog extends AbstractModel
{
    protected string $table = 'scheduler_log';
    protected bool $timestamps = false;

    protected array $fillable = [
        'website_id',
        'test_execution_id',
        'event_type',
        'message',
        'details',
        'execution_time_ms',
        'memory_usage_bytes',
        'process_id',
        'severity'
    ];

    protected array $casts = [
        'id' => 'int',
        'website_id' => 'int',
        'test_execution_id' => 'int',
        'execution_time_ms' => 'int',
        'memory_usage_bytes' => 'int',
        'process_id' => 'int',
        'details' => 'json'
    ];

    /**
     * Log scheduler start event
     */
    public function logSchedulerStart(string $batchId, array $context = []): array
    {
        return $this->create([
            'event_type' => 'start',
            'message' => 'Scheduler execution started',
            'memory_usage_bytes' => memory_get_usage(),
            'process_id' => getmypid(),
            'details' => array_merge(['batch_id' => $batchId], $context),
            'severity' => 'info'
        ]);
    }

    /**
     * Log scheduler completion
     */
    public function logSchedulerComplete(string $batchId, array $stats): array
    {
        return $this->create([
            'event_type' => 'stop',
            'message' => sprintf(
                'Scheduler completed: %d websites processed, %d executions created',
                $stats['websites_processed'] ?? 0,
                $stats['executions_created'] ?? 0
            ),
            'execution_time_ms' => $stats['execution_time_ms'] ?? 0,
            'memory_usage_bytes' => memory_get_peak_usage(),
            'process_id' => getmypid(),
            'details' => array_merge(['batch_id' => $batchId], $stats),
            'severity' => 'info'
        ]);
    }

    /**
     * Log website scheduling event
     */
    public function logWebsiteScheduled(string $batchId, int $websiteId, int $executionId): array
    {
        return $this->create([
            'website_id' => $websiteId,
            'test_execution_id' => $executionId,
            'event_type' => 'scan_queued',
            'message' => 'Website scheduled for testing',
            'process_id' => getmypid(),
            'details' => ['batch_id' => $batchId],
            'severity' => 'info'
        ]);
    }

    /**
     * Log error event
     */
    public function logError(string $batchId, string $message, array $errorDetails = [], ?int $websiteId = null): array
    {
        return $this->create([
            'website_id' => $websiteId,
            'event_type' => 'error',
            'message' => $message,
            'details' => array_merge(['batch_id' => $batchId], $errorDetails),
            'memory_usage_bytes' => memory_get_usage(),
            'process_id' => getmypid(),
            'severity' => 'error'
        ]);
    }

    /**
     * Log warning event
     */
    public function logWarning(string $batchId, string $message, array $context = [], ?int $websiteId = null): array
    {
        return $this->create([
            'website_id' => $websiteId,
            'event_type' => 'maintenance',
            'message' => $message,
            'details' => array_merge(['batch_id' => $batchId], $context),
            'process_id' => getmypid(),
            'severity' => 'warning'
        ]);
    }

    /**
     * Log system status
     */
    public function logSystemStatus(string $batchId, array $systemStats): array
    {
        return $this->create([
            'event_type' => 'maintenance',
            'message' => 'System status check',
            'memory_usage_bytes' => $systemStats['memory_usage'] ?? memory_get_usage(),
            'process_id' => getmypid(),
            'details' => array_merge(['batch_id' => $batchId], $systemStats),
            'severity' => 'info'
        ]);
    }

    /**
     * Get scheduler execution history
     */
    public function getExecutionHistory(int $days = 30): array
    {
        $sql = "
            SELECT
                JSON_EXTRACT(details, '$.batch_id') as batch_id,
                MIN(created_at) as start_time,
                MAX(created_at) as end_time,
                COUNT(*) as total_events,
                COUNT(CASE WHEN event_type = 'scan_queued' THEN 1 END) as websites_scheduled,
                COUNT(CASE WHEN severity = 'error' THEN 1 END) as errors,
                COUNT(CASE WHEN severity = 'warning' THEN 1 END) as warnings,
                MAX(memory_usage_bytes) as peak_memory
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND JSON_EXTRACT(details, '$.batch_id') IS NOT NULL
            GROUP BY JSON_EXTRACT(details, '$.batch_id')
            ORDER BY start_time DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get error logs for troubleshooting
     */
    public function getErrorLogs(int $days = 7): array
    {
        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE severity = 'error'
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY created_at DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(int $days = 30): array
    {
        $sql = "
            SELECT
                DATE(created_at) as date,
                COUNT(DISTINCT execution_batch_id) as scheduler_runs,
                COUNT(CASE WHEN event_type = 'website_scheduled' THEN 1 END) as total_websites_scheduled,
                AVG(memory_usage_bytes) as avg_memory_usage,
                MAX(memory_usage_bytes) as peak_memory_usage,
                AVG(system_load) as avg_system_load,
                AVG(queue_size) as avg_queue_size,
                COUNT(CASE WHEN severity = 'error' THEN 1 END) as error_count
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get batch execution details
     */
    public function getBatchExecutionDetails(string $batchId): array
    {
        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE execution_batch_id = ?
            ORDER BY created_at ASC
        ";

        return $this->query($sql, [$batchId]);
    }

    /**
     * Get system health indicators
     */
    public function getSystemHealthIndicators(int $hours = 24): array
    {
        $sql = "
            SELECT
                COUNT(DISTINCT execution_batch_id) as scheduler_runs,
                COUNT(CASE WHEN severity = 'error' THEN 1 END) as total_errors,
                COUNT(CASE WHEN severity = 'warning' THEN 1 END) as total_warnings,
                AVG(memory_usage_bytes) as avg_memory_usage,
                MAX(memory_usage_bytes) as peak_memory_usage,
                AVG(system_load) as avg_system_load,
                MAX(system_load) as peak_system_load,
                AVG(queue_size) as avg_queue_size,
                MAX(queue_size) as max_queue_size
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
        ";

        $result = $this->query($sql);
        return $result[0] ?? [];
    }

    /**
     * Get problematic websites (frequent errors)
     */
    public function getProblematicWebsites(int $days = 7): array
    {
        $sql = "
            SELECT
                sl.website_id,
                w.name as website_name,
                w.url as website_url,
                COUNT(*) as error_count,
                MAX(sl.created_at) as latest_error,
                GROUP_CONCAT(DISTINCT sl.event_message ORDER BY sl.created_at DESC LIMIT 3) as recent_errors
            FROM {$this->table} sl
            JOIN websites w ON sl.website_id = w.id
            WHERE sl.severity = 'error'
                AND sl.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY sl.website_id
            HAVING error_count > 2
            ORDER BY error_count DESC, latest_error DESC
        ";

        return $this->query($sql);
    }

    /**
     * Cleanup old log entries
     */
    public function cleanupOldLogs(int $retentionDays = 90): int
    {
        $sql = "
            DELETE FROM {$this->table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();

        $deletedCount = $stmt->rowCount();

        if ($deletedCount > 0) {
            $this->logger->info('Cleaned up old scheduler logs', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get scheduler execution trends
     */
    public function getExecutionTrends(int $days = 30): array
    {
        $sql = "
            SELECT
                DATE(created_at) as date,
                HOUR(created_at) as hour,
                COUNT(CASE WHEN event_type = 'scheduler_start' THEN 1 END) as scheduler_starts,
                COUNT(CASE WHEN event_type = 'website_scheduled' THEN 1 END) as websites_scheduled,
                AVG(execution_time_ms) as avg_execution_time,
                AVG(memory_usage_bytes) as avg_memory_usage
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY DATE(created_at), HOUR(created_at)
            ORDER BY date DESC, hour DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get current system load
     */
    private function getSystemLoad(): ?float
    {
        if (function_exists('sys_getloadavg') && PHP_OS_FAMILY !== 'Windows') {
            $load = sys_getloadavg();
            return $load[0] ?? null; // 1-minute average
        }
        return null;
    }

    /**
     * Check if batch execution is complete
     */
    public function isBatchComplete(string $batchId): bool
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM {$this->table}
            WHERE execution_batch_id = ?
                AND event_type IN ('scheduler_complete', 'scheduler_failed')
        ";

        $result = $this->query($sql, [$batchId]);
        return ($result[0]['count'] ?? 0) > 0;
    }

    /**
     * Get active scheduler batches
     */
    public function getActiveBatches(): array
    {
        $sql = "
            SELECT
                execution_batch_id,
                MIN(created_at) as start_time,
                MAX(created_at) as last_activity,
                COUNT(*) as event_count,
                COUNT(CASE WHEN event_type = 'website_scheduled' THEN 1 END) as websites_scheduled
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY execution_batch_id
            HAVING COUNT(CASE WHEN event_type IN ('scheduler_complete', 'scheduler_failed') THEN 1 END) = 0
            ORDER BY start_time DESC
        ";

        return $this->query($sql);
    }
}