<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Validator;
use SecurityScanner\Tests\TestExecutionEngine;
use SecurityScanner\Tests\TestRegistry;

class TestService
{
    private Database $db;
    private Validator $validator;
    private TestExecutionEngine $testEngine;
    private TestRegistry $testRegistry;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->validator = new Validator();
        $this->testEngine = new TestExecutionEngine();
        $this->testRegistry = $this->testEngine->getRegistry();
    }

    /**
     * Configure tests for a website
     */
    public function configureWebsiteTests(int $websiteId, array $testConfigurations): array
    {
        $website = $this->db->fetchRow("SELECT * FROM websites WHERE id = ?", [$websiteId]);
        if (!$website) {
            return [
                'success' => false,
                'errors' => ['website' => ['Website not found']]
            ];
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($testConfigurations as $testName => $config) {
            $result = $this->configureWebsiteTest($websiteId, $testName, $config);
            $results[$testName] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success' => $failureCount === 0,
            'summary' => [
                'total' => count($testConfigurations),
                'successful' => $successCount,
                'failed' => $failureCount
            ],
            'results' => $results
        ];
    }

    /**
     * Configure a single test for a website
     */
    public function configureWebsiteTest(int $websiteId, string $testName, array $config): array
    {
        // Validate test exists
        if (!$this->testRegistry->hasTest($testName)) {
            return [
                'success' => false,
                'errors' => ['test' => ['Test not found or not available']]
            ];
        }

        $validationRules = [
            'enabled' => 'boolean',
            'configuration' => 'array',
            'invert_result' => 'boolean',
            'timeout' => 'integer|min:1|max:300',
            'retry_count' => 'integer|min:0|max:5'
        ];

        if (!$this->validator->validate($config, $validationRules)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }

        // Validate test-specific configuration
        $testInfo = $this->testRegistry->getTestInfo($testName);
        if (isset($config['configuration']) && !empty($testInfo['config_schema'])) {
            $configValidation = $this->validateTestConfiguration($config['configuration'], $testInfo['config_schema']);
            if (!$configValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => ['configuration' => $configValidation['errors']]
                ];
            }
        }

        // Check if configuration already exists
        $existingConfig = $this->db->fetchRow(
            "SELECT id FROM website_test_config WHERE website_id = ? AND test_name = ?",
            [$websiteId, $testName]
        );

        $configData = [
            'website_id' => $websiteId,
            'test_name' => $testName,
            'enabled' => $config['enabled'] ?? true,
            'configuration' => json_encode($config['configuration'] ?? []),
            'invert_result' => $config['invert_result'] ?? false,
            'timeout' => $config['timeout'] ?? 30,
            'retry_count' => $config['retry_count'] ?? 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($existingConfig) {
            $this->db->update('website_test_config', $configData, ['id' => $existingConfig['id']]);
            $configId = $existingConfig['id'];
        } else {
            $configData['created_at'] = date('Y-m-d H:i:s');
            $configId = $this->db->insert('website_test_config', $configData);
        }

        return [
            'success' => true,
            'config_id' => $configId,
            'config' => $this->getTestConfiguration($websiteId, $testName)
        ];
    }

    /**
     * Execute tests for a website
     */
    public function executeWebsiteTests(int $websiteId, array $testNames = []): array
    {
        $website = $this->db->fetchRow("SELECT * FROM websites WHERE id = ?", [$websiteId]);
        if (!$website) {
            return [
                'success' => false,
                'errors' => ['website' => ['Website not found']]
            ];
        }

        // Get test configurations
        $query = "SELECT * FROM website_test_config WHERE website_id = ? AND enabled = 1";
        $params = [$websiteId];

        if (!empty($testNames)) {
            $placeholders = str_repeat('?,', count($testNames) - 1) . '?';
            $query .= " AND test_name IN ({$placeholders})";
            $params = array_merge($params, $testNames);
        }

        $configurations = $this->db->fetchAll($query, $params);

        if (empty($configurations)) {
            return [
                'success' => false,
                'errors' => ['tests' => ['No enabled tests found for this website']]
            ];
        }

        // Create scan execution record
        $scanId = $this->db->insert('scan_results', [
            'website_id' => $websiteId,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Execute tests
        $testResults = [];
        $overallSuccess = true;
        $startTime = microtime(true);

        foreach ($configurations as $config) {
            $result = $this->executeTest(
                $config['test_name'],
                $website['url'],
                json_decode($config['configuration'], true),
                [
                    'timeout' => $config['timeout'],
                    'retry_count' => $config['retry_count'],
                    'invert_result' => $config['invert_result']
                ]
            );

            $testResults[] = $result;

            if (!$result->isSuccessful()) {
                $overallSuccess = false;
            }

            // Store individual test result
            $this->db->insert('test_executions', [
                'scan_id' => $scanId,
                'test_name' => $config['test_name'],
                'status' => $result->isSuccessful() ? 'passed' : 'failed',
                'result_data' => json_encode($result->toArray()),
                'execution_time' => $result->getExecutionTime(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        $executionTime = microtime(true) - $startTime;

        // Update scan record
        $this->db->update('scan_results', [
            'status' => 'completed',
            'success' => $overallSuccess,
            'total_tests' => count($testResults),
            'passed_tests' => count(array_filter($testResults, fn($r) => $r->isSuccessful())),
            'execution_time' => round($executionTime, 3),
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $scanId]);

        return [
            'success' => true,
            'scan_id' => $scanId,
            'overall_success' => $overallSuccess,
            'execution_time' => round($executionTime, 3),
            'results' => array_map(fn($r) => $r->toArray(), $testResults)
        ];
    }

    /**
     * Execute a single test
     */
    public function executeTest(string $testName, string $target, array $config = [], array $options = []): \SecurityScanner\Tests\TestResult
    {
        $timeout = $options['timeout'] ?? 30;
        $retryCount = $options['retry_count'] ?? 1;
        $invertResult = $options['invert_result'] ?? false;

        $lastResult = null;
        $attempts = 0;

        while ($attempts < $retryCount) {
            $attempts++;

            try {
                $result = $this->testEngine->executeTest($testName, $target, $config, $timeout);

                if ($invertResult) {
                    $result = $result->invert();
                }

                if ($result->isSuccessful() || $attempts >= $retryCount) {
                    return $result;
                }

                $lastResult = $result;

                // Wait before retry (exponential backoff)
                if ($attempts < $retryCount) {
                    sleep(min(pow(2, $attempts - 1), 10));
                }

            } catch (\Exception $e) {
                $lastResult = new \SecurityScanner\Tests\TestResult($testName, false, [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts
                ]);

                if ($attempts >= $retryCount) {
                    break;
                }

                sleep(min(pow(2, $attempts - 1), 10));
            }
        }

        return $lastResult;
    }

    /**
     * Get test configurations for a website
     */
    public function getWebsiteTestConfigurations(int $websiteId): array
    {
        return $this->db->fetchAll(
            "SELECT wtc.*, at.name, at.description, at.category
             FROM website_test_config wtc
             JOIN available_tests at ON wtc.test_name = at.name
             WHERE wtc.website_id = ?
             ORDER BY at.category, at.name",
            [$websiteId]
        );
    }

    /**
     * Get single test configuration
     */
    public function getTestConfiguration(int $websiteId, string $testName): ?array
    {
        return $this->db->fetchRow(
            "SELECT wtc.*, at.name, at.description, at.category
             FROM website_test_config wtc
             JOIN available_tests at ON wtc.test_name = at.name
             WHERE wtc.website_id = ? AND wtc.test_name = ?",
            [$websiteId, $testName]
        );
    }

    /**
     * Get all available tests with their information
     */
    public function getAvailableTests(): array
    {
        $tests = $this->testRegistry->getAllTests();
        $availableTests = [];

        foreach ($tests as $testName => $testData) {
            $availableTests[] = [
                'name' => $testName,
                'info' => $testData['info'],
                'enabled' => $testData['enabled'],
                'category' => $testData['category'] ?? 'general',
                'config_schema' => $testData['config_schema'] ?? []
            ];
        }

        return $availableTests;
    }

    /**
     * Enable/disable tests globally
     */
    public function manageTestAvailability(string $testName, bool $enabled): array
    {
        if (!$this->testRegistry->hasTest($testName)) {
            return [
                'success' => false,
                'errors' => ['test' => ['Test not found']]
            ];
        }

        $this->db->update('available_tests', [
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['name' => $testName]);

        return [
            'success' => true,
            'test_name' => $testName,
            'enabled' => $enabled
        ];
    }

    /**
     * Get test execution history
     */
    public function getTestExecutionHistory(int $websiteId, array $filters = []): array
    {
        $query = "SELECT sr.*, COUNT(te.id) as test_count,
                         AVG(te.execution_time) as avg_execution_time
                  FROM scan_results sr
                  LEFT JOIN test_executions te ON sr.id = te.scan_id
                  WHERE sr.website_id = ?";
        $params = [$websiteId];

        if (!empty($filters['status'])) {
            $query .= " AND sr.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $query .= " AND sr.created_at >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $query .= " AND sr.created_at <= ?";
            $params[] = $filters['to_date'];
        }

        $query .= " GROUP BY sr.id ORDER BY sr.created_at DESC";

        if (!empty($filters['limit'])) {
            $query .= " LIMIT " . (int)$filters['limit'];
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get detailed scan results
     */
    public function getScanResults(int $scanId): ?array
    {
        $scan = $this->db->fetchRow(
            "SELECT sr.*, w.name as website_name, w.url as website_url
             FROM scan_results sr
             JOIN websites w ON sr.website_id = w.id
             WHERE sr.id = ?",
            [$scanId]
        );

        if (!$scan) {
            return null;
        }

        $testExecutions = $this->db->fetchAll(
            "SELECT * FROM test_executions WHERE scan_id = ? ORDER BY created_at",
            [$scanId]
        );

        $scan['test_executions'] = $testExecutions;

        return $scan;
    }

    /**
     * Get test performance metrics
     */
    public function getTestPerformanceMetrics(string $testName = null, int $days = 30): array
    {
        $query = "SELECT test_name,
                         COUNT(*) as execution_count,
                         AVG(execution_time) as avg_execution_time,
                         MIN(execution_time) as min_execution_time,
                         MAX(execution_time) as max_execution_time,
                         SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) as success_count,
                         AVG(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) * 100 as success_rate
                  FROM test_executions
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = [$days];

        if ($testName) {
            $query .= " AND test_name = ?";
            $params[] = $testName;
        }

        $query .= " GROUP BY test_name ORDER BY execution_count DESC";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Cleanup old test results
     */
    public function cleanupOldResults(int $daysToKeep = 90): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        // Count records to be deleted
        $oldScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE created_at < ?",
            [$cutoffDate]
        );

        $oldExecutions = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM test_executions te
             JOIN scan_results sr ON te.scan_id = sr.id
             WHERE sr.created_at < ?",
            [$cutoffDate]
        );

        // Delete old records
        $this->db->query(
            "DELETE te FROM test_executions te
             JOIN scan_results sr ON te.scan_id = sr.id
             WHERE sr.created_at < ?",
            [$cutoffDate]
        );

        $this->db->delete('scan_results', "created_at < '{$cutoffDate}'");

        return [
            'success' => true,
            'deleted_scans' => (int)$oldScans,
            'deleted_executions' => (int)$oldExecutions,
            'cutoff_date' => $cutoffDate
        ];
    }

    /**
     * Validate test-specific configuration
     */
    private function validateTestConfiguration(array $config, array $schema): array
    {
        $errors = [];

        foreach ($schema as $field => $rules) {
            if (isset($rules['required']) && $rules['required'] && !isset($config[$field])) {
                $errors[$field] = 'Field is required';
                continue;
            }

            if (!isset($config[$field])) {
                continue;
            }

            $value = $config[$field];

            if (isset($rules['type'])) {
                $expectedType = $rules['type'];
                $actualType = gettype($value);

                if ($expectedType === 'integer' && !is_int($value)) {
                    $errors[$field] = 'Must be an integer';
                } elseif ($expectedType === 'string' && !is_string($value)) {
                    $errors[$field] = 'Must be a string';
                } elseif ($expectedType === 'boolean' && !is_bool($value)) {
                    $errors[$field] = 'Must be a boolean';
                }
            }

            if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
                $errors[$field] = "Must be at least {$rules['min']}";
            }

            if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
                $errors[$field] = "Must be no more than {$rules['max']}";
            }

            if (isset($rules['options']) && !in_array($value, $rules['options'])) {
                $errors[$field] = 'Invalid option selected';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}