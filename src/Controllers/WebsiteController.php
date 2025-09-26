<?php

namespace SecurityScanner\Controllers;

use SecurityScanner\Core\{Request, Response, Database};

class WebsiteController extends BaseController
{
    private Database $db;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->db = Database::getInstance();
    }

    /**
     * Display list of websites (index)
     */
    public function indexAction(array $params = []): mixed
    {
        try {
            // Get pagination parameters
            $page = (int)($this->request->input('page') ?? 1);
            $limit = (int)($this->request->input('limit') ?? $this->config['pagination_limit']);
            $search = $this->request->input('search', '');
            $sort = $this->request->input('sort', 'created_at');
            $order = $this->request->input('order', 'DESC');

            // Build query
            $query = "SELECT * FROM websites WHERE 1=1";
            $queryParams = [];

            // Add search filter
            if (!empty($search)) {
                $query .= " AND (name LIKE ? OR url LIKE ? OR description LIKE ?)";
                $searchParam = "%{$search}%";
                $queryParams = array_fill(0, 3, $searchParam);
            }

            // Add sorting
            $allowedSorts = ['name', 'url', 'created_at', 'updated_at', 'status'];
            if (in_array($sort, $allowedSorts)) {
                $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
                $query .= " ORDER BY {$sort} {$order}";
            }

            // Get total count for pagination
            $countQuery = str_replace('SELECT *', 'SELECT COUNT(*)', explode('ORDER BY', $query)[0]);
            $totalCount = $this->db->fetchColumn($countQuery, $queryParams);

            // Add pagination
            $offset = ($page - 1) * $limit;
            $query .= " LIMIT {$limit} OFFSET {$offset}";

            // Execute query
            $websites = $this->db->fetchAll($query, $queryParams);

            // Calculate pagination info
            $totalPages = ceil($totalCount / $limit);

            $data = [
                'title' => 'Security Scanner - Websites',
                'main' => $this->renderWebsitesList([
                    'websites' => $websites,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_items' => $totalCount,
                        'items_per_page' => $limit,
                        'has_next' => $page < $totalPages,
                        'has_prev' => $page > 1
                    ],
                    'search' => $search,
                    'sort' => $sort,
                    'order' => $order
                ])
            ];

            return $data;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to load websites: ' . $e->getMessage());
            return ['title' => 'Error', 'main' => '<h1>Error loading websites</h1>'];
        }
    }

    /**
     * Show form to create new website
     */
    public function createAction(array $params = []): mixed
    {
        $data = [
            'title' => 'Add New Website',
            'main' => $this->renderWebsiteForm([
                'website' => [
                    'name' => '',
                    'url' => '',
                    'description' => '',
                    'scan_frequency' => 'daily',
                    'notification_email' => '',
                    'active' => true
                ],
                'action' => 'create',
                'method' => 'POST'
            ])
        ];

        return $data;
    }

    /**
     * Store new website
     */
    public function storeAction(array $params = []): mixed
    {
        try {
            // Validate input
            $validationRules = [
                'name' => 'required|string|max:255',
                'url' => 'required|url|max:500',
                'description' => 'string|max:1000',
                'scan_frequency' => 'required|in:hourly,daily,weekly,monthly',
                'notification_email' => 'email|max:255',
                'active' => 'boolean'
            ];

            $data = $this->request->all();

            if (!$this->validate($data, $validationRules)) {
                return $this->createAction($params);
            }

            // Check if URL already exists
            $existingWebsite = $this->db->fetchRow(
                "SELECT id FROM websites WHERE url = ?",
                [$data['url']]
            );

            if ($existingWebsite) {
                $this->addFlash('error', 'A website with this URL already exists');
                return $this->createAction($params);
            }

            // Insert new website
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

            $this->auditLog('website_created', ['website_id' => $websiteId, 'url' => $data['url']]);
            $this->addFlash('success', 'Website added successfully');

            return $this->redirect('/websites/' . $websiteId);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create website: ' . $e->getMessage());
            return $this->createAction($params);
        }
    }

    /**
     * Show website details
     */
    public function showAction(array $params = []): mixed
    {
        try {
            $id = $params['id'] ?? 0;

            $website = $this->db->fetchRow(
                "SELECT * FROM websites WHERE id = ?",
                [$id]
            );

            if (!$website) {
                throw new \Exception('Website not found');
            }

            // Get recent test results
            $recentResults = $this->db->fetchAll(
                "SELECT * FROM scan_results WHERE website_id = ? ORDER BY created_at DESC LIMIT 10",
                [$id]
            );

            // Get website statistics
            $stats = $this->getWebsiteStatistics($id);

            $data = [
                'title' => 'Website: ' . htmlspecialchars($website['name']),
                'main' => $this->renderWebsiteDetails([
                    'website' => $website,
                    'recent_results' => $recentResults,
                    'statistics' => $stats
                ])
            ];

            return $data;

        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Show form to edit website
     */
    public function editAction(array $params = []): mixed
    {
        try {
            $id = $params['id'] ?? 0;

            $website = $this->db->fetchRow(
                "SELECT * FROM websites WHERE id = ?",
                [$id]
            );

            if (!$website) {
                throw new \Exception('Website not found');
            }

            $data = [
                'title' => 'Edit Website: ' . htmlspecialchars($website['name']),
                'main' => $this->renderWebsiteForm([
                    'website' => $website,
                    'action' => 'update',
                    'method' => 'PUT'
                ])
            ];

            return $data;

        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Update website
     */
    public function updateAction(array $params = []): mixed
    {
        try {
            $id = $params['id'] ?? 0;

            // Check if website exists
            $website = $this->db->fetchRow(
                "SELECT * FROM websites WHERE id = ?",
                [$id]
            );

            if (!$website) {
                throw new \Exception('Website not found');
            }

            // Validate input
            $validationRules = [
                'name' => 'required|string|max:255',
                'url' => 'required|url|max:500',
                'description' => 'string|max:1000',
                'scan_frequency' => 'required|in:hourly,daily,weekly,monthly',
                'notification_email' => 'email|max:255',
                'active' => 'boolean'
            ];

            $data = $this->request->all();

            if (!$this->validate($data, $validationRules)) {
                return $this->editAction($params);
            }

            // Check if URL already exists (excluding current website)
            $existingWebsite = $this->db->fetchRow(
                "SELECT id FROM websites WHERE url = ? AND id != ?",
                [$data['url'], $id]
            );

            if ($existingWebsite) {
                $this->addFlash('error', 'A website with this URL already exists');
                return $this->editAction($params);
            }

            // Update website
            $this->db->update('websites', [
                'name' => $data['name'],
                'url' => $data['url'],
                'description' => $data['description'] ?? '',
                'scan_frequency' => $data['scan_frequency'],
                'notification_email' => $data['notification_email'] ?? '',
                'active' => $data['active'] ?? true,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            $this->auditLog('website_updated', ['website_id' => $id, 'url' => $data['url']]);
            $this->addFlash('success', 'Website updated successfully');

            return $this->redirect('/websites/' . $id);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update website: ' . $e->getMessage());
            return $this->editAction($params);
        }
    }

    /**
     * Delete website
     */
    public function destroyAction(array $params = []): mixed
    {
        try {
            $id = $params['id'] ?? 0;

            // Check if website exists
            $website = $this->db->fetchRow(
                "SELECT * FROM websites WHERE id = ?",
                [$id]
            );

            if (!$website) {
                throw new \Exception('Website not found');
            }

            // Delete related scan results first
            $this->db->delete('scan_results', ['website_id' => $id]);

            // Delete website
            $this->db->delete('websites', ['id' => $id]);

            $this->auditLog('website_deleted', ['website_id' => $id, 'url' => $website['url']]);
            $this->addFlash('success', 'Website deleted successfully');

            return $this->redirect('/websites');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete website: ' . $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Bulk operations on websites
     */
    public function bulkAction(array $params = []): mixed
    {
        try {
            $action = $this->request->post('bulk_action');
            $websiteIds = $this->request->post('website_ids', []);

            if (empty($websiteIds) || !is_array($websiteIds)) {
                $this->addFlash('error', 'No websites selected');
                return $this->redirect('/websites');
            }

            $count = 0;

            switch ($action) {
                case 'activate':
                    $count = $this->bulkActivate($websiteIds);
                    $this->addFlash('success', "Activated {$count} websites");
                    break;

                case 'deactivate':
                    $count = $this->bulkDeactivate($websiteIds);
                    $this->addFlash('success', "Deactivated {$count} websites");
                    break;

                case 'delete':
                    $count = $this->bulkDelete($websiteIds);
                    $this->addFlash('success', "Deleted {$count} websites");
                    break;

                case 'scan':
                    $count = $this->bulkScan($websiteIds);
                    $this->addFlash('success', "Initiated scans for {$count} websites");
                    break;

                default:
                    $this->addFlash('error', 'Invalid bulk action');
            }

            return $this->redirect('/websites');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Bulk operation failed: ' . $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Export websites
     */
    public function exportAction(array $params = []): mixed
    {
        try {
            $format = $this->request->input('format', 'csv');
            $websites = $this->db->fetchAll("SELECT * FROM websites ORDER BY name");

            switch ($format) {
                case 'json':
                    return $this->exportJson($websites);
                case 'xml':
                    return $this->exportXml($websites);
                case 'csv':
                default:
                    return $this->exportCsv($websites);
            }

        } catch (\Exception $e) {
            $this->addFlash('error', 'Export failed: ' . $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Import websites
     */
    public function importAction(array $params = []): mixed
    {
        try {
            if ($this->request->getMethod() === 'POST') {
                return $this->processImport();
            }

            // Show import form
            $data = [
                'title' => 'Import Websites',
                'main' => $this->renderImportForm()
            ];

            return $data;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Import failed: ' . $e->getMessage());
            return $this->redirect('/websites');
        }
    }

    /**
     * Render websites list
     */
    private function renderWebsitesList(array $data): string
    {
        $html = '<div class="websites-container">';
        $html .= '<div class="header-actions">';
        $html .= '<h1>Websites</h1>';
        $html .= '<div class="actions">';
        $html .= '<a href="/websites/create" class="btn btn-primary">Add Website</a>';
        $html .= '<a href="/websites/import" class="btn btn-secondary">Import</a>';
        $html .= '<a href="/websites/export" class="btn btn-secondary">Export</a>';
        $html .= '</div>';
        $html .= '</div>';

        // Search form
        $html .= $this->renderSearchForm($data);

        // Bulk actions form
        $html .= '<form method="POST" action="/websites/bulk">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $this->generateCsrfToken() . '">';

        // Bulk actions bar
        $html .= '<div class="bulk-actions">';
        $html .= '<select name="bulk_action">';
        $html .= '<option value="">Bulk Actions</option>';
        $html .= '<option value="activate">Activate</option>';
        $html .= '<option value="deactivate">Deactivate</option>';
        $html .= '<option value="scan">Run Scan</option>';
        $html .= '<option value="delete">Delete</option>';
        $html .= '</select>';
        $html .= '<button type="submit" class="btn btn-secondary">Apply</button>';
        $html .= '</div>';

        // Websites table
        $html .= $this->renderWebsitesTable($data['websites']);

        $html .= '</form>';

        // Pagination
        if ($data['pagination']['total_pages'] > 1) {
            $html .= $this->pe->renderPagination($data['pagination']);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render search form
     */
    private function renderSearchForm(array $data): string
    {
        return $this->pe->renderForm([
            'action' => '/websites',
            'method' => 'GET',
            'fields' => [
                [
                    'type' => 'text',
                    'name' => 'search',
                    'label' => 'Search',
                    'value' => $data['search'],
                    'placeholder' => 'Search websites...'
                ]
            ],
            'submit_text' => 'Search'
        ]);
    }

    /**
     * Render websites table
     */
    private function renderWebsitesTable(array $websites): string
    {
        if (empty($websites)) {
            return '<div class="no-results">No websites found.</div>';
        }

        $headers = [
            ['text' => '', 'sortable' => false], // Checkbox
            ['text' => 'Name', 'sortable' => true, 'sort_url' => '?sort=name'],
            ['text' => 'URL', 'sortable' => true, 'sort_url' => '?sort=url'],
            ['text' => 'Status', 'sortable' => false],
            ['text' => 'Last Scan', 'sortable' => true, 'sort_url' => '?sort=updated_at'],
            ['text' => 'Actions', 'sortable' => false]
        ];

        $rows = [];
        foreach ($websites as $website) {
            $statusBadge = $website['active'] ? '<span class="badge badge-success">Active</span>'
                                              : '<span class="badge badge-secondary">Inactive</span>';

            $actions = '<div class="btn-group">';
            $actions .= '<a href="/websites/' . $website['id'] . '" class="btn btn-sm">View</a>';
            $actions .= '<a href="/websites/' . $website['id'] . '/edit" class="btn btn-sm">Edit</a>';
            $actions .= '<a href="/websites/' . $website['id'] . '/scan" class="btn btn-sm">Scan</a>';
            $actions .= '</div>';

            $rows[] = [
                '<input type="checkbox" name="website_ids[]" value="' . $website['id'] . '">',
                htmlspecialchars($website['name']),
                '<a href="' . htmlspecialchars($website['url']) . '" target="_blank">' . htmlspecialchars($website['url']) . '</a>',
                $statusBadge,
                date('Y-m-d H:i', strtotime($website['updated_at'])),
                $actions
            ];
        }

        return $this->pe->renderDataTable([
            'headers' => $headers,
            'rows' => $rows,
            'caption' => 'Websites List'
        ]);
    }

    /**
     * Get website statistics
     */
    private function getWebsiteStatistics(int $websiteId): array
    {
        $stats = [];

        // Total scans
        $stats['total_scans'] = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE website_id = ?",
            [$websiteId]
        );

        // Success rate
        $successCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM scan_results WHERE website_id = ? AND status = 'completed'",
            [$websiteId]
        );

        $stats['success_rate'] = $stats['total_scans'] > 0
            ? round(($successCount / $stats['total_scans']) * 100, 1)
            : 0;

        // Average score
        $avgScore = $this->db->fetchColumn(
            "SELECT AVG(score) FROM scan_results WHERE website_id = ? AND score IS NOT NULL",
            [$websiteId]
        );

        $stats['average_score'] = $avgScore ? round($avgScore, 1) : null;

        // Last scan date
        $lastScan = $this->db->fetchColumn(
            "SELECT MAX(created_at) FROM scan_results WHERE website_id = ?",
            [$websiteId]
        );

        $stats['last_scan'] = $lastScan;

        return $stats;
    }

    /**
     * Bulk activate websites
     */
    private function bulkActivate(array $websiteIds): int
    {
        $placeholders = str_repeat('?,', count($websiteIds) - 1) . '?';
        $query = "UPDATE websites SET active = 1, updated_at = ? WHERE id IN ({$placeholders})";
        $params = array_merge([date('Y-m-d H:i:s')], $websiteIds);

        $this->db->execute($query, $params);
        $this->auditLog('websites_bulk_activated', ['website_ids' => $websiteIds]);

        return count($websiteIds);
    }

    /**
     * Bulk deactivate websites
     */
    private function bulkDeactivate(array $websiteIds): int
    {
        $placeholders = str_repeat('?,', count($websiteIds) - 1) . '?';
        $query = "UPDATE websites SET active = 0, updated_at = ? WHERE id IN ({$placeholders})";
        $params = array_merge([date('Y-m-d H:i:s')], $websiteIds);

        $this->db->execute($query, $params);
        $this->auditLog('websites_bulk_deactivated', ['website_ids' => $websiteIds]);

        return count($websiteIds);
    }

    /**
     * Bulk delete websites
     */
    private function bulkDelete(array $websiteIds): int
    {
        $placeholders = str_repeat('?,', count($websiteIds) - 1) . '?';

        // Delete scan results first
        $this->db->execute(
            "DELETE FROM scan_results WHERE website_id IN ({$placeholders})",
            $websiteIds
        );

        // Delete websites
        $this->db->execute(
            "DELETE FROM websites WHERE id IN ({$placeholders})",
            $websiteIds
        );

        $this->auditLog('websites_bulk_deleted', ['website_ids' => $websiteIds]);

        return count($websiteIds);
    }

    /**
     * Bulk scan websites
     */
    private function bulkScan(array $websiteIds): int
    {
        // This would trigger scans for selected websites
        // For now, just log the action
        $this->auditLog('websites_bulk_scan_initiated', ['website_ids' => $websiteIds]);

        return count($websiteIds);
    }

    /**
     * Export websites as CSV
     */
    private function exportCsv(array $websites): Response
    {
        $csv = "Name,URL,Description,Scan Frequency,Notification Email,Active,Created At\n";

        foreach ($websites as $website) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $website['name']),
                str_replace('"', '""', $website['url']),
                str_replace('"', '""', $website['description']),
                $website['scan_frequency'],
                str_replace('"', '""', $website['notification_email']),
                $website['active'] ? 'Yes' : 'No',
                $website['created_at']
            );
        }

        return Response::create($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="websites_' . date('Y-m-d') . '.csv"'
        ]);
    }

    /**
     * Export websites as JSON
     */
    private function exportJson(array $websites): Response
    {
        return Response::json($websites, 200, [
            'Content-Disposition' => 'attachment; filename="websites_' . date('Y-m-d') . '.json"'
        ]);
    }

    /**
     * Export websites as XML
     */
    private function exportXml(array $websites): Response
    {
        $xml = $this->arrayToXml(['websites' => $websites], 'export');

        return Response::create($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="websites_' . date('Y-m-d') . '.xml"'
        ]);
    }

    /**
     * Render website form
     */
    private function renderWebsiteForm(array $data): string
    {
        return $this->pe->renderForm([
            'action' => $data['action'] === 'create' ? '/websites' : '/websites/' . $data['website']['id'],
            'method' => $data['method'],
            'fields' => [
                [
                    'type' => 'text',
                    'name' => 'name',
                    'label' => 'Website Name',
                    'value' => $data['website']['name'],
                    'required' => true
                ],
                [
                    'type' => 'url',
                    'name' => 'url',
                    'label' => 'Website URL',
                    'value' => $data['website']['url'],
                    'required' => true
                ],
                [
                    'type' => 'textarea',
                    'name' => 'description',
                    'label' => 'Description',
                    'value' => $data['website']['description']
                ],
                [
                    'type' => 'select',
                    'name' => 'scan_frequency',
                    'label' => 'Scan Frequency',
                    'value' => $data['website']['scan_frequency'],
                    'options' => [
                        'hourly' => 'Hourly',
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly'
                    ],
                    'required' => true
                ],
                [
                    'type' => 'email',
                    'name' => 'notification_email',
                    'label' => 'Notification Email',
                    'value' => $data['website']['notification_email']
                ]
            ],
            'submit_text' => $data['action'] === 'create' ? 'Add Website' : 'Update Website'
        ]);
    }

    /**
     * Render website details
     */
    private function renderWebsiteDetails(array $data): string
    {
        $website = $data['website'];
        $stats = $data['statistics'];

        $html = '<div class="website-details">';
        $html .= '<div class="header">';
        $html .= '<h1>' . htmlspecialchars($website['name']) . '</h1>';
        $html .= '<div class="actions">';
        $html .= '<a href="/websites/' . $website['id'] . '/edit" class="btn btn-primary">Edit</a>';
        $html .= '<a href="/websites/' . $website['id'] . '/scan" class="btn btn-success">Run Scan</a>';
        $html .= '</div>';
        $html .= '</div>';

        // Website info
        $html .= '<div class="website-info">';
        $html .= '<p><strong>URL:</strong> <a href="' . htmlspecialchars($website['url']) . '" target="_blank">' . htmlspecialchars($website['url']) . '</a></p>';
        $html .= '<p><strong>Description:</strong> ' . htmlspecialchars($website['description']) . '</p>';
        $html .= '<p><strong>Scan Frequency:</strong> ' . htmlspecialchars($website['scan_frequency']) . '</p>';
        $html .= '<p><strong>Status:</strong> ' . ($website['active'] ? 'Active' : 'Inactive') . '</p>';
        $html .= '</div>';

        // Statistics
        $html .= '<div class="statistics">';
        $html .= '<h2>Statistics</h2>';
        $html .= '<div class="stats-grid">';
        $html .= '<div class="stat"><span class="label">Total Scans:</span> <span class="value">' . $stats['total_scans'] . '</span></div>';
        $html .= '<div class="stat"><span class="label">Success Rate:</span> <span class="value">' . $stats['success_rate'] . '%</span></div>';
        $html .= '<div class="stat"><span class="label">Average Score:</span> <span class="value">' . ($stats['average_score'] ?? 'N/A') . '</span></div>';
        $html .= '<div class="stat"><span class="label">Last Scan:</span> <span class="value">' . ($stats['last_scan'] ? date('Y-m-d H:i', strtotime($stats['last_scan'])) : 'Never') . '</span></div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render import form
     */
    private function renderImportForm(): string
    {
        return $this->pe->renderForm([
            'action' => '/websites/import',
            'method' => 'POST',
            'enctype' => 'multipart/form-data',
            'fields' => [
                [
                    'type' => 'file',
                    'name' => 'import_file',
                    'label' => 'Import File',
                    'required' => true
                ],
                [
                    'type' => 'select',
                    'name' => 'format',
                    'label' => 'File Format',
                    'options' => [
                        'csv' => 'CSV',
                        'json' => 'JSON',
                        'xml' => 'XML'
                    ],
                    'required' => true
                ]
            ],
            'submit_text' => 'Import Websites'
        ]);
    }

    /**
     * Process import
     */
    private function processImport(): mixed
    {
        // Implementation would handle file upload and parsing
        $this->addFlash('info', 'Import functionality would be implemented here');
        return $this->redirect('/websites');
    }
}