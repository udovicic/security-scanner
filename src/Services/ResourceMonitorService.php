<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;

class ResourceMonitorService
{
    private Database $db;
    private array $config;
    private array $thresholds;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'monitoring_interval' => 30, // seconds
            'history_retention' => 7200, // 2 hours of data points
            'alert_cooldown' => 300, // 5 minutes between alerts
            'throttle_duration' => 600, // 10 minutes throttle duration
            'sample_count' => 10, // Number of samples for averaging
            'enable_auto_throttling' => true,
            'enable_alerts' => true
        ], $config);

        $this->thresholds = array_merge([
            'cpu_usage' => [
                'warning' => 70,
                'critical' => 85,
                'throttle' => 90
            ],
            'memory_usage' => [
                'warning' => 75,
                'critical' => 90,
                'throttle' => 95
            ],
            'disk_usage' => [
                'warning' => 80,
                'critical' => 90,
                'throttle' => 95
            ],
            'load_average' => [
                'warning' => 2.0,
                'critical' => 4.0,
                'throttle' => 6.0
            ],
            'active_connections' => [
                'warning' => 100,
                'critical' => 150,
                'throttle' => 200
            ],
            'concurrent_scans' => [
                'warning' => 10,
                'critical' => 15,
                'throttle' => 20
            ]
        ], $config['thresholds'] ?? []);
    }

    /**
     * Monitor system resources
     */
    public function monitorResources(): array
    {
        $metrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'load_average' => $this->getLoadAverage(),
            'active_connections' => $this->getActiveConnections(),
            'concurrent_scans' => $this->getConcurrentScans(),
            'process_count' => $this->getProcessCount(),
            'network_connections' => $this->getNetworkConnections()
        ];

        // Store metrics
        $this->storeMetrics($metrics);

        // Analyze metrics and determine actions
        $analysis = $this->analyzeMetrics($metrics);

        // Take actions based on analysis
        if ($this->config['enable_auto_throttling']) {
            $throttlingActions = $this->handleThrottling($analysis);
            $analysis['throttling_actions'] = $throttlingActions;
        }

        if ($this->config['enable_alerts']) {
            $alertActions = $this->handleAlerts($analysis);
            $analysis['alert_actions'] = $alertActions;
        }

        return [
            'metrics' => $metrics,
            'analysis' => $analysis,
            'recommendations' => $this->generateRecommendations($analysis)
        ];
    }

    /**
     * Get CPU usage percentage
     */
    private function getCpuUsage(): float
    {
        // Get load averages
        $loadAvg = sys_getloadavg();
        $cpuCount = $this->getCpuCount();

        // Calculate CPU usage as percentage based on 1-minute load average
        $cpuUsage = ($loadAvg[0] / $cpuCount) * 100;

        return round(min($cpuUsage, 100), 2);
    }

    /**
     * Get memory usage information
     */
    private function getMemoryUsage(): array
    {
        $memInfo = [];

        if (file_exists('/proc/meminfo')) {
            $memInfoFile = file_get_contents('/proc/meminfo');
            preg_match_all('/(\w+):\s+(\d+)\s+kB/', $memInfoFile, $matches);

            $memData = array_combine($matches[1], $matches[2]);

            $totalMemory = ($memData['MemTotal'] ?? 0) * 1024;
            $freeMemory = ($memData['MemFree'] ?? 0) * 1024;
            $availableMemory = ($memData['MemAvailable'] ?? $freeMemory) * 1024;
            $usedMemory = $totalMemory - $availableMemory;

            $memInfo = [
                'total' => $totalMemory,
                'used' => $usedMemory,
                'free' => $freeMemory,
                'available' => $availableMemory,
                'usage_percentage' => $totalMemory > 0 ? round(($usedMemory / $totalMemory) * 100, 2) : 0
            ];
        } else {
            // Fallback for systems without /proc/meminfo
            $memInfo = [
                'total' => 0,
                'used' => memory_get_usage(true),
                'free' => 0,
                'available' => 0,
                'usage_percentage' => 0
            ];
        }

        return $memInfo;
    }

    /**
     * Get disk usage information
     */
    private function getDiskUsage(): array
    {
        $path = '/';
        $totalBytes = disk_total_space($path);
        $freeBytes = disk_free_space($path);
        $usedBytes = $totalBytes - $freeBytes;

        return [
            'total' => $totalBytes ?: 0,
            'used' => $usedBytes ?: 0,
            'free' => $freeBytes ?: 0,
            'usage_percentage' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0
        ];
    }

    /**
     * Get system load average
     */
    private function getLoadAverage(): array
    {
        $loadAvg = sys_getloadavg();

        return [
            '1min' => $loadAvg[0] ?? 0,
            '5min' => $loadAvg[1] ?? 0,
            '15min' => $loadAvg[2] ?? 0
        ];
    }

    /**
     * Get active database connections
     */
    private function getActiveConnections(): int
    {
        try {
            $count = $this->db->fetchColumn("SHOW STATUS LIKE 'Threads_connected'");
            return (int)$count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get concurrent scan count
     */
    private function getConcurrentScans(): int
    {
        try {
            return (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM scan_results WHERE status = 'running'"
            );
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get process count
     */
    private function getProcessCount(): int
    {
        if (function_exists('exec')) {
            $output = shell_exec('ps aux | wc -l');
            return (int)trim($output ?: '0');
        }

        return 0;
    }

    /**
     * Get network connections count
     */
    private function getNetworkConnections(): int
    {
        if (function_exists('exec')) {
            $output = shell_exec('netstat -an | grep ESTABLISHED | wc -l');
            return (int)trim($output ?: '0');
        }

        return 0;
    }

    /**
     * Get CPU count
     */
    private function getCpuCount(): int
    {
        if (file_exists('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuInfo, 'processor');
        }

        return 1; // Fallback
    }

    /**
     * Store metrics in database
     */
    private function storeMetrics(array $metrics): void
    {
        $this->db->insert('resource_metrics', [
            'timestamp' => $metrics['timestamp'],
            'cpu_usage' => $metrics['cpu_usage'],
            'memory_total' => $metrics['memory_usage']['total'],
            'memory_used' => $metrics['memory_usage']['used'],
            'memory_usage_percentage' => $metrics['memory_usage']['usage_percentage'],
            'disk_total' => $metrics['disk_usage']['total'],
            'disk_used' => $metrics['disk_usage']['used'],
            'disk_usage_percentage' => $metrics['disk_usage']['usage_percentage'],
            'load_average_1min' => $metrics['load_average']['1min'],
            'load_average_5min' => $metrics['load_average']['5min'],
            'load_average_15min' => $metrics['load_average']['15min'],
            'active_connections' => $metrics['active_connections'],
            'concurrent_scans' => $metrics['concurrent_scans'],
            'process_count' => $metrics['process_count'],
            'network_connections' => $metrics['network_connections'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Cleanup old metrics
        $this->cleanupOldMetrics();
    }

    /**
     * Analyze metrics and determine severity levels
     */
    private function analyzeMetrics(array $metrics): array
    {
        $analysis = [
            'overall_status' => 'normal',
            'severity_level' => 0,
            'warnings' => [],
            'critical_issues' => [],
            'throttle_recommended' => false,
            'resource_analysis' => []
        ];

        // Analyze each metric
        foreach ($this->thresholds as $metric => $thresholds) {
            $value = $this->extractMetricValue($metrics, $metric);
            $resourceAnalysis = $this->analyzeMetric($metric, $value, $thresholds);

            $analysis['resource_analysis'][$metric] = $resourceAnalysis;

            // Update overall status based on individual metrics
            if ($resourceAnalysis['level'] === 'critical') {
                $analysis['critical_issues'][] = $resourceAnalysis['message'];
                $analysis['severity_level'] = max($analysis['severity_level'], 3);
                $analysis['overall_status'] = 'critical';
            } elseif ($resourceAnalysis['level'] === 'warning') {
                $analysis['warnings'][] = $resourceAnalysis['message'];
                $analysis['severity_level'] = max($analysis['severity_level'], 2);
                if ($analysis['overall_status'] !== 'critical') {
                    $analysis['overall_status'] = 'warning';
                }
            }

            if ($resourceAnalysis['throttle_recommended']) {
                $analysis['throttle_recommended'] = true;
            }
        }

        return $analysis;
    }

    /**
     * Analyze individual metric
     */
    private function analyzeMetric(string $metric, float $value, array $thresholds): array
    {
        $analysis = [
            'metric' => $metric,
            'value' => $value,
            'level' => 'normal',
            'message' => '',
            'throttle_recommended' => false
        ];

        if ($value >= $thresholds['throttle']) {
            $analysis['level'] = 'critical';
            $analysis['throttle_recommended'] = true;
            $analysis['message'] = "{$metric} is at critical level ({$value}), throttling recommended";
        } elseif ($value >= $thresholds['critical']) {
            $analysis['level'] = 'critical';
            $analysis['message'] = "{$metric} is at critical level ({$value})";
        } elseif ($value >= $thresholds['warning']) {
            $analysis['level'] = 'warning';
            $analysis['message'] = "{$metric} is at warning level ({$value})";
        } else {
            $analysis['message'] = "{$metric} is at normal level ({$value})";
        }

        return $analysis;
    }

    /**
     * Extract metric value from metrics array
     */
    private function extractMetricValue(array $metrics, string $metric): float
    {
        switch ($metric) {
            case 'cpu_usage':
                return $metrics['cpu_usage'];
            case 'memory_usage':
                return $metrics['memory_usage']['usage_percentage'];
            case 'disk_usage':
                return $metrics['disk_usage']['usage_percentage'];
            case 'load_average':
                return $metrics['load_average']['1min'];
            case 'active_connections':
                return $metrics['active_connections'];
            case 'concurrent_scans':
                return $metrics['concurrent_scans'];
            default:
                return 0;
        }
    }

    /**
     * Handle throttling based on analysis
     */
    private function handleThrottling(array $analysis): array
    {
        $actions = [];

        if ($analysis['throttle_recommended']) {
            // Check if throttling is already active
            $activeThrottle = $this->isThrottlingActive();

            if (!$activeThrottle) {
                // Activate throttling
                $throttleResult = $this->activateThrottling($analysis);
                $actions[] = $throttleResult;

                $this->logResourceActivity('throttling_activated', [
                    'severity_level' => $analysis['severity_level'],
                    'critical_issues' => $analysis['critical_issues'],
                    'throttle_duration' => $this->config['throttle_duration']
                ]);
            }
        } else {
            // Check if we should deactivate throttling
            $activeThrottle = $this->isThrottlingActive();

            if ($activeThrottle && $analysis['severity_level'] < 2) {
                $deactivateResult = $this->deactivateThrottling();
                $actions[] = $deactivateResult;

                $this->logResourceActivity('throttling_deactivated', [
                    'reason' => 'resource_levels_normalized'
                ]);
            }
        }

        return $actions;
    }

    /**
     * Handle alerts based on analysis
     */
    private function handleAlerts(array $analysis): array
    {
        $actions = [];

        if (!empty($analysis['critical_issues']) || !empty($analysis['warnings'])) {
            $lastAlert = $this->getLastAlertTime();
            $timeSinceLastAlert = time() - ($lastAlert ? strtotime($lastAlert) : 0);

            if ($timeSinceLastAlert > $this->config['alert_cooldown']) {
                $alertResult = $this->sendResourceAlert($analysis);
                $actions[] = $alertResult;

                $this->logResourceActivity('alert_sent', [
                    'alert_type' => $analysis['overall_status'],
                    'severity_level' => $analysis['severity_level'],
                    'issues' => array_merge($analysis['critical_issues'], $analysis['warnings'])
                ]);
            }
        }

        return $actions;
    }

    /**
     * Activate system throttling
     */
    private function activateThrottling(array $analysis): array
    {
        $throttleConfig = [
            'active' => true,
            'activated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->config['throttle_duration']),
            'reason' => 'resource_constraints',
            'severity_level' => $analysis['severity_level'],
            'affected_resources' => array_keys(array_filter(
                $analysis['resource_analysis'],
                fn($r) => $r['throttle_recommended']
            ))
        ];

        // Store throttling state
        $this->setThrottlingState($throttleConfig);

        // Reduce concurrent operations
        $this->reduceConcurrentOperations();

        return [
            'action' => 'throttling_activated',
            'success' => true,
            'config' => $throttleConfig
        ];
    }

    /**
     * Deactivate system throttling
     */
    private function deactivateThrottling(): array
    {
        $throttleConfig = [
            'active' => false,
            'deactivated_at' => date('Y-m-d H:i:s'),
            'reason' => 'resource_levels_normalized'
        ];

        $this->setThrottlingState($throttleConfig);

        // Restore normal operations
        $this->restoreNormalOperations();

        return [
            'action' => 'throttling_deactivated',
            'success' => true,
            'config' => $throttleConfig
        ];
    }

    /**
     * Check if throttling is currently active
     */
    private function isThrottlingActive(): bool
    {
        $throttleState = $this->getThrottlingState();

        if (!$throttleState || !$throttleState['active']) {
            return false;
        }

        // Check if throttling has expired
        if (isset($throttleState['expires_at']) && strtotime($throttleState['expires_at']) < time()) {
            $this->deactivateThrottling();
            return false;
        }

        return true;
    }

    /**
     * Get current throttling state
     */
    private function getThrottlingState(): ?array
    {
        $stateFile = sys_get_temp_dir() . '/security_scanner_throttle_state.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            return $state ?: null;
        }

        return null;
    }

    /**
     * Set throttling state
     */
    private function setThrottlingState(array $state): void
    {
        $stateFile = sys_get_temp_dir() . '/security_scanner_throttle_state.json';
        file_put_contents($stateFile, json_encode($state));
    }

    /**
     * Reduce concurrent operations
     */
    private function reduceConcurrentOperations(): void
    {
        // Pause pending scans
        $this->db->query(
            "UPDATE scan_results
             SET status = 'paused',
                 paused_at = NOW(),
                 updated_at = NOW()
             WHERE status = 'pending'"
        );

        // Reduce queue worker count if queue service is running
        $this->signalQueueService('reduce_workers');
    }

    /**
     * Restore normal operations
     */
    private function restoreNormalOperations(): void
    {
        // Resume paused scans
        $this->db->query(
            "UPDATE scan_results
             SET status = 'pending',
                 paused_at = NULL,
                 updated_at = NOW()
             WHERE status = 'paused'"
        );

        // Restore normal queue worker count
        $this->signalQueueService('restore_workers');
    }

    /**
     * Send signal to queue service
     */
    private function signalQueueService(string $signal): void
    {
        $signalFile = sys_get_temp_dir() . '/security_scanner_queue_signal.txt';
        file_put_contents($signalFile, $signal);
    }

    /**
     * Send resource alert
     */
    private function sendResourceAlert(array $analysis): array
    {
        try {
            $notificationService = new NotificationService();

            $alertData = [
                'type' => 'system_resource_alert',
                'severity' => $analysis['overall_status'],
                'timestamp' => date('Y-m-d H:i:s'),
                'issues' => array_merge($analysis['critical_issues'], $analysis['warnings']),
                'recommendations' => $this->generateRecommendations($analysis)
            ];

            // This would send to configured admin emails
            // For now, just log the alert
            $this->logResourceActivity('alert_generated', $alertData);

            return [
                'action' => 'alert_sent',
                'success' => true,
                'alert_data' => $alertData
            ];

        } catch (\Exception $e) {
            return [
                'action' => 'alert_failed',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get last alert time
     */
    private function getLastAlertTime(): ?string
    {
        return $this->db->fetchColumn(
            "SELECT MAX(created_at) FROM resource_log WHERE action = 'alert_sent'"
        );
    }

    /**
     * Generate recommendations based on analysis
     */
    private function generateRecommendations(array $analysis): array
    {
        $recommendations = [];

        foreach ($analysis['resource_analysis'] as $metric => $resourceAnalysis) {
            if ($resourceAnalysis['level'] === 'critical' || $resourceAnalysis['level'] === 'warning') {
                $recommendations = array_merge($recommendations, $this->getMetricRecommendations($metric, $resourceAnalysis));
            }
        }

        return array_unique($recommendations);
    }

    /**
     * Get recommendations for specific metric
     */
    private function getMetricRecommendations(string $metric, array $analysis): array
    {
        $recommendations = [];

        switch ($metric) {
            case 'cpu_usage':
                $recommendations[] = 'Reduce concurrent scan operations';
                $recommendations[] = 'Consider upgrading server CPU or adding more cores';
                $recommendations[] = 'Optimize scan algorithms to reduce CPU usage';
                break;

            case 'memory_usage':
                $recommendations[] = 'Reduce memory-intensive operations';
                $recommendations[] = 'Increase server RAM';
                $recommendations[] = 'Optimize memory usage in scan processes';
                break;

            case 'disk_usage':
                $recommendations[] = 'Clean up old log files and temporary data';
                $recommendations[] = 'Archive old scan results';
                $recommendations[] = 'Increase disk space';
                break;

            case 'load_average':
                $recommendations[] = 'Reduce system load by limiting concurrent processes';
                $recommendations[] = 'Scale horizontally by adding more servers';
                break;

            case 'active_connections':
                $recommendations[] = 'Optimize database queries to reduce connection time';
                $recommendations[] = 'Implement connection pooling';
                break;

            case 'concurrent_scans':
                $recommendations[] = 'Reduce maximum concurrent scan limit';
                $recommendations[] = 'Implement scan queuing';
                break;
        }

        return $recommendations;
    }

    /**
     * Get resource usage trends
     */
    public function getResourceTrends(string $period = '1h'): array
    {
        $interval = match($period) {
            '1h' => 'INTERVAL 1 HOUR',
            '6h' => 'INTERVAL 6 HOUR',
            '1d' => 'INTERVAL 1 DAY',
            '7d' => 'INTERVAL 7 DAY',
            default => 'INTERVAL 1 HOUR'
        };

        return $this->db->fetchAll(
            "SELECT
                timestamp,
                cpu_usage,
                memory_usage_percentage,
                disk_usage_percentage,
                load_average_1min,
                active_connections,
                concurrent_scans
             FROM resource_metrics
             WHERE created_at >= DATE_SUB(NOW(), {$interval})
             ORDER BY timestamp ASC"
        );
    }

    /**
     * Get current resource status
     */
    public function getCurrentResourceStatus(): array
    {
        $latestMetrics = $this->db->fetchRow(
            "SELECT * FROM resource_metrics ORDER BY created_at DESC LIMIT 1"
        );

        if (!$latestMetrics) {
            return [
                'status' => 'unknown',
                'message' => 'No resource metrics available'
            ];
        }

        $metrics = [
            'cpu_usage' => $latestMetrics['cpu_usage'],
            'memory_usage' => [
                'usage_percentage' => $latestMetrics['memory_usage_percentage']
            ],
            'disk_usage' => [
                'usage_percentage' => $latestMetrics['disk_usage_percentage']
            ],
            'load_average' => [
                '1min' => $latestMetrics['load_average_1min']
            ],
            'active_connections' => $latestMetrics['active_connections'],
            'concurrent_scans' => $latestMetrics['concurrent_scans']
        ];

        $analysis = $this->analyzeMetrics($metrics);

        return [
            'status' => $analysis['overall_status'],
            'severity_level' => $analysis['severity_level'],
            'throttling_active' => $this->isThrottlingActive(),
            'metrics' => $metrics,
            'analysis' => $analysis,
            'last_updated' => $latestMetrics['timestamp']
        ];
    }

    /**
     * Cleanup old metrics
     */
    private function cleanupOldMetrics(): void
    {
        $cutoffTime = date('Y-m-d H:i:s', time() - $this->config['history_retention']);

        $this->db->query(
            "DELETE FROM resource_metrics WHERE created_at < ?",
            [$cutoffTime]
        );
    }

    /**
     * Log resource monitoring activity
     */
    private function logResourceActivity(string $action, array $context = []): void
    {
        $this->db->insert('resource_log', [
            'action' => $action,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Force throttling activation (for testing or manual intervention)
     */
    public function forceThrottling(?int $duration = null): array
    {
        $duration = $duration ?: $this->config['throttle_duration'];

        $throttleConfig = [
            'active' => true,
            'activated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $duration),
            'reason' => 'manual_activation',
            'severity_level' => 3
        ];

        $this->setThrottlingState($throttleConfig);
        $this->reduceConcurrentOperations();

        $this->logResourceActivity('throttling_forced', [
            'duration' => $duration,
            'expires_at' => $throttleConfig['expires_at']
        ]);

        return [
            'success' => true,
            'message' => 'Throttling activated manually',
            'expires_at' => $throttleConfig['expires_at']
        ];
    }

    /**
     * Force throttling deactivation
     */
    public function forceThrottlingDeactivation(): array
    {
        $result = $this->deactivateThrottling();

        $this->logResourceActivity('throttling_forced_deactivation', [
            'reason' => 'manual_deactivation'
        ]);

        return [
            'success' => true,
            'message' => 'Throttling deactivated manually'
        ];
    }
}