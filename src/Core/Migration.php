<?php

namespace SecurityScanner\Core;

abstract class Migration
{
    protected Database $db;
    protected Logger $logger;
    protected string $migrationName;
    protected string $version;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();

        // Extract migration name and version from class name
        $className = (new \ReflectionClass($this))->getShortName();
        if (preg_match('/^Migration_(\d{4}_\d{2}_\d{2}_\d{6})_(.+)$/', $className, $matches)) {
            $this->version = $matches[1];
            $this->migrationName = str_replace('_', ' ', $matches[2]);
        } else {
            $this->version = date('Y_m_d_His');
            $this->migrationName = $className;
        }
    }

    /**
     * Run the migration
     */
    abstract public function up(): void;

    /**
     * Reverse the migration
     */
    abstract public function down(): void;

    /**
     * Get migration version
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get migration name
     */
    public function getName(): string
    {
        return $this->migrationName;
    }

    /**
     * Get full migration identifier
     */
    public function getIdentifier(): string
    {
        return get_class($this);
    }

    /**
     * Execute raw SQL
     */
    protected function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute($params);

            $this->logger->debug('Migration SQL executed', [
                'migration' => $this->getIdentifier(),
                'sql' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                'params_count' => count($params)
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Migration SQL failed', [
                'migration' => $this->getIdentifier(),
                'sql' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Execute SQL file
     */
    protected function executeFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Migration SQL file not found: {$filePath}");
        }

        $sql = file_get_contents($filePath);

        // Split SQL into individual statements
        $statements = $this->splitSQLStatements($sql);

        // Check if transaction is already active
        $connection = $this->db->getConnection();
        $needTransaction = !$connection->inTransaction();

        if ($needTransaction) {
            $connection->beginTransaction();
        }

        try {
            foreach ($statements as $statement) {
                if (trim($statement)) {
                    $this->execute($statement);
                }
            }

            if ($needTransaction) {
                $connection->commit();
            }
            return true;
        } catch (\Exception $e) {
            if ($needTransaction) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $tableName): bool
    {
        $sql = "SHOW TABLES LIKE ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if column exists in table
     */
    protected function columnExists(string $tableName, string $columnName): bool
    {
        $sql = "SHOW COLUMNS FROM `{$tableName}` LIKE ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if index exists
     */
    protected function indexExists(string $tableName, string $indexName): bool
    {
        $sql = "SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$indexName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Create table
     */
    protected function createTable(string $tableName, array $columns, array $options = []): bool
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            $columnDefinitions[] = "`{$name}` {$definition}";
        }

        $engine = $options['engine'] ?? 'InnoDB';
        $charset = $options['charset'] ?? 'utf8mb4';
        $collate = $options['collate'] ?? 'utf8mb4_unicode_ci';
        $comment = isset($options['comment']) ? " COMMENT='{$options['comment']}'" : '';

        $sql = "CREATE TABLE `{$tableName}` (\n";
        $sql .= "    " . implode(",\n    ", $columnDefinitions) . "\n";
        $sql .= ") ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}{$comment}";

        return $this->execute($sql);
    }

    /**
     * Drop table
     */
    protected function dropTable(string $tableName): bool
    {
        return $this->execute("DROP TABLE IF EXISTS `{$tableName}`");
    }

    /**
     * Add column to table
     */
    protected function addColumn(string $tableName, string $columnName, string $definition, string $after = null): bool
    {
        $afterClause = $after ? " AFTER `{$after}`" : '';
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$definition}{$afterClause}";
        return $this->execute($sql);
    }

    /**
     * Drop column from table
     */
    protected function dropColumn(string $tableName, string $columnName): bool
    {
        return $this->execute("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`");
    }

    /**
     * Modify column in table
     */
    protected function modifyColumn(string $tableName, string $columnName, string $newDefinition): bool
    {
        $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$columnName}` {$newDefinition}";
        return $this->execute($sql);
    }

    /**
     * Add index to table
     */
    protected function addIndex(string $tableName, string $indexName, array $columns, string $type = ''): bool
    {
        $columnList = '`' . implode('`, `', $columns) . '`';
        $typeClause = $type ? " {$type}" : '';
        $sql = "CREATE{$typeClause} INDEX `{$indexName}` ON `{$tableName}` ({$columnList})";
        return $this->execute($sql);
    }

    /**
     * Drop index from table
     */
    protected function dropIndex(string $tableName, string $indexName): bool
    {
        return $this->execute("DROP INDEX `{$indexName}` ON `{$tableName}`");
    }

    /**
     * Add foreign key constraint
     */
    protected function addForeignKey(string $tableName, string $constraintName, array $columns, string $referencedTable, array $referencedColumns, string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE'): bool
    {
        $columnList = '`' . implode('`, `', $columns) . '`';
        $referencedColumnList = '`' . implode('`, `', $referencedColumns) . '`';

        $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraintName}` ";
        $sql .= "FOREIGN KEY ({$columnList}) REFERENCES `{$referencedTable}` ({$referencedColumnList})";
        $sql .= " ON DELETE {$onDelete} ON UPDATE {$onUpdate}";

        return $this->execute($sql);
    }

    /**
     * Drop foreign key constraint
     */
    protected function dropForeignKey(string $tableName, string $constraintName): bool
    {
        return $this->execute("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$constraintName}`");
    }

    /**
     * Insert data
     */
    protected function insert(string $tableName, array $data): bool
    {
        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';

        $sql = "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})";

        return $this->execute($sql, array_values($data));
    }

    /**
     * Split SQL into individual statements
     */
    private function splitSQLStatements(string $sql): array
    {
        // Remove comments and normalize whitespace
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);

        // Split by semicolon, but be careful with strings and delimiters
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $escaped = false;

        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if ($escaped) {
                $escaped = false;
                $current .= $char;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $current .= $char;
                continue;
            }

            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                $inString = false;
                $stringChar = '';
            }

            if (!$inString && $char === ';') {
                $statement = trim($current);
                if ($statement) {
                    $statements[] = $statement;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add final statement if it doesn't end with semicolon
        $statement = trim($current);
        if ($statement) {
            $statements[] = $statement;
        }

        return $statements;
    }
}