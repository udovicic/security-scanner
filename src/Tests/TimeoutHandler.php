<?php

namespace SecurityScanner\Tests;

class TimeoutHandler
{
    private array $config;
    private array $activeTimeouts = [];
    private array $timeoutStats = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_timeout' => 30.0,
            'max_timeout' => 300.0,
            'min_timeout' => 1.0,
            'timeout_buffer' => 1.0,
            'use_signals' => function_exists('pcntl_signal'),
            'enable_soft_timeout' => true,
            'soft_timeout_warning' => 0.8, // 80% of timeout
            'cleanup_on_timeout' => true,
            'timeout_retry_count' => 2,
            'timeout_escalation' => true
        ], $config);

        if ($this->config['use_signals'] && function_exists('pcntl_signal')) {
            $this->initializeSignalHandling();
        }
    }

    /**
     * Initialize signal handling for timeouts
     */
    private function initializeSignalHandling(): void
    {
        // Set up SIGALRM handler for timeouts
        pcntl_signal(SIGALRM, [$this, 'handleTimeoutSignal']);
    }

    /**
     * Execute test with timeout protection
     */
    public function executeWithTimeout(
        AbstractTest $test,
        string $target,
        array $context = [],
        ?float $timeout = null
    ): TestResult {
        $timeout = $this->validateTimeout($timeout ?? $test->getConfig()['timeout'] ?? $this->config['default_timeout']);
        $testId = $this->generateTestId($test, $target);

        $this->recordTimeoutStart($testId, $timeout);

        try {
            if ($this->config['use_signals']) {
                return $this->executeWithSignalTimeout($test, $target, $context, $timeout, $testId);
            } else {
                return $this->executeWithPollingTimeout($test, $target, $context, $timeout, $testId);
            }
        } finally {
            $this->recordTimeoutEnd($testId);
        }
    }

    /**
     * Execute with signal-based timeout (Unix systems)
     */
    private function executeWithSignalTimeout(
        AbstractTest $test,
        string $target,
        array $context,
        float $timeout,
        string $testId
    ): TestResult {
        $this->activeTimeouts[$testId] = [
            'start_time' => microtime(true),
            'timeout' => $timeout,
            'test' => $test,
            'target' => $target,
            'timed_out' => false
        ];

        // Set alarm
        $alarmTime = (int)ceil($timeout);
        pcntl_alarm($alarmTime);

        try {
            $result = $test->execute($target, $context);

            // Clear alarm
            pcntl_alarm(0);

            // Check if we actually timed out during execution
            if ($this->activeTimeouts[$testId]['timed_out']) {
                return $this->createTimeoutResult($test, $target, $timeout);
            }

            return $result;

        } catch (\Exception $e) {
            pcntl_alarm(0);

            // Check if it was a timeout
            if ($this->activeTimeouts[$testId]['timed_out']) {
                return $this->createTimeoutResult($test, $target, $timeout);
            }

            // Re-throw non-timeout exceptions
            throw $e;
        }
    }

    /**
     * Execute with polling-based timeout (cross-platform)
     */
    private function executeWithPollingTimeout(
        AbstractTest $test,
        string $target,
        array $context,
        float $timeout,
        string $testId
    ): TestResult {
        $startTime = microtime(true);
        $softTimeoutWarned = false;

        // Start test execution in a way that allows monitoring
        $result = null;
        $exception = null;

        // Use output buffering to capture test execution
        ob_start();

        try {
            // For polling timeout, we need to wrap the test execution
            $result = $this->executeTestWithPolling($test, $target, $context, $timeout, $startTime);
        } catch (\Exception $e) {
            $exception = $e;
        } finally {
            ob_end_clean();
        }

        $executionTime = microtime(true) - $startTime;

        // Check if we exceeded timeout
        if ($executionTime > $timeout) {
            return $this->createTimeoutResult($test, $target, $timeout, $executionTime);
        }

        if ($exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Execute test with polling checks
     */
    private function executeTestWithPolling(
        AbstractTest $test,
        string $target,
        array $context,
        float $timeout,
        float $startTime
    ): TestResult {
        // This is a simplified approach - in a real implementation,
        // you might want to use process forking or async execution

        $result = $test->execute($target, $context);

        // Check timeout after execution
        $executionTime = microtime(true) - $startTime;
        if ($executionTime > $timeout) {
            throw new TimeoutException("Test execution exceeded timeout of {$timeout}s");
        }

        return $result;
    }

    /**
     * Handle timeout signal
     */
    public function handleTimeoutSignal(int $signal): void
    {
        if ($signal === SIGALRM) {
            // Mark all active timeouts as timed out
            foreach ($this->activeTimeouts as $testId => &$timeoutData) {
                $timeoutData['timed_out'] = true;
            }
        }
    }

    /**
     * Create timeout result
     */
    private function createTimeoutResult(
        AbstractTest $test,
        string $target,
        float $timeout,
        float $actualTime = null
    ): TestResult {
        $actualTime = $actualTime ?? $timeout;

        $message = sprintf(
            "Test timed out after %.2fs (limit: %.2fs)",
            $actualTime,
            $timeout
        );

        $data = [
            'timeout_limit' => $timeout,
            'actual_execution_time' => $actualTime,
            'timeout_type' => $this->config['use_signals'] ? 'signal' : 'polling',
            'timeout_exceeded_by' => max(0, $actualTime - $timeout)
        ];

        $result = new TestResult(
            $test->getName(),
            TestResult::STATUS_TIMEOUT,
            $message,
            $data,
            $actualTime
        );

        $result->setTarget($target);

        return $result;
    }

    /**
     * Validate timeout value
     */
    private function validateTimeout(float $timeout): float
    {
        if ($timeout < $this->config['min_timeout']) {
            return $this->config['min_timeout'];
        }

        if ($timeout > $this->config['max_timeout']) {
            return $this->config['max_timeout'];
        }

        return $timeout;
    }

    /**
     * Generate unique test ID
     */
    private function generateTestId(AbstractTest $test, string $target): string
    {
        return md5($test->getName() . '|' . $target . '|' . microtime(true));
    }

    /**
     * Record timeout start
     */
    private function recordTimeoutStart(string $testId, float $timeout): void
    {
        $this->timeoutStats[$testId] = [
            'start_time' => microtime(true),
            'timeout' => $timeout,
            'status' => 'running'
        ];
    }

    /**
     * Record timeout end
     */
    private function recordTimeoutEnd(string $testId): void
    {
        if (isset($this->timeoutStats[$testId])) {
            $this->timeoutStats[$testId]['end_time'] = microtime(true);
            $this->timeoutStats[$testId]['actual_duration'] =
                $this->timeoutStats[$testId]['end_time'] - $this->timeoutStats[$testId]['start_time'];
            $this->timeoutStats[$testId]['status'] = 'completed';
        }

        // Clean up active timeout
        unset($this->activeTimeouts[$testId]);
    }

    /**
     * Execute with adaptive timeout
     */
    public function executeWithAdaptiveTimeout(
        AbstractTest $test,
        string $target,
        array $context = [],
        array $timeoutHistory = []
    ): TestResult {
        $adaptiveTimeout = $this->calculateAdaptiveTimeout($test, $target, $timeoutHistory);

        return $this->executeWithTimeout($test, $target, $context, $adaptiveTimeout);
    }

    /**
     * Calculate adaptive timeout based on history
     */
    private function calculateAdaptiveTimeout(
        AbstractTest $test,
        string $target,
        array $timeoutHistory
    ): float {
        $baseTimeout = $test->getConfig()['timeout'] ?? $this->config['default_timeout'];

        if (empty($timeoutHistory)) {
            return $baseTimeout;
        }

        // Calculate average execution time from history
        $totalTime = 0;
        $count = 0;

        foreach ($timeoutHistory as $historyItem) {
            if (isset($historyItem['execution_time']) && $historyItem['execution_time'] > 0) {
                $totalTime += $historyItem['execution_time'];
                $count++;
            }
        }

        if ($count === 0) {
            return $baseTimeout;
        }

        $averageTime = $totalTime / $count;

        // Add buffer (default 50% more than average)
        $adaptiveTimeout = $averageTime * 1.5;

        // Ensure it's within bounds
        return $this->validateTimeout($adaptiveTimeout);
    }

    /**
     * Execute with escalating timeouts
     */
    public function executeWithEscalatingTimeout(
        AbstractTest $test,
        string $target,
        array $context = [],
        int $maxAttempts = 3
    ): TestResult {
        $baseTimeout = $test->getConfig()['timeout'] ?? $this->config['default_timeout'];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Increase timeout with each attempt
            $timeout = $baseTimeout * $attempt;
            $timeout = $this->validateTimeout($timeout);

            $result = $this->executeWithTimeout($test, $target, $context, $timeout);

            // If not a timeout, return the result
            if (!$result->isTimeout()) {
                $result->addData('timeout_attempts', $attempt);
                $result->addData('timeout_used', $timeout);
                return $result;
            }

            // If it's the last attempt, return the timeout result
            if ($attempt === $maxAttempts) {
                $result->addData('timeout_attempts', $attempt);
                $result->addData('max_attempts_reached', true);
                return $result;
            }
        }

        // This should never be reached, but just in case
        return $this->createTimeoutResult($test, $target, $baseTimeout);
    }

    /**
     * Execute batch with timeout management
     */
    public function executeBatchWithTimeouts(
        array $tests,
        string $target,
        array $context = [],
        ?float $totalTimeout = null
    ): array {
        $results = [];
        $startTime = microtime(true);

        foreach ($tests as $test) {
            if (!$test instanceof AbstractTest) {
                continue;
            }

            // Check if we have time left for this test
            if ($totalTimeout !== null) {
                $elapsedTime = microtime(true) - $startTime;
                $remainingTime = $totalTimeout - $elapsedTime;

                if ($remainingTime <= 0) {
                    // Create timeout result for remaining tests
                    $results[] = $this->createTimeoutResult($test, $target, 0, $elapsedTime);
                    continue;
                }

                // Use remaining time or test's timeout, whichever is smaller
                $testTimeout = min(
                    $remainingTime,
                    $test->getConfig()['timeout'] ?? $this->config['default_timeout']
                );
            } else {
                $testTimeout = null;
            }

            $results[] = $this->executeWithTimeout($test, $target, $context, $testTimeout);
        }

        return $results;
    }

    /**
     * Get timeout statistics
     */
    public function getTimeoutStatistics(): array
    {
        $stats = [
            'total_executions' => count($this->timeoutStats),
            'timeouts_occurred' => 0,
            'average_execution_time' => 0,
            'timeout_rate' => 0,
            'timeout_distribution' => []
        ];

        if (empty($this->timeoutStats)) {
            return $stats;
        }

        $totalTime = 0;
        foreach ($this->timeoutStats as $stat) {
            if (isset($stat['actual_duration'])) {
                $totalTime += $stat['actual_duration'];

                // Check if this was a timeout
                if ($stat['actual_duration'] >= $stat['timeout']) {
                    $stats['timeouts_occurred']++;
                }

                // Categorize timeout duration
                $duration = $stat['actual_duration'];
                if ($duration < 5) {
                    $stats['timeout_distribution']['< 5s']++;
                } elseif ($duration < 15) {
                    $stats['timeout_distribution']['5-15s']++;
                } elseif ($duration < 30) {
                    $stats['timeout_distribution']['15-30s']++;
                } elseif ($duration < 60) {
                    $stats['timeout_distribution']['30-60s']++;
                } else {
                    $stats['timeout_distribution']['> 60s']++;
                }
            }
        }

        $stats['average_execution_time'] = $totalTime / count($this->timeoutStats);
        $stats['timeout_rate'] = ($stats['timeouts_occurred'] / count($this->timeoutStats)) * 100;

        return $stats;
    }

    /**
     * Clean up timed out processes
     */
    public function cleanupTimeouts(): void
    {
        foreach ($this->activeTimeouts as $testId => $timeoutData) {
            if ($timeoutData['timed_out']) {
                // Perform cleanup if configured
                if ($this->config['cleanup_on_timeout']) {
                    $this->performTimeoutCleanup($timeoutData);
                }

                unset($this->activeTimeouts[$testId]);
            }
        }
    }

    /**
     * Perform cleanup for timed out test
     */
    private function performTimeoutCleanup(array $timeoutData): void
    {
        // This could include:
        // - Killing child processes
        // - Closing network connections
        // - Cleaning up temporary files
        // - Releasing locks

        $test = $timeoutData['test'];
        if (method_exists($test, 'cleanup')) {
            try {
                $test->cleanup();
            } catch (\Exception $e) {
                error_log("Cleanup failed for timed out test: " . $e->getMessage());
            }
        }
    }

    /**
     * Set custom timeout for specific test
     */
    public function setTestTimeout(string $testName, float $timeout): void
    {
        $this->config['custom_timeouts'][$testName] = $this->validateTimeout($timeout);
    }

    /**
     * Get timeout for specific test
     */
    public function getTestTimeout(string $testName): float
    {
        return $this->config['custom_timeouts'][$testName] ?? $this->config['default_timeout'];
    }

    /**
     * Check if timeout handling is available
     */
    public function isTimeoutHandlingAvailable(): bool
    {
        return $this->config['use_signals'] && function_exists('pcntl_signal');
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}

/**
 * Custom timeout exception
 */
class TimeoutException extends \Exception
{
    private float $timeout;
    private float $actualTime;

    public function __construct(string $message, float $timeout = 0, float $actualTime = 0)
    {
        parent::__construct($message);
        $this->timeout = $timeout;
        $this->actualTime = $actualTime;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getActualTime(): float
    {
        return $this->actualTime;
    }
}