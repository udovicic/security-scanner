<?php

use SecurityScanner\Core\Migration;

class Migration_2025_09_26_220800_create_execution_monitoring_tables extends Migration
{
    public function up(): void
    {
        // Create execution_monitoring table
        $sql1 = "
        CREATE TABLE IF NOT EXISTS `execution_monitoring` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `execution_id` VARCHAR(255) NOT NULL,
            `type` VARCHAR(100) NOT NULL,
            `start_time` TIMESTAMP NOT NULL,
            `end_time` TIMESTAMP NULL,
            `status` ENUM('started', 'completed', 'failed') NOT NULL DEFAULT 'started',
            `execution_time` DECIMAL(8,3) NULL,
            `checkpoints_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `warnings_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `errors_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `peak_memory` DECIMAL(8,2) NULL,
            `metadata` JSON NULL,
            `final_data` JSON NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_execution_id` (`execution_id`),
            KEY `idx_type_status` (`type`, `status`),
            KEY `idx_start_time` (`start_time`),
            KEY `idx_status_time` (`status`, `start_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execution monitoring for scheduler and background processes';
        ";

        $this->execute($sql1);

        // Create execution_checkpoints table
        $sql2 = "
        CREATE TABLE IF NOT EXISTS `execution_checkpoints` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `execution_id` VARCHAR(255) NOT NULL,
            `checkpoint_name` VARCHAR(255) NOT NULL,
            `timestamp` TIMESTAMP NOT NULL,
            `memory_usage` BIGINT UNSIGNED NOT NULL,
            `data` JSON NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_execution_checkpoint` (`execution_id`, `checkpoint_name`),
            KEY `idx_timestamp` (`timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execution checkpoints for detailed monitoring';
        ";

        $this->execute($sql2);
    }

    public function down(): void
    {
        $this->dropTable('execution_checkpoints');
        $this->dropTable('execution_monitoring');
    }}