<?php

use SecurityScanner\Core\Migration;

class Migration_2025_09_25_220000_initial_schema extends Migration
{
    /**
     * Run the migration - Create initial database schema
     */
    public function up(): void
    {
        // Execute the main schema file
        $schemaPath = ROOT_PATH . '/database/schema.sql';
        $this->executeFile($schemaPath);

        $this->logger->info('Initial schema migration completed', [
            'tables_created' => [
                'websites',
                'available_tests',
                'website_test_config',
                'test_executions',
                'test_results',
                'scheduler_log',
                'notifications',
                'api_keys',
                'system_settings'
            ]
        ]);
    }

    /**
     * Reverse the migration - Drop all tables
     */
    public function down(): void
    {
        // Drop tables in reverse dependency order
        $tables = [
            'notifications',
            'test_results',
            'test_executions',
            'website_test_config',
            'scheduler_log',
            'available_tests',
            'websites',
            'api_keys',
            'system_settings'
        ];

        foreach ($tables as $table) {
            $this->dropTable($table);
        }

        // Drop views
        $this->execute("DROP VIEW IF EXISTS critical_findings");
        $this->execute("DROP VIEW IF EXISTS recent_execution_summary");
        $this->execute("DROP VIEW IF EXISTS active_website_configs");

        $this->logger->info('Initial schema rollback completed', [
            'tables_dropped' => $tables
        ]);
    }
}