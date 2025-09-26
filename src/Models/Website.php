<?php

namespace SecurityScanner\Models;

use SecurityScanner\Core\AbstractModel;

class Website extends AbstractModel
{
    protected string $table = 'websites';

    protected array $fillable = [
        'name',
        'url',
        'description',
        'status',
        'scan_frequency',
        'last_scan_at',
        'next_scan_at',
        'scan_enabled',
        'notification_email',
        'max_concurrent_tests',
        'timeout_seconds',
        'verify_ssl',
        'follow_redirects',
        'max_redirects',
        'user_agent',
        'auth_type',
        'auth_username',
        'auth_password',
        'auth_token',
        'custom_headers',
        'metadata'
    ];

    protected array $hidden = [
        'auth_password',
        'auth_token'
    ];

    protected array $casts = [
        'id' => 'int',
        'scan_enabled' => 'bool',
        'max_concurrent_tests' => 'int',
        'timeout_seconds' => 'int',
        'verify_ssl' => 'bool',
        'follow_redirects' => 'bool',
        'max_redirects' => 'int',
        'custom_headers' => 'json',
        'metadata' => 'json'
    ];

    /**
     * Get websites that need to be scanned
     */
    public function getScheduledWebsites(): array
    {
        $sql = "
            SELECT * FROM {$this->table}
            WHERE scan_enabled = 1
                AND status = 'active'
                AND (next_scan_at IS NULL OR next_scan_at <= NOW())
            ORDER BY next_scan_at ASC, id ASC
        ";

        return $this->query($sql);
    }

    /**
     * Get active websites with configuration
     */
    public function getActiveWithConfig(): array
    {
        $sql = "
            SELECT * FROM active_website_configs
            ORDER BY website_name ASC
        ";

        return $this->query($sql);
    }

    /**
     * Update next scan time based on frequency
     */
    public function updateNextScanTime(int $websiteId): bool
    {
        $website = $this->find($websiteId);
        if (!$website) {
            return false;
        }

        $nextScan = $this->calculateNextScanTime($website['scan_frequency']);

        return $this->update($websiteId, [
            'last_scan_at' => date('Y-m-d H:i:s'),
            'next_scan_at' => $nextScan
        ]) !== null;
    }

    /**
     * Get website statistics
     */
    public function getStatistics(int $websiteId): array
    {
        $stats = [
            'total_executions' => 0,
            'successful_executions' => 0,
            'failed_executions' => 0,
            'last_execution_at' => null,
            'average_execution_time' => 0,
            'total_tests_run' => 0,
            'passing_tests' => 0,
            'failing_tests' => 0
        ];

        // Get execution statistics
        $sql = "
            SELECT
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_executions,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_executions,
                MAX(start_time) as last_execution_at,
                AVG(execution_time_ms) as average_execution_time,
                SUM(total_tests) as total_tests_run,
                SUM(passed_tests) as passing_tests,
                SUM(failed_tests) as failing_tests
            FROM test_executions
            WHERE website_id = ?
        ";

        $result = $this->query($sql, [$websiteId]);
        if (!empty($result)) {
            $stats = array_merge($stats, $result[0]);
        }

        return $stats;
    }

    /**
     * Get website security score based on recent test results
     */
    public function getSecurityScore(int $websiteId): float
    {
        $sql = "
            SELECT
                AVG(CASE
                    WHEN status = 'passed' THEN 100
                    WHEN status = 'failed' AND severity = 'critical' THEN 0
                    WHEN status = 'failed' AND severity = 'high' THEN 25
                    WHEN status = 'failed' AND severity = 'medium' THEN 50
                    WHEN status = 'failed' AND severity = 'low' THEN 75
                    WHEN status = 'failed' AND severity = 'info' THEN 90
                    ELSE 50
                END) as security_score
            FROM test_results tr
            JOIN test_executions te ON tr.test_execution_id = te.id
            WHERE tr.website_id = ?
                AND te.status = 'completed'
                AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";

        $result = $this->query($sql, [$websiteId]);
        return $result[0]['security_score'] ?? 0.0;
    }

