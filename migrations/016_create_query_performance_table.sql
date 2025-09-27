-- Migration: Create query performance tracking table
-- Description: Store query performance metrics and analysis

CREATE TABLE IF NOT EXISTS query_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_hash VARCHAR(64) NOT NULL,
    query_type ENUM('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'OTHER') NOT NULL,
    execution_time_ms DECIMAL(10,3) NOT NULL,
    estimated_cost INT DEFAULT 0,
    performance_score TINYINT UNSIGNED DEFAULT 100,
    suggestions_count TINYINT UNSIGNED DEFAULT 0,
    memory_used_bytes INT DEFAULT 0,
    rows_affected INT DEFAULT 0,
    created_at DATETIME NOT NULL,

    INDEX idx_query_hash (query_hash),
    INDEX idx_query_type (query_type),
    INDEX idx_execution_time (execution_time_ms),
    INDEX idx_performance_score (performance_score),
    INDEX idx_created_at (created_at),
    INDEX idx_slow_queries (execution_time_ms, created_at)
) ENGINE=InnoDB;