<?php

use SecurityScanner\Core\Migration;

class Migration_2025_09_26_183000_create_database_locks_table extends Migration
{
    public function up(): void
    {
        // Create the database_locks table using the createTable method
        $this->createTable('database_locks', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'lock_id' => 'VARCHAR(255) NOT NULL',
            'lock_name' => 'VARCHAR(255) NOT NULL',
            'owner' => 'VARCHAR(255) NOT NULL',
            'acquired_at' => 'TIMESTAMP NOT NULL',
            'expires_at' => 'TIMESTAMP NOT NULL',
            'heartbeat_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'timeout_seconds' => 'INT UNSIGNED NOT NULL DEFAULT 300',
            'metadata' => 'JSON NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ], 'id');

        // Add unique constraint for lock_name
        $this->addIndex('database_locks', 'unique_lock_name', ['lock_name'], true);

        // Add performance indexes
        $this->addIndex('database_locks', 'idx_lock_name_owner', ['lock_name', 'owner']);
        $this->addIndex('database_locks', 'idx_expires_at', ['expires_at']);
        $this->addIndex('database_locks', 'idx_heartbeat_at', ['heartbeat_at']);
        $this->addIndex('database_locks', 'idx_acquired_at', ['acquired_at']);
        $this->addIndex('database_locks', 'idx_owner_acquired', ['owner', 'acquired_at']);
        $this->addIndex('database_locks', 'idx_lock_status', ['lock_name', 'expires_at', 'heartbeat_at']);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS `database_locks`");
    }
}