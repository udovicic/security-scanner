<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class WebsiteTestConfig extends AbstractModel
{
    protected string $table = 'website_test_config';

    protected array $fillable = [
        'website_id',
        'available_test_id',
        'is_enabled',
        'priority',
        'timeout_seconds',
        'max_retries',
        'retry_delay_seconds',
        'config_options',
        'custom_headers',
        'expected_result',
        'failure_threshold',
        'success_threshold',
        'alert_on_failure',
        'alert_on_recovery',
        'notes'
    ];

    protected array $casts = [
        'id' => 'int',
        'website_id' => 'int',
        'available_test_id' => 'int',
        'is_enabled' => 'bool',
        'priority' => 'int',
        'timeout_seconds' => 'int',
        'max_retries' => 'int',
        'retry_delay_seconds' => 'int',
        'failure_threshold' => 'int',
        'success_threshold' => 'int',
        'alert_on_failure' => 'bool',
        'alert_on_recovery' => 'bool',
        'config_options' => 'json',
        'custom_headers' => 'json'
    ];

    /**
     * Get enabled test configurations for a website
     */
    public function getEnabledTestsForWebsite(int $websiteId): array
    {
        $sql = "
            SELECT
                wtc.*,
                at.name as test_name,
                at.display_name,
                at.description,
                at.category,
                at.test_class,
                at.default_timeout_seconds,
                at.default_config
            FROM {$this->table} wtc
            JOIN available_tests at ON wtc.available_test_id = at.id
            WHERE wtc.website_id = ?
                AND wtc.is_enabled = 1
                AND at.is_enabled = 1
            ORDER BY wtc.priority ASC, at.display_name ASC
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Get all configurations for a specific test across websites
     */
    public function getConfigurationsForTest(int $testId): array
    {
        $sql = "
            SELECT
                wtc.*,
                w.name as website_name,
                w.url as website_url,
                w.status as website_status
            FROM {$this->table} wtc
            JOIN websites w ON wtc.website_id = w.id
            WHERE wtc.available_test_id = ?
            ORDER BY w.name ASC
        ";

        return $this->query($sql, [$testId]);
    }

    /**
     * Assign test to website with default configuration
     */
    public function assignTestToWebsite(int $websiteId, int $testId, array $config = []): array
    {
        // Get test default configuration
        $availableTest = new AvailableTest();
        $test = $availableTest->find($testId);

        if (!$test) {
            throw new \Exception("Test with ID {$testId} not found");
        }

        $defaultConfig = [
            'website_id' => $websiteId,
            'available_test_id' => $testId,
            'is_enabled' => true,
            'priority' => 50,
            'timeout_seconds' => $test['default_timeout_seconds'] ?? 30,
            'max_retries' => 3,
            'retry_delay_seconds' => 5,
            'failure_threshold' => 1,
            'success_threshold' => 1,
            'alert_on_failure' => true,
            'alert_on_recovery' => true,
            'config_options' => array_merge(
                json_decode($test['default_config'] ?? '{}', true),
                $config['options'] ?? []
            )
        ];

        // Merge with provided config
        $finalConfig = array_merge($defaultConfig, $config);

        return $this->create($finalConfig);
    }

    /**
     * Bulk assign tests to website
     */
    public function bulkAssignTests(int $websiteId, array $testIds, array $globalConfig = []): array
    {
        $results = [];
        $availableTest = new AvailableTest();

        foreach ($testIds as $testId) {
            try {
                $testConfig = $globalConfig;

                // Allow per-test specific configuration
                if (is_array($testId)) {
                    $actualTestId = $testId['test_id'];
                    $testConfig = array_merge($globalConfig, $testId['config'] ?? []);
                } else {
                    $actualTestId = $testId;
                }

                $result = $this->assignTestToWebsite($websiteId, $actualTestId, $testConfig);
                $results[] = [
                    'test_id' => $actualTestId,
                    'success' => true,
                    'config_id' => $result['id']
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'test_id' => $actualTestId ?? $testId,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Remove test assignment from website
     */
    public function removeTestFromWebsite(int $websiteId, int $testId): bool
    {
        $sql = "
            DELETE FROM {$this->table}
            WHERE website_id = ? AND available_test_id = ?
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        return $stmt->execute([$websiteId, $testId]);
    }

    /**
     * Update test configuration
     */
    public function updateTestConfig(int $websiteId, int $testId, array $config): bool
    {
        $sql = "
            UPDATE {$this->table}
            SET " . implode(', ', array_map(fn($key) => "{$key} = ?", array_keys($config))) . ",
                updated_at = NOW()
            WHERE website_id = ? AND available_test_id = ?
        ";

        $params = array_merge(array_values($config), [$websiteId, $testId]);
        $stmt = $this->db->getConnection()->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Enable/disable test for website
     */
    public function toggleTestStatus(int $websiteId, int $testId, bool $enabled): bool
    {
        return $this->updateTestConfig($websiteId, $testId, ['is_enabled' => $enabled]);
    }

    /**
     * Get test configuration for specific website and test
     */
    public function getTestConfig(int $websiteId, int $testId): ?array
    {
        $sql = "
            SELECT
                wtc.*,
                at.name as test_name,
                at.display_name,
                at.test_class,
                at.default_config
            FROM {$this->table} wtc
            JOIN available_tests at ON wtc.available_test_id = at.id
            WHERE wtc.website_id = ? AND wtc.available_test_id = ?
        ";

        $result = $this->query($sql, [$websiteId, $testId]);
        return $result[0] ?? null;
    }

    /**
     * Copy test configurations from one website to another
     */
    public function copyConfigurationsToWebsite(int $sourceWebsiteId, int $targetWebsiteId, bool $overwriteExisting = false): array
    {
        $sourceConfigs = $this->where(['website_id' => $sourceWebsiteId]);
        $results = [];

        foreach ($sourceConfigs as $config) {
            // Check if configuration already exists
            $existing = $this->getTestConfig($targetWebsiteId, $config['available_test_id']);

            if ($existing && !$overwriteExisting) {
                $results[] = [
                    'test_id' => $config['available_test_id'],
                    'success' => false,
                    'message' => 'Configuration already exists, skipped'
                ];
                continue;
            }

            try {
                // Remove existing if overwrite is enabled
                if ($existing && $overwriteExisting) {
                    $this->removeTestFromWebsite($targetWebsiteId, $config['available_test_id']);
                }

                // Create new configuration
                $newConfig = $config;
                $newConfig['website_id'] = $targetWebsiteId;
                unset($newConfig['id'], $newConfig['created_at'], $newConfig['updated_at']);

                $result = $this->create($newConfig);
                $results[] = [
                    'test_id' => $config['available_test_id'],
                    'success' => true,
                    'config_id' => $result['id']
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'test_id' => $config['available_test_id'],
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Get test execution priorities for scheduling
     */
    public function getExecutionPriorities(int $websiteId): array
    {
        $sql = "
            SELECT
                wtc.available_test_id,
                wtc.priority,
                at.name as test_name,
                at.category,
                wtc.timeout_seconds,
                wtc.max_retries
            FROM {$this->table} wtc
            JOIN available_tests at ON wtc.available_test_id = at.id
            WHERE wtc.website_id = ?
                AND wtc.is_enabled = 1
                AND at.is_enabled = 1
            ORDER BY wtc.priority ASC
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Get configurations requiring attention (high failure rates)
     */
    public function getConfigurationsNeedingAttention(int $days = 7): array
    {
        $sql = "
            SELECT
                wtc.*,
                w.name as website_name,
                w.url as website_url,
                at.display_name as test_name,
                COUNT(tr.id) as total_runs,
                COUNT(CASE WHEN tr.status = 'failed' THEN 1 END) as failed_runs,
                (COUNT(CASE WHEN tr.status = 'failed' THEN 1 END) / COUNT(tr.id)) * 100 as failure_rate
            FROM {$this->table} wtc
            JOIN websites w ON wtc.website_id = w.id
            JOIN available_tests at ON wtc.available_test_id = at.id
            LEFT JOIN test_results tr ON tr.website_id = wtc.website_id
                AND tr.available_test_id = wtc.available_test_id
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            WHERE wtc.is_enabled = 1
            GROUP BY wtc.id
            HAVING total_runs > 5 AND failure_rate > 50
            ORDER BY failure_rate DESC, total_runs DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get test coverage statistics
     */
    public function getTestCoverageStatistics(): array
    {
        $sql = "
            SELECT
                w.id as website_id,
                w.name as website_name,
                COUNT(wtc.id) as assigned_tests,
                COUNT(CASE WHEN wtc.is_enabled = 1 THEN 1 END) as enabled_tests,
                (
                    SELECT COUNT(*)
                    FROM available_tests
                    WHERE is_enabled = 1
                ) as total_available_tests,
                ROUND(
                    (COUNT(CASE WHEN wtc.is_enabled = 1 THEN 1 END) /
                     (SELECT COUNT(*) FROM available_tests WHERE is_enabled = 1)) * 100,
                    2
                ) as coverage_percentage
            FROM websites w
            LEFT JOIN {$this->table} wtc ON w.id = wtc.website_id
            WHERE w.status = 'active'
            GROUP BY w.id
            ORDER BY coverage_percentage DESC
        ";

        return $this->query($sql);
    }

    /**
     * Get configuration templates (most common configurations)
     */
    public function getConfigurationTemplates(int $testId): array
    {
        $sql = "
            SELECT
                priority,
                timeout_seconds,
                max_retries,
                retry_delay_seconds,
                alert_on_failure,
                alert_on_recovery,
                config_options,
                COUNT(*) as usage_count
            FROM {$this->table}
            WHERE available_test_id = ?
            GROUP BY
                priority, timeout_seconds, max_retries, retry_delay_seconds,
                alert_on_failure, alert_on_recovery, config_options
            ORDER BY usage_count DESC
            LIMIT 5
        ";

        return $this->query($sql, [$testId]);
    }

    /**
     * Validate configuration against test requirements
     */
    public function validateConfiguration(array $config): array
    {
        $errors = [];

        // Validate required fields
        if (empty($config['website_id'])) {
            $errors['website_id'] = 'Website ID is required';
        }

        if (empty($config['available_test_id'])) {
            $errors['available_test_id'] = 'Test ID is required';
        }

        // Validate numeric ranges
        if (isset($config['priority']) && ($config['priority'] < 1 || $config['priority'] > 100)) {
            $errors['priority'] = 'Priority must be between 1 and 100';
        }

        if (isset($config['timeout_seconds']) && ($config['timeout_seconds'] < 1 || $config['timeout_seconds'] > 300)) {
            $errors['timeout_seconds'] = 'Timeout must be between 1 and 300 seconds';
        }

        if (isset($config['max_retries']) && ($config['max_retries'] < 0 || $config['max_retries'] > 10)) {
            $errors['max_retries'] = 'Max retries must be between 0 and 10';
        }

        // Validate test and website exist
        if (!empty($config['website_id'])) {
            $website = new Website();
            if (!$website->find($config['website_id'])) {
                $errors['website_id'] = 'Website not found';
            }
        }

        if (!empty($config['available_test_id'])) {
            $test = new AvailableTest();
            if (!$test->find($config['available_test_id'])) {
                $errors['available_test_id'] = 'Test not found';
            }
        }

        return $errors;
    }

    /**
     * Get test configurations summary
     */
    public function getConfigurationsSummary(): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_configurations,
                COUNT(CASE WHEN is_enabled = 1 THEN 1 END) as enabled_configurations,
                COUNT(DISTINCT website_id) as websites_with_tests,
                COUNT(DISTINCT available_test_id) as tests_in_use,
                AVG(priority) as avg_priority,
                AVG(timeout_seconds) as avg_timeout
            FROM {$this->table}
        ";

        $result = $this->query($sql);
        return $result[0] ?? [];
    }

    /**
     * Archive configurations for disabled websites or tests
     */
    public function archiveObsoleteConfigurations(): int
    {
        $sql = "
            DELETE wtc FROM {$this->table} wtc
            LEFT JOIN websites w ON wtc.website_id = w.id
            LEFT JOIN available_tests at ON wtc.available_test_id = at.id
            WHERE w.status = 'disabled' OR at.is_enabled = 0 OR w.id IS NULL OR at.id IS NULL
        ";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();

        $deletedCount = $stmt->rowCount();

        if ($deletedCount > 0) {
            $this->logger->info('Archived obsolete test configurations', [
                'deleted_count' => $deletedCount
            ]);
        }

        return $deletedCount;
    }
}