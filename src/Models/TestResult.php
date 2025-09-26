<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class TestResult extends AbstractModel
{
    protected string $table = 'test_results';

    protected array $fillable = [
        'test_execution_id',
        'website_id',
        'available_test_id',
        'test_name',
        'status',
        'severity',
        'score',
        'message',
        'details',
        'evidence',
        'recommendations',
        'external_references',
        'start_time',
        'end_time',
        'execution_time_ms',
        'retry_count',
        'request_url',
        'response_status',
        'response_headers',
        'response_size',
        'ssl_info',
        'raw_output'
    ];

    protected array $casts = [
        'id' => 'int',
        'test_execution_id' => 'int',
        'website_id' => 'int',
        'available_test_id' => 'int',
        'score' => 'float',
        'execution_time_ms' => 'int',
        'retry_count' => 'int',
        'response_status' => 'int',
        'response_size' => 'int',
        'details' => 'json',
        'evidence' => 'json',
        'external_references' => 'json',
        'response_headers' => 'json',
        'ssl_info' => 'json'
    ];

    /**
     * Create a new test result
     */
    public function createResult(int $executionId, int $websiteId, int $testId, array $resultData): array
    {
        $data = array_merge([
            'test_execution_id' => $executionId,
            'website_id' => $websiteId,
            'available_test_id' => $testId,
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => date('Y-m-d H:i:s'),
            'execution_time_ms' => 0,
            'retry_count' => 0
        ], $resultData);

        return $this->create($data);
    }

    /**
     * Get results for a specific execution
     */
    public function getExecutionResults(int $executionId): array
    {
        $sql = "
            SELECT
                tr.*,
                at.display_name as test_display_name,
                at.category as test_category,
                at.description as test_description
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            WHERE tr.test_execution_id = ?
            ORDER BY tr.start_time ASC
        ";

        return $this->query($sql, [$executionId]);
    }

    /**
     * Get critical security findings
     */
    public function getCriticalFindings(int $days = 7): array
    {
        $sql = "
            SELECT * FROM critical_findings
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY
                CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 END,
                created_at DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get test results history for a website
     */
    public function getWebsiteResultsHistory(int $websiteId, int $limit = 50): array
    {
        $sql = "
            SELECT
                tr.*,
                at.display_name as test_display_name,
                at.category as test_category,
                te.execution_type
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            JOIN test_executions te ON tr.test_execution_id = te.id
            WHERE tr.website_id = ?
            ORDER BY tr.created_at DESC
            LIMIT {$limit}
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Get security score trend for a website
     */
    public function getSecurityScoreTrend(int $websiteId, int $days = 30): array
    {
        $sql = "
            SELECT
                DATE(tr.created_at) as date,
                AVG(CASE
                    WHEN tr.status = 'passed' THEN 100
                    WHEN tr.status = 'failed' AND tr.severity = 'critical' THEN 0
                    WHEN tr.status = 'failed' AND tr.severity = 'high' THEN 25
                    WHEN tr.status = 'failed' AND tr.severity = 'medium' THEN 50
                    WHEN tr.status = 'failed' AND tr.severity = 'low' THEN 75
                    WHEN tr.status = 'failed' AND tr.severity = 'info' THEN 90
                    ELSE 50
                END) as security_score,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN tr.status = 'passed' THEN 1 END) as passed_tests,
                COUNT(CASE WHEN tr.status = 'failed' THEN 1 END) as failed_tests
            FROM {$this->table} tr
            JOIN test_executions te ON tr.test_execution_id = te.id
            WHERE tr.website_id = ?
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND te.status = 'completed'
            GROUP BY DATE(tr.created_at)
            ORDER BY date DESC
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Get vulnerability statistics
     */
    public function getVulnerabilityStatistics(int $days = 30): array
    {
        $sql = "
            SELECT
                tr.severity,
                at.category,
                COUNT(*) as count,
                COUNT(DISTINCT tr.website_id) as affected_websites
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            WHERE tr.status = 'failed'
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY tr.severity, at.category
            ORDER BY
                CASE tr.severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                    WHEN 'info' THEN 5
                END,
                count DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get test performance statistics
     */
    public function getTestPerformanceStats(int $testId, int $days = 30): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_runs,
                AVG(execution_time_ms) as avg_execution_time,
                MIN(execution_time_ms) as min_execution_time,
                MAX(execution_time_ms) as max_execution_time,
                COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_runs,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                COUNT(CASE WHEN status = 'error' THEN 1 END) as error_runs,
                COUNT(CASE WHEN status = 'timeout' THEN 1 END) as timeout_runs,
                AVG(CASE WHEN score IS NOT NULL THEN score END) as avg_score
            FROM {$this->table}
            WHERE available_test_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        ";

        $result = $this->query($sql, [$testId]);
        return $result[0] ?? [];
    }

    /**
     * Get failing tests summary
     */
    public function getFailingTestsSummary(int $days = 7): array
    {
        $sql = "
            SELECT
                at.name as test_name,
                at.display_name,
                at.category,
                tr.severity,
                COUNT(*) as failure_count,
                COUNT(DISTINCT tr.website_id) as affected_websites,
                MAX(tr.created_at) as latest_failure
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            WHERE tr.status = 'failed'
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY at.id, tr.severity
            HAVING failure_count > 1
            ORDER BY
                CASE tr.severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                    WHEN 'info' THEN 5
                END,
                failure_count DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get results comparison between two time periods
     */
    public function getResultsComparison(int $websiteId, int $currentDays = 7, int $previousDays = 14): array
    {
        $sql = "
            SELECT
                'current' as period,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_tests,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_tests,
                AVG(CASE WHEN status = 'passed' THEN 100 ELSE 0 END) as success_rate
            FROM {$this->table}
            WHERE website_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$currentDays} DAY)

            UNION ALL

            SELECT
                'previous' as period,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_tests,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_tests,
                AVG(CASE WHEN status = 'passed' THEN 100 ELSE 0 END) as success_rate
            FROM {$this->table}
            WHERE website_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$previousDays} DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL {$currentDays} DAY)
        ";

        return $this->query($sql, [$websiteId, $websiteId]);
    }

    /**
     * Search results by criteria
     */
    public function searchResults(array $criteria, int $limit = 100): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($criteria['website_id'])) {
            $whereClauses[] = 'tr.website_id = ?';
            $params[] = $criteria['website_id'];
        }

        if (!empty($criteria['test_id'])) {
            $whereClauses[] = 'tr.available_test_id = ?';
            $params[] = $criteria['test_id'];
        }

        if (!empty($criteria['status'])) {
            if (is_array($criteria['status'])) {
                $placeholders = str_repeat('?,', count($criteria['status']) - 1) . '?';
                $whereClauses[] = "tr.status IN ({$placeholders})";
                $params = array_merge($params, $criteria['status']);
            } else {
                $whereClauses[] = 'tr.status = ?';
                $params[] = $criteria['status'];
            }
        }

        if (!empty($criteria['severity'])) {
            if (is_array($criteria['severity'])) {
                $placeholders = str_repeat('?,', count($criteria['severity']) - 1) . '?';
                $whereClauses[] = "tr.severity IN ({$placeholders})";
                $params = array_merge($params, $criteria['severity']);
            } else {
                $whereClauses[] = 'tr.severity = ?';
                $params[] = $criteria['severity'];
            }
        }

        if (!empty($criteria['category'])) {
            $whereClauses[] = 'at.category = ?';
            $params[] = $criteria['category'];
        }

        if (!empty($criteria['date_from'])) {
            $whereClauses[] = 'tr.created_at >= ?';
            $params[] = $criteria['date_from'];
        }

        if (!empty($criteria['date_to'])) {
            $whereClauses[] = 'tr.created_at <= ?';
            $params[] = $criteria['date_to'];
        }

        if (!empty($criteria['message_contains'])) {
            $whereClauses[] = 'tr.message LIKE ?';
            $params[] = '%' . $criteria['message_contains'] . '%';
        }

        $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT
                tr.*,
                at.display_name as test_display_name,
                at.category as test_category,
                w.name as website_name,
                w.url as website_url
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            JOIN websites w ON tr.website_id = w.id
            {$whereClause}
            ORDER BY tr.created_at DESC
            LIMIT {$limit}
        ";

        return $this->query($sql, $params);
    }

    /**
     * Get results that need attention (failed/error with high/critical severity)
     */
    public function getResultsNeedingAttention(int $days = 7): array
    {
        $sql = "
            SELECT
                tr.*,
                at.display_name as test_display_name,
                at.category as test_category,
                w.name as website_name,
                w.url as website_url,
                te.execution_type
            FROM {$this->table} tr
            JOIN available_tests at ON tr.available_test_id = at.id
            JOIN websites w ON tr.website_id = w.id
            JOIN test_executions te ON tr.test_execution_id = te.id
            WHERE tr.status IN ('failed', 'error')
                AND tr.severity IN ('high', 'critical')
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                AND w.status = 'active'
            ORDER BY
                CASE tr.severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 END,
                tr.created_at DESC
        ";

        return $this->query($sql);
    }

    /**
     * Archive old results
     */
    public function archiveOldResults(int $archiveDays = 180): int
    {
        // This could move old results to an archive table
        // For now, we'll just delete them as per the schema cleanup policy
        $sql = "
            DELETE FROM {$this->table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL {$archiveDays} DAY)
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();

        $deletedCount = $stmt->rowCount();

        if ($deletedCount > 0) {
            $this->logger->info('Archived old test results', [
                'deleted_count' => $deletedCount,
                'archive_days' => $archiveDays
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get SSL certificate information from results
     */
    public function getSSLCertificateInfo(int $websiteId): ?array
    {
        $sql = "
            SELECT ssl_info
            FROM {$this->table}
            WHERE website_id = ?
                AND ssl_info IS NOT NULL
                AND status = 'passed'
            ORDER BY created_at DESC
            LIMIT 1
        ";

        $result = $this->query($sql, [$websiteId]);
        return $result[0]['ssl_info'] ?? null;
    }

    /**
     * Get trend analysis for specific test type
     */
    public function getTestTrend(int $websiteId, int $testId, int $days = 30): array
    {
        $sql = "
            SELECT
                DATE(created_at) as date,
                status,
                severity,
                score,
                execution_time_ms,
                message
            FROM {$this->table}
            WHERE website_id = ?
                AND available_test_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY created_at ASC
        ";

        return $this->query($sql, [$websiteId, $testId]);
    }
}