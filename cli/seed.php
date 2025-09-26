#!/usr/bin/env php
<?php

/**
 * Database Seeding CLI Tool
 * Usage:
 *   php cli/seed.php all                    - Run all seeders
 *   php cli/seed.php <seeder_name>          - Run specific seeder
 *   php cli/seed.php status                 - Show seeding status
 *   php cli/seed.php list                   - List available seeders
 */

require_once __DIR__ . '/../bootstrap.php';

use SecurityScanner\Core\DatabaseSeeder;
use SecurityScanner\Core\Logger;

function showUsage(): void
{
    echo "Database Seeding Tool\n\n";
    echo "Usage:\n";
    echo "  php cli/seed.php all                 - Run all seeders\n";
    echo "  php cli/seed.php <seeder_name>       - Run specific seeder\n";
    echo "  php cli/seed.php status              - Show seeding status\n";
    echo "  php cli/seed.php list                - List available seeders\n";
    echo "  php cli/seed.php help                - Show this help\n";
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
    $seeder = new DatabaseSeeder();

    echo "🌱 Security Scanner Database Seeder\n";
    echo "===================================\n\n";

    switch ($command) {
        case 'all':
            echo "Running all database seeders...\n\n";

            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            $results = $seeder->seedAll();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsed = $endMemory - $startMemory;

            echo "Seeding Results:\n";
            echo "----------------\n";

            foreach ($results as $seederName => $result) {
                $status = $result['success'] ? '✅' : '❌';
                $duration = formatDuration($result['execution_time_ms']);

                echo "{$status} {$seederName} ({$duration})\n";

                if (!$result['success']) {
                    echo "   Error: {$result['error']}\n";
                }
            }

            $successCount = count(array_filter($results, fn($r) => $r['success']));
            echo "\nSummary:\n";
            echo "--------\n";
            echo "Total seeders: " . count($results) . "\n";
            echo "Successful: {$successCount}\n";
            echo "Failed: " . (count($results) - $successCount) . "\n";
            echo "Total time: " . formatDuration($executionTime) . "\n";
            echo "Memory used: " . formatBytes($memoryUsed) . "\n";
            echo "Peak memory: " . formatBytes(memory_get_peak_usage()) . "\n";

            break;

        case 'status':
            echo "Seeding Status:\n";
            echo "---------------\n";

            $status = $seeder->getSeederStatus();

            if (empty($status)) {
                echo "No seeders registered.\n";
            } else {
                foreach ($status as $name => $info) {
                    $statusIcon = $info['has_run'] ? '✅' : '⏳';
                    $lastRun = $info['has_run'] ? "Last run: {$info['last_run']}" : "Never run";

                    echo "{$statusIcon} {$name}\n";
                    echo "    Class: {$info['seeder_class']}\n";
                    echo "    Status: {$lastRun}\n\n";
                }
            }

            break;

        case 'list':
            echo "Available Seeders:\n";
            echo "------------------\n";

            $availableSeeders = $seeder->getAvailableSeeders();

            if (empty($availableSeeders)) {
                echo "No seeders available.\n";
            } else {
                foreach ($availableSeeders as $seederName) {
                    echo "• {$seederName}\n";
                }
            }

            echo "\nUsage: php cli/seed.php <seeder_name>\n";

            break;

        case 'help':
            showUsage();
            break;

        default:
            // Try to run specific seeder
            if (in_array($command, $seeder->getAvailableSeeders())) {
                echo "Running seeder: {$command}\n\n";

                $startTime = microtime(true);
                $startMemory = memory_get_usage();

                $result = $seeder->seed($command);

                $endTime = microtime(true);
                $endMemory = memory_get_usage();
                $executionTime = ($endTime - $startTime) * 1000;
                $memoryUsed = $endMemory - $startMemory;

                $status = $result['success'] ? '✅' : '❌';
                $duration = formatDuration($result['execution_time_ms']);

                echo "Seeding Result:\n";
                echo "---------------\n";
                echo "{$status} {$command} ({$duration})\n";

                if (!$result['success']) {
                    echo "Error: {$result['error']}\n";
                }

                echo "\nExecution Statistics:\n";
                echo "Total time: " . formatDuration($executionTime) . "\n";
                echo "Memory used: " . formatBytes($memoryUsed) . "\n";
                echo "Peak memory: " . formatBytes(memory_get_peak_usage()) . "\n";
            } else {
                echo "❌ Error: Unknown command or seeder '{$command}'\n\n";
                echo "Available seeders:\n";
                foreach ($seeder->getAvailableSeeders() as $seederName) {
                    echo "  • {$seederName}\n";
                }
                echo "\n";
                showUsage();
                exit(1);
            }
            break;
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";

    if (isset($logger)) {
        $logger->error('Seeding CLI error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'command' => $command ?? 'unknown'
        ]);
    }

    exit(1);
}

echo "\n";