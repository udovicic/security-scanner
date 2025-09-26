<?php

namespace SecurityScanner\Tests;

class TestExecutionEngine
{
    private TestRegistry $registry;
    private TimeoutHandler $timeoutHandler;
    private RetryHandler $retryHandler;
    private ResultInverter $resultInverter;
    private array $config;
    private array $executionStats = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'parallel_execution' => false,
            'max_parallel_tests' => 4,
            'enable_timeouts' => true,
            'enable_retries' => true,
            'enable_result_inversion' => true,
            'memory_limit' => '256M',
            'execution_timeout' => 300,
            'log_execution' => true,
            'cache_results' => false,
            'cache_duration' => 3600,
            'fail_fast' => false,
            'progress_callback' => null
        ], $config);

        $this->initializeComponents();
    }

    /**
     * Initialize engine components
     */
    private function initializeComponents(): void
    {
        $this->registry = new TestRegistry([
            'auto_discover' => true,
            'cache_enabled' => $this->config['cache_results']
        ]);

        $this->timeoutHandler = new TimeoutHandler([
            'default_timeout' => $this->config['execution_timeout']
        ]);

        $this->retryHandler = new RetryHandler([
            'default_max_retries' => 3
        ]);

        $this->resultInverter = new ResultInverter([
            'enable_inversion' => $this->config['enable_result_inversion']
        ]);
    }

    /**
     * Execute single test
     */
    public function executeTest(
        string $testName,
        string $target,
        array $context = [],
        array $options = []
    ): TestResult {
        $startTime = microtime(true);

        try {
            // Get test instance
            $test = $this->registry->createTestInstance($testName, $options['test_config'] ?? []);
            if (!$test) {
                return $this->createErrorResult($testName, $target, "Test not found or could not be instantiated");
            }

            // Check if test should be skipped
            if ($test->shouldSkip($target, $context)) {
                return $test->createSkippedResult("Test was skipped");
            }

            // Execute with configured handlers
            $result = $this->executeWithHandlers($test, $target, $context, $options);

            // Apply result inversion if configured
            if ($this->config['enable_result_inversion'] && isset($options['inversion_mode'])) {
                $result = $this->resultInverter->applyInversion($result, $options['inversion_mode']);
            }

            // Record execution stats
            $this->recordExecutionStats($testName, $result, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            return $this->createErrorResult($testName, $target, $e->getMessage(), [
                'exception_class' => get_class($e),
                'stack_trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Execute test with all handlers
     */
    private function executeWithHandlers(
        AbstractTest $test,
        string $target,
        array $context,
        array $options
    ): TestResult {
        // Determine execution strategy
        $useTimeout = $this->config['enable_timeouts'] && ($options['timeout'] ?? true);
        $useRetries = $this->config['enable_retries'] && ($options['retries'] ?? true);

        if ($useTimeout && $useRetries) {
            // Use retry handler which will call timeout handler
            return $this->retryHandler->executeWithRetry(
                $test,
                $target,
                $context,
                $options['max_retries'] ?? null,
                $options['retry_delay'] ?? null
            );
        } elseif ($useTimeout) {
            return $this->timeoutHandler->executeWithTimeout(
                $test,
                $target,
                $context,
                $options['timeout'] ?? null
            );
        } elseif ($useRetries) {
            return $this->retryHandler->executeWithRetry(
                $test,
                $target,
                $context,
                $options['max_retries'] ?? null,
                $options['retry_delay'] ?? null
            );
        } else {
            return $test->execute($target, $context);
        }
    }

    /**
     * Execute multiple tests
     */
    public function executeTests(
        array $testNames,
        string $target,
        array $context = [],
        array $options = []
    ): array {
        if ($this->config['parallel_execution']) {
            return $this->executeTestsParallel($testNames, $target, $context, $options);
        } else {
            return $this->executeTestsSequential($testNames, $target, $context, $options);
        }
    }

    /**
     * Execute tests sequentially
     */
    private function executeTestsSequential(
        array $testNames,
        string $target,
        array $context,
        array $options
    ): array {
        $results = [];
        $totalTests = count($testNames);

        foreach ($testNames as $index => $testName) {
            $this->reportProgress($index + 1, $totalTests, $testName);

            $result = $this->executeTest($testName, $target, $context, $options);
            $results[$testName] = $result;

            // Fail fast if enabled and test failed
            if ($this->config['fail_fast'] && $result->hasProblems()) {
                break;
            }
        }

        return $results;
    }

    /**
     * Execute tests in parallel (simplified implementation)
     */
    private function executeTestsParallel(
        array $testNames,
        string $target,
        array $context,
        array $options
    ): array {
        // Note: This is a simplified parallel execution
        // In production, you might want to use proper process forking or async libraries

        $results = [];
        $batches = array_chunk($testNames, $this->config['max_parallel_tests']);

        foreach ($batches as $batch) {
            $batchResults = [];

            // Execute batch (in this simplified version, still sequential within batch)
            foreach ($batch as $testName) {
                $batchResults[$testName] = $this->executeTest($testName, $target, $context, $options);
            }

            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Execute tests by category
     */
    public function executeTestsByCategory(
        string $category,
        string $target,
        array $context = [],
        array $options = []
    ): array {
        $categoryTests = $this->registry->getTestsByCategory($category);
        $testNames = array_keys($categoryTests);

        return $this->executeTests($testNames, $target, $context, $options);
    }

    /**
     * Execute tests by tags
     */
    public function executeTestsByTags(
        array $tags,
        string $target,
        array $context = [],
        array $options = []
    ): array {
        $taggedTests = $this->registry->getTestsByTags($tags);
        $testNames = array_keys($taggedTests);

        return $this->executeTests($testNames, $target, $context, $options);
    }

    /**
     * Execute all enabled tests
     */
    public function executeAllTests(
        string $target,
        array $context = [],
        array $options = []
    ): array {
        $enabledTests = $this->registry->getEnabledTests();
        $testNames = array_keys($enabledTests);

        return $this->executeTests($testNames, $target, $context, $options);
    }

    /**
     * Execute test suite with configuration
     */
    public function executeSuite(array $suiteConfig): TestSuiteResult
    {
        $startTime = microtime(true);
        $target = $suiteConfig['target'];
        $context = $suiteConfig['context'] ?? [];
        $options = $suiteConfig['options'] ?? [];

        $results = [];

        // Execute tests based on suite configuration
        if (isset($suiteConfig['tests'])) {
            $results = $this->executeTests($suiteConfig['tests'], $target, $context, $options);
        } elseif (isset($suiteConfig['categories'])) {
            foreach ($suiteConfig['categories'] as $category) {
                $categoryResults = $this->executeTestsByCategory($category, $target, $context, $options);
                $results = array_merge($results, $categoryResults);
            }
        } elseif (isset($suiteConfig['tags'])) {
            $results = $this->executeTestsByTags($suiteConfig['tags'], $target, $context, $options);
        } else {
            $results = $this->executeAllTests($target, $context, $options);
        }

        $executionTime = microtime(true) - $startTime;

        return new TestSuiteResult(
            $suiteConfig['name'] ?? 'Test Suite',
            $target,
            $results,
            $executionTime,
            $context
        );
    }

    /**
     * Execute health check (quick verification tests)
     */
    public function executeHealthCheck(string $target): array
    {
        $healthTests = [
            'http_status_check',
            'response_time_check',
            'basic_security_headers'
        ];

        $availableTests = array_filter($healthTests, fn($test) => $this->registry->hasTest($test));

        return $this->executeTests($availableTests, $target, [], [
            'timeout' => 10,
            'max_retries' => 1,
            'fail_fast' => false
        ]);
    }

    /**
     * Execute security scan
     */
    public function executeSecurityScan(string $target, array $options = []): array
    {
        $securityTests = $this->registry->getTestsByCategory('security');
        $testNames = array_keys($securityTests);

        return $this->executeTests($testNames, $target, [], array_merge([
            'timeout' => 60,
            'max_retries' => 2
        ], $options));
    }

    /**
     * Create error result
     */
    private function createErrorResult(string $testName, string $target, string $message, array $data = []): TestResult
    {
        $result = new TestResult($testName, TestResult::STATUS_ERROR, $message, $data);
        $result->setTarget($target);
        return $result;
    }

    /**
     * Record execution statistics
     */
    private function recordExecutionStats(string $testName, TestResult $result, float $executionTime): void
    {
        if (!isset($this->executionStats[$testName])) {
            $this->executionStats[$testName] = [
                'total_executions' => 0,
                'total_time' => 0,
                'status_counts' => [],
                'avg_execution_time' => 0
            ];
        }

        $stats = &$this->executionStats[$testName];
        $stats['total_executions']++;
        $stats['total_time'] += $executionTime;
        $stats['avg_execution_time'] = $stats['total_time'] / $stats['total_executions'];

        $status = $result->getStatus();
        $stats['status_counts'][$status] = ($stats['status_counts'][$status] ?? 0) + 1;
    }

    /**
     * Report progress
     */
    private function reportProgress(int $current, int $total, string $testName): void
    {
        if ($this->config['progress_callback'] && is_callable($this->config['progress_callback'])) {
            call_user_func($this->config['progress_callback'], $current, $total, $testName);
        }

        if ($this->config['log_execution']) {
            $percentage = round(($current / $total) * 100, 1);
            error_log("Test execution progress: {$percentage}% ({$current}/{$total}) - {$testName}");
        }
    }

    /**
     * Get execution statistics
     */
    public function getExecutionStatistics(): array
    {
        return $this->executionStats;
    }

    /**
     * Reset execution statistics
     */
    public function resetExecutionStatistics(): void
    {
        $this->executionStats = [];
    }

    /**
     * Get registry
     */
    public function getRegistry(): TestRegistry
    {
        return $this->registry;
    }

    /**
     * Get timeout handler
     */
    public function getTimeoutHandler(): TimeoutHandler
    {
        return $this->timeoutHandler;
    }

    /**
     * Get retry handler
     */
    public function getRetryHandler(): RetryHandler
    {
        return $this->retryHandler;
    }

    /**
     * Get result inverter
     */
    public function getResultInverter(): ResultInverter
    {
        return $this->resultInverter;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}

/**
 * Test Suite Result container
 */
class TestSuiteResult
{
    private string $name;
    private string $target;
    private array $results;
    private float $executionTime;
    private array $context;
    private \DateTime $timestamp;

    public function __construct(
        string $name,
        string $target,
        array $results,
        float $executionTime,
        array $context = []
    ) {
        $this->name = $name;
        $this->target = $target;
        $this->results = $results;
        $this->executionTime = $executionTime;
        $this->context = $context;
        $this->timestamp = new \DateTime();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }

    public function getTotalTests(): int
    {
        return count($this->results);
    }

    public function getPassedTests(): array
    {
        return array_filter($this->results, fn($result) => $result->isPassed());
    }

    public function getFailedTests(): array
    {
        return array_filter($this->results, fn($result) => $result->isFailed());
    }

    public function getSuccessRate(): float
    {
        $total = $this->getTotalTests();
        if ($total === 0) return 0;
        return (count($this->getPassedTests()) / $total) * 100;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'target' => $this->target,
            'execution_time' => $this->executionTime,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'total_tests' => $this->getTotalTests(),
            'passed_tests' => count($this->getPassedTests()),
            'failed_tests' => count($this->getFailedTests()),
            'success_rate' => $this->getSuccessRate(),
            'results' => array_map(fn($result) => $result->toArray(), $this->results),
            'context' => $this->context
        ];
    }
}