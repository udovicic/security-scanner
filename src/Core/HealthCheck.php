<?php

namespace SecurityScanner\Core;

class HealthCheck
{
    private array $checks = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'timeout' => 30, // seconds
            'max_execution_time' => 30,
            'include_system_info' => true,
            'include_performance' => true,
            'critical_checks' => [],
            'warning_thresholds' => [
                'memory_usage' => 80, // percentage
                'disk_usage' => 90, // percentage
                'load_average' => 2.0,
                'response_time' => 5000 // milliseconds
            ]
        ], $config);

        $this->registerDefaultChecks();
    }

    /**
     * Register a health check
     */
    public function register(string $name, callable $check, bool $critical = false): self
    {
        $this->checks[$name] = [
            'callback' => $check,
            'critical' => $critical,
            'registered_at' => time()
        ];

        return $this;
    }

    /**
     * Run all health checks
     */
    public function check(): array
    {
        $startTime = microtime(true);
        $results = [
            'status' => 'healthy',
            'timestamp' => time(),
            'datetime' => date('c'),
            'checks' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'warnings' => 0,
                'critical_failed' => 0
            ],
            'execution_time' => 0
        ];

        foreach ($this->checks as $name => $check) {
            $results['checks'][$name] = $this->runSingleCheck($name, $check);
            $results['summary']['total']++;

            $status = $results['checks'][$name]['status'];
            switch ($status) {
                case 'pass':
                    $results['summary']['passed']++;
                    break;
                case 'fail':
                    $results['summary']['failed']++;
                    if ($check['critical']) {
                        $results['summary']['critical_failed']++;
                        $results['status'] = 'unhealthy';
                    }
                    break;
                case 'warning':
                    $results['summary']['warnings']++;
                    if ($results['status'] === 'healthy') {
                        $results['status'] = 'degraded';
                    }
                    break;
            }
        }

        $results['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->config['include_system_info']) {
            $results['system'] = $this->getSystemInfo();
        }

        if ($this->config['include_performance']) {
            $results['performance'] = $this->getPerformanceMetrics();
        }

        return $results;
    }

    /**
     * Run a specific health check
     */
    public function checkSingle(string $name): array
    {
        if (!isset($this->checks[$name])) {
            throw new \InvalidArgumentException("Health check '{$name}' not found");
        }

        return $this->runSingleCheck($name, $this->checks[$name]);
    }

    /**
     * Get simple health status
     */
    public function getStatus(): string
    {
        $results = $this->check();
        return $results['status'];
    }

    /**
     * Check if system is healthy
     */
    public function isHealthy(): bool
    {
        return $this->getStatus() === 'healthy';
    }

    /**
     * Get health check as HTTP response
     */
    public function getHttpResponse(): Response
    {
        try {
            $results = $this->check();
            $statusCode = $this->getHttpStatusCode($results['status']);

            return Response::json($results, $statusCode)
                ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->setHeader('Content-Type', 'application/health+json');
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'error',
                'message' => 'Health check failed: ' . $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
    }

    /**
     * Register default system checks
     */
    private function registerDefaultChecks(): void
    {
        // Database connectivity check
        $this->register('database', function() {
            // TODO: Implement when database layer is available
            return [
                'status' => 'pass',
                'message' => 'Database connectivity check placeholder',
                'response_time' => 0
            ];
        }, true);

        // Memory usage check
        $this->register('memory', function() {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $percentage = ($memoryUsage / $memoryLimit) * 100;

            $status = 'pass';
            if ($percentage > $this->config['warning_thresholds']['memory_usage']) {
                $status = $percentage > 95 ? 'fail' : 'warning';
            }

            return [
                'status' => $status,
                'message' => sprintf('Memory usage: %.1f%% (%s / %s)',
                    $percentage,
                    $this->formatBytes($memoryUsage),
                    $this->formatBytes($memoryLimit)
                ),
                'details' => [
                    'used' => $memoryUsage,
                    'limit' => $memoryLimit,
                    'percentage' => round($percentage, 1)
                ]
            ];
        });

        // Disk space check
        $this->register('disk_space', function() {
            $path = sys_get_temp_dir();
            $totalSpace = disk_total_space($path);
            $freeSpace = disk_free_space($path);
            $usedSpace = $totalSpace - $freeSpace;
            $percentage = ($usedSpace / $totalSpace) * 100;

            $status = 'pass';
            if ($percentage > $this->config['warning_thresholds']['disk_usage']) {
                $status = $percentage > 98 ? 'fail' : 'warning';
            }

            return [
                'status' => $status,
                'message' => sprintf('Disk usage: %.1f%% (%s / %s)',
                    $percentage,
                    $this->formatBytes($usedSpace),
                    $this->formatBytes($totalSpace)
                ),
                'details' => [
                    'path' => $path,
                    'total' => $totalSpace,
                    'used' => $usedSpace,
                    'free' => $freeSpace,
                    'percentage' => round($percentage, 1)
                ]
            ];
        });

        // Load average check (Unix-like systems only)
        if (function_exists('sys_getloadavg')) {
            $this->register('load_average', function() {
                $load = sys_getloadavg();
                $load1min = $load[0];

                $status = 'pass';
                $threshold = $this->config['warning_thresholds']['load_average'];

                if ($load1min > $threshold) {
                    $status = $load1min > ($threshold * 2) ? 'fail' : 'warning';
                }

                return [
                    'status' => $status,
                    'message' => sprintf('Load average: %.2f, %.2f, %.2f', $load[0], $load[1], $load[2]),
                    'details' => [
                        '1min' => $load[0],
                        '5min' => $load[1],
                        '15min' => $load[2]
                    ]
                ];
            });
        }

        // PHP version and configuration check
        $this->register('php_config', function() {
            $version = PHP_VERSION;
            $issues = [];

            // Check for required extensions
            $requiredExtensions = ['json', 'mbstring', 'openssl'];
            foreach ($requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $issues[] = "Missing extension: {$ext}";
                }
            }

            // Check PHP configuration
            if (ini_get('display_errors')) {
                $issues[] = 'display_errors should be disabled in production';
            }

            if (!ini_get('log_errors')) {
                $issues[] = 'log_errors should be enabled';
            }

            $status = empty($issues) ? 'pass' : 'warning';

            return [
                'status' => $status,
                'message' => "PHP {$version}" . (empty($issues) ? ' - OK' : ' - Issues found'),
                'details' => [
                    'version' => $version,
                    'issues' => $issues,
                    'sapi' => PHP_SAPI
                ]
            ];
        });

        // File permissions check
        $this->register('file_permissions', function() {
            $paths = [
                sys_get_temp_dir() => 'temp directory',
                __DIR__ . '/../../' => 'application root'
            ];

            $issues = [];
            foreach ($paths as $path => $description) {
                if (!is_readable($path)) {
                    $issues[] = "{$description} not readable: {$path}";
                }
                if (!is_writable(dirname($path))) {
                    $issues[] = "{$description} not writable: {$path}";
                }
            }

            return [
                'status' => empty($issues) ? 'pass' : 'fail',
                'message' => empty($issues) ? 'File permissions OK' : 'File permission issues',
                'details' => [
                    'issues' => $issues,
                    'checked_paths' => array_keys($paths)
                ]
            ];
        });
    }

    /**
     * Run a single health check
     */
    private function runSingleCheck(string $name, array $check): array
    {
        $startTime = microtime(true);

        try {
            $callback = $check['callback'];
            $result = $callback();

            // Ensure result has required fields
            if (!is_array($result)) {
                $result = ['status' => 'fail', 'message' => 'Invalid check result'];
            }

            if (!isset($result['status'])) {
                $result['status'] = 'fail';
            }

            if (!isset($result['message'])) {
                $result['message'] = 'No message provided';
            }

            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
            $result['critical'] = $check['critical'];

            return $result;

        } catch (\Exception $e) {
            return [
                'status' => 'fail',
                'message' => 'Check execution failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'execution_time' => round((microtime(true) - $startTime) * 1000, 2),
                'critical' => $check['critical']
            ];
        }
    }

    /**
     * Get system information
     */
    private function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'hostname' => gethostname(),
            'timezone' => date_default_timezone_get(),
            'uptime' => $this->getSystemUptime()
        ];
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => $this->parseMemoryLimit(ini_get('memory_limit')),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'included_files' => count(get_included_files()),
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null
        ];
    }

    /**
     * Get HTTP status code based on health status
     */
    private function getHttpStatusCode(string $status): int
    {
        switch ($status) {
            case 'healthy':
                return 200;
            case 'degraded':
                return 200; // Still OK, just degraded
            case 'unhealthy':
                return 503; // Service Unavailable
            case 'error':
                return 500;
            default:
                return 500;
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get system uptime (Unix-like systems)
     */
    private function getSystemUptime(): ?string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (float) explode(' ', $uptime)[0];

            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return sprintf('%d days, %d hours, %d minutes', $days, $hours, $minutes);
        }

        return null;
    }

    /**
     * Create endpoint response for monitoring systems
     */
    public function createEndpoint(): \Closure
    {
        return function() {
            return $this->getHttpResponse();
        };
    }

    /**
     * Create simple ping endpoint
     */
    public function createPingEndpoint(): \Closure
    {
        return function() {
            return Response::json([
                'status' => 'ok',
                'timestamp' => time(),
                'service' => 'security-scanner'
            ], 200);
        };
    }

    /**
     * Create readiness endpoint (for Kubernetes)
     */
    public function createReadinessEndpoint(): \Closure
    {
        return function() {
            $criticalChecks = array_filter($this->checks, function($check) {
                return $check['critical'];
            });

            $results = [];
            $allPassed = true;

            foreach ($criticalChecks as $name => $check) {
                $result = $this->runSingleCheck($name, $check);
                $results[$name] = $result;

                if ($result['status'] === 'fail') {
                    $allPassed = false;
                }
            }

            $statusCode = $allPassed ? 200 : 503;

            return Response::json([
                'ready' => $allPassed,
                'checks' => $results,
                'timestamp' => time()
            ], $statusCode);
        };
    }

    /**
     * Create liveness endpoint (for Kubernetes)
     */
    public function createLivenessEndpoint(): \Closure
    {
        return function() {
            // Simple liveness check - just verify the application is responding
            return Response::json([
                'alive' => true,
                'timestamp' => time(),
                'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time())
            ], 200);
        };
    }

    /**
     * Get registered checks info
     */
    public function getChecksInfo(): array
    {
        $info = [];

        foreach ($this->checks as $name => $check) {
            $info[$name] = [
                'critical' => $check['critical'],
                'registered_at' => $check['registered_at']
            ];
        }

        return $info;
    }
}