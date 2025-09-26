<?php

namespace SecurityScanner\Core;

class PerformanceMonitor
{
    private array $timers = [];
    private array $metrics = [];
    private array $config;
    private float $requestStartTime;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'auto_track_memory' => true,
            'auto_track_queries' => true,
            'slow_query_threshold' => 1000, // milliseconds
            'memory_limit_warning' => 0.8, // 80% of memory limit
            'max_metrics' => 1000,
            'storage_driver' => 'memory', // memory, file, cache
            'aggregation_enabled' => true,
            'percentiles' => [50, 95, 99]
        ], $config);

        $this->requestStartTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        if ($this->config['enabled']) {
            $this->initializeMonitoring();
        }
    }

    /**
     * Start a performance timer
     */
    public function startTimer(string $name, array $context = []): string
    {
        if (!$this->config['enabled']) {
            return $name;
        }

        $timerId = $name . '_' . uniqid();

        $this->timers[$timerId] = [
            'name' => $name,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context
        ];

        return $timerId;
    }

    /**
     * Stop a performance timer
     */
    public function stopTimer(string $timerId): array
    {
        if (!$this->config['enabled'] || !isset($this->timers[$timerId])) {
            return [];
        }

        $timer = $this->timers[$timerId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $metric = [
            'name' => $timer['name'],
            'duration' => ($endTime - $timer['start_time']) * 1000, // in milliseconds
            'memory_used' => $endMemory - $timer['start_memory'],
            'memory_peak' => memory_get_peak_usage(true),
            'start_time' => $timer['start_time'],
            'end_time' => $endTime,
            'context' => $timer['context'],
            'timestamp' => time()
        ];

        $this->addMetric('timer', $metric);

        unset($this->timers[$timerId]);

        return $metric;
    }

    /**
     * Measure execution time of a callable
     */
    public function measure(string $name, callable $callback, array $context = [])
    {
        $timerId = $this->startTimer($name, $context);

        try {
            $result = $callback();
            $this->stopTimer($timerId);
            return $result;
        } catch (\Exception $e) {
            $this->stopTimer($timerId);
            $this->recordError($name, $e, $context);
            throw $e;
        }
    }

    /**
     * Record a custom metric
     */
    public function recordMetric(string $name, $value, string $type = 'gauge', array $tags = []): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->addMetric('custom', [
            'name' => $name,
            'value' => $value,
            'type' => $type, // gauge, counter, histogram, timer
            'tags' => $tags,
            'timestamp' => time()
        ]);
    }

    /**
     * Increment a counter
     */
    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->recordMetric($name, $value, 'counter', $tags);
    }

    /**
     * Record a gauge value
     */
    public function gauge(string $name, $value, array $tags = []): void
    {
        $this->recordMetric($name, $value, 'gauge', $tags);
    }

    /**
     * Record histogram value
     */
    public function histogram(string $name, $value, array $tags = []): void
    {
        $this->recordMetric($name, $value, 'histogram', $tags);
    }

    /**
     * Record database query performance
     */
    public function recordQuery(string $sql, float $duration, bool $cached = false, array $context = []): void
    {
        if (!$this->config['enabled'] || !$this->config['auto_track_queries']) {
            return;
        }

        $queryType = $this->getQueryType($sql);

        $metric = [
            'sql' => $sql,
            'duration' => $duration * 1000, // convert to milliseconds
            'cached' => $cached,
            'query_type' => $queryType,
            'context' => $context,
            'timestamp' => time()
        ];

        $this->addMetric('query', $metric);

        // Track slow queries
        if ($duration * 1000 > $this->config['slow_query_threshold']) {
            $this->addMetric('slow_query', $metric);
        }

        // Update query statistics
        $this->increment("queries.{$queryType}.total");
        if ($cached) {
            $this->increment("queries.{$queryType}.cached");
        }
        $this->histogram("queries.{$queryType}.duration", $duration * 1000);
    }

    /**
     * Record an error or exception
     */
    public function recordError(string $operation, \Exception $exception, array $context = []): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->addMetric('error', [
            'operation' => $operation,
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context,
            'timestamp' => time()
        ]);

        $this->increment('errors.total');
        $this->increment('errors.' . strtolower(get_class($exception)));
    }

    /**
     * Track HTTP request performance
     */
    public function trackRequest(Request $request, Response $response, float $duration): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $metric = [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'status_code' => $response->getStatusCode(),
            'duration' => $duration * 1000,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => time()
        ];

        $this->addMetric('request', $metric);

        // Update request statistics
        $this->increment('requests.total');
        $this->increment('requests.method.' . strtolower($request->getMethod()));
        $this->increment('requests.status.' . $response->getStatusCode());
        $this->histogram('requests.duration', $duration * 1000);
        $this->gauge('requests.memory_usage', memory_get_usage(true));
    }

    /**
     * Get current performance statistics
     */
    public function getStats(): array
    {
        $stats = [
            'request_time' => (microtime(true) - $this->requestStartTime) * 1000,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'included_files' => count(get_included_files()),
            'metrics_count' => count($this->metrics),
            'active_timers' => count($this->timers)
        ];

        if ($this->config['aggregation_enabled']) {
            $stats['aggregated_metrics'] = $this->getAggregatedMetrics();
        }

        return $stats;
    }

    /**
     * Get aggregated metrics
     */
    public function getAggregatedMetrics(): array
    {
        $aggregated = [];

        foreach ($this->metrics as $type => $typeMetrics) {
            $aggregated[$type] = $this->aggregateMetricsForType($typeMetrics);
        }

        return $aggregated;
    }

    /**
     * Get slow queries
     */
    public function getSlowQueries(int $limit = 10): array
    {
        $slowQueries = $this->metrics['slow_query'] ?? [];

        usort($slowQueries, function($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });

        return array_slice($slowQueries, 0, $limit);
    }

    /**
     * Get error summary
     */
    public function getErrorSummary(): array
    {
        $errors = $this->metrics['error'] ?? [];

        $summary = [
            'total_errors' => count($errors),
            'by_type' => [],
            'recent_errors' => array_slice($errors, -10)
        ];

        foreach ($errors as $error) {
            $type = $error['exception_class'];
            if (!isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = 0;
            }
            $summary['by_type'][$type]++;
        }

        return $summary;
    }

    /**
     * Create performance middleware
     */
    public function middleware(): \Closure
    {
        return function(Request $request, \Closure $next) {
            if (!$this->config['enabled']) {
                return $next($request);
            }

            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            try {
                $response = $next($request);
                $duration = microtime(true) - $startTime;

                $this->trackRequest($request, $response, $duration);

                // Add performance headers
                if ($response instanceof Response) {
                    $response->setHeader('X-Response-Time', number_format($duration * 1000, 2) . 'ms')
                             ->setHeader('X-Memory-Usage', $this->formatBytes(memory_get_usage(true)));
                }

                return $response;

            } catch (\Exception $e) {
                $duration = microtime(true) - $startTime;
                $this->recordError('request', $e, [
                    'method' => $request->getMethod(),
                    'path' => $request->getPath(),
                    'duration' => $duration * 1000
                ]);

                throw $e;
            }
        };
    }

    /**
     * Initialize performance monitoring
     */
    private function initializeMonitoring(): void
    {
        // Track initial memory usage
        if ($this->config['auto_track_memory']) {
            $this->gauge('memory.initial', memory_get_usage(true));
        }

        // Register shutdown function to capture final stats
        register_shutdown_function(function() {
            if ($this->config['auto_track_memory']) {
                $this->gauge('memory.final', memory_get_usage(true));
                $this->gauge('memory.peak', memory_get_peak_usage(true));
            }
        });
    }

    /**
     * Add a metric to storage
     */
    private function addMetric(string $type, array $metric): void
    {
        if (!isset($this->metrics[$type])) {
            $this->metrics[$type] = [];
        }

        $this->metrics[$type][] = $metric;

        // Limit metrics to prevent memory issues
        if (count($this->metrics[$type]) > $this->config['max_metrics']) {
            array_shift($this->metrics[$type]);
        }
    }

    /**
     * Get query type from SQL
     */
    private function getQueryType(string $sql): string
    {
        $sql = strtolower(trim($sql));

        if (str_starts_with($sql, 'select')) return 'select';
        if (str_starts_with($sql, 'insert')) return 'insert';
        if (str_starts_with($sql, 'update')) return 'update';
        if (str_starts_with($sql, 'delete')) return 'delete';
        if (str_starts_with($sql, 'show')) return 'show';
        if (str_starts_with($sql, 'describe')) return 'describe';

        return 'other';
    }

    /**
     * Aggregate metrics for a specific type
     */
    private function aggregateMetricsForType(array $metrics): array
    {
        if (empty($metrics)) {
            return [];
        }

        $aggregated = [
            'count' => count($metrics),
            'latest_timestamp' => 0
        ];

        // Find numeric fields for aggregation
        $numericFields = [];
        foreach ($metrics as $metric) {
            foreach ($metric as $key => $value) {
                if (is_numeric($value) && !in_array($key, ['timestamp'])) {
                    $numericFields[] = $key;
                }
            }
            break;
        }

        $numericFields = array_unique($numericFields);

        foreach ($numericFields as $field) {
            $values = array_column($metrics, $field);
            $values = array_filter($values, 'is_numeric');

            if (!empty($values)) {
                $aggregated[$field] = [
                    'min' => min($values),
                    'max' => max($values),
                    'avg' => array_sum($values) / count($values),
                    'sum' => array_sum($values)
                ];

                // Calculate percentiles
                sort($values);
                foreach ($this->config['percentiles'] as $percentile) {
                    $index = (int)(($percentile / 100) * (count($values) - 1));
                    $aggregated[$field]["p{$percentile}"] = $values[$index];
                }
            }
        }

        // Get latest timestamp
        $timestamps = array_column($metrics, 'timestamp');
        if (!empty($timestamps)) {
            $aggregated['latest_timestamp'] = max($timestamps);
        }

        return $aggregated;
    }

    /**
     * Format bytes to human readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Export metrics for external monitoring systems
     */
    public function exportMetrics(string $format = 'json'): string
    {
        $data = [
            'timestamp' => time(),
            'request_time' => (microtime(true) - $this->requestStartTime) * 1000,
            'stats' => $this->getStats(),
            'metrics' => $this->metrics
        ];

        switch ($format) {
            case 'prometheus':
                return $this->exportPrometheus($data);
            case 'json':
            default:
                return json_encode($data, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Export metrics in Prometheus format
     */
    private function exportPrometheus(array $data): string
    {
        $output = [];

        // Add basic metrics
        $output[] = "# HELP request_duration_ms Total request duration in milliseconds";
        $output[] = "# TYPE request_duration_ms gauge";
        $output[] = "request_duration_ms " . $data['request_time'];

        $output[] = "# HELP memory_usage_bytes Current memory usage in bytes";
        $output[] = "# TYPE memory_usage_bytes gauge";
        $output[] = "memory_usage_bytes " . $data['stats']['memory_usage'];

        $output[] = "# HELP memory_peak_bytes Peak memory usage in bytes";
        $output[] = "# TYPE memory_peak_bytes gauge";
        $output[] = "memory_peak_bytes " . $data['stats']['memory_peak'];

        return implode("\n", $output) . "\n";
    }

    /**
     * Create performance monitoring instance
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Get current memory usage percentage
     */
    public function getMemoryUsagePercent(): float
    {
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $usage = memory_get_usage(true);

        if ($limit === -1) {
            return 0;
        }

        return ($usage / $limit) * 100;
    }

    /**
     * Parse memory limit
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
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
}