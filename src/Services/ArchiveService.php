<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;

class ArchiveService
{
    private Database $db;
    private array $config;
    private string $archiveBasePath;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'retention_days' => [
                'scan_results' => 365,
                'test_executions' => 365,
                'scheduler_log' => 90,
                'notification_log' => 90,
                'scan_metrics' => 730 // 2 years
            ],
            'archive_batch_size' => 1000,
            'compression_enabled' => true,
            'encryption_enabled' => false,
            'encryption_key' => '',
            'max_archive_file_size' => 100 * 1024 * 1024, // 100MB
            'cleanup_enabled' => true,
            'backup_before_delete' => true
        ], $config);

        $this->archiveBasePath = $config['archive_path'] ?? sys_get_temp_dir() . '/security_scanner_archives';

        // Ensure archive directory exists
        if (!is_dir($this->archiveBasePath)) {
            mkdir($this->archiveBasePath, 0755, true);
        }
    }

    /**
     * Perform full archive operation
     */
    public function performArchive(): array
    {
        $startTime = microtime(true);
        $results = [
            'success' => true,
            'execution_time' => 0,
            'tables_processed' => 0,
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files_created' => 0,
            'space_freed' => 0,
            'errors' => []
        ];

        try {
            // Archive scan results
            $scanResults = $this->archiveScanResults();
            $results = $this->mergeResults($results, $scanResults);

            // Archive test executions
            $testResults = $this->archiveTestExecutions();
            $results = $this->mergeResults($results, $testResults);

            // Archive scheduler logs
            $schedulerResults = $this->archiveSchedulerLogs();
            $results = $this->mergeResults($results, $schedulerResults);

            // Archive notification logs
            $notificationResults = $this->archiveNotificationLogs();
            $results = $this->mergeResults($results, $notificationResults);

            // Archive scan metrics (older than retention period)
            $metricsResults = $this->archiveScanMetrics();
            $results = $this->mergeResults($results, $metricsResults);

            // Cleanup orphaned records
            if ($this->config['cleanup_enabled']) {
                $cleanupResults = $this->cleanupOrphanedRecords();
                $results = $this->mergeResults($results, $cleanupResults);
            }

            // Optimize database tables
            $this->optimizeTables();

            $results['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logArchiveActivity('archive_completed', $results);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $results['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logArchiveActivity('archive_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Archive scan results
     */
    public function archiveScanResults(): array
    {
        $retentionDays = $this->config['retention_days']['scan_results'];
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $results = [
            'table' => 'scan_results',
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files' => [],
            'errors' => []
        ];

        try {
            // Get old scan results
            $oldResults = $this->db->fetchAll(
                "SELECT sr.*, w.name as website_name, w.url as website_url
                 FROM scan_results sr
                 LEFT JOIN websites w ON sr.website_id = w.id
                 WHERE sr.created_at < ?
                 ORDER BY sr.created_at
                 LIMIT ?",
                [$cutoffDate, $this->config['archive_batch_size']]
            );

            if (!empty($oldResults)) {
                // Create archive file
                $archiveFile = $this->createArchiveFile('scan_results', $oldResults);
                $results['archive_files'][] = $archiveFile;
                $results['records_archived'] = count($oldResults);

                // Delete archived records if backup was successful
                if ($archiveFile['success']) {
                    $scanIds = array_column($oldResults, 'id');
                    $placeholders = str_repeat('?,', count($scanIds) - 1) . '?';

                    // First delete related test_executions
                    $this->db->execute(
                        "DELETE FROM test_executions WHERE scan_id IN ({$placeholders})",
                        $scanIds
                    );

                    // Then delete scan_results
                    $deleted = $this->db->execute(
                        "DELETE FROM scan_results WHERE id IN ({$placeholders})",
                        $scanIds
                    );

                    $results['records_deleted'] = $deleted;
                } else {
                    $results['errors'][] = 'Failed to create archive, skipping deletion';
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Archive test executions
     */
    public function archiveTestExecutions(): array
    {
        $retentionDays = $this->config['retention_days']['test_executions'];
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $results = [
            'table' => 'test_executions',
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files' => [],
            'errors' => []
        ];

        try {
            // Get orphaned test executions (those without parent scan_results)
            $orphanedExecutions = $this->db->fetchAll(
                "SELECT te.*
                 FROM test_executions te
                 LEFT JOIN scan_results sr ON te.scan_id = sr.id
                 WHERE sr.id IS NULL OR te.created_at < ?
                 ORDER BY te.created_at
                 LIMIT ?",
                [$cutoffDate, $this->config['archive_batch_size']]
            );

            if (!empty($orphanedExecutions)) {
                $archiveFile = $this->createArchiveFile('test_executions', $orphanedExecutions);
                $results['archive_files'][] = $archiveFile;
                $results['records_archived'] = count($orphanedExecutions);

                if ($archiveFile['success']) {
                    $executionIds = array_column($orphanedExecutions, 'id');
                    $placeholders = str_repeat('?,', count($executionIds) - 1) . '?';

                    $deleted = $this->db->execute(
                        "DELETE FROM test_executions WHERE id IN ({$placeholders})",
                        $executionIds
                    );

                    $results['records_deleted'] = $deleted;
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Archive scheduler logs
     */
    public function archiveSchedulerLogs(): array
    {
        $retentionDays = $this->config['retention_days']['scheduler_log'];
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $results = [
            'table' => 'scheduler_log',
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files' => [],
            'errors' => []
        ];

        try {
            $oldLogs = $this->db->fetchAll(
                "SELECT * FROM scheduler_log
                 WHERE created_at < ?
                 ORDER BY created_at
                 LIMIT ?",
                [$cutoffDate, $this->config['archive_batch_size']]
            );

            if (!empty($oldLogs)) {
                $archiveFile = $this->createArchiveFile('scheduler_log', $oldLogs);
                $results['archive_files'][] = $archiveFile;
                $results['records_archived'] = count($oldLogs);

                if ($archiveFile['success']) {
                    $logIds = array_column($oldLogs, 'id');
                    $placeholders = str_repeat('?,', count($logIds) - 1) . '?';

                    $deleted = $this->db->execute(
                        "DELETE FROM scheduler_log WHERE id IN ({$placeholders})",
                        $logIds
                    );

                    $results['records_deleted'] = $deleted;
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Archive notification logs
     */
    public function archiveNotificationLogs(): array
    {
        $retentionDays = $this->config['retention_days']['notification_log'];
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $results = [
            'table' => 'notification_log',
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files' => [],
            'errors' => []
        ];

        try {
            $oldLogs = $this->db->fetchAll(
                "SELECT * FROM notification_log
                 WHERE created_at < ?
                 ORDER BY created_at
                 LIMIT ?",
                [$cutoffDate, $this->config['archive_batch_size']]
            );

            if (!empty($oldLogs)) {
                $archiveFile = $this->createArchiveFile('notification_log', $oldLogs);
                $results['archive_files'][] = $archiveFile;
                $results['records_archived'] = count($oldLogs);

                if ($archiveFile['success']) {
                    $logIds = array_column($oldLogs, 'id');
                    $placeholders = str_repeat('?,', count($logIds) - 1) . '?';

                    $deleted = $this->db->execute(
                        "DELETE FROM notification_log WHERE id IN ({$placeholders})",
                        $logIds
                    );

                    $results['records_deleted'] = $deleted;
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Archive scan metrics
     */
    public function archiveScanMetrics(): array
    {
        $retentionDays = $this->config['retention_days']['scan_metrics'];
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $results = [
            'table' => 'scan_metrics',
            'records_archived' => 0,
            'records_deleted' => 0,
            'archive_files' => [],
            'errors' => []
        ];

        try {
            $oldMetrics = $this->db->fetchAll(
                "SELECT sm.*, w.name as website_name
                 FROM scan_metrics sm
                 LEFT JOIN websites w ON sm.website_id = w.id
                 WHERE sm.created_at < ?
                 ORDER BY sm.created_at
                 LIMIT ?",
                [$cutoffDate, $this->config['archive_batch_size']]
            );

            if (!empty($oldMetrics)) {
                $archiveFile = $this->createArchiveFile('scan_metrics', $oldMetrics);
                $results['archive_files'][] = $archiveFile;
                $results['records_archived'] = count($oldMetrics);

                if ($archiveFile['success']) {
                    $metricIds = array_column($oldMetrics, 'id');
                    $placeholders = str_repeat('?,', count($metricIds) - 1) . '?';

                    $deleted = $this->db->execute(
                        "DELETE FROM scan_metrics WHERE id IN ({$placeholders})",
                        $metricIds
                    );

                    $results['records_deleted'] = $deleted;
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Clean up orphaned records
     */
    public function cleanupOrphanedRecords(): array
    {
        $results = [
            'table' => 'cleanup_orphaned',
            'records_deleted' => 0,
            'cleanup_operations' => [],
            'errors' => []
        ];

        try {
            // Clean up test_executions without parent scan_results
            $orphanedExecutions = $this->db->execute(
                "DELETE te FROM test_executions te
                 LEFT JOIN scan_results sr ON te.scan_id = sr.id
                 WHERE sr.id IS NULL"
            );

            $results['cleanup_operations'][] = [
                'operation' => 'orphaned_test_executions',
                'deleted' => $orphanedExecutions
            ];
            $results['records_deleted'] += $orphanedExecutions;

            // Clean up website_test_config for deleted websites
            $orphanedConfigs = $this->db->execute(
                "DELETE wtc FROM website_test_config wtc
                 LEFT JOIN websites w ON wtc.website_id = w.id
                 WHERE w.id IS NULL"
            );

            $results['cleanup_operations'][] = [
                'operation' => 'orphaned_website_configs',
                'deleted' => $orphanedConfigs
            ];
            $results['records_deleted'] += $orphanedConfigs;

            // Clean up scan_metrics for deleted websites
            $orphanedMetrics = $this->db->execute(
                "DELETE sm FROM scan_metrics sm
                 LEFT JOIN websites w ON sm.website_id = w.id
                 WHERE w.id IS NULL"
            );

            $results['cleanup_operations'][] = [
                'operation' => 'orphaned_scan_metrics',
                'deleted' => $orphanedMetrics
            ];
            $results['records_deleted'] += $orphanedMetrics;

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Create archive file
     */
    private function createArchiveFile(string $table, array $data): array
    {
        $result = [
            'success' => false,
            'file_path' => '',
            'file_size' => 0,
            'record_count' => count($data),
            'compression_ratio' => 0,
            'error' => null
        ];

        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "{$table}_{$timestamp}.json";

            if ($this->config['compression_enabled']) {
                $filename .= '.gz';
            }

            $filePath = $this->archiveBasePath . '/' . $filename;

            // Prepare data for archiving
            $archiveData = [
                'table' => $table,
                'archived_at' => date('Y-m-d H:i:s'),
                'record_count' => count($data),
                'records' => $data
            ];

            $jsonData = json_encode($archiveData, JSON_PRETTY_PRINT);
            $originalSize = strlen($jsonData);

            if ($this->config['compression_enabled']) {
                $compressedData = gzencode($jsonData, 9);
                $finalData = $compressedData;
                $result['compression_ratio'] = round((1 - strlen($compressedData) / $originalSize) * 100, 1);
            } else {
                $finalData = $jsonData;
            }

            // Encrypt if enabled
            if ($this->config['encryption_enabled'] && !empty($this->config['encryption_key'])) {
                $finalData = $this->encryptData($finalData);
            }

            // Write to file
            if (file_put_contents($filePath, $finalData) !== false) {
                $result['success'] = true;
                $result['file_path'] = $filePath;
                $result['file_size'] = filesize($filePath);

                // Set proper file permissions
                chmod($filePath, 0640);
            } else {
                $result['error'] = 'Failed to write archive file';
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Restore data from archive
     */
    public function restoreFromArchive(string $archiveFile, ?string $targetTable = null): array
    {
        $result = [
            'success' => false,
            'records_restored' => 0,
            'table' => $targetTable,
            'error' => null
        ];

        try {
            if (!file_exists($archiveFile)) {
                $result['error'] = 'Archive file not found';
                return $result;
            }

            $data = file_get_contents($archiveFile);

            // Decrypt if needed
            if ($this->config['encryption_enabled'] && !empty($this->config['encryption_key'])) {
                $data = $this->decryptData($data);
            }

            // Decompress if needed
            if (str_ends_with($archiveFile, '.gz')) {
                $data = gzdecode($data);
            }

            $archiveData = json_decode($data, true);

            if (!$archiveData || !isset($archiveData['records'])) {
                $result['error'] = 'Invalid archive format';
                return $result;
            }

            $table = $targetTable ?: $archiveData['table'];
            $records = $archiveData['records'];

            // Restore records
            foreach ($records as $record) {
                // Remove ID to avoid conflicts
                unset($record['id']);

                try {
                    $this->db->insert($table, $record);
                    $result['records_restored']++;
                } catch (\Exception $e) {
                    // Log individual record failures but continue
                    error_log("Failed to restore record: " . $e->getMessage());
                }
            }

            $result['success'] = true;
            $result['table'] = $table;

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * List available archive files
     */
    public function listArchiveFiles(): array
    {
        $files = [];

        if (!is_dir($this->archiveBasePath)) {
            return $files;
        }

        $pattern = $this->archiveBasePath . '/*.json*';
        foreach (glob($pattern) as $file) {
            $files[] = [
                'filename' => basename($file),
                'full_path' => $file,
                'size' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'table' => $this->extractTableFromFilename(basename($file))
            ];
        }

        // Sort by creation time, newest first
        usort($files, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return $files;
    }

    /**
     * Get archive statistics
     */
    public function getArchiveStatistics(): array
    {
        $files = $this->listArchiveFiles();

        $stats = [
            'total_files' => count($files),
            'total_size' => 0,
            'oldest_archive' => null,
            'newest_archive' => null,
            'by_table' => []
        ];

        foreach ($files as $file) {
            $stats['total_size'] += $file['size'];

            if (!$stats['oldest_archive'] || $file['created_at'] < $stats['oldest_archive']) {
                $stats['oldest_archive'] = $file['created_at'];
            }

            if (!$stats['newest_archive'] || $file['created_at'] > $stats['newest_archive']) {
                $stats['newest_archive'] = $file['created_at'];
            }

            $table = $file['table'];
            if (!isset($stats['by_table'][$table])) {
                $stats['by_table'][$table] = [
                    'file_count' => 0,
                    'total_size' => 0
                ];
            }

            $stats['by_table'][$table]['file_count']++;
            $stats['by_table'][$table]['total_size'] += $file['size'];
        }

        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);

        return $stats;
    }

    /**
     * Clean up old archive files
     */
    public function cleanupOldArchives(int $retentionDays = 90): array
    {
        $cutoffTime = time() - ($retentionDays * 86400);
        $files = $this->listArchiveFiles();

        $results = [
            'files_deleted' => 0,
            'space_freed' => 0,
            'errors' => []
        ];

        foreach ($files as $file) {
            if (strtotime($file['created_at']) < $cutoffTime) {
                try {
                    $results['space_freed'] += $file['size'];
                    if (unlink($file['full_path'])) {
                        $results['files_deleted']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed to delete {$file['filename']}: " . $e->getMessage();
                }
            }
        }

        return $results;
    }

    /**
     * Optimize database tables after archival
     */
    private function optimizeTables(): void
    {
        $tables = ['scan_results', 'test_executions', 'scheduler_log', 'notification_log', 'scan_metrics'];

        foreach ($tables as $table) {
            try {
                $this->db->query("OPTIMIZE TABLE {$table}");
            } catch (\Exception $e) {
                // Log but don't fail the entire operation
                error_log("Failed to optimize table {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * Encrypt data
     */
    private function encryptData(string $data): string
    {
        $key = $this->config['encryption_key'];
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    private function decryptData(string $encryptedData): string
    {
        $key = $this->config['encryption_key'];
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Extract table name from archive filename
     */
    private function extractTableFromFilename(string $filename): string
    {
        $parts = explode('_', $filename);
        return $parts[0] ?? 'unknown';
    }

    /**
     * Merge results from different archive operations
     */
    private function mergeResults(array $base, array $new): array
    {
        $base['tables_processed']++;
        $base['records_archived'] += $new['records_archived'] ?? 0;
        $base['records_deleted'] += $new['records_deleted'] ?? 0;
        $base['archive_files_created'] += count($new['archive_files'] ?? []);

        if (!empty($new['errors'])) {
            $base['errors'] = array_merge($base['errors'], $new['errors']);
        }

        return $base;
    }

    /**
     * Log archive activity
     */
    private function logArchiveActivity(string $action, array $context = []): void
    {
        $this->db->insert('scheduler_log', [
            'level' => 'info',
            'message' => $action,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}