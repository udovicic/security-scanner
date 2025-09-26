#!/usr/bin/env php
<?php

/**
 * Database Migration CLI Tool
 * Usage:
 *   php cli/migrate.php migrate            - Run pending migrations
 *   php cli/migrate.php rollback [steps]   - Rollback migrations (default: 1 step)
 *   php cli/migrate.php status             - Show migration status
 *   php cli/migrate.php reset              - Reset all migrations (DANGER!)
 *   php cli/migrate.php create <name>      - Create new migration
 */

require_once __DIR__ . '/../bootstrap.php';

use SecurityScanner\Core\MigrationManager;
use SecurityScanner\Core\Logger;

function showUsage(): void
{
    echo "Database Migration Tool\n\n";
    echo "Usage:\n";
    echo "  php cli/migrate.php migrate              - Run pending migrations\n";
    echo "  php cli/migrate.php rollback [steps]     - Rollback migrations (default: 1)\n";
    echo "  php cli/migrate.php status               - Show migration status\n";
    echo "  php cli/migrate.php reset                - Reset all migrations (DANGER!)\n";
    echo "  php cli/migrate.php create <name>        - Create new migration\n";
    echo "  php cli/migrate.php help                 - Show this help\n";
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

try {
    $command = $argv[1] ?? 'help';
    $logger = Logger::scheduler();

    $manager = new MigrationManager();

    echo "🔧 Security Scanner Migration Tool\n";
    echo "===================================\n\n";

    switch ($command) {
        case 'migrate':
            echo "Running pending migrations...\n\n";

            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            $manager->initialize();
            $results = $manager->migrate();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsed = $endMemory - $startMemory;

            if (empty($results)) {
                echo "✅ No pending migrations found.\n";
            } else {
                echo "Migration Results:\n";
                echo "------------------\n";

                foreach ($results as $result) {
                    $status = $result['success'] ? '✅' : '❌';
                    $duration = formatDuration($result['execution_time']);

                    echo "{$status} {$result['migration']} ({$duration})\n";

                    if (!$result['success']) {
                        echo "   Error: {$result['error']}\n";
                    }
                }

                $successCount = count(array_filter($results, fn($r) => $r['success']));
                echo "\nSummary:\n";
                echo "--------\n";
                echo "Total migrations: " . count($results) . "\n";
                echo "Successful: {$successCount}\n";
                echo "Failed: " . (count($results) - $successCount) . "\n";
            }

            echo "\nExecution Statistics:\n";
            echo "Total time: " . formatDuration($executionTime) . "\n";
            echo "Memory used: " . formatBytes($memoryUsed) . "\n";
            echo "Peak memory: " . formatBytes(memory_get_peak_usage()) . "\n";

            break;

        case 'rollback':
            $steps = isset($argv[2]) ? (int)$argv[2] : 1;

            echo "Rolling back {$steps} migration(s)...\n\n";

            $results = $manager->rollback($steps);

            if (empty($results)) {
                echo "✅ No migrations to rollback.\n";
            } else {
                echo "Rollback Results:\n";
                echo "-----------------\n";

                foreach ($results as $result) {
                    $status = $result['success'] ? '✅' : '❌';
                    $duration = formatDuration($result['execution_time']);

                    echo "{$status} {$result['migration']} ({$duration})\n";

                    if (!$result['success']) {
                        echo "   Error: {$result['error']}\n";
                    }
                }
            }

            break;

        case 'status':
            echo "Migration Status:\n";
            echo "-----------------\n";

            $status = $manager->getStatus();

            if (empty($status)) {
                echo "No migration files found.\n";
            } else {
                $appliedCount = 0;

                foreach ($status as $migration) {
                    $statusIcon = $migration['applied'] ? '✅' : '⏳';
                    $appliedText = $migration['applied']
                        ? "Applied on {$migration['applied_at']}"
                        : "Pending";

                    echo "{$statusIcon} {$migration['version']} - {$migration['class']}\n";
                    echo "    Status: {$appliedText}\n\n";

                    if ($migration['applied']) {
                        $appliedCount++;
                    }
                }

                echo "Summary:\n";
                echo "--------\n";
                echo "Total migrations: " . count($status) . "\n";
                echo "Applied: {$appliedCount}\n";
                echo "Pending: " . (count($status) - $appliedCount) . "\n";
            }

            break;

        case 'reset':
            echo "⚠️  WARNING: This will rollback ALL migrations!\n";
            echo "This action cannot be undone and will destroy all data.\n\n";
            echo "Are you sure you want to continue? (type 'yes' to confirm): ";

            $handle = fopen("php://stdin", "r");
            $confirmation = trim(fgets($handle));
            fclose($handle);

            if ($confirmation === 'yes') {
                echo "\nResetting all migrations...\n\n";

                $results = $manager->reset();

                echo "Reset complete. All migrations have been rolled back.\n";

                if (!empty($results)) {
                    echo "\nRollback Results:\n";
                    echo "-----------------\n";

                    foreach ($results as $result) {
                        $status = $result['success'] ? '✅' : '❌';
                        echo "{$status} {$result['migration']}\n";
                    }
                }
            } else {
                echo "Reset cancelled.\n";
            }

            break;

        case 'create':
            if (!isset($argv[2])) {
                echo "❌ Error: Migration name is required.\n";
                echo "Usage: php cli/migrate.php create <migration_name>\n";
                exit(1);
            }

            $migrationName = $argv[2];
            echo "Creating new migration: {$migrationName}\n\n";

            $filePath = $manager->createMigration($migrationName);

            echo "✅ Migration created successfully!\n";
            echo "File: {$filePath}\n\n";
            echo "Edit the migration file to add your up() and down() methods.\n";

            break;

        case 'help':
        default:
            showUsage();
            break;
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";

    if (isset($logger)) {
        $logger->error('Migration CLI error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'command' => $command ?? 'unknown'
        ]);
    }

    exit(1);
}

echo "\n";