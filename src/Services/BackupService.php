<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;

class BackupService
{
    private Database $db;
    private array $config;
    private string $backupBasePath;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'backup_path' => sys_get_temp_dir() . '/security_scanner_backups',
            'compression_enabled' => true,
            'encryption_enabled' => false,
            'encryption_key' => '',
            'retention_days' => 30,
            'max_backup_size' => 500 * 1024 * 1024, // 500MB
            'backup_timeout' => 3600, // 1 hour
            'verify_backups' => true,
            'include_data' => true,
            'include_structure' => true,
            'exclude_tables' => ['scheduler_log', 'notification_log'], // Tables to exclude from backups
            'mysql_dump_path' => 'mysqldump',
            'mysql_restore_path' => 'mysql'
        ], $config);

        $this->backupBasePath = $this->config['backup_path'];

        // Ensure backup directory exists
        if (!is_dir($this->backupBasePath)) {
            mkdir($this->backupBasePath, 0755, true);
        }
    }

    /**
     * Create database backup
     */
    public function createBackup(string $backupType = 'full', array $options = []): array
    {
        $startTime = microtime(true);
        $backupId = uniqid('backup_', true);

        $result = [
            'success' => false,
            'backup_id' => $backupId,
            'backup_type' => $backupType,
            'file_path' => '',
            'file_size' => 0,
            'compression_ratio' => 0,
            'execution_time' => 0,
            'verification_status' => 'not_verified',
            'error' => null
        ];

        try {
            // Log backup start
            $this->logBackupActivity('backup_started', [
                'backup_id' => $backupId,
                'backup_type' => $backupType,
                'options' => $options
            ]);

            // Create backup based on type
            switch ($backupType) {
                case 'full':
                    $backupResult = $this->createFullBackup($backupId, $options);
                    break;

                case 'incremental':
                    $backupResult = $this->createIncrementalBackup($backupId, $options);
                    break;

                case 'structure_only':
                    $backupResult = $this->createStructureBackup($backupId, $options);
                    break;

                case 'data_only':
                    $backupResult = $this->createDataBackup($backupId, $options);
                    break;

                default:
                    throw new \Exception("Unknown backup type: {$backupType}");
            }

            $result = array_merge($result, $backupResult);

            // Verify backup if enabled
            if ($this->config['verify_backups'] && $result['success']) {
                $verificationResult = $this->verifyBackup($result['file_path']);
                $result['verification_status'] = $verificationResult['status'];

                if (!$verificationResult['success']) {
                    $result['error'] = 'Backup verification failed: ' . $verificationResult['error'];
                }
            }

            $result['execution_time'] = round(microtime(true) - $startTime, 3);

            // Record backup in database
            $this->recordBackup($result);

            if ($result['success']) {
                $this->logBackupActivity('backup_completed', $result);
            } else {
                $this->logBackupActivity('backup_failed', $result);
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            $result['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logBackupActivity('backup_error', [
                'backup_id' => $backupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }

    /**
     * Create full database backup
     */
    private function createFullBackup(string $backupId, array $options): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "full_backup_{$backupId}_{$timestamp}.sql";

        if ($this->config['compression_enabled']) {
            $filename .= '.gz';
        }

        $filePath = $this->backupBasePath . '/' . $filename;

        // Build mysqldump command
        $dbConfig = $this->db->getConfig();
        $command = $this->buildMysqlDumpCommand($dbConfig, $options);

        if ($this->config['compression_enabled']) {
            $command .= ' | gzip';
        }

        $command .= " > " . escapeshellarg($filePath);

        // Execute backup
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Backup command failed with return code {$returnCode}: " . implode("\n", $output));
        }

        // Check if file was created and has content
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            throw new \Exception("Backup file was not created or is empty");
        }

        $fileSize = filesize($filePath);

        // Calculate compression ratio if applicable
        $compressionRatio = 0;
        if ($this->config['compression_enabled']) {
            // Estimate original size (this is approximate)
            $compressionRatio = $this->estimateCompressionRatio($filePath);
        }

        return [
            'success' => true,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'compression_ratio' => $compressionRatio
        ];
    }

    /**
     * Create incremental backup (changes since last backup)
     */
    private function createIncrementalBackup(string $backupId, array $options): array
    {
        // Get last backup timestamp
        $lastBackup = $this->getLastBackupInfo();
        $sinceTimestamp = $lastBackup ? $lastBackup['created_at'] : date('Y-m-d H:i:s', strtotime('-1 day'));

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "incremental_backup_{$backupId}_{$timestamp}.sql";

        if ($this->config['compression_enabled']) {
            $filename .= '.gz';
        }

        $filePath = $this->backupBasePath . '/' . $filename;

        // Get tables with changes since last backup
        $changedTables = $this->getTablesChangedSince($sinceTimestamp);

        if (empty($changedTables)) {
            return [
                'success' => true,
                'file_path' => '',
                'file_size' => 0,
                'compression_ratio' => 0,
                'message' => 'No changes since last backup'
            ];
        }

        // Create backup of changed tables only
        $dbConfig = $this->db->getConfig();
        $command = $this->buildMysqlDumpCommand($dbConfig, array_merge($options, [
            'tables' => $changedTables,
            'where_clause' => "created_at >= '{$sinceTimestamp}' OR updated_at >= '{$sinceTimestamp}'"
        ]));

        if ($this->config['compression_enabled']) {
            $command .= ' | gzip';
        }

        $command .= " > " . escapeshellarg($filePath);

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Incremental backup failed with return code {$returnCode}: " . implode("\n", $output));
        }

        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
        $compressionRatio = $this->config['compression_enabled'] ? $this->estimateCompressionRatio($filePath) : 0;

        return [
            'success' => true,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'compression_ratio' => $compressionRatio,
            'tables_backed_up' => $changedTables,
            'since_timestamp' => $sinceTimestamp
        ];
    }

    /**
     * Create structure-only backup
     */
    private function createStructureBackup(string $backupId, array $options): array
    {
        $options['no_data'] = true;
        return $this->createFullBackup($backupId, $options);
    }

    /**
     * Create data-only backup
     */
    private function createDataBackup(string $backupId, array $options): array
    {
        $options['no_create_info'] = true;
        return $this->createFullBackup($backupId, $options);
    }

    /**
     * Restore database from backup
     */
    public function restoreBackup(string $backupFile, array $options = []): array
    {
        $startTime = microtime(true);

        $result = [
            'success' => false,
            'backup_file' => $backupFile,
            'execution_time' => 0,
            'restored_tables' => [],
            'error' => null
        ];

        try {
            if (!file_exists($backupFile)) {
                throw new \Exception("Backup file not found: {$backupFile}");
            }

            $this->logBackupActivity('restore_started', [
                'backup_file' => basename($backupFile),
                'options' => $options
            ]);

            // Verify backup before restore
            if ($this->config['verify_backups']) {
                $verificationResult = $this->verifyBackup($backupFile);
                if (!$verificationResult['success']) {
                    throw new \Exception("Backup verification failed: " . $verificationResult['error']);
                }
            }

            // Create database backup before restore (safety measure)
            if (!isset($options['skip_safety_backup']) || !$options['skip_safety_backup']) {
                $safetyBackup = $this->createBackup('full', ['safety_backup' => true]);
                if (!$safetyBackup['success']) {
                    throw new \Exception("Failed to create safety backup before restore");
                }
                $result['safety_backup'] = $safetyBackup['file_path'];
            }

            // Build restore command
            $dbConfig = $this->db->getConfig();
            $command = $this->buildMysqlRestoreCommand($dbConfig, $backupFile, $options);

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception("Restore command failed with return code {$returnCode}: " . implode("\n", $output));
            }

            // Verify restore if requested
            if (isset($options['verify_restore']) && $options['verify_restore']) {
                $this->verifyRestore($options);
            }

            $result['success'] = true;
            $result['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logBackupActivity('restore_completed', $result);

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            $result['execution_time'] = round(microtime(true) - $startTime, 3);

            $this->logBackupActivity('restore_failed', [
                'backup_file' => basename($backupFile),
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        $backups = [];

        if (!is_dir($this->backupBasePath)) {
            return $backups;
        }

        $pattern = $this->backupBasePath . '/*.sql*';
        foreach (glob($pattern) as $file) {
            $backups[] = [
                'filename' => basename($file),
                'full_path' => $file,
                'size' => filesize($file),
                'size_mb' => round(filesize($file) / 1024 / 1024, 2),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'type' => $this->extractBackupTypeFromFilename(basename($file)),
                'compressed' => str_ends_with($file, '.gz')
            ];
        }

        // Sort by creation time, newest first
        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return $backups;
    }

    /**
     * Get backup statistics
     */
    public function getBackupStatistics(): array
    {
        $backups = $this->listBackups();

        $stats = [
            'total_backups' => count($backups),
            'total_size' => 0,
            'total_size_mb' => 0,
            'oldest_backup' => null,
            'newest_backup' => null,
            'by_type' => [],
            'avg_size' => 0
        ];

        foreach ($backups as $backup) {
            $stats['total_size'] += $backup['size'];

            if (!$stats['oldest_backup'] || $backup['created_at'] < $stats['oldest_backup']) {
                $stats['oldest_backup'] = $backup['created_at'];
            }

            if (!$stats['newest_backup'] || $backup['created_at'] > $stats['newest_backup']) {
                $stats['newest_backup'] = $backup['created_at'];
            }

            $type = $backup['type'];
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = [
                    'count' => 0,
                    'total_size' => 0
                ];
            }

            $stats['by_type'][$type]['count']++;
            $stats['by_type'][$type]['total_size'] += $backup['size'];
        }

        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);
        $stats['avg_size'] = $stats['total_backups'] > 0 ? round($stats['total_size'] / $stats['total_backups']) : 0;

        return $stats;
    }

    /**
     * Cleanup old backups
     */
    public function cleanupOldBackups(): array
    {
        $cutoffTime = time() - ($this->config['retention_days'] * 86400);
        $backups = $this->listBackups();

        $results = [
            'files_deleted' => 0,
            'space_freed' => 0,
            'errors' => []
        ];

        foreach ($backups as $backup) {
            if (strtotime($backup['created_at']) < $cutoffTime) {
                try {
                    $results['space_freed'] += $backup['size'];
                    if (unlink($backup['full_path'])) {
                        $results['files_deleted']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed to delete {$backup['filename']}: " . $e->getMessage();
                }
            }
        }

        $this->logBackupActivity('cleanup_completed', $results);

        return $results;
    }

    /**
     * Verify backup file integrity
     */
    private function verifyBackup(string $backupFile): array
    {
        $result = [
            'success' => false,
            'status' => 'invalid',
            'error' => null,
            'checks' => []
        ];

        try {
            // Check file exists and is readable
            if (!file_exists($backupFile) || !is_readable($backupFile)) {
                throw new \Exception("Backup file is not accessible");
            }

            $result['checks']['file_accessible'] = true;

            // Check file size
            $fileSize = filesize($backupFile);
            if ($fileSize === 0) {
                throw new \Exception("Backup file is empty");
            }

            $result['checks']['file_not_empty'] = true;

            // Check if compressed file is valid
            if (str_ends_with($backupFile, '.gz')) {
                $testResult = exec("gzip -t " . escapeshellarg($backupFile) . " 2>&1", $output, $returnCode);
                if ($returnCode !== 0) {
                    throw new \Exception("Compressed backup file is corrupted");
                }
                $result['checks']['compression_valid'] = true;
            }

            // Basic SQL syntax check (for uncompressed files)
            if (!str_ends_with($backupFile, '.gz')) {
                $firstLine = file($backupFile, FILE_IGNORE_NEW_LINES)[0] ?? '';
                if (!str_contains($firstLine, 'MySQL dump') && !str_contains($firstLine, 'CREATE') && !str_contains($firstLine, 'INSERT')) {
                    throw new \Exception("Backup file does not appear to contain valid SQL");
                }
                $result['checks']['sql_format_valid'] = true;
            }

            $result['success'] = true;
            $result['status'] = 'valid';

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['status'] = 'invalid';
        }

        return $result;
    }

    /**
     * Build mysqldump command
     */
    private function buildMysqlDumpCommand(array $dbConfig, array $options): string
    {
        $command = $this->config['mysql_dump_path'];

        // Connection parameters
        $command .= " --host=" . escapeshellarg($dbConfig['host']);
        $command .= " --port=" . escapeshellarg($dbConfig['port']);
        $command .= " --user=" . escapeshellarg($dbConfig['username']);
        $command .= " --password=" . escapeshellarg($dbConfig['password']);

        // Dump options
        $command .= " --single-transaction";
        $command .= " --routines";
        $command .= " --triggers";
        $command .= " --opt";

        if (isset($options['no_data']) && $options['no_data']) {
            $command .= " --no-data";
        }

        if (isset($options['no_create_info']) && $options['no_create_info']) {
            $command .= " --no-create-info";
        }

        if (isset($options['where_clause'])) {
            $command .= " --where=" . escapeshellarg($options['where_clause']);
        }

        // Exclude tables
        foreach ($this->config['exclude_tables'] as $table) {
            $command .= " --ignore-table=" . escapeshellarg($dbConfig['database'] . '.' . $table);
        }

        // Add database name
        $command .= " " . escapeshellarg($dbConfig['database']);

        // Add specific tables if specified
        if (isset($options['tables']) && is_array($options['tables'])) {
            foreach ($options['tables'] as $table) {
                $command .= " " . escapeshellarg($table);
            }
        }

        return $command;
    }

    /**
     * Build mysql restore command
     */
    private function buildMysqlRestoreCommand(array $dbConfig, string $backupFile, array $options): string
    {
        $command = "";

        // Handle compressed files
        if (str_ends_with($backupFile, '.gz')) {
            $command .= "gunzip -c " . escapeshellarg($backupFile) . " | ";
        } else {
            $command .= "cat " . escapeshellarg($backupFile) . " | ";
        }

        // MySQL restore command
        $command .= $this->config['mysql_restore_path'];
        $command .= " --host=" . escapeshellarg($dbConfig['host']);
        $command .= " --port=" . escapeshellarg($dbConfig['port']);
        $command .= " --user=" . escapeshellarg($dbConfig['username']);
        $command .= " --password=" . escapeshellarg($dbConfig['password']);
        $command .= " " . escapeshellarg($dbConfig['database']);

        return $command;
    }

    /**
     * Get tables that have changed since timestamp
     */
    private function getTablesChangedSince(string $timestamp): array
    {
        $tables = [];

        // This is a simplified implementation
        // In practice, you'd need to check table modification times or use binary logs
        $monitoredTables = ['websites', 'scan_results', 'test_executions', 'website_test_config'];

        foreach ($monitoredTables as $table) {
            $hasChanges = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= ? OR updated_at >= ?",
                [$timestamp, $timestamp]
            );

            if ($hasChanges > 0) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Get information about the last backup
     */
    private function getLastBackupInfo(): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM backups ORDER BY created_at DESC LIMIT 1"
        );
    }

    /**
     * Record backup information in database
     */
    private function recordBackup(array $backupInfo): void
    {
        $this->db->insert('backups', [
            'backup_id' => $backupInfo['backup_id'],
            'backup_type' => $backupInfo['backup_type'],
            'file_path' => $backupInfo['file_path'],
            'file_size' => $backupInfo['file_size'],
            'compression_ratio' => $backupInfo['compression_ratio'],
            'execution_time' => $backupInfo['execution_time'],
            'verification_status' => $backupInfo['verification_status'],
            'success' => $backupInfo['success'] ? 1 : 0,
            'error_message' => $backupInfo['error'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Estimate compression ratio
     */
    private function estimateCompressionRatio(string $compressedFile): float
    {
        if (!str_ends_with($compressedFile, '.gz')) {
            return 0;
        }

        // Get compressed size
        $compressedSize = filesize($compressedFile);

        // Estimate original size (this is approximate)
        // For SQL dumps, typical compression ratio is 4:1 to 10:1
        $estimatedOriginalSize = $compressedSize * 7; // Assume 7:1 ratio

        return round((1 - $compressedSize / $estimatedOriginalSize) * 100, 1);
    }

    /**
     * Extract backup type from filename
     */
    private function extractBackupTypeFromFilename(string $filename): string
    {
        if (str_contains($filename, 'full_backup')) return 'full';
        if (str_contains($filename, 'incremental_backup')) return 'incremental';
        if (str_contains($filename, 'structure_backup')) return 'structure_only';
        if (str_contains($filename, 'data_backup')) return 'data_only';
        return 'unknown';
    }

    /**
     * Verify restore operation
     */
    private function verifyRestore(array $options): bool
    {
        // Basic verification - check if key tables exist and have data
        $keyTables = ['websites', 'available_tests'];

        foreach ($keyTables as $table) {
            $exists = $this->db->fetchColumn("SHOW TABLES LIKE ?", [$table]);
            if (!$exists) {
                throw new \Exception("Table {$table} not found after restore");
            }
        }

        return true;
    }

    /**
     * Log backup activity
     */
    private function logBackupActivity(string $action, array $context = []): void
    {
        $this->db->insert('backup_log', [
            'action' => $action,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Schedule automatic backup
     */
    public function scheduleBackup(string $backupType = 'full', string $frequency = 'daily'): array
    {
        $queueService = new QueueService();

        $delay = match($frequency) {
            'hourly' => 3600,
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000,
            default => 86400
        };

        $jobId = $queueService->enqueue('backup_database', [
            'backup_type' => $backupType
        ], 'normal', $delay);

        $this->logBackupActivity('backup_scheduled', [
            'job_id' => $jobId,
            'backup_type' => $backupType,
            'frequency' => $frequency,
            'delay' => $delay
        ]);

        return [
            'success' => true,
            'job_id' => $jobId,
            'scheduled_for' => date('Y-m-d H:i:s', time() + $delay)
        ];
    }

    /**
     * Create database backup (alias for createBackup)
     */
    public function createDatabaseBackup(array $options = []): array
    {
        return $this->createBackup('database', $options);
    }

    /**
     * Restore database backup (alias for restoreBackup)
     */
    public function restoreDatabaseBackup(string $backupFile, array $options = []): array
    {
        return $this->restoreBackup($backupFile, $options);
    }

    /**
     * Delete a backup file
     */
    public function deleteBackup(string $backupFile): bool
    {
        $backupPath = $this->config['backup_path'] . '/' . basename($backupFile);

        if (!file_exists($backupPath)) {
            return false;
        }

        if (!is_file($backupPath)) {
            return false;
        }

        $deleted = unlink($backupPath);

        if ($deleted) {
            $this->logBackupActivity('backup_deleted', [
                'backup_file' => $backupFile,
                'path' => $backupPath
            ]);
        }

        return $deleted;
    }

    /**
     * Schedule automatic backup
     */
    public function scheduleAutomaticBackup($scheduleOrType = 'full', string $frequency = 'daily', array $options = []): array
    {
        // Handle both old signature (string, string, array) and new signature (array)
        if (is_array($scheduleOrType)) {
            $schedule = $scheduleOrType;
            $backupType = $schedule['backup_type'] ?? 'full';
            $frequency = $schedule['frequency'] ?? 'daily';

            $result = $this->scheduleBackup($backupType, $frequency);
            $result['schedule_id'] = $result['job_id'] ?? null;
            return $result;
        }

        return $this->scheduleBackup($scheduleOrType, $frequency);
    }

    /**
     * Import backup configuration
     */
    public function importBackupConfiguration(array $config): array
    {
        $imported = 0;
        $failed = 0;
        $errors = [];

        foreach ($config as $key => $value) {
            if (isset($this->config[$key])) {
                $this->config[$key] = $value;
                $imported++;
            } else {
                $failed++;
                $errors[] = "Unknown configuration key: {$key}";
            }
        }

        $this->logBackupActivity('configuration_imported', [
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors
        ]);

        return [
            'success' => $failed === 0,
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Get information about a backup file
     */
    public function getBackupInfo(string $backupFile): array
    {
        $backupPath = $this->config['backup_path'] . '/' . basename($backupFile);

        if (!file_exists($backupPath)) {
            return [
                'error' => 'Backup file not found',
                'filename' => $backupFile
            ];
        }

        $stat = stat($backupPath);
        $checksum = md5_file($backupPath);

        return [
            'filename' => basename($backupFile),
            'path' => $backupPath,
            'size' => $stat['size'],
            'created_at' => date('Y-m-d H:i:s', $stat['ctime']),
            'modified_at' => date('Y-m-d H:i:s', $stat['mtime']),
            'checksum' => $checksum,
            'readable' => is_readable($backupPath)
        ];
    }

    /**
     * Verify backup file integrity
     */
    public function verifyBackupIntegrity(string $backupFile): array
    {
        $info = $this->getBackupInfo($backupFile);

        if (isset($info['error'])) {
            return [
                'valid' => false,
                'error' => $info['error']
            ];
        }

        $valid = $info['readable'] && $info['size'] > 0;

        return [
            'valid' => $valid,
            'checksum' => $info['checksum'],
            'size' => $info['size'],
            'created_at' => $info['created_at']
        ];
    }

    /**
     * Compress a backup file
     */
    public function compressBackup(string $backupFile): array
    {
        $backupPath = $this->config['backup_path'] . '/' . basename($backupFile);

        if (!file_exists($backupPath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found'
            ];
        }

        $compressedPath = $backupPath . '.gz';

        $fp = gzopen($compressedPath, 'wb9');
        if (!$fp) {
            return [
                'success' => false,
                'error' => 'Failed to create compressed file'
            ];
        }

        $file = fopen($backupPath, 'rb');
        while (!feof($file)) {
            gzwrite($fp, fread($file, 1024 * 512));
        }
        fclose($file);
        gzclose($fp);

        $originalSize = filesize($backupPath);
        $compressedSize = filesize($compressedPath);

        $this->logBackupActivity('backup_compressed', [
            'original_file' => $backupFile,
            'compressed_file' => basename($compressedPath),
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'compression_ratio' => round((1 - ($compressedSize / $originalSize)) * 100, 2)
        ]);

        return [
            'success' => true,
            'compressed_file' => $compressedPath,
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'compression_ratio' => round((1 - ($compressedSize / $originalSize)) * 100, 2)
        ];
    }

    /**
     * Decompress a backup file
     */
    public function decompressBackup(string $compressedFile): array
    {
        $compressedPath = $this->config['backup_path'] . '/' . basename($compressedFile);

        if (!file_exists($compressedPath)) {
            return [
                'success' => false,
                'error' => 'Compressed file not found'
            ];
        }

        $decompressedPath = preg_replace('/\.gz$/', '', $compressedPath);

        $gz = gzopen($compressedPath, 'rb');
        if (!$gz) {
            return [
                'success' => false,
                'error' => 'Failed to open compressed file'
            ];
        }

        $fp = fopen($decompressedPath, 'wb');
        while (!gzeof($gz)) {
            fwrite($fp, gzread($gz, 1024 * 512));
        }
        fclose($fp);
        gzclose($gz);

        $this->logBackupActivity('backup_decompressed', [
            'compressed_file' => $compressedFile,
            'decompressed_file' => basename($decompressedPath)
        ]);

        return [
            'success' => true,
            'decompressed_file' => $decompressedPath,
            'size' => filesize($decompressedPath)
        ];
    }

    /**
     * Get backup health status
     */
    public function getBackupHealthStatus(): array
    {
        $backups = $this->listBackups();
        $totalBackups = count($backups);

        $healthScore = 100;
        $issues = [];

        if ($totalBackups === 0) {
            $healthScore = 0;
            $issues[] = 'No backups found';
        }

        $lastBackupTime = $this->getLastBackupTime();
        if ($lastBackupTime) {
            $hoursSinceLastBackup = (time() - strtotime($lastBackupTime)) / 3600;
            if ($hoursSinceLastBackup > 48) {
                $healthScore -= 30;
                $issues[] = 'Last backup is older than 48 hours';
            }
        } else {
            $healthScore -= 50;
            $issues[] = 'No backup history found';
        }

        return [
            'health_score' => max(0, $healthScore),
            'total_backups' => $totalBackups,
            'last_backup_time' => $lastBackupTime,
            'issues' => $issues,
            'status' => $healthScore >= 70 ? 'healthy' : ($healthScore >= 40 ? 'warning' : 'critical')
        ];
    }

    /**
     * Get backup schedule information
     */
    public function getBackupSchedule(): array
    {
        $schedule = $this->db->fetchAll(
            "SELECT * FROM job_queue WHERE job_type = 'backup' ORDER BY scheduled_at ASC"
        );

        return [
            'scheduled_backups' => $schedule,
            'next_backup' => $schedule[0] ?? null,
            'total_scheduled' => count($schedule)
        ];
    }

    /**
     * Get a preview of what would be restored
     */
    public function getRestorePreview(string $backupFile): array
    {
        $backupPath = $this->config['backup_path'] . '/' . basename($backupFile);

        if (!file_exists($backupPath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found'
            ];
        }

        $info = $this->getBackupInfo($backupFile);

        return [
            'success' => true,
            'backup_file' => $backupFile,
            'size' => $info['size'],
            'created_at' => $info['created_at'],
            'warning' => 'This will replace all current data in the database',
            'estimated_duration' => ceil($info['size'] / (1024 * 1024)) . ' minutes'
        ];
    }

    /**
     * Validate a backup file
     */
    public function validateBackupFile(string $backupFile): array
    {
        $backupPath = $this->config['backup_path'] . '/' . basename($backupFile);

        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        if (!file_exists($backupPath)) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Backup file does not exist';
            return $validation;
        }

        if (!is_readable($backupPath)) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Backup file is not readable';
        }

        $size = filesize($backupPath);
        if ($size === 0) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Backup file is empty';
        }

        if ($size < 1024) {
            $validation['warnings'][] = 'Backup file is very small, may be incomplete';
        }

        return $validation;
    }

    /**
     * Export backup configuration
     */
    public function exportBackupConfiguration(): array
    {
        return [
            'backup_path' => $this->config['backup_path'],
            'retention_days' => $this->config['retention_days'],
            'compression_enabled' => $this->config['compression_enabled'] ?? false,
            'encryption_enabled' => $this->config['encryption_enabled'] ?? false,
            'schedule' => $this->getBackupSchedule()
        ];
    }

    /**
     * Get last backup time
     */
    private function getLastBackupTime(): ?string
    {
        $result = $this->db->fetchRow(
            "SELECT MAX(created_at) as last_backup FROM database_backups"
        );

        return $result['last_backup'] ?? null;
    }
}