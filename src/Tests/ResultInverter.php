<?php

namespace SecurityScanner\Tests;

class ResultInverter
{
    private array $config;
    private array $inversionRules;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_inversion' => true,
            'default_inversion_mode' => 'none',
            'custom_rules_enabled' => true,
            'preserve_original_result' => true,
            'log_inversions' => false
        ], $config);

        $this->inversionRules = [];
        $this->initializeDefaultRules();
    }

    /**
     * Initialize default inversion rules
     */
    private function initializeDefaultRules(): void
    {
        // Default rules for common scenarios
        $this->addInversionRule('expect_failure', function(TestResult $result) {
            // Invert pass/fail - expecting the test to fail
            return match($result->getStatus()) {
                TestResult::STATUS_PASS => TestResult::STATUS_FAIL,
                TestResult::STATUS_FAIL => TestResult::STATUS_PASS,
                default => $result->getStatus()
            };
        });

        $this->addInversionRule('expect_warning', function(TestResult $result) {
            // Convert warnings to pass, pass to warning
            return match($result->getStatus()) {
                TestResult::STATUS_PASS => TestResult::STATUS_WARNING,
                TestResult::STATUS_WARNING => TestResult::STATUS_PASS,
                default => $result->getStatus()
            };
        });

        $this->addInversionRule('security_inverted', function(TestResult $result) {
            // For security tests where presence of something is bad
            return match($result->getStatus()) {
                TestResult::STATUS_PASS => TestResult::STATUS_FAIL,
                TestResult::STATUS_FAIL => TestResult::STATUS_PASS,
                TestResult::STATUS_WARNING => TestResult::STATUS_WARNING, // Keep warnings
                default => $result->getStatus()
            };
        });

        $this->addInversionRule('availability_inverted', function(TestResult $result) {
            // For availability tests where timeout/error means service is down
            return match($result->getStatus()) {
                TestResult::STATUS_TIMEOUT => TestResult::STATUS_FAIL,
                TestResult::STATUS_ERROR => TestResult::STATUS_FAIL,
                TestResult::STATUS_FAIL => TestResult::STATUS_PASS,
                TestResult::STATUS_PASS => TestResult::STATUS_FAIL,
                default => $result->getStatus()
            };
        });

        $this->addInversionRule('compliance_strict', function(TestResult $result) {
            // For compliance tests - warnings become failures
            return match($result->getStatus()) {
                TestResult::STATUS_WARNING => TestResult::STATUS_FAIL,
                default => $result->getStatus()
            };
        });

        $this->addInversionRule('compliance_lenient', function(TestResult $result) {
            // For compliance tests - failures become warnings
            return match($result->getStatus()) {
                TestResult::STATUS_FAIL => TestResult::STATUS_WARNING,
                default => $result->getStatus()
            };
        });
    }

    /**
     * Add custom inversion rule
     */
    public function addInversionRule(string $name, callable $rule): void
    {
        if (!$this->config['custom_rules_enabled']) {
            throw new \RuntimeException('Custom inversion rules are disabled');
        }

        $this->inversionRules[$name] = $rule;
    }

    /**
     * Remove inversion rule
     */
    public function removeInversionRule(string $name): bool
    {
        if (isset($this->inversionRules[$name])) {
            unset($this->inversionRules[$name]);
            return true;
        }
        return false;
    }

    /**
     * Get all available inversion rules
     */
    public function getAvailableRules(): array
    {
        return array_keys($this->inversionRules);
    }

    /**
     * Apply inversion to test result
     */
    public function applyInversion(TestResult $result, string $inversionMode = null): TestResult
    {
        if (!$this->config['enable_inversion']) {
            return $result;
        }

        $mode = $inversionMode ?? $this->config['default_inversion_mode'];

        if ($mode === 'none') {
            return $result;
        }

        // Preserve original result if configured
        $originalResult = $this->config['preserve_original_result']
            ? clone $result
            : null;

        $invertedResult = $this->performInversion($result, $mode);

        // Add inversion metadata
        if ($originalResult) {
            $invertedResult->addData('original_status', $originalResult->getStatus());
            $invertedResult->addData('original_message', $originalResult->getMessage());
            $invertedResult->addData('inversion_applied', $mode);
        }

        // Log inversion if enabled
        if ($this->config['log_inversions']) {
            $this->logInversion($result, $invertedResult, $mode);
        }

        return $invertedResult;
    }

    /**
     * Perform the actual inversion
     */
    private function performInversion(TestResult $result, string $mode): TestResult
    {
        // Clone the result to avoid modifying the original
        $invertedResult = clone $result;

        if (!isset($this->inversionRules[$mode])) {
            throw new \InvalidArgumentException("Unknown inversion mode: {$mode}");
        }

        $rule = $this->inversionRules[$mode];
        $newStatus = $rule($result);

        $invertedResult->setStatus($newStatus);

        // Update message to reflect inversion
        $originalMessage = $result->getMessage();
        $inversionNote = $this->getInversionNote($result->getStatus(), $newStatus, $mode);

        if ($originalMessage) {
            $newMessage = $originalMessage . ' ' . $inversionNote;
        } else {
            $newMessage = $inversionNote;
        }

        $invertedResult->setMessage($newMessage);

        return $invertedResult;
    }

    /**
     * Get inversion note for message
     */
    private function getInversionNote(string $originalStatus, string $newStatus, string $mode): string
    {
        if ($originalStatus === $newStatus) {
            return "[Inversion: {$mode} - no change]";
        }

        return "[Inverted: {$originalStatus} → {$newStatus} via {$mode}]";
    }

    /**
     * Apply conditional inversion based on test context
     */
    public function applyConditionalInversion(
        TestResult $result,
        array $conditions,
        string $inversionMode
    ): TestResult {
        if (!$this->shouldApplyInversion($result, $conditions)) {
            return $result;
        }

        return $this->applyInversion($result, $inversionMode);
    }

    /**
     * Check if inversion should be applied based on conditions
     */
    private function shouldApplyInversion(TestResult $result, array $conditions): bool
    {
        foreach ($conditions as $conditionType => $conditionValue) {
            switch ($conditionType) {
                case 'test_name':
                    if (!in_array($result->getTestName(), (array)$conditionValue)) {
                        return false;
                    }
                    break;

                case 'status':
                    if (!in_array($result->getStatus(), (array)$conditionValue)) {
                        return false;
                    }
                    break;

                case 'target_pattern':
                    $target = $result->getTarget();
                    if ($target && !preg_match($conditionValue, $target)) {
                        return false;
                    }
                    break;

                case 'score_threshold':
                    $score = $result->getScore();
                    if ($score === null || $score >= $conditionValue) {
                        return false;
                    }
                    break;

                case 'execution_time_min':
                    if ($result->getExecutionTime() < $conditionValue) {
                        return false;
                    }
                    break;

                case 'execution_time_max':
                    if ($result->getExecutionTime() > $conditionValue) {
                        return false;
                    }
                    break;

                case 'data_contains':
                    $data = $result->getData();
                    foreach ((array)$conditionValue as $key => $value) {
                        if (!isset($data[$key]) || $data[$key] !== $value) {
                            return false;
                        }
                    }
                    break;

                case 'custom_condition':
                    if (is_callable($conditionValue) && !$conditionValue($result)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Apply multiple inversions in sequence
     */
    public function applyMultipleInversions(TestResult $result, array $inversionModes): TestResult
    {
        $currentResult = $result;

        foreach ($inversionModes as $mode) {
            $currentResult = $this->applyInversion($currentResult, $mode);
        }

        return $currentResult;
    }

    /**
     * Create inversion profile for batch operations
     */
    public function createInversionProfile(string $name, array $rules): void
    {
        $this->inversionRules[$name] = function(TestResult $result) use ($rules) {
            $status = $result->getStatus();

            // Apply rules in order
            foreach ($rules as $rule) {
                if (isset($rule['from']) && isset($rule['to'])) {
                    if ($status === $rule['from']) {
                        $status = $rule['to'];
                        break; // Apply first matching rule only
                    }
                }
            }

            return $status;
        };
    }

    /**
     * Apply inversion profile to result
     */
    public function applyProfile(TestResult $result, string $profileName): TestResult
    {
        return $this->applyInversion($result, $profileName);
    }

    /**
     * Batch apply inversions to multiple results
     */
    public function batchApplyInversions(array $results, string $inversionMode): array
    {
        $invertedResults = [];

        foreach ($results as $key => $result) {
            if ($result instanceof TestResult) {
                $invertedResults[$key] = $this->applyInversion($result, $inversionMode);
            } else {
                $invertedResults[$key] = $result;
            }
        }

        return $invertedResults;
    }

    /**
     * Create smart inversion based on test category and target
     */
    public function createSmartInversion(TestResult $result, array $context = []): TestResult
    {
        $testName = $result->getTestName();
        $target = $result->getTarget();

        // Determine appropriate inversion based on context
        $inversionMode = $this->determineSmartInversion($result, $context);

        if ($inversionMode === 'none') {
            return $result;
        }

        return $this->applyInversion($result, $inversionMode);
    }

    /**
     * Determine smart inversion mode
     */
    private function determineSmartInversion(TestResult $result, array $context): string
    {
        $testName = strtolower($result->getTestName());

        // Security tests that should typically be inverted
        if (str_contains($testName, 'vulnerability') ||
            str_contains($testName, 'exploit') ||
            str_contains($testName, 'malware')) {
            return 'security_inverted';
        }

        // Compliance tests based on context
        if (isset($context['compliance_mode'])) {
            return $context['compliance_mode'] === 'strict'
                ? 'compliance_strict'
                : 'compliance_lenient';
        }

        // Availability tests
        if (str_contains($testName, 'availability') ||
            str_contains($testName, 'uptime') ||
            str_contains($testName, 'response_time')) {
            return 'availability_inverted';
        }

        return 'none';
    }

    /**
     * Validate inversion configuration
     */
    public function validateConfiguration(): array
    {
        $issues = [];

        // Check if required rules exist
        $requiredRules = ['expect_failure', 'security_inverted'];
        foreach ($requiredRules as $rule) {
            if (!isset($this->inversionRules[$rule])) {
                $issues[] = "Missing required inversion rule: {$rule}";
            }
        }

        // Test each rule with sample data
        $sampleResult = new TestResult('test', TestResult::STATUS_PASS, 'Sample test');

        foreach ($this->inversionRules as $ruleName => $rule) {
            try {
                $rule($sampleResult);
            } catch (\Exception $e) {
                $issues[] = "Invalid inversion rule '{$ruleName}': " . $e->getMessage();
            }
        }

        return $issues;
    }

    /**
     * Log inversion for debugging
     */
    private function logInversion(TestResult $original, TestResult $inverted, string $mode): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'test_name' => $original->getTestName(),
            'target' => $original->getTarget(),
            'original_status' => $original->getStatus(),
            'inverted_status' => $inverted->getStatus(),
            'inversion_mode' => $mode,
            'changed' => $original->getStatus() !== $inverted->getStatus()
        ];

        error_log('[ResultInverter] ' . json_encode($logData));
    }

    /**
     * Get inversion statistics
     */
    public function getInversionStatistics(array $results): array
    {
        $stats = [
            'total_results' => count($results),
            'inversions_applied' => 0,
            'status_changes' => [],
            'inversion_modes_used' => []
        ];

        foreach ($results as $result) {
            if ($result instanceof TestResult) {
                $data = $result->getData();

                if (isset($data['inversion_applied'])) {
                    $stats['inversions_applied']++;

                    $mode = $data['inversion_applied'];
                    $stats['inversion_modes_used'][$mode] =
                        ($stats['inversion_modes_used'][$mode] ?? 0) + 1;

                    if (isset($data['original_status'])) {
                        $originalStatus = $data['original_status'];
                        $currentStatus = $result->getStatus();

                        $changeKey = "{$originalStatus}_to_{$currentStatus}";
                        $stats['status_changes'][$changeKey] =
                            ($stats['status_changes'][$changeKey] ?? 0) + 1;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Reset to default configuration
     */
    public function resetToDefaults(): void
    {
        $this->inversionRules = [];
        $this->initializeDefaultRules();
    }

    /**
     * Export inversion configuration
     */
    public function exportConfiguration(): array
    {
        return [
            'config' => $this->config,
            'available_rules' => $this->getAvailableRules()
        ];
    }
}