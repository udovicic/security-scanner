<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class TestExecution extends AbstractModel
{
    protected string $table = 'test_executions';

    protected array $fillable = [
        'website_id',
        'execution_type',
        'status',
        'total_tests',
        'completed_tests',
        'passed_tests',
        'failed_tests',
        'error_tests',
        'skipped_tests',
        'start_time',
        'end_time',
        'execution_time_ms',
        'memory_usage_bytes',
        'trigger_user_id',
        'trigger_ip',
        'execution_context',
        'error_message',
        'notifications_sent'
    ];

    protected array $casts = [
        'id' => 'int',
        'website_id' => 'int',
        'total_tests' => 'int',
        'completed_tests' => 'int',
        'passed_tests' => 'int',
        'failed_tests' => 'int',
        'error_tests' => 'int',
        'skipped_tests' => 'int',
        'execution_time_ms' => 'int',
        'memory_usage_bytes' => 'int',
        'trigger_user_id' => 'int',
        'notifications_sent' => 'bool',
        'execution_context' => 'json'
    ];

    /**
     * Create a new test execution
     */
    public function createExecution(int $websiteId, string $executionType = 'scheduled', array $context = []): array
    {
        $data = [
            'website_id' => $websiteId,
            'execution_type' => $executionType,
            'status' => 'queued',
            'execution_context' => $context,
            'trigger_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        return $this->create($data);
    }

    /**
     * Start an execution
     */
    public function startExecution(int $executionId, int $totalTests): bool
    {
        return $this->update($executionId, [
            'status' => 'running',
            'start_time' => date('Y-m-d H:i:s'),
            'total_tests' => $totalTests,
            'completed_tests' => 0
        ]) !== null;
    }

    /**
     * Update execution progress
     */
    public function updateProgress(int $executionId, array $stats): bool
    {
        $updateData = [];

        if (isset($stats['completed_tests'])) {
            $updateData['completed_tests'] = $stats['completed_tests'];
        }
        if (isset($stats['passed_tests'])) {
            $updateData['passed_tests'] = $stats['passed_tests'];
        }
        if (isset($stats['failed_tests'])) {
            $updateData['failed_tests'] = $stats['failed_tests'];
        }
        if (isset($stats['error_tests'])) {
            $updateData['error_tests'] = $stats['error_tests'];
        }
        if (isset($stats['skipped_tests'])) {
            $updateData['skipped_tests'] = $stats['skipped_tests'];
        }

        return !empty($updateData) && $this->update($executionId, $updateData) !== null;
    }

    /**
     * Complete an execution
     */
    public function completeExecution(int $executionId, string $status = 'completed', string $errorMessage = null): bool
    {
        $execution = $this->find($executionId);
        if (!$execution) {
            return false;
        }

        $endTime = new \DateTime();
        $startTime = $execution['start_time'] ? new \DateTime($execution['start_time']) : $endTime;
        $executionTimeMs = max(0, ($endTime->getTimestamp() - $startTime->getTimestamp()) * 1000);

        $updateData = [
            'status' => $status,
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'execution_time_ms' => $executionTimeMs,
            'memory_usage_bytes' => memory_get_peak_usage()
        ];

        if ($errorMessage) {
            $updateData['error_message'] = $errorMessage;
        }

        return $this->update($executionId, $updateData) !== null;
    }

    /**
     * Get running executions
     */
    public function getRunningExecutions(): array
    {
        return $this->where(['status' => 'running']);
    }

    /**
     * Get queued executions
     */
    public function getQueuedExecutions(int $limit = 10): array
    {
        $sql = "
            SELECT * FROM {$this->table}
            WHERE status = 'queued'
            ORDER BY created_at ASC
            LIMIT {$limit}
        ";

        return $this->query($sql);
    }

    /**
     * Get recent executions for a website
     */
    public function getRecentExecutions(int $websiteId, int $limit = 20): array
    {
        $sql = "
            SELECT
                te.*,
                w.name as website_name,
                w.url as website_url
            FROM {$this->table} te
            JOIN websites w ON te.website_id = w.id
            WHERE te.website_id = ?
            ORDER BY te.created_at DESC
            LIMIT {$limit}
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Get execution statistics
     */
    public function getExecutionStatistics(int $days = 30): array
    {
        $sql = "
            SELECT
                DATE(created_at) as date,
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'timeout' THEN 1 END) as timeout,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                AVG(execution_time_ms) as avg_execution_time,
                AVG(total_tests) as avg_tests_per_execution,
                SUM(passed_tests) as total_passed,
                SUM(failed_tests) as total_failed
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get execution details with test results
     */
    public function getExecutionWithResults(int $executionId): ?array
    {
        $sql = "
            SELECT
                te.*,
                w.name as website_name,
                w.url as website_url,
                w.status as website_status
            FROM {$this->table} te
            JOIN websites w ON te.website_id = w.id
            WHERE te.id = ?
        ";

        $execution = $this->query($sql, [$executionId]);
        if (empty($execution)) {
            return null;
        }

        $execution = $execution[0];

        // Get test results
        $sql = "
            SELECT
                tr.*,
                at.display_name as test_display_name,
                at.category as test_category
            FROM test_results tr
            JOIN available_tests at ON tr.available_test_id = at.id
            WHERE tr.test_execution_id = ?
            ORDER BY tr.start_time ASC
        ";

        $execution['results'] = $this->query($sql, [$executionId]);

        return $execution;
    }

    /**
     * Cancel execution
     */
    public function cancelExecution(int $executionId, string $reason = null): bool
    {
        $execution = $this->find($executionId);
        if (!$execution || !in_array($execution['status'], ['queued', 'running'])) {
            return false;
        }

        $updateData = [
            'status' => 'cancelled',
            'end_time' => date('Y-m-d H:i:s')
        ];

        if ($reason) {
            $updateData['error_message'] = "Cancelled: {$reason}";
        }

        return $this->update($executionId, $updateData) !== null;
    }

    /**
     * Get execution queue length
     */
    public function getQueueLength(): int
    {
        return $this->count(['status' => 'queued']);
    }

    /**
     * Get active executions count
     */
    public function getActiveExecutionsCount(): int
    {
        return $this->count(['status' => 'running']);
    }

    /**
     * Check if website has running execution
     */
    public function hasRunningExecution(int $websiteId): bool
    {
        return $this->count([
            'website_id' => $websiteId,
            'status' => 'running'
        ]) > 0;
    }

    /**
     * Cleanup old executions
     */
    public function cleanupOldExecutions(int $retentionDays = 90): int
    {
        $sql = "
            DELETE FROM {$this->table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
                AND status IN ('completed', 'failed', 'cancelled', 'timeout')
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();

        $deletedCount = $stmt->rowCount();

        if ($deletedCount > 0) {
            $this->logger->info('Cleaned up old test executions', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get execution performance metrics
     */
    public function getPerformanceMetrics(int $days = 7): array
    {
        $sql = "
            SELECT
                AVG(execution_time_ms) as avg_execution_time,
                MIN(execution_time_ms) as min_execution_time,
                MAX(execution_time_ms) as max_execution_time,
                AVG(memory_usage_bytes) as avg_memory_usage,
                MAX(memory_usage_bytes) as max_memory_usage,
                AVG(total_tests) as avg_tests_per_execution,
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_executions,
                (COUNT(CASE WHEN status = 'completed' THEN 1 END) / COUNT(*)) * 100 as success_rate
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND status IN ('completed', 'failed')
        ";

        $result = $this->query($sql);
        return $result[0] ?? [];
    }

    /**
     * Get slowest executions
     */
    public function getSlowestExecutions(int $limit = 10, int $days = 30): array
    {
        $sql = "
            SELECT
                te.*,
                w.name as website_name,
                w.url as website_url
            FROM {$this->table} te
            JOIN websites w ON te.website_id = w.id
            WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND te.execution_time_ms IS NOT NULL
                AND te.status = 'completed'
            ORDER BY te.execution_time_ms DESC
            LIMIT {$limit}
        ";

        return $this->query($sql);
    }

    /**
     * Mark notifications as sent
     */
    public function markNotificationsSent(int $executionId): bool
    {
        return $this->update($executionId, ['notifications_sent' => true]) !== null;
    }

    /**
     * Get executions needing notifications
     */
    public function getExecutionsNeedingNotifications(): array
    {
        $sql = "
            SELECT
                te.*,
                w.name as website_name,
                w.url as website_url,
                w.notification_email
            FROM {$this->table} te
            JOIN websites w ON te.website_id = w.id
            WHERE te.notifications_sent = 0
                AND te.status IN ('completed', 'failed', 'timeout')
                AND w.notification_email IS NOT NULL
                AND w.notification_email != ''
            ORDER BY te.end_time ASC
        ";

        return $this->query($sql);
    }
}