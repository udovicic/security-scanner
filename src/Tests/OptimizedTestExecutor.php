<?php

namespace SecurityScanner\Tests;

use SecurityScanner\Core\Logger;
use SecurityScanner\Core\Config;

class OptimizedTestExecutor
{
    private Logger $logger;
    private Config $config;
    private array $optimizationStrategies = [];
    private array $executionMetrics = [];
    private TestDependencyGraph $dependencyGraph;
    private TestScheduler $scheduler;
    private ResourcePool $resourcePool;

    public function __construct(array $config = [])
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance('test_execution');

        $this->optimizationStrategies = array_merge([
            'dependency_resolution' => true,
            'parallel_execution' => true,
            'resource_pooling' => true,
            'batch_optimization' => true,
            'cache_results' => true,
            'adaptive_timeouts' => true,
            'load_balancing' => true,
            'priority_scheduling' => true
        ], $config);

        $this->initializeComponents();
    }

    private function initializeComponents(): void
    {
        $this->dependencyGraph = new TestDependencyGraph();
        $this->scheduler = new TestScheduler($this->optimizationStrategies);
        $this->resourcePool = new ResourcePool([
            'max_connections' => $this->config->get('test_execution.max_connections', 10),
            'connection_timeout' => $this->config->get('test_execution.connection_timeout', 30),
            'pool_size' => $this->config->get('test_execution.pool_size', 20)
        ]);
    }

    public function executeBatch(array $testJobs): array
    {
        $startTime = microtime(true);

        try {
            // Analyze test dependencies
            $dependencyMap = $this->dependencyGraph->analyze($testJobs);

            // Optimize execution order
            $optimizedJobs = $this->scheduler->optimize($testJobs, $dependencyMap);

            // Execute tests using optimal strategy
            $results = $this->executeOptimized($optimizedJobs);

            // Record performance metrics
            $this->recordBatchMetrics($testJobs, $results, microtime(true) - $startTime);

            return [
                'success' => true,
                'results' => $results,
                'metrics' => $this->getExecutionMetrics(),
                'optimizations_applied' => $this->getAppliedOptimizations()
            ];

        } catch (\Exception $e) {
            $this->logger->error('Batch execution failed', [
                'error' => $e->getMessage(),
                'job_count' => count($testJobs),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
                'metrics' => $this->getExecutionMetrics()
            ];
        }
    }

    private function executeOptimized(array $optimizedJobs): array
    {
        if ($this->optimizationStrategies['parallel_execution']) {
            return $this->executeParallel($optimizedJobs);
        }

        return $this->executeSequential($optimizedJobs);
    }

    private function executeParallel(array $jobs): array
    {
        $results = [];
        $runningJobs = [];
        $maxConcurrent = $this->config->get('test_execution.max_concurrent', 4);

        while (!empty($jobs) || !empty($runningJobs)) {
            // Start new jobs if capacity available
            while (count($runningJobs) < $maxConcurrent && !empty($jobs)) {
                $job = array_shift($jobs);

                if ($this->canExecuteJob($job, $results)) {
                    $runningJobs[$job['id']] = $this->startAsyncJob($job);
                } else {
                    // Job dependencies not met, put back at end
                    $jobs[] = $job;
                }
            }

            // Check for completed jobs
            $completedJobs = $this->checkCompletedJobs($runningJobs);

            foreach ($completedJobs as $jobId => $result) {
                $results[$jobId] = $result;
                unset($runningJobs[$jobId]);
            }

            // Prevent busy waiting
            if (!empty($runningJobs)) {
                usleep(10000); // 10ms
            }
        }

        return $results;
    }

    private function executeSequential(array $jobs): array
    {
        $results = [];

        foreach ($jobs as $job) {
            if ($this->canExecuteJob($job, $results)) {
                $results[$job['id']] = $this->executeJob($job);
            } else {
                $results[$job['id']] = $this->createSkippedResult($job, 'Dependencies not met');
            }
        }

        return $results;
    }

    private function canExecuteJob(array $job, array $completedResults): bool
    {
        if (empty($job['dependencies'])) {
            return true;
        }

        foreach ($job['dependencies'] as $dependency) {
            if (!isset($completedResults[$dependency]) || !$completedResults[$dependency]['success']) {
                return false;
            }
        }

        return true;
    }

    private function startAsyncJob(array $job): array
    {
        $resource = $this->resourcePool->acquire();

        return [
            'job' => $job,
            'resource' => $resource,
            'start_time' => microtime(true),
            'process' => $this->createAsyncProcess($job, $resource)
        ];
    }

    private function createAsyncProcess(array $job, $resource): array
    {
        // In a real implementation, this would create an actual async process
        // For now, we'll simulate with a simple job structure
        return [
            'id' => $job['id'],
            'status' => 'running',
            'progress' => 0,
            'started_at' => microtime(true)
        ];
    }

    private function checkCompletedJobs(array $runningJobs): array
    {
        $completed = [];

        foreach ($runningJobs as $jobId => $jobData) {
            if ($this->isJobCompleted($jobData)) {
                $result = $this->getJobResult($jobData);
                $this->resourcePool->release($jobData['resource']);
                $completed[$jobId] = $result;
            }
        }

        return $completed;
    }

    private function isJobCompleted(array $jobData): bool
    {
        // Simulate job completion based on time and random factors
        $elapsed = microtime(true) - $jobData['start_time'];
        $estimatedDuration = $this->estimateJobDuration($jobData['job']);

        return $elapsed >= $estimatedDuration || mt_rand(1, 100) > 90;
    }

    private function estimateJobDuration(array $job): float
    {
        // Use historical data or job complexity to estimate duration
        $baseTime = 1.0; // 1 second base
        $complexity = $job['complexity'] ?? 1.0;
        $historical = $this->getHistoricalDuration($job['test_name']) ?? $baseTime;

        return max($baseTime, $historical * $complexity);
    }

    private function getHistoricalDuration(string $testName): ?float
    {
        // Retrieve historical execution time from metrics
        return $this->executionMetrics['historical_durations'][$testName] ?? null;
    }

    private function executeJob(array $job): array
    {
        $startTime = microtime(true);

        try {
            // Use resource pool for efficient resource management
            $resource = $this->resourcePool->acquire();

            // Execute the test with resource
            $result = $this->runTestWithResource($job, $resource);

            // Release resource back to pool
            $this->resourcePool->release($resource);

            $duration = microtime(true) - $startTime;

            return [
                'success' => true,
                'result' => $result,
                'duration' => $duration,
                'resource_id' => $resource['id']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime
            ];
        }
    }

    private function runTestWithResource(array $job, array $resource): array
    {
        // Simulate test execution with optimizations
        $testName = $job['test_name'];
        $target = $job['target'];

        // Apply caching if enabled
        if ($this->optimizationStrategies['cache_results']) {
            $cacheKey = $this->generateCacheKey($testName, $target, $job['parameters'] ?? []);

            if ($cachedResult = $this->getCachedResult($cacheKey)) {
                return array_merge($cachedResult, ['from_cache' => true]);
            }
        }

        // Execute test with adaptive timeout
        $timeout = $this->calculateAdaptiveTimeout($job);
        $result = $this->executeTestWithTimeout($job, $timeout);

        // Cache result if caching enabled
        if ($this->optimizationStrategies['cache_results'] && $result['success']) {
            $this->cacheResult($cacheKey, $result);
        }

        return $result;
    }

    private function calculateAdaptiveTimeout(array $job): int
    {
        if (!$this->optimizationStrategies['adaptive_timeouts']) {
            return $this->config->get('test_execution.default_timeout', 30);
        }

        $baseTimeout = 30;
        $complexity = $job['complexity'] ?? 1.0;
        $historical = $this->getHistoricalDuration($job['test_name']) ?? $baseTimeout;

        return (int) max($baseTimeout, $historical * 1.5 * $complexity);
    }

    private function executeTestWithTimeout(array $job, int $timeout): array
    {
        // Simulate test execution
        $success = mt_rand(1, 100) > 10; // 90% success rate
        $duration = mt_rand(500, 3000) / 1000; // 0.5-3 seconds

        return [
            'success' => $success,
            'test_name' => $job['test_name'],
            'target' => $job['target'],
            'duration' => $duration,
            'timeout_used' => $timeout,
            'details' => [
                'status_code' => $success ? 200 : 500,
                'response_time' => $duration,
                'checks_passed' => $success ? 5 : 2,
                'checks_total' => 5
            ]
        ];
    }

    private function getJobResult(array $jobData): array
    {
        // Get result from completed async job
        return $this->executeJob($jobData['job']);
    }

    private function createSkippedResult(array $job, string $reason): array
    {
        return [
            'success' => false,
            'skipped' => true,
            'reason' => $reason,
            'test_name' => $job['test_name'],
            'target' => $job['target']
        ];
    }

    private function generateCacheKey(string $testName, string $target, array $parameters): string
    {
        return 'test_result:' . md5($testName . ':' . $target . ':' . serialize($parameters));
    }

    private function getCachedResult(string $cacheKey): ?array
    {
        // Implement caching logic (could use Redis, Memcached, or file cache)
        return null; // For now, no caching
    }

    private function cacheResult(string $cacheKey, array $result): void
    {
        // Implement caching logic
        // Store result with TTL
    }

    private function recordBatchMetrics(array $jobs, array $results, float $totalDuration): void
    {
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failureCount = count($results) - $successCount;

        $this->executionMetrics = [
            'batch_id' => uniqid('batch_'),
            'total_jobs' => count($jobs),
            'successful_jobs' => $successCount,
            'failed_jobs' => $failureCount,
            'success_rate' => count($jobs) > 0 ? ($successCount / count($jobs)) * 100 : 0,
            'total_duration' => $totalDuration,
            'average_job_duration' => count($jobs) > 0 ? $totalDuration / count($jobs) : 0,
            'jobs_per_second' => $totalDuration > 0 ? count($jobs) / $totalDuration : 0,
            'memory_peak' => memory_get_peak_usage(true),
            'memory_current' => memory_get_usage(true),
            'optimizations_applied' => array_filter($this->optimizationStrategies)
        ];

        $this->logger->info('Batch execution completed', $this->executionMetrics);
    }

    public function getExecutionMetrics(): array
    {
        return $this->executionMetrics;
    }

    public function getAppliedOptimizations(): array
    {
        return array_filter($this->optimizationStrategies);
    }

    public function getResourcePoolStats(): array
    {
        return $this->resourcePool->getStats();
    }

    public function getDependencyGraphStats(): array
    {
        return $this->dependencyGraph->getStats();
    }

    public function optimizeForTarget(string $target, array $jobs): array
    {
        // Target-specific optimizations
        $optimized = [];

        foreach ($jobs as $job) {
            $optimizedJob = $job;

            // Adjust timeouts based on target type
            if (strpos($target, 'https://') === 0) {
                $optimizedJob['timeout'] = $this->config->get('test_execution.https_timeout', 45);
            } else {
                $optimizedJob['timeout'] = $this->config->get('test_execution.http_timeout', 30);
            }

            // Adjust complexity based on target characteristics
            $domain = parse_url($target, PHP_URL_HOST);
            if ($this->isDomainKnownSlow($domain)) {
                $optimizedJob['complexity'] = ($optimizedJob['complexity'] ?? 1.0) * 1.5;
            }

            $optimized[] = $optimizedJob;
        }

        return $optimized;
    }

    private function isDomainKnownSlow(string $domain): bool
    {
        $slowDomains = $this->config->get('test_execution.known_slow_domains', []);
        return in_array($domain, $slowDomains);
    }

    public function enableOptimization(string $strategy, bool $enable = true): void
    {
        $this->optimizationStrategies[$strategy] = $enable;

        $this->logger->info('Optimization strategy changed', [
            'strategy' => $strategy,
            'enabled' => $enable
        ]);
    }

    public function getOptimizationRecommendations(): array
    {
        $recommendations = [];

        // Analyze recent execution metrics
        if (isset($this->executionMetrics['success_rate']) && $this->executionMetrics['success_rate'] < 80) {
            $recommendations[] = [
                'type' => 'reliability',
                'message' => 'Consider enabling retry logic or increasing timeouts',
                'action' => 'enable_retries'
            ];
        }

        if (isset($this->executionMetrics['average_job_duration']) && $this->executionMetrics['average_job_duration'] > 10) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Tests are running slowly, consider parallel execution',
                'action' => 'enable_parallel_execution'
            ];
        }

        return $recommendations;
    }
}