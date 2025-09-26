<?php

namespace SecurityScanner\Controllers;

use SecurityScanner\Core\Database;
use SecurityScanner\Tests\TestExecutionEngine;

class ApiController extends BaseController
{
    private Database $db;
    private TestExecutionEngine $testEngine;

    public function __construct(array $config = [])
    {
        parent::__construct(array_merge([
            'default_format' => 'json',
            'enable_csrf' => false // CSRF disabled for API endpoints
        ], $config));

        $this->db = Database::getInstance();
        $this->testEngine = new TestExecutionEngine();
    }

    /**
     * API Documentation endpoint
     */
    public function docsAction(array $params = []): mixed
    {
        $apiDocs = [
            'title' => 'Security Scanner API',
            'version' => '1.0.0',
            'base_url' => 'http://localhost/api',
            'endpoints' => [
                // Websites endpoints
                'websites' => [
                    'GET /api/websites' => 'List all websites',
                    'POST /api/websites' => 'Create new website',
                    'GET /api/websites/{id}' => 'Get website details',
                    'PUT /api/websites/{id}' => 'Update website',
                    'DELETE /api/websites/{id}' => 'Delete website',
                    'POST /api/websites/{id}/scan' => 'Start website scan'
                ],
                // Scans endpoints
                'scans' => [
                    'GET /api/scans' => 'List scan results',
                    'GET /api/scans/{id}' => 'Get scan details',
                    'POST /api/scans' => 'Start new scan',
                    'DELETE /api/scans/{id}' => 'Delete scan result'
                ],
                // Tests endpoints
                'tests' => [
                    'GET /api/tests' => 'List available tests',
                    'GET /api/tests/{name}' => 'Get test details',
                    'POST /api/tests/{name}/run' => 'Run specific test'
                ],
                // System endpoints
                'system' => [
                    'GET /api/health' => 'System health check',
                    'GET /api/stats' => 'System statistics',
                    'GET /api/docs' => 'API documentation'
                ]
            ],
            'authentication' => [
                'type' => 'API Key',
                'header' => 'X-API-Key',
                'description' => 'Include your API key in the X-API-Key header'
            ]
        ];

        return $apiDocs;
    }

