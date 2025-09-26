<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;

class MetricsService
{
    private Database $db;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'retention_days' => 365,
            'aggregation_intervals' => ['hourly', 'daily', 'weekly', 'monthly'],
            'cache_ttl' => 300, // 5 minutes
            'performance_thresholds' => [
                'slow_scan' => 30, // seconds
                'very_slow_scan' => 60, // seconds
                'low_success_rate' => 0.8,
                'critical_success_rate' => 0.5
            ]
        ], $config);
    }

    /**
     * Record scan metrics
     */
    public function recordScanMetrics(array $scanData): void
    {
        $this->db->insert('scan_metrics', [
            'website_id' => $scanData['website_id'],
            'scan_id' => $scanData['scan_id'],
            'total_tests' => $scanData['total_tests'] ?? 0,
            'passed_tests' => $scanData['passed_tests'] ?? 0,
            'failed_tests' => $scanData['failed_tests'] ?? 0,
            'execution_time' => $scanData['execution_time'] ?? 0,
            'memory_usage' => $scanData['memory_usage'] ?? 0,
            'success_rate' => $scanData['success_rate'] ?? 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get website performance metrics
     */
    public function getWebsiteMetrics(int $websiteId, string $period = '7d'): array
    {
        $dateCondition = $this->getPeriodDateCondition($period);

        $metrics = [
            'website_id' => $websiteId,
            'period' => $period,
            'scan_summary' => $this->getScanSummary($websiteId, $dateCondition),
            'performance_trends' => $this->getPerformanceTrends($websiteId, $dateCondition),
            'test_results' => $this->getTestResultMetrics($websiteId, $dateCondition),
            'reliability_score' => $this->calculateReliabilityScore($websiteId, $dateCondition),
            'recent_issues' => $this->getRecentIssues($websiteId, $dateCondition)
        ];

        return $metrics;
    }

    /**
     * Get system-wide metrics
     */
    public function getSystemMetrics(string $period = '7d'): array
    {
        $dateCondition = $this->getPeriodDateCondition($period);

        return [
            'period' => $period,
            'overview' => $this->getSystemOverview($dateCondition),
            'performance' => $this->getSystemPerformance($dateCondition),
            'reliability' => $this->getSystemReliability($dateCondition),
            'resource_usage' => $this->getResourceUsage($dateCondition),
            'top_performing_websites' => $this->getTopPerformingWebsites($dateCondition),
            'problematic_websites' => $this->getProblematicWebsites($dateCondition),
            'test_statistics' => $this->getTestStatistics($dateCondition)
        ];
    }

    /**
     * Get real-time dashboard metrics
     */
    public function getDashboardMetrics(): array
    {
        $cacheKey = 'dashboard_metrics';
        $cached = $this->getCachedMetrics($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $metrics = [
            'current_status' => $this->getCurrentStatus(),
            'today_summary' => $this->getTodaySummary(),
            'recent_activity' => $this->getRecentActivity(),
            'active_alerts' => $this->getActiveAlerts(),
            'quick_stats' => $this->getQuickStats(),
            'system_health' => $this->getSystemHealth()
        ];

        $this->cacheMetrics($cacheKey, $metrics);

        return $metrics;
    }

    /**
     * Get performance trends over time
     */
    public function getPerformanceTrends(int $websiteId, string $dateCondition): array
    {
        $trends = [
            'execution_time' => [],
            'success_rate' => [],
            'test_count' => []
        ];

        $results = $this->db->fetchAll(
            "SELECT
                DATE(created_at) as date,
                AVG(execution_time) as avg_execution_time,
                AVG(success_rate) as avg_success_rate,
                AVG(total_tests) as avg_test_count,
                COUNT(*) as scan_count
             FROM scan_metrics
             WHERE website_id = ? AND {$dateCondition}
             GROUP BY DATE(created_at)
             ORDER BY date",
            [$websiteId]
        );

        foreach ($results as $result) {
            $trends['execution_time'][] = [
                'date' => $result['date'],
                'value' => round($result['avg_execution_time'], 2),
                'count' => (int)$result['scan_count']
            ];

            $trends['success_rate'][] = [
                'date' => $result['date'],
                'value' => round($result['avg_success_rate'] * 100, 1),
                'count' => (int)$result['scan_count']
            ];

            $trends['test_count'][] = [
                'date' => $result['date'],
                'value' => round($result['avg_test_count'], 1),
                'count' => (int)$result['scan_count']
            ];
        }

        return $trends;
    }

    /**
     * Calculate reliability score for a website
     */
    public function calculateReliabilityScore(int $websiteId, string $dateCondition): array
    {
        $metrics = $this->db->fetchRow(
            "SELECT
                AVG(success_rate) as avg_success_rate,
                STDDEV(success_rate) as success_rate_variance,
                COUNT(*) as total_scans,
                SUM(CASE WHEN success_rate >= 0.9 THEN 1 ELSE 0 END) as excellent_scans,
                SUM(CASE WHEN success_rate < 0.5 THEN 1 ELSE 0 END) as poor_scans,
                AVG(execution_time) as avg_execution_time,
                MAX(execution_time) as max_execution_time
             FROM scan_metrics
             WHERE website_id = ? AND {$dateCondition}",
            [$websiteId]
        );

        if (!$metrics || $metrics['total_scans'] == 0) {
            return [
                'score' => 0,
                'grade' => 'N/A',
                'factors' => [],
                'recommendations' => ['No data available for analysis']
            ];
        }

        $score = 100;
        $factors = [];
        $recommendations = [];

        // Success rate factor (40% weight)
        $successRateScore = $metrics['avg_success_rate'] * 40;
        $score = min($score, $successRateScore + 60);
        $factors['success_rate'] = [
            'value' => round($metrics['avg_success_rate'] * 100, 1),
            'score' => round($successRateScore, 1),
            'weight' => 40
        ];

        if ($metrics['avg_success_rate'] < 0.9) {
            $recommendations[] = 'Improve test success rate by addressing failing tests';
        }

        // Consistency factor (25% weight)
        $variance = $metrics['success_rate_variance'] ?? 0;
        $consistencyScore = max(0, 25 - ($variance * 100));
        $score = min($score, $score * (1 - $variance));
        $factors['consistency'] = [
            'variance' => round($variance, 3),
            'score' => round($consistencyScore, 1),
            'weight' => 25
        ];

        if ($variance > 0.2) {
            $recommendations[] = 'Improve consistency by stabilizing test environment';
        }

        // Performance factor (20% weight)
        $performanceThreshold = $this->config['performance_thresholds']['slow_scan'];
        $performanceScore = max(0, 20 * (1 - ($metrics['avg_execution_time'] / $performanceThreshold)));
        $factors['performance'] = [
            'avg_time' => round($metrics['avg_execution_time'], 2),
            'max_time' => round($metrics['max_execution_time'], 2),
            'score' => round($performanceScore, 1),
            'weight' => 20
        ];

        if ($metrics['avg_execution_time'] > $performanceThreshold) {
            $recommendations[] = 'Optimize test performance to reduce execution time';
        }

        // Data availability factor (15% weight)
        $expectedScans = $this->calculateExpectedScans($websiteId, $dateCondition);
        $availabilityScore = min(15, ($metrics['total_scans'] / max($expectedScans, 1)) * 15);
        $factors['availability'] = [
            'actual_scans' => (int)$metrics['total_scans'],
            'expected_scans' => $expectedScans,
            'score' => round($availabilityScore, 1),
            'weight' => 15
        ];

        $finalScore = $successRateScore + $consistencyScore + $performanceScore + $availabilityScore;

        // Determine grade
        $grade = 'F';
        if ($finalScore >= 90) $grade = 'A';
        elseif ($finalScore >= 80) $grade = 'B';
        elseif ($finalScore >= 70) $grade = 'C';
        elseif ($finalScore >= 60) $grade = 'D';

        return [
            'score' => round($finalScore, 1),
            'grade' => $grade,
            'factors' => $factors,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Get test result metrics breakdown
     */
    private function getTestResultMetrics(int $websiteId, string $dateCondition): array
    {
        return $this->db->fetchAll(
            "SELECT
                te.test_name,
                COUNT(*) as total_executions,
                SUM(CASE WHEN te.status = 'passed' THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN te.status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(te.execution_time) as avg_execution_time,
                MAX(te.execution_time) as max_execution_time,
                AVG(CASE WHEN te.status = 'passed' THEN 1 ELSE 0 END) as success_rate
             FROM test_executions te
             JOIN scan_results sr ON te.scan_id = sr.id
             WHERE sr.website_id = ? AND sr.{$dateCondition}
             GROUP BY te.test_name
             ORDER BY total_executions DESC",
            [$websiteId]
        );
    }

    /**
     * Get scan summary statistics
     */
    private function getScanSummary(int $websiteId, string $dateCondition): array
    {
        return $this->db->fetchRow(
            "SELECT
                COUNT(*) as total_scans,
                SUM(CASE WHEN success_rate >= 1.0 THEN 1 ELSE 0 END) as perfect_scans,
                SUM(CASE WHEN success_rate >= 0.9 THEN 1 ELSE 0 END) as excellent_scans,
                SUM(CASE WHEN success_rate < 0.5 THEN 1 ELSE 0 END) as poor_scans,
                AVG(success_rate) as avg_success_rate,
                AVG(execution_time) as avg_execution_time,
                MIN(execution_time) as min_execution_time,
                MAX(execution_time) as max_execution_time,
                AVG(total_tests) as avg_total_tests
             FROM scan_metrics
             WHERE website_id = ? AND {$dateCondition}",
            [$websiteId]
        ) ?: [];
    }

    /**
     * Get recent issues for a website
     */
    private function getRecentIssues(int $websiteId, string $dateCondition): array
    {
        return $this->db->fetchAll(
            "SELECT
                sr.id as scan_id,
                sr.created_at,
                sr.success_rate,
                GROUP_CONCAT(te.test_name) as failed_tests
             FROM scan_results sr
             LEFT JOIN test_executions te ON sr.id = te.scan_id AND te.status = 'failed'
             WHERE sr.website_id = ?
             AND sr.success_rate < 1.0
             AND sr.{$dateCondition}
             GROUP BY sr.id
             ORDER BY sr.created_at DESC
             LIMIT 10",
            [$websiteId]
        );
    }

    /**
     * Get system overview metrics
     */
    private function getSystemOverview(string $dateCondition): array
    {
        return $this->db->fetchRow(
            "SELECT
                COUNT(DISTINCT website_id) as active_websites,
                COUNT(*) as total_scans,
                SUM(total_tests) as total_tests_executed,
                SUM(passed_tests) as total_tests_passed,
                SUM(failed_tests) as total_tests_failed,
                AVG(success_rate) as avg_success_rate,
                SUM(execution_time) as total_execution_time
             FROM scan_metrics
             WHERE {$dateCondition}"
        ) ?: [];
    }

    /**
     * Get system performance metrics
     */
    private function getSystemPerformance(string $dateCondition): array
    {
        $performance = $this->db->fetchRow(
            "SELECT
                AVG(execution_time) as avg_execution_time,
                MIN(execution_time) as min_execution_time,
                MAX(execution_time) as max_execution_time,
                STDDEV(execution_time) as execution_time_variance,
                COUNT(CASE WHEN execution_time > ? THEN 1 END) as slow_scans,
                COUNT(CASE WHEN execution_time > ? THEN 1 END) as very_slow_scans
             FROM scan_metrics
             WHERE {$dateCondition}",
            [
                $this->config['performance_thresholds']['slow_scan'],
                $this->config['performance_thresholds']['very_slow_scan']
            ]
        ) ?: [];

        $performance['slow_scan_percentage'] = 0;
        $performance['very_slow_scan_percentage'] = 0;

        if (isset($performance['slow_scans']) && $performance['slow_scans'] > 0) {
            $totalScans = $this->db->fetchColumn("SELECT COUNT(*) FROM scan_metrics WHERE {$dateCondition}");
            if ($totalScans > 0) {
                $performance['slow_scan_percentage'] = round(($performance['slow_scans'] / $totalScans) * 100, 1);
                $performance['very_slow_scan_percentage'] = round(($performance['very_slow_scans'] / $totalScans) * 100, 1);
            }
        }

        return $performance;
    }

    /**
     * Get system reliability metrics
     */
    private function getSystemReliability(string $dateCondition): array
    {
        return $this->db->fetchRow(
            "SELECT
                AVG(success_rate) as overall_success_rate,
                COUNT(CASE WHEN success_rate >= 0.9 THEN 1 END) as high_reliability_scans,
                COUNT(CASE WHEN success_rate < 0.5 THEN 1 END) as low_reliability_scans,
                STDDEV(success_rate) as reliability_variance
             FROM scan_metrics
             WHERE {$dateCondition}"
        ) ?: [];
    }

    /**
     * Get resource usage metrics
     */
    private function getResourceUsage(string $dateCondition): array
    {
        return $this->db->fetchRow(
            "SELECT
                AVG(memory_usage) as avg_memory_usage,
                MAX(memory_usage) as peak_memory_usage,
                SUM(execution_time) / 3600 as total_cpu_hours
             FROM scan_metrics
             WHERE {$dateCondition} AND memory_usage > 0"
        ) ?: [];
    }

    /**
     * Get top performing websites
     */
    private function getTopPerformingWebsites(string $dateCondition, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT
                sm.website_id,
                w.name,
                w.url,
                AVG(sm.success_rate) as avg_success_rate,
                AVG(sm.execution_time) as avg_execution_time,
                COUNT(*) as scan_count
             FROM scan_metrics sm
             JOIN websites w ON sm.website_id = w.id
             WHERE sm.{$dateCondition}
             GROUP BY sm.website_id
             HAVING scan_count >= 5
             ORDER BY avg_success_rate DESC, avg_execution_time ASC
             LIMIT {$limit}"
        );
    }

    /**
     * Get problematic websites
     */
    private function getProblematicWebsites(string $dateCondition, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT
                sm.website_id,
                w.name,
                w.url,
                AVG(sm.success_rate) as avg_success_rate,
                AVG(sm.execution_time) as avg_execution_time,
                COUNT(*) as scan_count,
                COUNT(CASE WHEN sm.success_rate < 0.5 THEN 1 END) as poor_scans
             FROM scan_metrics sm
             JOIN websites w ON sm.website_id = w.id
             WHERE sm.{$dateCondition}
             GROUP BY sm.website_id
             HAVING scan_count >= 3 AND avg_success_rate < 0.8
             ORDER BY avg_success_rate ASC, poor_scans DESC
             LIMIT {$limit}"
        );
    }

    /**
     * Get test statistics across all websites
     */
    private function getTestStatistics(string $dateCondition): array
    {
        return $this->db->fetchAll(
            "SELECT
                te.test_name,
                COUNT(*) as total_executions,
                SUM(CASE WHEN te.status = 'passed' THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN te.status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(te.execution_time) as avg_execution_time,
                AVG(CASE WHEN te.status = 'passed' THEN 1 ELSE 0 END) as success_rate
             FROM test_executions te
             JOIN scan_results sr ON te.scan_id = sr.id
             WHERE sr.{$dateCondition}
             GROUP BY te.test_name
             ORDER BY total_executions DESC"
        );
    }

    /**
     * Get current system status
     */
    private function getCurrentStatus(): array
    {
        $runningScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE status = 'running'"
        );

        $websitesDue = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM websites WHERE active = 1 AND next_scan_at <= NOW()"
        );

        $recentFailures = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        return [
            'running_scans' => (int)$runningScans,
            'websites_due' => (int)$websitesDue,
            'recent_failures' => (int)$recentFailures,
            'status' => $runningScans > 0 ? 'active' : 'idle'
        ];
    }

    /**
     * Get today's summary
     */
    private function getTodaySummary(): array
    {
        return $this->db->fetchRow(
            "SELECT
                COUNT(*) as scans_today,
                SUM(CASE WHEN success_rate >= 1.0 THEN 1 ELSE 0 END) as successful_scans,
                AVG(success_rate) as avg_success_rate,
                AVG(execution_time) as avg_execution_time
             FROM scan_metrics
             WHERE DATE(created_at) = CURDATE()"
        ) ?: [];
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT
                sr.id,
                sr.website_id,
                w.name as website_name,
                sr.status,
                sr.success_rate,
                sr.execution_time,
                sr.created_at
             FROM scan_results sr
             JOIN websites w ON sr.website_id = w.id
             ORDER BY sr.created_at DESC
             LIMIT {$limit}"
        );
    }

    /**
     * Get active alerts
     */
    private function getActiveAlerts(): array
    {
        $alerts = [];

        // Check for websites with consecutive failures
        $failingWebsites = $this->db->fetchAll(
            "SELECT w.id, w.name, w.consecutive_failures
             FROM websites w
             WHERE w.consecutive_failures >= 3"
        );

        foreach ($failingWebsites as $website) {
            $alerts[] = [
                'type' => 'consecutive_failures',
                'severity' => 'high',
                'message' => "Website '{$website['name']}' has {$website['consecutive_failures']} consecutive failures",
                'website_id' => $website['id']
            ];
        }

        // Check for slow performance
        $slowWebsites = $this->db->fetchAll(
            "SELECT
                sm.website_id,
                w.name,
                AVG(sm.execution_time) as avg_time
             FROM scan_metrics sm
             JOIN websites w ON sm.website_id = w.id
             WHERE sm.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY sm.website_id
             HAVING avg_time > ?",
            [$this->config['performance_thresholds']['slow_scan']]
        );

        foreach ($slowWebsites as $website) {
            $alerts[] = [
                'type' => 'slow_performance',
                'severity' => 'medium',
                'message' => "Website '{$website['name']}' has slow scan performance ({$website['avg_time']}s average)",
                'website_id' => $website['website_id']
            ];
        }

        return $alerts;
    }

    /**
     * Get quick statistics
     */
    private function getQuickStats(): array
    {
        return [
            'total_websites' => $this->db->fetchColumn("SELECT COUNT(*) FROM websites WHERE active = 1"),
            'total_scans_today' => $this->db->fetchColumn("SELECT COUNT(*) FROM scan_results WHERE DATE(created_at) = CURDATE()"),
            'success_rate_today' => $this->db->fetchColumn("SELECT AVG(success_rate) * 100 FROM scan_metrics WHERE DATE(created_at) = CURDATE()"),
            'avg_execution_time_today' => $this->db->fetchColumn("SELECT AVG(execution_time) FROM scan_metrics WHERE DATE(created_at) = CURDATE()")
        ];
    }

    /**
     * Get system health indicators
     */
    private function getSystemHealth(): array
    {
        $health = ['status' => 'healthy', 'indicators' => []];

        // Check database connection
        try {
            $this->db->fetchColumn("SELECT 1");
            $health['indicators']['database'] = 'healthy';
        } catch (\Exception $e) {
            $health['status'] = 'unhealthy';
            $health['indicators']['database'] = 'error';
        }

        // Check recent activity
        $recentScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        $health['indicators']['activity'] = $recentScans > 0 ? 'active' : 'idle';

        return $health;
    }

    /**
     * Get period date condition for SQL queries
     */
    private function getPeriodDateCondition(string $period): string
    {
        $intervals = [
            '1h' => 'INTERVAL 1 HOUR',
            '6h' => 'INTERVAL 6 HOUR',
            '1d' => 'INTERVAL 1 DAY',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY',
            '90d' => 'INTERVAL 90 DAY',
            '1y' => 'INTERVAL 1 YEAR'
        ];

        $interval = $intervals[$period] ?? 'INTERVAL 7 DAY';
        return "created_at >= DATE_SUB(NOW(), {$interval})";
    }

    /**
     * Calculate expected scans for a website in a period
     */
    private function calculateExpectedScans(int $websiteId, string $dateCondition): int
    {
        $website = $this->db->fetchRow("SELECT scan_frequency FROM websites WHERE id = ?", [$websiteId]);
        if (!$website) return 0;

        // This is a simplified calculation
        // In practice, you'd need to consider the actual time period and frequency
        $frequency = $website['scan_frequency'];
        $scansPerDay = [
            'hourly' => 24,
            'daily' => 1,
            'weekly' => 1/7,
            'monthly' => 1/30
        ][$frequency] ?? 1;

        // Estimate based on 7-day period (adjust based on actual period logic)
        return ceil($scansPerDay * 7);
    }

    /**
     * Cache metrics data
     */
    private function cacheMetrics(string $key, array $data): void
    {
        // In a production environment, you'd use Redis or another caching system
        // For this implementation, we'll use a simple file-based cache
        $cacheFile = sys_get_temp_dir() . '/metrics_cache_' . md5($key) . '.json';
        $cacheData = [
            'data' => $data,
            'expires' => time() + $this->config['cache_ttl']
        ];
        file_put_contents($cacheFile, json_encode($cacheData));
    }

    /**
     * Get cached metrics data
     */
    private function getCachedMetrics(string $key): ?array
    {
        $cacheFile = sys_get_temp_dir() . '/metrics_cache_' . md5($key) . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $cacheData = json_decode(file_get_contents($cacheFile), true);

        if (!$cacheData || $cacheData['expires'] < time()) {
            unlink($cacheFile);
            return null;
        }

        return $cacheData['data'];
    }

    /**
     * Clear metrics cache
     */
    public function clearCache(): void
    {
        $pattern = sys_get_temp_dir() . '/metrics_cache_*.json';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }

    /**
     * Aggregate metrics for reporting
     */
    public function aggregateMetrics(string $interval = 'daily', int $days = 30): array
    {
        $groupBy = [
            'hourly' => 'DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00")',
            'daily' => 'DATE(created_at)',
            'weekly' => 'YEARWEEK(created_at)',
            'monthly' => 'DATE_FORMAT(created_at, "%Y-%m")'
        ][$interval] ?? 'DATE(created_at)';

        return $this->db->fetchAll(
            "SELECT
                {$groupBy} as period,
                COUNT(*) as total_scans,
                COUNT(DISTINCT website_id) as websites_scanned,
                AVG(success_rate) as avg_success_rate,
                AVG(execution_time) as avg_execution_time,
                SUM(total_tests) as total_tests,
                SUM(passed_tests) as total_passed,
                SUM(failed_tests) as total_failed
             FROM scan_metrics
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY {$groupBy}
             ORDER BY period DESC",
            [$days]
        );
    }
}