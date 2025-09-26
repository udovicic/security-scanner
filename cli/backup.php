#!/usr/bin/env php
<?php

/**
 * Database Backup CLI Tool
 * Usage:
 *   php cli/backup.php create [--no-encrypt] [--no-compress]  - Create full backup
 *   php cli/backup.php incremental [--no-encrypt] [--no-compress] - Create incremental backup
 *   php cli/backup.php restore <filename> [--confirm]         - Restore from backup
 *   php cli/backup.php list                                   - List available backups
 *   php cli/backup.php cleanup [--days=30]                    - Cleanup old backups
 */

require_once __DIR__ . '/../bootstrap.php';

use SecurityScanner\Services\DatabaseBackupService;
use SecurityScanner\Core\Logger;

function showUsage(): void
{
    echo "Database Backup Tool\n\n";
    echo "Usage:\n";
    echo "  php cli/backup.php create [--no-encrypt] [--no-compress]    - Create full backup\n";
    echo "  php cli/backup.php incremental [--no-encrypt] [--no-compress] - Create incremental backup\n";
    echo "  php cli/backup.php restore <filename> [--confirm]           - Restore from backup\n";
    echo "  php cli/backup.php list                                     - List available backups\n";
    echo "  php cli/backup.php cleanup [--days=30]                      - Cleanup old backups\n";
    echo "  php cli/backup.php help                                     - Show this help\n";
    echo "\nOptions:\n";
    echo "  --no-encrypt    - Skip encryption of backup file\n";
    echo "  --no-compress   - Skip compression of backup file\n";
    echo "  --confirm       - Confirm dangerous restore operation\n";
    echo "  --days=N        - Retention days for cleanup (default: 30)\n";
    echo "\n";
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function formatDuration(float $ms): string
{
    if ($ms < 1000) {
        return round($ms, 2) . 'ms';
    }
    return round($ms / 1000, 2) . 's';
}

function parseArgs(array $argv): array
{
    $args = [];
    $options = [];

    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $options[$key] = $value;
            } else {
                $options[substr($arg, 2)] = true;
            }
        } else {
            $args[] = $arg;
        }
    }

    return [$args, $options];
}

try {
    [$args, $options] = parseArgs($argv);
    $command = $args[1] ?? 'help';

    $logger = Logger::general();
    $backupService = new DatabaseBackupService();

    echo "💾 Security Scanner Database Backup Tool\n";
    echo "========================================\n\n";

    switch ($command) {
        case 'create':
            echo "Creating full database backup...\n\n";

            $encrypt = !isset($options['no-encrypt']);
            $compress = !isset($options['no-compress']);

            echo "Options:\n";
            echo "  Encryption: " . ($encrypt ? "enabled" : "disabled") . "\n";
            echo "  Compression: " . ($compress ? "enabled" : "disabled") . "\n\n";

            $startTime = microtime(true);
            $result = $backupService->createFullBackup($encrypt, $compress);
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                $info = $result['backup_info'];
                echo "✅ Backup created successfully!\n\n";
                echo "Backup Details:\n";
                echo "---------------\n";
                echo "Name: {$info['backup_name']}\n";
                echo "File: {$info['filename']}\n";
                echo "Size: " . formatBytes($info['file_size']) . "\n";
                echo "Tables: {$info['tables_backed_up']}\n";
                echo "Encrypted: " . ($info['is_encrypted'] ? 'yes' : 'no') . "\n";
                echo "Compressed: " . ($info['is_compressed'] ? 'yes' : 'no') . "\n";
                echo "Time: " . formatDuration($info['execution_time_ms']) . "\n";
            } else {
                echo "❌ Backup failed: {$result['error']}\n";
                exit(1);
            }

            break;

        case 'incremental':
            echo "Creating incremental database backup...\n\n";

            $encrypt = !isset($options['no-encrypt']);
            $compress = !isset($options['no-compress']);

            echo "Options:\n";
            echo "  Encryption: " . ($encrypt ? "enabled" : "disabled") . "\n";
            echo "  Compression: " . ($compress ? "enabled" : "disabled") . "\n\n";

            $result = $backupService->createIncrementalBackup($encrypt, $compress);

            if ($result['success']) {
                $info = $result['backup_info'];

                if (isset($info['no_changes'])) {
                    echo "ℹ️  No changes found since last backup.\n";
                    echo "Last backup: {$info['last_backup_time']}\n";
                } else {
                    echo "✅ Incremental backup created successfully!\n\n";
                    echo "Backup Details:\n";
                    echo "---------------\n";
                    echo "Name: {$info['backup_name']}\n";
                    echo "File: {$info['filename']}\n";
                    echo "Size: " . formatBytes($info['file_size']) . "\n";
                    echo "Changes: {$info['changes_count']}\n";
                    echo "Since: {$info['since']}\n";
                    echo "Time: " . formatDuration($info['execution_time_ms']) . "\n";
                }
            } else {
                echo "❌ Incremental backup failed: {$result['error']}\n";
                exit(1);
            }

            break;

        case 'restore':
            if (!isset($args[2])) {
                echo "❌ Error: Backup filename is required.\n";
                echo "Usage: php cli/backup.php restore <filename> [--confirm]\n";
                exit(1);
            }

            $filename = $args[2];
            $confirm = isset($options['confirm']);

            if (!$confirm) {
                echo "⚠️  WARNING: Database restore is a DESTRUCTIVE operation!\n";
                echo "This will OVERWRITE your current database with the backup data.\n";
                echo "Use --confirm flag to proceed.\n";
                exit(1);
            }

            echo "🔄 Restoring database from backup: {$filename}\n";
            echo "⚠️  This is a DESTRUCTIVE operation!\n\n";

            $result = $backupService->restoreFromBackup($filename, true);

            if ($result['success']) {
                echo "✅ Database restored successfully!\n";
                echo "Time: " . formatDuration($result['execution_time_ms']) . "\n";
            } else {
                echo "❌ Restore failed: {$result['error']}\n";
                exit(1);
            }

            break;

        case 'list':
            echo "Available Backups:\n";
            echo "------------------\n";

            $backups = $backupService->listBackups();

            if (empty($backups)) {
                echo "No backups found.\n";
            } else {
                foreach ($backups as $backup) {
                    $typeIcon = $backup['backup_type'] === 'incremental' ? '📊' : '💾';
                    $encIcon = $backup['is_encrypted'] ? '🔒' : '🔓';
                    $compIcon = $backup['is_compressed'] ? '🗜️' : '';

                    echo "{$typeIcon} {$encIcon} {$compIcon} {$backup['filename']}\n";
                    echo "    Size: " . formatBytes($backup['file_size']) . "\n";
                    echo "    Created: {$backup['created_at']}\n";
                    echo "    Type: {$backup['backup_type']}\n\n";
                }

                echo "Total backups: " . count($backups) . "\n";
            }

            break;

        case 'cleanup':
            $retentionDays = (int)($options['days'] ?? 30);

            echo "Cleaning up backups older than {$retentionDays} days...\n\n";

            $deletedCount = $backupService->cleanupOldBackups($retentionDays);

            if ($deletedCount > 0) {
                echo "✅ Cleanup completed: {$deletedCount} old backup(s) deleted\n";
            } else {
                echo "ℹ️  No old backups found to delete\n";
            }

            break;

        case 'help':
        default:
            showUsage();
            break;
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";

    if (isset($logger)) {
        $logger->error('Backup CLI error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'command' => $command ?? 'unknown'
        ]);
    }

    exit(1);
}

echo "\n";