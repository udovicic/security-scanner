<?php

namespace SecurityScanner\Controllers;

use SecurityScanner\Core\Database;
use SecurityScanner\Tests\{TestExecutionEngine, TestResultAggregator};

class DashboardController extends BaseController
{
    private Database $db;
    private TestExecutionEngine $testEngine;
    private TestResultAggregator $aggregator;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->db = Database::getInstance();
        $this->testEngine = new TestExecutionEngine();
        $this->aggregator = new TestResultAggregator();
    }

    /**
     * Main dashboard view
     */
    public function indexAction(array $params = []): mixed
    {
        try {
            $metrics = $this->getDashboardMetrics();
            $recentScans = $this->getRecentScans();
            $alerts = $this->getActiveAlerts();
            $systemStatus = $this->getSystemStatus();

            $data = [
                'title' => 'Security Scanner - Dashboard',
                'main' => $this->renderDashboard([
                    'metrics' => $metrics,
                    'recent_scans' => $recentScans,
                    'alerts' => $alerts,
                    'system_status' => $systemStatus
                ])
            ];

            return $data;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to load dashboard: ' . $e->getMessage());
            return ['title' => 'Dashboard Error', 'main' => '<h1>Dashboard Error</h1>'];
        }
    }

    /**
     * System health check endpoint
     */
    public function healthAction(array $params = []): mixed
    {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'checks' => [
                    'database' => $this->checkDatabase(),
                    'test_engine' => $this->checkTestEngine(),
                    'disk_space' => $this->checkDiskSpace(),
                    'memory' => $this->checkMemoryUsage()
                ]
            ];

            // Determine overall status
            $allHealthy = true;
            foreach ($health['checks'] as $check) {
                if ($check['status'] !== 'healthy') {
                    $allHealthy = false;
                    break;
                }
            }

            $health['status'] = $allHealthy ? 'healthy' : 'unhealthy';

            return $health;

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'timestamp' => date('c'),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get dashboard metrics
     */
    private function getDashboardMetrics(): array
    {
        // Website metrics
        $totalWebsites = $this->db->fetchColumn("SELECT COUNT(*) FROM websites");
        $activeWebsites = $this->db->fetchColumn("SELECT COUNT(*) FROM websites WHERE active = 1");

        // Scan metrics
        $totalScans = $this->db->fetchColumn("SELECT COUNT(*) FROM scan_results");
        $todayScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE DATE(created_at) = CURDATE()"
        );

        // Recent scans success rate
        $recentSuccessRate = $this->db->fetchColumn(
            "SELECT AVG(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100
             FROM scan_results
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // Average security score
        $avgSecurityScore = $this->db->fetchColumn(
            "SELECT AVG(score) FROM scan_results
             WHERE score IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        // Issues count
        $criticalIssues = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results
             WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'websites' => [
                'total' => (int)$totalWebsites,
                'active' => (int)$activeWebsites,
                'inactive' => (int)($totalWebsites - $activeWebsites)
            ],
            'scans' => [
                'total' => (int)$totalScans,
                'today' => (int)$todayScans,
                'success_rate' => round($recentSuccessRate ?? 0, 1)
            ],
            'security' => [
                'average_score' => round($avgSecurityScore ?? 0, 1),
                'critical_issues' => (int)$criticalIssues
            ],
            'performance' => $this->getPerformanceMetrics()
        ];
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(): array
    {
        $avgScanTime = $this->db->fetchColumn(
            "SELECT AVG(execution_time) FROM scan_results
             WHERE execution_time IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $slowScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results
             WHERE execution_time > 30 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return [
            'avg_scan_time' => round($avgScanTime ?? 0, 2),
            'slow_scans_24h' => (int)$slowScans
        ];
    }

    /**
     * Get recent scans
     */
    private function getRecentScans(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT sr.*, w.name as website_name, w.url as website_url
             FROM scan_results sr
             JOIN websites w ON sr.website_id = w.id
             ORDER BY sr.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Get active alerts
     */
    private function getActiveAlerts(): array
    {
        $alerts = [];

        // Critical security issues
        $criticalIssues = $this->db->fetchAll(
            "SELECT sr.*, w.name as website_name
             FROM scan_results sr
             JOIN websites w ON sr.website_id = w.id
             WHERE sr.status = 'failed' AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY sr.created_at DESC
             LIMIT 5"
        );

        foreach ($criticalIssues as $issue) {
            $alerts[] = [
                'type' => 'critical',
                'title' => 'Security scan failed',
                'message' => "Website '{$issue['website_name']}' failed security scan",
                'timestamp' => $issue['created_at'],
                'url' => "/websites/{$issue['website_id']}"
            ];
        }

        // System health alerts
        $systemChecks = $this->getSystemStatus();
        foreach ($systemChecks as $check => $status) {
            if ($status['status'] !== 'healthy') {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'System Health Issue',
                    'message' => "System check '{$check}' is not healthy",
                    'timestamp' => date('Y-m-d H:i:s'),
                    'url' => '/dashboard/health'
                ];
            }
        }

        return $alerts;
    }

    /**
     * Get system status
     */
    private function getSystemStatus(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'test_engine' => $this->checkTestEngine(),
            'disk_space' => $this->checkDiskSpace(),
            'memory' => $this->checkMemoryUsage()
        ];
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            $this->db->fetchColumn("SELECT 1");
            return [
                'status' => 'healthy',
                'message' => 'Database connection is working',
                'details' => []
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Check test engine
     */
    private function checkTestEngine(): array
    {
        try {
            $stats = $this->testEngine->getExecutionStatistics();
            return [
                'status' => 'healthy',
                'message' => 'Test engine is operational',
                'details' => [
                    'tests_executed' => count($stats),
                    'registry_tests' => count($this->testEngine->getRegistry()->getAllTests())
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Test engine check failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Check disk space
     */
    private function checkDiskSpace(): array
    {
        $freeBytes = disk_free_space('.');
        $totalBytes = disk_total_space('.');

        if ($freeBytes === false || $totalBytes === false) {
            return [
                'status' => 'unknown',
                'message' => 'Could not determine disk space',
                'details' => []
            ];
        }

        $usedPercent = (($totalBytes - $freeBytes) / $totalBytes) * 100;

        $status = 'healthy';
        $message = 'Disk space is adequate';

        if ($usedPercent > 90) {
            $status = 'critical';
            $message = 'Disk space is critically low';
        } elseif ($usedPercent > 80) {
            $status = 'warning';
            $message = 'Disk space is running low';
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'used_percent' => round($usedPercent, 1),
                'free_gb' => round($freeBytes / (1024 * 1024 * 1024), 2),
                'total_gb' => round($totalBytes / (1024 * 1024 * 1024), 2)
            ]
        ];
    }

    /**
     * Check memory usage
     */
    private function checkMemoryUsage(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');

        // Convert memory limit to bytes
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $usagePercent = ($memoryUsage / $memoryLimitBytes) * 100;

        $status = 'healthy';
        $message = 'Memory usage is normal';

        if ($usagePercent > 90) {
            $status = 'critical';
            $message = 'Memory usage is critically high';
        } elseif ($usagePercent > 80) {
            $status = 'warning';
            $message = 'Memory usage is high';
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'used_percent' => round($usagePercent, 1),
                'used_mb' => round($memoryUsage / (1024 * 1024), 2),
                'limit' => $memoryLimit
            ]
        ];
    }

    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $number = (int)$memoryLimit;

        switch ($last) {
            case 'g':
                $number *= 1024;
            case 'm':
                $number *= 1024;
            case 'k':
                $number *= 1024;
        }

        return $number;
    }

    /**
     * Render dashboard
     */
    private function renderDashboard(array $data): string
    {
        $html = '<div class="dashboard">';

        // Header
        $html .= '<div class="dashboard-header">';
        $html .= '<h1>Security Scanner Dashboard</h1>';
        $html .= '<div class="refresh-info">Last updated: ' . date('Y-m-d H:i:s') . '</div>';
        $html .= '</div>';

        // Metrics cards
        $html .= $this->renderMetricsCards($data['metrics']);

        // Charts and recent activity
        $html .= '<div class="dashboard-content">';
        $html .= '<div class="dashboard-left">';
        $html .= $this->renderRecentScans($data['recent_scans']);
        $html .= '</div>';
        $html .= '<div class="dashboard-right">';
        $html .= $this->renderAlerts($data['alerts']);
        $html .= $this->renderSystemStatus($data['system_status']);
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render metrics cards
     */
    private function renderMetricsCards(array $metrics): string
    {
        $html = '<div class="metrics-grid">';

        // Websites card
        $html .= '<div class="metric-card">';
        $html .= '<h3>Websites</h3>';
        $html .= '<div class="metric-value">' . $metrics['websites']['total'] . '</div>';
        $html .= '<div class="metric-details">';
        $html .= '<span class="active">' . $metrics['websites']['active'] . ' active</span>';
        $html .= '<span class="inactive">' . $metrics['websites']['inactive'] . ' inactive</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Scans card
        $html .= '<div class="metric-card">';
        $html .= '<h3>Scans</h3>';
        $html .= '<div class="metric-value">' . $metrics['scans']['today'] . '</div>';
        $html .= '<div class="metric-details">';
        $html .= '<span>Today / ' . $metrics['scans']['total'] . ' total</span>';
        $html .= '<span class="success-rate">' . $metrics['scans']['success_rate'] . '% success rate</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Security card
        $html .= '<div class="metric-card">';
        $html .= '<h3>Security Score</h3>';
        $html .= '<div class="metric-value">' . $metrics['security']['average_score'] . '</div>';
        $html .= '<div class="metric-details">';
        $html .= '<span class="critical">' . $metrics['security']['critical_issues'] . ' critical issues</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Performance card
        $html .= '<div class="metric-card">';
        $html .= '<h3>Performance</h3>';
        $html .= '<div class="metric-value">' . $metrics['performance']['avg_scan_time'] . 's</div>';
        $html .= '<div class="metric-details">';
        $html .= '<span>Average scan time</span>';
        $html .= '<span class="slow">' . $metrics['performance']['slow_scans_24h'] . ' slow scans (24h)</span>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render recent scans
     */
    private function renderRecentScans(array $scans): string
    {
        $html = '<div class="recent-scans">';
        $html .= '<h2>Recent Scans</h2>';

        if (empty($scans)) {
            $html .= '<div class="no-data">No recent scans</div>';
        } else {
            $html .= '<div class="scans-list">';
            foreach ($scans as $scan) {
                $statusClass = $scan['status'] === 'completed' ? 'success' : 'error';
                $score = $scan['score'] ? $scan['score'] . '/100' : 'N/A';

                $html .= '<div class="scan-item">';
                $html .= '<div class="scan-website">';
                $html .= '<a href="/websites/' . $scan['website_id'] . '">' . htmlspecialchars($scan['website_name']) . '</a>';
                $html .= '</div>';
                $html .= '<div class="scan-status ' . $statusClass . '">' . $scan['status'] . '</div>';
                $html .= '<div class="scan-score">' . $score . '</div>';
                $html .= '<div class="scan-time">' . date('M j, H:i', strtotime($scan['created_at'])) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render alerts
     */
    private function renderAlerts(array $alerts): string
    {
        $html = '<div class="alerts-section">';
        $html .= '<h2>Active Alerts</h2>';

        if (empty($alerts)) {
            $html .= '<div class="no-alerts">No active alerts</div>';
        } else {
            $html .= '<div class="alerts-list">';
            foreach ($alerts as $alert) {
                $html .= '<div class="alert alert-' . $alert['type'] . '">';
                $html .= '<div class="alert-title">' . htmlspecialchars($alert['title']) . '</div>';
                $html .= '<div class="alert-message">' . htmlspecialchars($alert['message']) . '</div>';
                $html .= '<div class="alert-time">' . date('M j, H:i', strtotime($alert['timestamp'])) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render system status
     */
    private function renderSystemStatus(array $status): string
    {
        $html = '<div class="system-status">';
        $html .= '<h2>System Status</h2>';

        $html .= '<div class="status-list">';
        foreach ($status as $component => $check) {
            $statusClass = $check['status'];
            $icon = $check['status'] === 'healthy' ? '✅' : ($check['status'] === 'warning' ? '⚠️' : '❌');

            $html .= '<div class="status-item ' . $statusClass . '">';
            $html .= '<span class="status-icon">' . $icon . '</span>';
            $html .= '<span class="status-name">' . ucfirst(str_replace('_', ' ', $component)) . '</span>';
            $html .= '<span class="status-message">' . $check['message'] . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}