<?php

namespace SecurityScanner\Core;

use SecurityScanner\Models\AvailableTest;

abstract class Seeder
{
    protected Database $db;
    protected Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();
    }

    /**
     * Run the database seeder
     */
    abstract public function seed(): void;

    /**
     * Check if seeder has already been run
     */
    protected function isSeeded(string $seederName): bool
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM system_settings
            WHERE `key` = ?
        ";

        $stmt = $this->db->query($sql, ["seeder_run_{$seederName}"]);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return ($result[0]['count'] ?? 0) > 0;
    }

    /**
     * Mark seeder as completed
     */
    protected function markSeeded(string $seederName): void
    {
        $sql = "
            INSERT INTO system_settings (`key`, value, description, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            value = VALUES(value),
            updated_at = NOW()
        ";

        $this->db->getConnection()->prepare($sql)->execute([
            "seeder_run_{$seederName}",
            date('Y-m-d H:i:s'),
            "Timestamp when {$seederName} seeder was last run"
        ]);
    }

    /**
     * Truncate a table safely
     */
    protected function truncateTable(string $table): void
    {
        $sql = "TRUNCATE TABLE {$table}";
        $this->db->getConnection()->exec($sql);
    }

    /**
     * Insert data if not exists
     */
    protected function insertIfNotExists(string $table, array $data, array $uniqueFields): bool
    {
        // Check if record exists
        $whereClause = implode(' AND ', array_map(fn($field) => "{$field} = ?", $uniqueFields));
        $checkSql = "SELECT COUNT(*) as count FROM {$table} WHERE {$whereClause}";
        $checkParams = array_intersect_key($data, array_flip($uniqueFields));

        $stmt = $this->db->query($checkSql, array_values($checkParams));
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (($result[0]['count'] ?? 0) > 0) {
            return false; // Record already exists
        }

        // Insert the record
        $fields = array_keys($data);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        $insertSql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES ({$placeholders})";

        $stmt = $this->db->getConnection()->prepare($insertSql);
        return $stmt->execute(array_values($data));
    }
}