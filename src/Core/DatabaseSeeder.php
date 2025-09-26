<?php

namespace SecurityScanner\Core;

use SecurityScanner\Seeders\AvailableTestsSeeder;

class DatabaseSeeder
{
    private Logger $logger;
    private array $seeders = [];

    public function __construct()
    {
        $this->logger = Logger::scheduler();
        $this->registerSeeders();
    }

    /**
     * Register all available seeders
     */
    private function registerSeeders(): void
    {
        $this->seeders = [
            'available_tests' => AvailableTestsSeeder::class
        ];
    }

    /**
     * Run all seeders
     */
    public function seedAll(): array
    {
        $this->logger->info('DatabaseSeeder: Starting to seed all data');
        $results = [];
        $startTime = microtime(true);

        foreach ($this->seeders as $name => $seederClass) {
            try {
                $this->logger->info("Running seeder: {$name}");
                $seederStartTime = microtime(true);

                $seeder = new $seederClass();
                $seeder->seed();

                $seederTime = round((microtime(true) - $seederStartTime) * 1000, 2);
                $results[$name] = [
                    'success' => true,
                    'execution_time_ms' => $seederTime
                ];

                $this->logger->info("Seeder {$name} completed successfully", [
                    'execution_time_ms' => $seederTime
                ]);
            } catch (\Exception $e) {
                $seederTime = round((microtime(true) - $seederStartTime) * 1000, 2);
                $results[$name] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'execution_time_ms' => $seederTime
                ];

                $this->logger->error("Seeder {$name} failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'execution_time_ms' => $seederTime
                ]);
            }
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $successCount = count(array_filter($results, fn($r) => $r['success']));

        $this->logger->info('DatabaseSeeder: All seeders completed', [
            'total_seeders' => count($this->seeders),
            'successful' => $successCount,
            'failed' => count($this->seeders) - $successCount,
            'total_execution_time_ms' => $totalTime
        ]);

        return $results;
    }

    /**
     * Run specific seeder
     */
    public function seed(string $seederName): array
    {
        if (!isset($this->seeders[$seederName])) {
            throw new \InvalidArgumentException("Seeder '{$seederName}' not found");
        }

        $this->logger->info("DatabaseSeeder: Running specific seeder: {$seederName}");
        $startTime = microtime(true);

        try {
            $seederClass = $this->seeders[$seederName];
            $seeder = new $seederClass();
            $seeder->seed();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info("Seeder {$seederName} completed successfully", [
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => true,
                'execution_time_ms' => $executionTime
            ];
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error("Seeder {$seederName} failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * Get list of available seeders
     */
    public function getAvailableSeeders(): array
    {
        return array_keys($this->seeders);
    }

    /**
     * Check seeding status
     */
    public function getSeederStatus(): array
    {
        $db = Database::getInstance();
        $status = [];

        foreach ($this->seeders as $name => $seederClass) {
            $sql = "
                SELECT value as last_run
                FROM system_settings
                WHERE `key` = ?
            ";

            $stmt = $db->query($sql, ["seeder_run_{$name}"]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $lastRun = $result[0]['last_run'] ?? null;

            $status[$name] = [
                'seeder_class' => $seederClass,
                'has_run' => !is_null($lastRun),
                'last_run' => $lastRun
            ];
        }

        return $status;
    }
}