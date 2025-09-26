<?php

namespace SecurityScanner\Core;

class MigrationManager
{
    private Database $db;
    private Logger $logger;
    private Config $config;
    private string $migrationPath;
    private string $migrationTable = 'migrations';

    public function __construct(string $migrationPath = null)
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();
        $this->config = Config::getInstance();
        $this->migrationPath = $migrationPath ?: ROOT_PATH . '/migrations';
    }

    /**
     * Initialize migration system
     */
    public function initialize(): void
    {
        $this->createMigrationsTable();
        $this->logger->info('Migration system initialized');
    }

    /**
     * Run pending migrations
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $pendingMigrations = $this->getPendingMigrations();
        $results = [];

        if (empty($pendingMigrations)) {
            $this->logger->info('No pending migrations found');
            return $results;
        }

        $this->logger->info('Starting migration process', [
            'pending_count' => count($pendingMigrations)
        ]);

        foreach ($pendingMigrations as $migrationFile) {
            $result = $this->runMigration($migrationFile, 'up');
            $results[] = $result;

            if (!$result['success']) {
                $this->logger->error('Migration failed, stopping process', [
                    'failed_migration' => $migrationFile
                ]);
                break;
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $this->logger->info('Migration process completed', [
            'total_migrations' => count($results),
            'successful' => $successCount,
            'failed' => count($results) - $successCount
        ]);

        return $results;
    }

    /**
     * Rollback migrations
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTable();

        $appliedMigrations = $this->getAppliedMigrations($steps);
        $results = [];

        if (empty($appliedMigrations)) {
            $this->logger->info('No migrations to rollback');
            return $results;
        }

        $this->logger->info('Starting rollback process', [
            'rollback_count' => count($appliedMigrations),
            'steps' => $steps
        ]);

        // Rollback in reverse order
        foreach (array_reverse($appliedMigrations) as $migration) {
            $migrationFile = $this->findMigrationFile($migration['migration']);
            if ($migrationFile) {
                $result = $this->runMigration($migrationFile, 'down');
                $results[] = $result;

                if (!$result['success']) {
                    $this->logger->error('Rollback failed, stopping process', [
                        'failed_migration' => $migrationFile
                    ]);
                    break;
                }
            } else {
                $this->logger->error('Migration file not found for rollback', [
                    'migration' => $migration['migration']
                ]);
            }
        }

        return $results;
    }

    /**
     * Get migration status
     */
    public function getStatus(): array
    {
        $this->ensureMigrationsTable();

        $allMigrations = $this->getAllMigrationFiles();
        $appliedMigrations = $this->getAppliedMigrationsArray();

        $status = [];

        foreach ($allMigrations as $migrationFile) {
            $className = $this->getMigrationClassName($migrationFile);
            $version = $this->extractVersionFromFilename($migrationFile);

            $status[] = [
                'file' => $migrationFile,
                'class' => $className,
                'version' => $version,
                'applied' => in_array($className, $appliedMigrations),
                'applied_at' => $this->getMigrationAppliedAt($className)
            ];
        }

        return $status;
    }

    /**
     * Create a new migration file
     */
    public function createMigration(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $className = 'Migration_' . $timestamp . '_' . $this->sanitizeName($name);
        $filename = $timestamp . '_' . strtolower($this->sanitizeName($name)) . '.php';
        $filepath = $this->migrationPath . '/' . $filename;

        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0755, true);
        }

        $template = $this->getMigrationTemplate($className, $name);

        file_put_contents($filepath, $template);

        $this->logger->info('Migration file created', [
            'file' => $filename,
            'class' => $className,
            'path' => $filepath
        ]);

        return $filepath;
    }

    /**
     * Reset all migrations (dangerous!)
     */
    public function reset(): array
    {
        $this->logger->warning('Resetting all migrations - this will drop all tables!');

        $appliedMigrations = $this->getAppliedMigrations();
        $results = [];

        // Rollback all migrations
        foreach (array_reverse($appliedMigrations) as $migration) {
            $migrationFile = $this->findMigrationFile($migration['migration']);
            if ($migrationFile) {
                $result = $this->runMigration($migrationFile, 'down');
                $results[] = $result;
            }
        }

        // Clear migration table
        $this->execute("DELETE FROM `{$this->migrationTable}`");

        return $results;
    }

    /**
     * Run a specific migration
     */
    private function runMigration(string $migrationFile, string $direction): array
    {
        $startTime = microtime(true);
        $className = $this->getMigrationClassName($migrationFile);

        try {
            require_once $migrationFile;

            if (!class_exists($className)) {
                throw new \Exception("Migration class {$className} not found in file {$migrationFile}");
            }

            $migration = new $className();

            if (!$migration instanceof Migration) {
                throw new \Exception("Migration class {$className} must extend Migration");
            }

            $this->logger->info("Running migration {$direction}", [
                'migration' => $className,
                'direction' => $direction
            ]);

            $this->db->getConnection()->beginTransaction();

            if ($direction === 'up') {
                $migration->up();
                $this->recordMigration($className);
            } else {
                $migration->down();
                $this->removeMigrationRecord($className);
            }

            $this->db->getConnection()->commit();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info("Migration {$direction} completed", [
                'migration' => $className,
                'direction' => $direction,
                'execution_time' => $executionTime . 'ms'
            ]);

            return [
                'success' => true,
                'migration' => $className,
                'direction' => $direction,
                'execution_time' => $executionTime,
                'message' => "Migration {$direction} successful"
            ];

        } catch (\Exception $e) {
            if ($this->db->getConnection()->inTransaction()) {
                $this->db->getConnection()->rollBack();
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error("Migration {$direction} failed", [
                'migration' => $className,
                'direction' => $direction,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime . 'ms'
            ]);

            return [
                'success' => false,
                'migration' => $className,
                'direction' => $direction,
                'execution_time' => $executionTime,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get pending migrations
     */
    private function getPendingMigrations(): array
    {
        $allMigrations = $this->getAllMigrationFiles();
        $appliedMigrations = $this->getAppliedMigrationsArray();

        $pending = [];

        foreach ($allMigrations as $migrationFile) {
            $className = $this->getMigrationClassName($migrationFile);
            if (!in_array($className, $appliedMigrations)) {
                $pending[] = $migrationFile;
            }
        }

        return $pending;
    }

    /**
     * Get all migration files
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            return [];
        }

        $files = glob($this->migrationPath . '/*.php');
        sort($files);

        return $files;
    }

    /**
     * Get applied migrations from database
     */
    private function getAppliedMigrations(int $limit = null): array
    {
        $sql = "SELECT * FROM `{$this->migrationTable}` ORDER BY applied_at DESC";
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->db->getConnection()->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get applied migrations as array of class names
     */
    private function getAppliedMigrationsArray(): array
    {
        $applied = $this->getAppliedMigrations();
        return array_column($applied, 'migration');
    }

    /**
     * Get migration applied timestamp
     */
    private function getMigrationAppliedAt(string $className): ?string
    {
        $sql = "SELECT applied_at FROM `{$this->migrationTable}` WHERE migration = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$className]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? $result['applied_at'] : null;
    }

    /**
     * Record migration as applied
     */
    private function recordMigration(string $className): void
    {
        $sql = "INSERT INTO `{$this->migrationTable}` (migration, applied_at) VALUES (?, NOW())";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$className]);
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(string $className): void
    {
        $sql = "DELETE FROM `{$this->migrationTable}` WHERE migration = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$className]);
    }

    /**
     * Find migration file by class name
     */
    private function findMigrationFile(string $className): ?string
    {
        $files = $this->getAllMigrationFiles();

        foreach ($files as $file) {
            if ($this->getMigrationClassName($file) === $className) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Get migration class name from file
     */
    private function getMigrationClassName(string $migrationFile): string
    {
        $filename = basename($migrationFile, '.php');
        $parts = explode('_', $filename);

        if (count($parts) >= 5) {
            $timestamp = implode('_', array_slice($parts, 0, 4));
            $name = implode('_', array_slice($parts, 4));
            return 'Migration_' . $timestamp . '_' . $name;
        }

        return 'Migration_' . $filename;
    }

    /**
     * Extract version from filename
     */
    private function extractVersionFromFilename(string $filename): string
    {
        $basename = basename($filename, '.php');
        $parts = explode('_', $basename);

        if (count($parts) >= 4) {
            return implode('_', array_slice($parts, 0, 4));
        }

        return $basename;
    }

    /**
     * Sanitize migration name
     */
    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    /**
     * Get migration template
     */
    private function getMigrationTemplate(string $className, string $name): string
    {
        return "<?php

use SecurityScanner\Core\Migration;

class {$className} extends Migration
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        // TODO: Implement migration logic for: {$name}

        // Example: Create table
        // \$this->createTable('example_table', [
        //     'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        //     'name' => 'VARCHAR(255) NOT NULL',
        //     'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        // ], [
        //     'comment' => 'Example table description'
        // ]);

        // Example: Add index
        // \$this->addIndex('example_table', 'idx_example_name', ['name']);
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        // TODO: Implement rollback logic for: {$name}

        // Example: Drop table
        // \$this->dropTable('example_table');
    }
}
";
    }

    /**
     * Create migrations table if it doesn't exist
     */
    private function createMigrationsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->migrationTable}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_migration_name` (`migration`),
                KEY `idx_migration_applied_at` (`applied_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Database migration tracking'
        ";

        $this->execute($sql);
    }

    /**
     * Ensure migrations table exists
     */
    private function ensureMigrationsTable(): void
    {
        if (!$this->tableExists($this->migrationTable)) {
            $this->createMigrationsTable();
        }
    }

    /**
     * Check if table exists
     */
    private function tableExists(string $tableName): bool
    {
        $sql = "SHOW TABLES LIKE '{$tableName}'";
        $stmt = $this->db->getConnection()->query($sql);
        return $stmt && $stmt->rowCount() > 0;
    }

    /**
     * Execute SQL
     */
    private function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            $this->logger->error('Migration manager SQL failed', [
                'sql' => substr($sql, 0, 200),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}