<?php

namespace SecurityScanner\Tests;

class RetryHandler
{
    private array $config;
    private array $retryStats = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_max_retries' => 3,
            'default_retry_delay' => 1.0,
            'exponential_backoff' => true,
            'backoff_multiplier' => 2.0,
            'max_retry_delay' => 60.0,
            'jitter' => true,
            'jitter_max' => 0.1,
            'retryable_statuses' => [
                TestResult::STATUS_ERROR,
                TestResult::STATUS_TIMEOUT
            ],
            'retryable_exceptions' => [
                \RuntimeException::class,
                \Exception::class
            ],
            'custom_retry_conditions' => []
        ], $config);
    }

    /**
     * Execute test with retry logic
     */
    public function executeWithRetry(
        AbstractTest $test,
        string $target,
        array $context = [],
        ?int $maxRetries = null,
        ?float $retryDelay = null
    ): TestResult {
        $maxRetries = $maxRetries ?? $test->getConfig()['max_retries'] ?? $this->config['default_max_retries'];
        $retryDelay = $retryDelay ?? $this->config['default_retry_delay'];

        $attempts = [];
        $lastResult = null;

        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            $startTime = microtime(true);

            try {
                $result = $test->execute($target, $context);
                $executionTime = microtime(true) - $startTime;

                $attempts[] = [
                    'attempt' => $attempt,
                    'result' => $result,
                    'execution_time' => $executionTime,
                    'exception' => null
                ];

                // Check if retry is needed
                if (!$this->shouldRetry($result, $attempt, $maxRetries + 1)) {
                    return $this->finalizeResult($result, $attempts);
                }

                $lastResult = $result;

            } catch (\Exception $e) {
                $executionTime = microtime(true) - $startTime;

                $attempts[] = [
                    'attempt' => $attempt,
                    'result' => null,
                    'execution_time' => $executionTime,
                    'exception' => $e
                ];

                // Check if we should retry this exception
                if (!$this->shouldRetryException($e, $attempt, $maxRetries + 1)) {
                    return $this->createErrorResult($test, $target, $e, $attempts);
                }

                $lastResult = $this->createErrorResult($test, $target, $e, $attempts);
            }

            // Don't delay after the last attempt
            if ($attempt <= $maxRetries) {
                $this->performRetryDelay($retryDelay, $attempt);
            }
        }

        // If we get here, all retries were exhausted
        return $this->createRetriesExhaustedResult($test, $target, $lastResult, $attempts);
    }

    /**
     * Check if result should trigger a retry
     */
    private function shouldRetry(TestResult $result, int $attempt, int $maxAttempts): bool
    {
        // Don't retry if this is the last attempt
        if ($attempt >= $maxAttempts) {
            return false;
        }

        // Check if status is retryable
        if (in_array($result->getStatus(), $this->config['retryable_statuses'])) {
            return true;
        }

        // Check custom retry conditions
        foreach ($this->config['custom_retry_conditions'] as $condition) {
            if (is_callable($condition) && $condition($result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if exception should trigger a retry
     */
    private function shouldRetryException(\Exception $exception, int $attempt, int $maxAttempts): bool
    {
        // Don't retry if this is the last attempt
        if ($attempt >= $maxAttempts) {
            return false;
        }

        // Check if exception type is retryable
        foreach ($this->config['retryable_exceptions'] as $retryableClass) {
            if ($exception instanceof $retryableClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform retry delay with backoff and jitter
     */
    private function performRetryDelay(float $baseDelay, int $attempt): void
    {
        $delay = $baseDelay;

        // Apply exponential backoff
        if ($this->config['exponential_backoff']) {
            $delay = $baseDelay * pow($this->config['backoff_multiplier'], $attempt - 1);
        }

        // Apply maximum delay limit
        $delay = min($delay, $this->config['max_retry_delay']);

        // Add jitter to prevent thundering herd
        if ($this->config['jitter']) {
            $jitterAmount = $delay * $this->config['jitter_max'];
            $jitter = (random_int(0, 1000) / 1000) * $jitterAmount;
            $delay += $jitter;
        }

        // Sleep for the calculated delay
        if ($delay > 0) {
            usleep((int)($delay * 1000000));
        }
    }

    /**
     * Finalize successful result with retry metadata
     */
    private function finalizeResult(TestResult $result, array $attempts): TestResult
    {
        $attemptCount = count($attempts);

        if ($attemptCount > 1) {
            $result->addData('retry_attempts', $attemptCount);
            $result->addData('retry_history', $this->summarizeAttempts($attempts));

            // Update message to indicate retries
            $originalMessage = $result->getMessage();
            $retryNote = " (succeeded after {$attemptCount} attempts)";
            $result->setMessage($originalMessage . $retryNote);
        }

        $this->recordRetryStats($result->getTestName(), $attempts, true);

        return $result;
    }

    /**
     * Create error result from exception
     */
    private function createErrorResult(
        AbstractTest $test,
        string $target,
        \Exception $exception,
        array $attempts
    ): TestResult {
        $result = new TestResult(
            $test->getName(),
            TestResult::STATUS_ERROR,
            "Test failed: " . $exception->getMessage(),
            [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTraceAsString()
            ]
        );

        $result->setTarget($target);

        if (count($attempts) > 1) {
            $result->addData('retry_attempts', count($attempts));
            $result->addData('retry_history', $this->summarizeAttempts($attempts));
        }

        $this->recordRetryStats($test->getName(), $attempts, false);

        return $result;
    }

    /**
     * Create result when all retries are exhausted
     */
    private function createRetriesExhaustedResult(
        AbstractTest $test,
        string $target,
        ?TestResult $lastResult,
        array $attempts
    ): TestResult {
        $message = "Test failed after " . count($attempts) . " attempts";

        if ($lastResult) {
            $message .= ": " . $lastResult->getMessage();
        }

        $result = new TestResult(
            $test->getName(),
            TestResult::STATUS_FAIL,
            $message,
            [
                'retry_attempts' => count($attempts),
                'retry_history' => $this->summarizeAttempts($attempts),
                'retries_exhausted' => true
            ]
        );

        $result->setTarget($target);

        if ($lastResult) {
            $result->addData('last_result_data', $lastResult->getData());
            $result->setScore($lastResult->getScore());
        }

        $this->recordRetryStats($test->getName(), $attempts, false);

        return $result;
    }

    /**
     * Summarize attempts for result data
     */
    private function summarizeAttempts(array $attempts): array
    {
        $summary = [];

        foreach ($attempts as $attempt) {
            $attemptSummary = [
                'attempt' => $attempt['attempt'],
                'execution_time' => $attempt['execution_time']
            ];

            if ($attempt['result']) {
                $attemptSummary['status'] = $attempt['result']->getStatus();
                $attemptSummary['message'] = $attempt['result']->getMessage();
            } elseif ($attempt['exception']) {
                $attemptSummary['status'] = 'exception';
                $attemptSummary['exception'] = get_class($attempt['exception']);
                $attemptSummary['message'] = $attempt['exception']->getMessage();
            }

            $summary[] = $attemptSummary;
        }

        return $summary;
    }

    /**
     * Record retry statistics
     */
    private function recordRetryStats(string $testName, array $attempts, bool $succeeded): void
    {
        if (!isset($this->retryStats[$testName])) {
            $this->retryStats[$testName] = [
                'total_executions' => 0,
                'total_attempts' => 0,
                'successful_retries' => 0,
                'failed_retries' => 0,
                'avg_attempts' => 0
            ];
        }

        $stats = &$this->retryStats[$testName];
        $stats['total_executions']++;
        $stats['total_attempts'] += count($attempts);

        if ($succeeded && count($attempts) > 1) {
            $stats['successful_retries']++;
        } elseif (!$succeeded && count($attempts) > 1) {
            $stats['failed_retries']++;
        }

        $stats['avg_attempts'] = $stats['total_attempts'] / $stats['total_executions'];
    }

    /**
     * Execute with smart retry based on failure pattern
     */
    public function executeWithSmartRetry(
        AbstractTest $test,
        string $target,
        array $context = [],
        array $failureHistory = []
    ): TestResult {
        $smartConfig = $this->calculateSmartRetryConfig($test, $target, $failureHistory);

        return $this->executeWithRetry(
            $test,
            $target,
            $context,
            $smartConfig['max_retries'],
            $smartConfig['retry_delay']
        );
    }

    /**
     * Calculate smart retry configuration
     */
    private function calculateSmartRetryConfig(
        AbstractTest $test,
        string $target,
        array $failureHistory
    ): array {
        $defaultMaxRetries = $this->config['default_max_retries'];
        $defaultRetryDelay = $this->config['default_retry_delay'];

        if (empty($failureHistory)) {
            return [
                'max_retries' => $defaultMaxRetries,
                'retry_delay' => $defaultRetryDelay
            ];
        }

        // Analyze failure patterns
        $recentFailures = array_slice($failureHistory, -10); // Last 10 failures
        $timeoutCount = 0;
        $errorCount = 0;
        $avgRecoveryTime = 0;

        foreach ($recentFailures as $failure) {
            if ($failure['status'] === TestResult::STATUS_TIMEOUT) {
                $timeoutCount++;
            } elseif ($failure['status'] === TestResult::STATUS_ERROR) {
                $errorCount++;
            }

            if (isset($failure['recovery_time'])) {
                $avgRecoveryTime += $failure['recovery_time'];
            }
        }

        if (count($recentFailures) > 0) {
            $avgRecoveryTime /= count($recentFailures);
        }

        // Adjust based on patterns
        $maxRetries = $defaultMaxRetries;
        $retryDelay = $defaultRetryDelay;

        // More retries for intermittent errors
        if ($errorCount > $timeoutCount) {
            $maxRetries = min($defaultMaxRetries + 2, 8);
        }

        // Longer delay for timeout-prone targets
        if ($timeoutCount > count($recentFailures) * 0.5) {
            $retryDelay = min($defaultRetryDelay * 2, 10.0);
        }

        // Adjust delay based on recovery time
        if ($avgRecoveryTime > 0) {
            $retryDelay = max($retryDelay, $avgRecoveryTime * 0.1);
        }

        return [
            'max_retries' => $maxRetries,
            'retry_delay' => $retryDelay
        ];
    }

    /**
     * Execute batch with retry logic
     */
    public function executeBatchWithRetry(
        array $tests,
        string $target,
        array $context = []
    ): array {
        $results = [];

        foreach ($tests as $test) {
            if (!$test instanceof AbstractTest) {
                continue;
            }

            $results[] = $this->executeWithRetry($test, $target, $context);
        }

        return $results;
    }

    /**
     * Add custom retry condition
     */
    public function addRetryCondition(callable $condition): void
    {
        $this->config['custom_retry_conditions'][] = $condition;
    }

    /**
     * Remove all custom retry conditions
     */
    public function clearRetryConditions(): void
    {
        $this->config['custom_retry_conditions'] = [];
    }

    /**
     * Set retryable status
     */
    public function addRetryableStatus(string $status): void
    {
        if (!in_array($status, $this->config['retryable_statuses'])) {
            $this->config['retryable_statuses'][] = $status;
        }
    }

    /**
     * Remove retryable status
     */
    public function removeRetryableStatus(string $status): void
    {
        $this->config['retryable_statuses'] = array_filter(
            $this->config['retryable_statuses'],
            fn($s) => $s !== $status
        );
    }

    /**
     * Add retryable exception class
     */
    public function addRetryableException(string $exceptionClass): void
    {
        if (!in_array($exceptionClass, $this->config['retryable_exceptions'])) {
            $this->config['retryable_exceptions'][] = $exceptionClass;
        }
    }

    /**
     * Get retry statistics
     */
    public function getRetryStatistics(): array
    {
        $globalStats = [
            'total_tests_with_retries' => count($this->retryStats),
            'total_executions' => 0,
            'total_attempts' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'overall_retry_rate' => 0,
            'overall_success_rate' => 0
        ];

        foreach ($this->retryStats as $testStats) {
            $globalStats['total_executions'] += $testStats['total_executions'];
            $globalStats['total_attempts'] += $testStats['total_attempts'];
            $globalStats['successful_retries'] += $testStats['successful_retries'];
            $globalStats['failed_retries'] += $testStats['failed_retries'];
        }

        if ($globalStats['total_executions'] > 0) {
            $retriedExecutions = $globalStats['successful_retries'] + $globalStats['failed_retries'];
            $globalStats['overall_retry_rate'] = ($retriedExecutions / $globalStats['total_executions']) * 100;

            if ($retriedExecutions > 0) {
                $globalStats['overall_success_rate'] = ($globalStats['successful_retries'] / $retriedExecutions) * 100;
            }
        }

        return [
            'global' => $globalStats,
            'per_test' => $this->retryStats
        ];
    }

    /**
     * Reset retry statistics
     */
    public function resetRetryStatistics(): void
    {
        $this->retryStats = [];
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