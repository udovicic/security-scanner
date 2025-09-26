<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class AvailableTest extends AbstractModel
{
    protected string $table = 'available_tests';

    protected array $fillable = [
        'name',
        'display_name',
        'description',
        'category',
        'severity',
        'test_class',
        'version',
        'enabled',
        'default_timeout',
        'default_config',
        'requirements',
        'documentation_url'
    ];

    protected array $casts = [
        'id' => 'int',
        'enabled' => 'bool',
        'default_timeout' => 'int',
        'default_config' => 'json',
        'requirements' => 'json'
    ];

    /**
     * Get all enabled tests
     */
    public function getEnabledTests(): array
    {
        return $this->where(['enabled' => true]);
    }

    /**
     * Get tests by category
     */
    public function getTestsByCategory(string $category): array
    {
        return $this->where([
            'category' => $category,
            'enabled' => true
        ]);
    }

    /**
     * Get test statistics
     */
    public function getTestStatistics(): array
    {
        $sql = "
            SELECT
                category,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN enabled = 1 THEN 1 END) as enabled_tests,
                COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_tests,
                COUNT(CASE WHEN severity = 'high' THEN 1 END) as high_tests,
                COUNT(CASE WHEN severity = 'medium' THEN 1 END) as medium_tests,
                COUNT(CASE WHEN severity = 'low' THEN 1 END) as low_tests,
                COUNT(CASE WHEN severity = 'info' THEN 1 END) as info_tests
            FROM {$this->table}
            GROUP BY category
            ORDER BY category ASC
        ";

        return $this->query($sql);
    }

    /**
     * Get test usage statistics
     */
    public function getUsageStatistics(): array
    {
        $sql = "
            SELECT
                at.id,
                at.name,
                at.display_name,
                at.category,
                COUNT(DISTINCT wtc.website_id) as websites_using,
                COUNT(tr.id) as total_executions,
                COUNT(CASE WHEN tr.status = 'passed' THEN 1 END) as successful_executions,
                COUNT(CASE WHEN tr.status = 'failed' THEN 1 END) as failed_executions,
                AVG(tr.execution_time_ms) as avg_execution_time
            FROM {$this->table} at
            LEFT JOIN website_test_config wtc ON at.id = wtc.available_test_id AND wtc.enabled = 1
            LEFT JOIN test_results tr ON at.id = tr.available_test_id AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE at.enabled = 1
            GROUP BY at.id
            ORDER BY websites_using DESC, total_executions DESC
        ";

        return $this->query($sql);
    }

    /**
     * Register a new test plugin
     */
    public function registerTest(array $testData): array
    {
        $errors = $this->validateTest($testData);
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Test validation failed: ' . implode(', ', $errors));
        }

        // Check if test already exists
        $existing = $this->findBy('name', $testData['name']);
        if ($existing) {
            // Update existing test
            return $this->update($existing['id'], $testData);
        }

        // Create new test
        return $this->create($testData);
    }

    /**
     * Validate test data
     */
    public function validateTest(array $data): array
    {
        $errors = [];

        // Validate required fields
        $requiredFields = ['name', 'display_name', 'description', 'category', 'test_class'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "{$field} is required";
            }
        }

        // Validate name format (must be valid identifier)
        if (!empty($data['name']) && !preg_match('/^[a-z][a-z0-9_]*$/', $data['name'])) {
            $errors['name'] = 'Test name must be lowercase alphanumeric with underscores';
        }

        // Validate severity
        $validSeverities = ['info', 'low', 'medium', 'high', 'critical'];
        if (!empty($data['severity']) && !in_array($data['severity'], $validSeverities)) {
            $errors['severity'] = 'Invalid severity. Must be one of: ' . implode(', ', $validSeverities);
        }

        // Validate test class
        if (!empty($data['test_class']) && !class_exists($data['test_class'])) {
            $errors['test_class'] = 'Test class does not exist: ' . $data['test_class'];
        }

        // Validate version format
        if (!empty($data['version']) && !preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            $errors['version'] = 'Version must be in format X.Y.Z';
        }

        // Validate timeout
        if (isset($data['default_timeout'])) {
            $timeout = (int)$data['default_timeout'];
            if ($timeout < 1 || $timeout > 600) {
                $errors['default_timeout'] = 'Default timeout must be between 1 and 600 seconds';
            }
        }

        // Validate JSON fields
        $jsonFields = ['default_config', 'requirements'];
        foreach ($jsonFields as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                if (json_decode($data[$field]) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $errors[$field] = "Invalid JSON in {$field}";
                }
            }
        }

        // Validate documentation URL
        if (!empty($data['documentation_url']) && !filter_var($data['documentation_url'], FILTER_VALIDATE_URL)) {
            $errors['documentation_url'] = 'Invalid documentation URL';
        }

        return $errors;
    }

    /**
     * Check if test meets requirements
     */
    public function checkRequirements(int $testId): array
    {
        $test = $this->find($testId);
        if (!$test || !$test['requirements']) {
            return ['status' => 'ok', 'messages' => []];
        }

        $requirements = $test['requirements'];
        $messages = [];
        $status = 'ok';

        // Check PHP extensions
        if (isset($requirements['extensions'])) {
            foreach ($requirements['extensions'] as $extension) {
                if (!extension_loaded($extension)) {
                    $messages[] = "Required PHP extension not loaded: {$extension}";
                    $status = 'error';
                }
            }
        }

        // Check PHP version
        if (isset($requirements['php_version'])) {
            if (version_compare(PHP_VERSION, $requirements['php_version'], '<')) {
                $messages[] = "Required PHP version {$requirements['php_version']}, current: " . PHP_VERSION;
                $status = 'error';
            }
        }

        // Check configuration requirements
        if (isset($requirements['config'])) {
            foreach ($requirements['config'] as $configKey => $configValue) {
                $currentValue = $this->config->get($configKey);
                if ($currentValue !== $configValue) {
                    $messages[] = "Configuration requirement not met: {$configKey} should be {$configValue}";
                    $status = 'warning';
                }
            }
        }

        // Check system commands
        if (isset($requirements['commands'])) {
            foreach ($requirements['commands'] as $command) {
                $output = null;
                $returnCode = null;
                exec("which {$command} 2>/dev/null", $output, $returnCode);
                if ($returnCode !== 0) {
                    $messages[] = "Required system command not found: {$command}";
                    $status = 'error';
                }
            }
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Get test configuration schema
     */
    public function getConfigSchema(int $testId): ?array
    {
        $test = $this->find($testId);
        if (!$test || !class_exists($test['test_class'])) {
            return null;
        }

        $testClass = $test['test_class'];
        if (method_exists($testClass, 'getConfigSchema')) {
            return $testClass::getConfigSchema();
        }

        return null;
    }

    /**
     * Get tests that are compatible with a specific target
     */
    public function getCompatibleTests(string $targetType, array $targetInfo = []): array
    {
        $sql = "
            SELECT * FROM {$this->table}
            WHERE enabled = 1
        ";

        $tests = $this->query($sql);
        $compatibleTests = [];

        foreach ($tests as $test) {
            if ($this->isTestCompatible($test, $targetType, $targetInfo)) {
                $compatibleTests[] = $test;
            }
        }

        return $compatibleTests;
    }

    /**
     * Check if a test is compatible with target
     */
    private function isTestCompatible(array $test, string $targetType, array $targetInfo): bool
    {
        // Basic compatibility check - can be extended
        $requirements = $test['requirements'] ?? [];

        // Check target type compatibility
        if (isset($requirements['target_types']) && !in_array($targetType, $requirements['target_types'])) {
            return false;
        }

        // Check protocol requirements
        if (isset($requirements['protocols']) && isset($targetInfo['protocol'])) {
            if (!in_array($targetInfo['protocol'], $requirements['protocols'])) {
                return false;
            }
        }

        // Check port requirements
        if (isset($requirements['ports']) && isset($targetInfo['port'])) {
            if (!in_array($targetInfo['port'], $requirements['ports'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get test performance metrics
     */
    public function getPerformanceMetrics(int $testId): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_runs,
                AVG(execution_time_ms) as avg_execution_time,
                MIN(execution_time_ms) as min_execution_time,
                MAX(execution_time_ms) as max_execution_time,
                STDDEV(execution_time_ms) as stddev_execution_time,
                COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_runs,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                COUNT(CASE WHEN status = 'error' THEN 1 END) as error_runs,
                COUNT(CASE WHEN status = 'timeout' THEN 1 END) as timeout_runs
            FROM test_results
            WHERE available_test_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";

        $result = $this->query($sql, [$testId]);
        return $result[0] ?? [];
    }

    /**
     * Disable test and all its configurations
     */
    public function disableTest(int $testId): bool
    {
        $this->beginTransaction();

        try {
            // Disable the test
            $this->update($testId, ['enabled' => false]);

            // Disable all website configurations for this test
            $sql = "UPDATE website_test_config SET enabled = 0 WHERE available_test_id = ?";
            $this->execute($sql, [$testId]);

            $this->commit();

            $this->logger->info('Test disabled', [
                'test_id' => $testId
            ]);

            return true;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}