    /**
     * List websites API
     */
    public function websitesListAction(array $params = []): mixed
    {
        try {
            $page = (int)($this->request->input('page') ?? 1);
            $limit = min((int)($this->request->input('limit') ?? 20), 100);
            $search = $this->request->input('search', '');

            $query = "SELECT * FROM websites WHERE 1=1";
            $queryParams = [];

            if (!empty($search)) {
                $query .= " AND (name LIKE ? OR url LIKE ?)";
                $searchParam = "%{$search}%";
                $queryParams = [$searchParam, $searchParam];
            }

            // Get total count
            $countQuery = str_replace('SELECT *', 'SELECT COUNT(*)', $query);
            $totalCount = $this->db->fetchColumn($countQuery, $queryParams);

            // Add pagination
            $offset = ($page - 1) * $limit;
            $query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

            $websites = $this->db->fetchAll($query, $queryParams);

            return [
                'data' => $websites,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($totalCount / $limit),
                    'total_items' => (int)$totalCount,
                    'items_per_page' => $limit
                ]
            ];

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to fetch websites',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create website API
     */
    public function websiteCreateAction(array $params = []): mixed
    {
        try {
            $data = $this->request->all();

            $validationRules = [
                'name' => 'required|string|max:255',
                'url' => 'required|url|max:500',
                'description' => 'string|max:1000',
                'scan_frequency' => 'required|in:hourly,daily,weekly,monthly',
                'notification_email' => 'email|max:255'
            ];

            if (!$this->validate($data, $validationRules)) {
                return $this->jsonResponse([
                    'error' => 'Validation failed',
                    'errors' => $this->errors
                ], 422);
            }

            $websiteId = $this->db->insert('websites', [
                'name' => $data['name'],
                'url' => $data['url'],
                'description' => $data['description'] ?? '',
                'scan_frequency' => $data['scan_frequency'],
                'notification_email' => $data['notification_email'] ?? '',
                'active' => $data['active'] ?? true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $website = $this->db->fetchRow("SELECT * FROM websites WHERE id = ?", [$websiteId]);

            $this->auditLog('api_website_created', ['website_id' => $websiteId]);

            return $this->jsonResponse([
                'message' => 'Website created successfully',
                'data' => $website
            ], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to create website',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start website scan API
     */
    public function websiteScanAction(array $params = []): mixed
    {
        try {
            $websiteId = $params['id'] ?? 0;

            $website = $this->db->fetchRow("SELECT * FROM websites WHERE id = ?", [$websiteId]);
            if (!$website) {
                return $this->jsonResponse(['error' => 'Website not found'], 404);
            }

            // Start scan (this would typically be queued in production)
            $scanId = $this->startWebsiteScan($website);

            return $this->jsonResponse([
                'message' => 'Scan initiated successfully',
                'scan_id' => $scanId,
                'website_id' => $websiteId
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to start scan',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List test results API
     */
    public function scanResultsAction(array $params = []): mixed
    {
        try {
            $page = (int)($this->request->input('page') ?? 1);
            $limit = min((int)($this->request->input('limit') ?? 20), 100);
            $websiteId = $this->request->input('website_id');
            $status = $this->request->input('status');

            $query = "SELECT sr.*, w.name as website_name, w.url as website_url
                      FROM scan_results sr
                      JOIN websites w ON sr.website_id = w.id
                      WHERE 1=1";
            $queryParams = [];

            if ($websiteId) {
                $query .= " AND sr.website_id = ?";
                $queryParams[] = $websiteId;
            }

            if ($status) {
                $query .= " AND sr.status = ?";
                $queryParams[] = $status;
            }

            // Get total count
            $countQuery = str_replace('SELECT sr.*, w.name as website_name, w.url as website_url', 'SELECT COUNT(*)', $query);
            $totalCount = $this->db->fetchColumn($countQuery, $queryParams);

            // Add pagination
            $offset = ($page - 1) * $limit;
            $query .= " ORDER BY sr.created_at DESC LIMIT {$limit} OFFSET {$offset}";

            $results = $this->db->fetchAll($query, $queryParams);

            return [
                'data' => $results,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($totalCount / $limit),
                    'total_items' => (int)$totalCount,
                    'items_per_page' => $limit
                ]
            ];

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to fetch scan results',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Run specific test API
     */
    public function runTestAction(array $params = []): mixed
    {
        try {
            $testName = $params['name'] ?? '';
            $target = $this->request->post('target');

            if (empty($target)) {
                return $this->jsonResponse(['error' => 'Target URL is required'], 422);
            }

            // Run the test
            $result = $this->testEngine->executeTest($testName, $target);

            return $this->jsonResponse([
                'message' => 'Test executed successfully',
                'result' => $result->toArray()
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Test execution failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List available tests API
     */
    public function testsListAction(array $params = []): mixed
    {
        try {
            $registry = $this->testEngine->getRegistry();
            $tests = $registry->getAllTests();

            $testList = [];
            foreach ($tests as $testName => $testData) {
                $testList[] = [
                    'name' => $testName,
                    'info' => $testData['info'],
                    'enabled' => $testData['enabled']
                ];
            }

            return [
                'data' => $testList,
                'total' => count($testList)
            ];

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to fetch tests',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * System statistics API
     */
    public function statsAction(array $params = []): mixed
    {
        try {
            $stats = [
                'websites' => [
                    'total' => $this->db->fetchColumn("SELECT COUNT(*) FROM websites"),
                    'active' => $this->db->fetchColumn("SELECT COUNT(*) FROM websites WHERE active = 1")
                ],
                'scans' => [
                    'total' => $this->db->fetchColumn("SELECT COUNT(*) FROM scan_results"),
                    'today' => $this->db->fetchColumn("SELECT COUNT(*) FROM scan_results WHERE DATE(created_at) = CURDATE()"),
                    'this_week' => $this->db->fetchColumn("SELECT COUNT(*) FROM scan_results WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
                ],
                'performance' => [
                    'avg_scan_time' => $this->db->fetchColumn("SELECT AVG(execution_time) FROM scan_results WHERE execution_time IS NOT NULL"),
                    'success_rate' => $this->db->fetchColumn("SELECT AVG(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100 FROM scan_results WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
                ]
            ];

            return $stats;

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start website scan (simplified implementation)
     */
    private function startWebsiteScan(array $website): int
    {
        // In production, this would queue the scan job
        $scanId = $this->db->insert('scan_results', [
            'website_id' => $website['id'],
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Log the scan initiation
        $this->auditLog('scan_initiated', [
            'scan_id' => $scanId,
            'website_id' => $website['id'],
            'website_url' => $website['url']
        ]);

        return $scanId;
    }
}