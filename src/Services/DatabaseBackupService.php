<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;
use SecurityScanner\Core\Config;

class DatabaseBackupService
{
    private Database $db;
    private Logger $logger;
    private Config $config;
    private string $backupDir;
    private string $encryptionKey;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();
        $this->config = Config::getInstance();

        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $this->backupDir = $this->config->get('backup.directory', $rootPath . '/storage/backups');
        $this->encryptionKey = $this->getEncryptionKey();

        $this->ensureBackupDirectory();
    }

    /**
     * Create a full database backup
     */
    public function createFullBackup(bool $encrypt = true, bool $compress = true): array
    {
        $startTime = microtime(true);
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "full_backup_{$timestamp}";

        $this->logger->info('Starting full database backup', [
            'backup_name' => $backupName,
            'encrypt' => $encrypt,
            'compress' => $compress
        ]);

        try {
            // Create backup filename
            $filename = $this->generateBackupFilename($backupName, $encrypt, $compress);
            $backupPath = $this->backupDir . '/' . $filename;

            // Get database connection info
            $dbConfig = $this->config->get('database');
            $host = $dbConfig['host'];
            $database = $dbConfig['database'];
            $username = $dbConfig['username'];
            $password = $dbConfig['password'];

            // Create mysqldump command
            $dumpCommand = $this->buildMysqldumpCommand($host, $database, $username, $password);

            // Execute backup
            if ($encrypt) {
                $backupData = $this->executeDumpCommand($dumpCommand);
                $encryptedData = $this->encryptData($backupData);
                $finalData = $compress ? gzcompress($encryptedData, 9) : $encryptedData;
            } else {
                $backupData = $this->executeDumpCommand($dumpCommand);
                $finalData = $compress ? gzcompress($backupData, 9) : $backupData;
            }

            // Write to file
            $bytesWritten = file_put_contents($backupPath, $finalData);
            if ($bytesWritten === false) {
                throw new \Exception("Failed to write backup file: {$backupPath}");
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Get backup statistics
            $stats = [
                'backup_name' => $backupName,
                'filename' => $filename,
                'file_path' => $backupPath,
                'file_size' => filesize($backupPath),
                'is_encrypted' => $encrypt,
                'is_compressed' => $compress,
                'execution_time_ms' => $executionTime,
                'tables_backed_up' => $this->getTableCount(),
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->recordBackup($stats);

            $this->logger->info('Full database backup completed successfully', $stats);

            return [
                'success' => true,
                'backup_info' => $stats
            ];

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('Full database backup failed', [
                'backup_name' => $backupName,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ];
        }
    }

    /**
     * Create an incremental backup (changes since last backup)
     */
    public function createIncrementalBackup(bool $encrypt = true, bool $compress = true): array
    {
        $startTime = microtime(true);
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "incremental_backup_{$timestamp}";

        $this->logger->info('Starting incremental database backup', [
            'backup_name' => $backupName
        ]);

        try {
            $lastBackupTime = $this->getLastBackupTime();
            if (!$lastBackupTime) {
                // No previous backup, create full backup instead
                $this->logger->info('No previous backup found, creating full backup instead');
                return $this->createFullBackup($encrypt, $compress);
            }

            // Get changed records since last backup
            $changedData = $this->getChangedDataSince($lastBackupTime);

            if (empty($changedData)) {
                $this->logger->info('No changes found since last backup');
                return [
                    'success' => true,
                    'backup_info' => [
                        'backup_name' => $backupName,
                        'no_changes' => true,
                        'last_backup_time' => $lastBackupTime
                    ]
                ];
            }

            // Create incremental backup
            $filename = $this->generateBackupFilename($backupName, $encrypt, $compress);
            $backupPath = $this->backupDir . '/' . $filename;

            $backupData = json_encode([
                'backup_type' => 'incremental',
                'since' => $lastBackupTime,
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => $changedData
            ], JSON_PRETTY_PRINT);

            // Process data (encrypt/compress)
            if ($encrypt) {
                $encryptedData = $this->encryptData($backupData);
                $finalData = $compress ? gzcompress($encryptedData, 9) : $encryptedData;
            } else {
                $finalData = $compress ? gzcompress($backupData, 9) : $backupData;
            }

            // Write to file
            $bytesWritten = file_put_contents($backupPath, $finalData);
            if ($bytesWritten === false) {
                throw new \Exception("Failed to write backup file: {$backupPath}");
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $stats = [
                'backup_name' => $backupName,
                'backup_type' => 'incremental',
                'filename' => $filename,
                'file_path' => $backupPath,
                'file_size' => filesize($backupPath),
                'is_encrypted' => $encrypt,
                'is_compressed' => $compress,
                'execution_time_ms' => $executionTime,
                'changes_count' => count($changedData),
                'since' => $lastBackupTime,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->recordBackup($stats);

            $this->logger->info('Incremental database backup completed successfully', $stats);

            return [
                'success' => true,
                'backup_info' => $stats
            ];

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('Incremental database backup failed', [
                'backup_name' => $backupName,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ];
        }
    }

    /**
     * Restore database from backup
     */
    public function restoreFromBackup(string $backupFilename, bool $confirmDangerous = false): array
    {
        if (!$confirmDangerous) {
            throw new \Exception('Database restore is a dangerous operation. Set confirmDangerous=true to proceed.');
        }

        $backupPath = $this->backupDir . '/' . $backupFilename;

        if (!file_exists($backupPath)) {
            throw new \Exception("Backup file not found: {$backupPath}");
        }

        $this->logger->warning('Starting database restore - DESTRUCTIVE OPERATION', [
            'backup_file' => $backupFilename
        ]);

        $startTime = microtime(true);

        try {
            // Read backup file
            $backupData = file_get_contents($backupPath);

            // Determine if encrypted/compressed based on filename
            $isEncrypted = strpos($backupFilename, '.enc') !== false;
            $isCompressed = strpos($backupFilename, '.gz') !== false;

            // Process backup data
            if ($isCompressed) {
                $backupData = gzuncompress($backupData);
                if ($backupData === false) {
                    throw new \Exception('Failed to decompress backup data');
                }
            }

            if ($isEncrypted) {
                $backupData = $this->decryptData($backupData);
            }

            // Check if it's incremental backup
            $decodedData = json_decode($backupData, true);
            if ($decodedData && isset($decodedData['backup_type']) && $decodedData['backup_type'] === 'incremental') {
                throw new \Exception('Cannot restore from incremental backup. Use full backup instead.');
            }

            // Execute restore
            $this->executeRestore($backupData);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->warning('Database restore completed successfully', [
                'backup_file' => $backupFilename,
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => true,
                'execution_time_ms' => $executionTime,
                'backup_file' => $backupFilename
            ];

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('Database restore failed', [
                'backup_file' => $backupFilename,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime
            ];
        }
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        $backups = [];
        $files = glob($this->backupDir . '/*.{sql,json}*', GLOB_BRACE);

        foreach ($files as $file) {
            $filename = basename($file);
            $stats = [
                'filename' => $filename,
                'file_path' => $file,
                'file_size' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'is_encrypted' => strpos($filename, '.enc') !== false,
                'is_compressed' => strpos($filename, '.gz') !== false,
                'backup_type' => strpos($filename, 'incremental') !== false ? 'incremental' : 'full'
            ];
            $backups[] = $stats;
        }

        // Sort by creation time (newest first)
        usort($backups, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

        return $backups;
    }

    /**
     * Delete old backups based on retention policy
     */
    public function cleanupOldBackups(int $retentionDays = 30): int
    {
        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        $deletedCount = 0;

        $backups = $this->listBackups();

        foreach ($backups as $backup) {
            $backupTime = strtotime($backup['created_at']);
            if ($backupTime < $cutoffTime) {
                if (unlink($backup['file_path'])) {
                    $deletedCount++;
                    $this->logger->info('Deleted old backup', [
                        'filename' => $backup['filename'],
                        'age_days' => floor((time() - $backupTime) / (24 * 60 * 60))
                    ]);
                }
            }
        }

        if ($deletedCount > 0) {
            $this->logger->info('Backup cleanup completed', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get encryption key from config or environment
     */
    private function getEncryptionKey(): string
    {
        $key = $this->config->get('backup.encryption_key') ?: getenv('BACKUP_ENCRYPTION_KEY');

        if (!$key) {
            // Generate a default key based on app key
            $appKey = $this->config->get('app.key', 'security-scanner-default-key');
            $key = hash('sha256', $appKey . 'backup-encryption');
        }

        return $key;
    }

    /**
     * Encrypt data using AES-256-CBC
     */
    private function encryptData(string $data): string
    {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, 0, $iv);

        if ($encrypted === false) {
            throw new \Exception('Encryption failed');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data using AES-256-CBC
     */
    private function decryptData(string $encryptedData): string
    {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);

        if ($decrypted === false) {
            throw new \Exception('Decryption failed');
        }

        return $decrypted;
    }

    /**
     * Build mysqldump command
     */
    private function buildMysqldumpCommand(string $host, string $database, string $username, string $password): string
    {
        return sprintf(
            'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers --add-drop-table %s',
            escapeshellarg($host),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database)
        );
    }

    /**
     * Execute dump command and return output
     */
    private function executeDumpCommand(string $command): string
    {
        $output = shell_exec($command . ' 2>&1');

        if ($output === null) {
            throw new \Exception('Failed to execute mysqldump command');
        }

        // Check for errors in output
        if (strpos($output, 'Error') !== false || strpos($output, 'mysqldump: ') === 0) {
            throw new \Exception("Mysqldump error: {$output}");
        }

        return $output;
    }

    /**
     * Execute database restore
     */
    private function executeRestore(string $sqlData): void
    {
        // Get database connection info
        $dbConfig = $this->config->get('database');
        $host = $dbConfig['host'];
        $database = $dbConfig['database'];
        $username = $dbConfig['username'];
        $password = $dbConfig['password'];

        // Create temporary SQL file
        $tempFile = tempnam(sys_get_temp_dir(), 'restore_');
        file_put_contents($tempFile, $sqlData);

        try {
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
                escapeshellarg($tempFile)
            );

            $output = shell_exec($command);

            if ($output && (strpos($output, 'Error') !== false || strpos($output, 'ERROR') !== false)) {
                throw new \Exception("MySQL restore error: {$output}");
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Generate backup filename
     */
    private function generateBackupFilename(string $backupName, bool $encrypt, bool $compress): string
    {
        $extension = '.sql';

        if ($encrypt) {
            $extension .= '.enc';
        }

        if ($compress) {
            $extension .= '.gz';
        }

        return $backupName . $extension;
    }

    /**
     * Ensure backup directory exists
     */
    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                throw new \Exception("Failed to create backup directory: {$this->backupDir}");
            }
        }

        if (!is_writable($this->backupDir)) {
            throw new \Exception("Backup directory is not writable: {$this->backupDir}");
        }
    }

    /**
     * Get table count
     */
    private function getTableCount(): int
    {
        $sql = "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE()";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result[0]['count'] ?? 0;
    }

    /**
     * Get last backup time
     */
    private function getLastBackupTime(): ?string
    {
        $sql = "
            SELECT value
            FROM system_settings
            WHERE `key` = 'last_backup_time'
        ";

        $stmt = $this->db->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result[0]['value'] ?? null;
    }

    /**
     * Get changed data since timestamp
     */
    private function getChangedDataSince(string $timestamp): array
    {
        $changes = [];
        $tables = ['websites', 'test_executions', 'test_results', 'website_test_config'];

        foreach ($tables as $table) {
            $sql = "SELECT * FROM {$table} WHERE updated_at > ? OR created_at > ?";
            $stmt = $this->db->query($sql, [$timestamp, $timestamp]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($results)) {
                $changes[$table] = $results;
            }
        }

        return $changes;
    }

    /**
     * Record backup information
     */
    private function recordBackup(array $stats): void
    {
        // Update last backup time
        $sql = "
            INSERT INTO system_settings (`key`, value, description, created_at)
            VALUES ('last_backup_time', ?, 'Timestamp of last backup', NOW())
            ON DUPLICATE KEY UPDATE
            value = VALUES(value),
            updated_at = NOW()
        ";

        $this->db->getConnection()->prepare($sql)->execute([date('Y-m-d H:i:s')]);

        // Could also store detailed backup info in a separate table if needed
    }
}