    /**
     * Validate website data
     */
    public function validateWebsite(array $data): array
    {
        $errors = [];

        // Validate URL
        if (empty($data['url'])) {
            $errors['url'] = 'URL is required';
        } elseif (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Invalid URL format';
        }

        // Validate name
        if (empty($data['name'])) {
            $errors['name'] = 'Website name is required';
        } elseif (strlen($data['name']) > 255) {
            $errors['name'] = 'Website name must not exceed 255 characters';
        }

        // Validate status
        $validStatuses = ['active', 'inactive', 'maintenance'];
        if (!empty($data['status']) && !in_array($data['status'], $validStatuses)) {
            $errors['status'] = 'Invalid status. Must be one of: ' . implode(', ', $validStatuses);
        }

        // Validate scan frequency
        $validFrequencies = ['manual', 'hourly', 'daily', 'weekly', 'monthly'];
        if (!empty($data['scan_frequency']) && !in_array($data['scan_frequency'], $validFrequencies)) {
            $errors['scan_frequency'] = 'Invalid scan frequency. Must be one of: ' . implode(', ', $validFrequencies);
        }

        // Validate email
        if (!empty($data['notification_email']) && !filter_var($data['notification_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['notification_email'] = 'Invalid email address';
        }

        // Validate numeric fields
        $numericFields = [
            'max_concurrent_tests' => ['min' => 1, 'max' => 50],
            'timeout_seconds' => ['min' => 5, 'max' => 300],
            'max_redirects' => ['min' => 0, 'max' => 20]
        ];

        foreach ($numericFields as $field => $constraints) {
            if (isset($data[$field])) {
                $value = (int)$data[$field];
                if ($value < $constraints['min'] || $value > $constraints['max']) {
                    $errors[$field] = "{$field} must be between {$constraints['min']} and {$constraints['max']}";
                }
            }
        }

        // Validate auth type
        $validAuthTypes = ['none', 'basic', 'bearer', 'custom'];
        if (!empty($data['auth_type']) && !in_array($data['auth_type'], $validAuthTypes)) {
            $errors['auth_type'] = 'Invalid auth type. Must be one of: ' . implode(', ', $validAuthTypes);
        }

        // Validate JSON fields
        $jsonFields = ['custom_headers', 'metadata'];
        foreach ($jsonFields as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                if (json_decode($data[$field]) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $errors[$field] = "Invalid JSON in {$field}";
                }
            }
        }

        return $errors;
    }

    /**
     * Get test configurations for a website
     */
    public function getTestConfigurations(int $websiteId): array
    {
        $sql = "
            SELECT
                wtc.*,
                at.name as test_name,
                at.display_name,
                at.description,
                at.category,
                at.severity as default_severity,
                at.default_timeout,
                at.default_config
            FROM website_test_config wtc
            JOIN available_tests at ON wtc.available_test_id = at.id
            WHERE wtc.website_id = ?
                AND wtc.enabled = 1
                AND at.enabled = 1
            ORDER BY wtc.priority DESC, at.category ASC, at.name ASC
        ";

        return $this->query($sql, [$websiteId]);
    }

    /**
     * Calculate next scan time based on frequency
     */
    private function calculateNextScanTime(string $frequency): string
    {
        $now = new \DateTime();

        switch ($frequency) {
            case 'hourly':
                $now->add(new \DateInterval('PT1H'));
                break;
            case 'daily':
                $now->add(new \DateInterval('P1D'));
                break;
            case 'weekly':
                $now->add(new \DateInterval('P7D'));
                break;
            case 'monthly':
                $now->add(new \DateInterval('P1M'));
                break;
            case 'manual':
            default:
                // Set far future date for manual scans
                $now->add(new \DateInterval('P10Y'));
                break;
        }

        return $now->format('Y-m-d H:i:s');
    }

    /**
     * Encrypt sensitive authentication data
     */
    public function encryptAuthData(string $data, string $key = null): string
    {
        $key = $key ?: $this->config->get('app.encryption_key', 'default-key');
        return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16)));
    }

    /**
     * Decrypt sensitive authentication data
     */
    public function decryptAuthData(string $encryptedData, string $key = null): string
    {
        $key = $key ?: $this->config->get('app.encryption_key', 'default-key');
        return openssl_decrypt(base64_decode($encryptedData), 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16));
    }
}