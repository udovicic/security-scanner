<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Validator;
use SecurityScanner\Models\Website;

class WebsiteService
{
    private Database $db;
    private Validator $validator;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->validator = new Validator();
    }

    /**
     * Create a new website with validation and business logic
     */
    public function createWebsite(array $data): array
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:500',
            'description' => 'string|max:1000',
            'scan_frequency' => 'required|in:hourly,daily,weekly,monthly',
            'notification_email' => 'email|max:255'
        ];

        if (!$this->validator->validate($data, $validationRules)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }

        // Check for duplicate URLs
        $existingWebsite = $this->db->fetchRow(
            "SELECT id FROM websites WHERE url = ?",
            [$data['url']]
        );

        if ($existingWebsite) {
            return [
                'success' => false,
                'errors' => ['url' => ['URL already exists in the system']]
            ];
        }

        // Normalize URL
        $normalizedUrl = $this->normalizeUrl($data['url']);

        // Validate URL accessibility
        if (!$this->validateUrlAccessibility($normalizedUrl)) {
            return [
                'success' => false,
                'errors' => ['url' => ['URL is not accessible or returns an error']]
            ];
        }

        $websiteData = [
            'name' => trim($data['name']),
            'url' => $normalizedUrl,
            'description' => trim($data['description'] ?? ''),
            'scan_frequency' => $data['scan_frequency'],
            'notification_email' => $data['notification_email'] ?? '',
            'active' => $data['active'] ?? true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'next_scan_at' => $this->calculateNextScanTime($data['scan_frequency'])
        ];

        $websiteId = $this->db->insert('websites', $websiteData);

        // Create default test configurations
        $this->createDefaultTestConfigurations($websiteId);

        return [
            'success' => true,
            'website_id' => $websiteId,
            'website' => $this->getWebsiteById($websiteId)
        ];
    }

    /**
     * Update website with business logic validation
     */
    public function updateWebsite(int $websiteId, array $data): array
    {
        $website = $this->getWebsiteById($websiteId);
        if (!$website) {
            return [
                'success' => false,
                'errors' => ['website' => ['Website not found']]
            ];
        }

        $validationRules = [
            'name' => 'string|max:255',
            'url' => 'url|max:500',
            'description' => 'string|max:1000',
            'scan_frequency' => 'in:hourly,daily,weekly,monthly',
            'notification_email' => 'email|max:255'
        ];

        if (!$this->validator->validate($data, $validationRules)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }

        // Check for URL conflicts if URL is being changed
        if (isset($data['url']) && $data['url'] !== $website['url']) {
            $normalizedUrl = $this->normalizeUrl($data['url']);
            $existingWebsite = $this->db->fetchRow(
                "SELECT id FROM websites WHERE url = ? AND id != ?",
                [$normalizedUrl, $websiteId]
            );

            if ($existingWebsite) {
                return [
                    'success' => false,
                    'errors' => ['url' => ['URL already exists in the system']]
                ];
            }

            if (!$this->validateUrlAccessibility($normalizedUrl)) {
                return [
                    'success' => false,
                    'errors' => ['url' => ['URL is not accessible or returns an error']]
                ];
            }

            $data['url'] = $normalizedUrl;
        }

        $updateData = array_filter($data, function($key) {
            return in_array($key, ['name', 'url', 'description', 'scan_frequency', 'notification_email', 'active']);
        }, ARRAY_FILTER_USE_KEY);

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        // Recalculate next scan time if frequency changed
        if (isset($data['scan_frequency']) && $data['scan_frequency'] !== $website['scan_frequency']) {
            $updateData['next_scan_at'] = $this->calculateNextScanTime($data['scan_frequency']);
        }

        $this->db->update('websites', $updateData, ['id' => $websiteId]);

        return [
            'success' => true,
            'website' => $this->getWebsiteById($websiteId)
        ];
    }

    /**
     * Delete website with cleanup
     */
    public function deleteWebsite(int $websiteId): array
    {
        $website = $this->getWebsiteById($websiteId);
        if (!$website) {
            return [
                'success' => false,
                'errors' => ['website' => ['Website not found']]
            ];
        }

        // Check if there are recent scan results
        $recentScans = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE website_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$websiteId]
        );

        // Archive recent scan data before deletion
        if ($recentScans > 0) {
            $this->archiveWebsiteData($websiteId);
        }

        // Delete related data
        $this->db->delete('website_test_config', ['website_id' => $websiteId]);
        $this->db->delete('scan_results', ['website_id' => $websiteId]);
        $this->db->delete('websites', ['id' => $websiteId]);

        return [
            'success' => true,
            'archived_scans' => (int)$recentScans
        ];
    }

    /**
     * Get websites with filtering and pagination
     */
    public function getWebsites(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $query = "SELECT * FROM websites WHERE 1=1";
        $params = [];

        if (!empty($filters['search'])) {
            $query .= " AND (name LIKE ? OR url LIKE ? OR description LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
        }

        if (isset($filters['active'])) {
            $query .= " AND active = ?";
            $params[] = $filters['active'] ? 1 : 0;
        }

        if (!empty($filters['scan_frequency'])) {
            $query .= " AND scan_frequency = ?";
            $params[] = $filters['scan_frequency'];
        }

        if (!empty($filters['due_for_scan'])) {
            $query .= " AND next_scan_at <= NOW()";
        }

        // Get total count
        $countQuery = str_replace('SELECT *', 'SELECT COUNT(*)', $query);
        $totalCount = $this->db->fetchColumn($countQuery, $params);

        // Add pagination and ordering
        $offset = ($page - 1) * $limit;
        $query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $websites = $this->db->fetchAll($query, $params);

        // Enhance websites with additional data
        foreach ($websites as &$website) {
            $website['last_scan'] = $this->getLastScanInfo($website['id']);
            $website['health_status'] = $this->calculateHealthStatus($website['id']);
            $website['test_count'] = $this->getActiveTestCount($website['id']);
        }

        return [
            'websites' => $websites,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($totalCount / $limit),
                'total_items' => (int)$totalCount,
                'items_per_page' => $limit
            ]
        ];
    }

    /**
     * Get website by ID with related data
     */
    public function getWebsiteById(int $websiteId): ?array
    {
        $website = $this->db->fetchRow(
            "SELECT * FROM websites WHERE id = ?",
            [$websiteId]
        );

        if (!$website) {
            return null;
        }

        $website['last_scan'] = $this->getLastScanInfo($websiteId);
        $website['health_status'] = $this->calculateHealthStatus($websiteId);
        $website['test_configurations'] = $this->getTestConfigurations($websiteId);
        $website['scan_history'] = $this->getRecentScanHistory($websiteId);

        return $website;
    }

    /**
     * Bulk operations for websites
     */
    public function bulkUpdateWebsites($websiteIdsOrUpdates, ?array $updates = null): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        // Determine which signature was used
        if ($updates === null) {
            // Single parameter: associative array with websiteId => updates
            $websiteUpdates = $websiteIdsOrUpdates;
            foreach ($websiteUpdates as $websiteId => $websiteSpecificUpdates) {
                $result = $this->updateWebsite($websiteId, $websiteSpecificUpdates);
                $results[$websiteId] = $result;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }
            $totalCount = count($websiteUpdates);
        } else {
            // Two parameters: apply same updates to multiple websites
            $websiteIds = $websiteIdsOrUpdates;
            foreach ($websiteIds as $websiteId) {
                $result = $this->updateWebsite($websiteId, $updates);
                $results[$websiteId] = $result;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }
            $totalCount = count($websiteIds);
        }

        return [
            'success' => $failureCount === 0,
            'updated_count' => $successCount,
            'failed_count' => $failureCount,
            'summary' => [
                'total' => $totalCount,
                'successful' => $successCount,
                'failed' => $failureCount
            ],
            'results' => $results
        ];
    }

    /**
     * Import websites from various formats
     */
    public function importWebsites(array $websiteData): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($websiteData as $index => $data) {
            $result = $this->createWebsite($data);
            $results[$index] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success' => $failureCount === 0,
            'summary' => [
                'total' => count($websiteData),
                'successful' => $successCount,
                'failed' => $failureCount
            ],
            'results' => $results
        ];
    }

    /**
     * Export websites in specified format
     */
    public function exportWebsites(array $websiteIds = [], string $format = 'json'): array
    {
        $query = "SELECT * FROM websites";
        $params = [];

        if (!empty($websiteIds)) {
            $placeholders = str_repeat('?,', count($websiteIds) - 1) . '?';
            $query .= " WHERE id IN ({$placeholders})";
            $params = $websiteIds;
        }

        $websites = $this->db->fetchAll($query, $params);

        // Remove sensitive data
        foreach ($websites as &$website) {
            unset($website['id']);
            $website['exported_at'] = date('Y-m-d H:i:s');
        }

        return [
            'success' => true,
            'format' => $format,
            'data' => $websites,
            'count' => count($websites)
        ];
    }

    /**
     * Calculate website health status based on recent scans
     */
    private function calculateHealthStatus(int $websiteId): string
    {
        $recentScans = $this->db->fetchAll(
            "SELECT status FROM scan_results WHERE website_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 10",
            [$websiteId]
        );

        if (empty($recentScans)) {
            return 'unknown';
        }

        $successCount = 0;
        foreach ($recentScans as $scan) {
            if ($scan['status'] === 'completed') {
                $successCount++;
            }
        }

        $successRate = $successCount / count($recentScans);

        if ($successRate >= 0.9) return 'excellent';
        if ($successRate >= 0.7) return 'good';
        if ($successRate >= 0.5) return 'fair';
        return 'poor';
    }

    /**
     * Get last scan information
     */
    private function getLastScanInfo(int $websiteId): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM scan_results WHERE website_id = ? ORDER BY created_at DESC LIMIT 1",
            [$websiteId]
        );
    }

    /**
     * Get active test count for website
     */
    private function getActiveTestCount(int $websiteId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM website_test_config WHERE website_id = ? AND enabled = 1",
            [$websiteId]
        );
    }

    /**
     * Get test configurations for website
     */
    private function getTestConfigurations(int $websiteId): array
    {
        return $this->db->fetchAll(
            "SELECT wtc.*, at.name, at.description FROM website_test_config wtc
             JOIN available_tests at ON wtc.available_test_id = at.id
             WHERE wtc.website_id = ?",
            [$websiteId]
        );
    }

    /**
     * Get recent scan history
     */
    private function getRecentScanHistory(int $websiteId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM scan_results WHERE website_id = ? ORDER BY created_at DESC LIMIT {$limit}",
            [$websiteId]
        );
    }

    /**
     * Normalize URL format
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // Remove trailing slash
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Validate URL accessibility
     */
    public function validateUrlAccessibility(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'SecurityScanner/1.0',
                'method' => 'HEAD'
            ]
        ]);

        $headers = @get_headers($url, 1, $context);

        if ($headers === false) {
            return false;
        }

        $statusCode = 0;
        if (is_array($headers) && isset($headers[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $headers[0], $matches);
            $statusCode = (int)($matches[1] ?? 0);
        }

        return $statusCode >= 200 && $statusCode < 400;
    }

    /**
     * Calculate next scan time based on frequency
     */
    public function calculateNextScanTime(string $frequency): string
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
            default:
                $now->add(new \DateInterval('P1D'));
        }

        return $now->format('Y-m-d H:i:s');
    }

    /**
     * Create default test configurations for new website
     */
    private function createDefaultTestConfigurations(int $websiteId): void
    {
        $defaultTests = $this->db->fetchAll(
            "SELECT name FROM available_tests WHERE enabled = 1 AND category IN ('security', 'availability')"
        );

        foreach ($defaultTests as $test) {
            $this->db->insert('website_test_config', [
                'website_id' => $websiteId,
                'test_name' => $test['name'],
                'enabled' => true,
                'configuration' => '{}',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Get websites due for scanning
     */
    public function getWebsitesDueForScan(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM websites
             WHERE scan_enabled = 1
             AND status = 'active'
             AND (next_scan_at IS NULL OR next_scan_at <= NOW())
             ORDER BY next_scan_at ASC"
        );
    }

    /**
     * Get active websites
     */
    public function getActiveWebsites(array $filters = []): array
    {
        $where = ["status = 'active'"];
        $params = [];

        if (!empty($filters['scan_enabled'])) {
            $where[] = "scan_enabled = ?";
            $params[] = (bool) $filters['scan_enabled'];
        }

        $sql = "SELECT * FROM websites WHERE " . implode(' AND ', $where) . " ORDER BY name";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Search websites by various criteria
     */
    public function searchWebsites(string $query, array $filters = []): array
    {
        $where = ["(name LIKE ? OR url LIKE ? OR description LIKE ?)"];
        $params = ["%{$query}%", "%{$query}%", "%{$query}%"];

        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }

        $sql = "SELECT * FROM websites WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 50";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Perform health check on website
     */
    public function performHealthCheck(int $websiteId): array
    {
        $website = $this->getWebsiteById($websiteId);
        if (!$website) {
            return ['success' => false, 'error' => 'Website not found'];
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $website['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'SecurityScanner/1.0'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);

            return [
                'success' => true,
                'http_code' => $httpCode,
                'response_time' => $responseTime,
                'is_accessible' => $httpCode >= 200 && $httpCode < 400,
                'response_size' => strlen($response)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get website statistics
     */
    public function getWebsiteStatistics(int $websiteId): array
    {
        $stats = $this->db->fetchRow(
            "SELECT
                COUNT(DISTINCT te.id) as total_scans,
                AVG(CASE WHEN tr.status = 'passed' THEN 100 ELSE 0 END) as avg_success_rate,
                COUNT(CASE WHEN tr.status = 'failed' THEN 1 END) as failed_tests,
                COUNT(CASE WHEN tr.status = 'passed' THEN 1 END) as passed_tests,
                MAX(te.created_at) as last_scan_date
             FROM test_executions te
             LEFT JOIN test_results tr ON te.id = tr.test_execution_id
             WHERE te.website_id = ?
             GROUP BY te.website_id",
            [$websiteId]
        );

        if (!$stats) {
            return [
                'total_scans' => 0,
                'avg_success_rate' => 0,
                'failed_tests' => 0,
                'passed_tests' => 0,
                'last_scan_date' => null
            ];
        }

        return $stats;
    }

    /**
     * Public wrapper for normalize URL method (for testing)
     */
    public function testNormalizeUrl(string $url): string
    {
        return $this->normalizeUrl($url);
    }

    /**
     * Public wrapper for calculate next scan time (for testing)
     */
    public function testCalculateNextScanTime(string $frequency): string
    {
        return $this->calculateNextScanTime($frequency);
    }

    /**
     * Public wrapper for validate URL accessibility (for testing)
     */
    public function testValidateUrlAccessibility(string $url): bool
    {
        return $this->validateUrlAccessibility($url);
    }

    /**
     * Archive website data before deletion
     */
    private function archiveWebsiteData(int $websiteId): void
    {
        // This would typically move data to an archive table or export to files
        // For now, we'll just log the archival
        error_log("Archiving data for website ID: {$websiteId}");
    }
}