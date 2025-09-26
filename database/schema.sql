-- Security Scanner Tool Database Schema
-- This file contains the complete database structure for the security scanner application
-- Created: 2025-09-25
-- Version: 1.0

-- Enable foreign key constraints (MySQL/MariaDB)
SET foreign_key_checks = 1;

-- ============================================================================
-- TABLE: websites
-- Stores information about websites to be scanned
-- ============================================================================
CREATE TABLE IF NOT EXISTS `websites` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Human-readable name for the website',
    `url` VARCHAR(2048) NOT NULL COMMENT 'Base URL of the website to scan',
    `description` TEXT NULL COMMENT 'Optional description of the website',
    `status` ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active' COMMENT 'Current status of the website',
    `scan_frequency` ENUM('manual', 'hourly', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily' COMMENT 'How often to scan this website',
    `last_scan_at` TIMESTAMP NULL COMMENT 'When was the last scan performed',
    `next_scan_at` TIMESTAMP NULL COMMENT 'When should the next scan be performed',
    `scan_enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether scanning is enabled for this website',
    `notification_email` VARCHAR(255) NULL COMMENT 'Email address for notifications',
    `max_concurrent_tests` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Maximum concurrent tests for this website',
    `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Request timeout in seconds',
    `verify_ssl` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether to verify SSL certificates',
    `follow_redirects` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether to follow HTTP redirects',
    `max_redirects` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Maximum number of redirects to follow',
    `user_agent` VARCHAR(500) NULL COMMENT 'Custom user agent string',
    `auth_type` ENUM('none', 'basic', 'bearer', 'custom') NOT NULL DEFAULT 'none' COMMENT 'Authentication type',
    `auth_username` VARCHAR(255) NULL COMMENT 'Username for basic auth',
    `auth_password` VARCHAR(255) NULL COMMENT 'Password for basic auth (encrypted)',
    `auth_token` TEXT NULL COMMENT 'Bearer token or custom auth data (encrypted)',
    `custom_headers` JSON NULL COMMENT 'Custom HTTP headers as JSON object',
    `metadata` JSON NULL COMMENT 'Additional metadata as JSON object',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_websites_url` (`url`(191)),
    KEY `idx_websites_status` (`status`),
    KEY `idx_websites_scan_frequency` (`scan_frequency`),
    KEY `idx_websites_next_scan` (`next_scan_at`, `scan_enabled`),
    KEY `idx_websites_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Websites to be scanned';

-- ============================================================================
-- TABLE: available_tests
-- Registry of all available security tests/plugins
-- ============================================================================
CREATE TABLE IF NOT EXISTS `available_tests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Unique test identifier/name',
    `display_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable test name',
    `description` TEXT NOT NULL COMMENT 'Description of what this test checks',
    `category` VARCHAR(100) NOT NULL COMMENT 'Test category (ssl, headers, vulnerabilities, etc.)',
    `severity` ENUM('info', 'low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' COMMENT 'Default severity level',
    `test_class` VARCHAR(255) NOT NULL COMMENT 'PHP class name that implements this test',
    `version` VARCHAR(20) NOT NULL DEFAULT '1.0.0' COMMENT 'Test version',
    `enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this test is available for use',
    `default_timeout` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Default timeout in seconds',
    `default_config` JSON NULL COMMENT 'Default configuration options as JSON',
    `requirements` JSON NULL COMMENT 'Test requirements (PHP extensions, etc.) as JSON',
    `documentation_url` VARCHAR(500) NULL COMMENT 'URL to test documentation',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_available_tests_name` (`name`),
    KEY `idx_available_tests_category` (`category`),
    KEY `idx_available_tests_enabled` (`enabled`),
    KEY `idx_available_tests_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registry of available security tests';

-- ============================================================================
-- TABLE: website_test_config
-- Configuration for which tests to run on which websites
-- ============================================================================
CREATE TABLE IF NOT EXISTS `website_test_config` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to websites table',
    `available_test_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to available_tests table',
    `enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this test is enabled for this website',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Test execution priority (1-100, higher = earlier)',
    `timeout_seconds` INT UNSIGNED NULL COMMENT 'Custom timeout for this test/website combo',
    `custom_config` JSON NULL COMMENT 'Custom configuration options as JSON',
    `notification_level` ENUM('none', 'changes_only', 'failures_only', 'all') NOT NULL DEFAULT 'failures_only' COMMENT 'When to send notifications',
    `retry_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Number of retry attempts on failure',
    `retry_delay_seconds` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Delay between retry attempts',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_website_test_config_unique` (`website_id`, `available_test_id`),
    KEY `idx_website_test_config_website` (`website_id`),
    KEY `idx_website_test_config_test` (`available_test_id`),
    KEY `idx_website_test_config_enabled` (`enabled`),
    KEY `idx_website_test_config_priority` (`priority`),
    CONSTRAINT `fk_website_test_config_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_website_test_config_test` FOREIGN KEY (`available_test_id`) REFERENCES `available_tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuration for website-specific test settings';

-- ============================================================================
-- TABLE: test_executions
-- Tracks individual test execution sessions/batches
-- ============================================================================
CREATE TABLE IF NOT EXISTS `test_executions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to websites table',
    `execution_type` ENUM('manual', 'scheduled', 'api', 'webhook') NOT NULL DEFAULT 'scheduled' COMMENT 'How this execution was triggered',
    `status` ENUM('queued', 'running', 'completed', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'queued' COMMENT 'Current execution status',
    `total_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of tests in this execution',
    `completed_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of completed tests',
    `passed_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of tests that passed',
    `failed_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of tests that failed',
    `error_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of tests that had errors',
    `skipped_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of tests that were skipped',
    `start_time` TIMESTAMP NULL COMMENT 'When the execution started',
    `end_time` TIMESTAMP NULL COMMENT 'When the execution completed/failed',
    `execution_time_ms` BIGINT UNSIGNED NULL COMMENT 'Total execution time in milliseconds',
    `memory_usage_bytes` BIGINT UNSIGNED NULL COMMENT 'Peak memory usage in bytes',
    `trigger_user_id` BIGINT UNSIGNED NULL COMMENT 'User who triggered this execution (if applicable)',
    `trigger_ip` VARCHAR(45) NULL COMMENT 'IP address that triggered this execution',
    `execution_context` JSON NULL COMMENT 'Additional context data as JSON',
    `error_message` TEXT NULL COMMENT 'Error message if execution failed',
    `notifications_sent` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether notifications have been sent',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_test_executions_website` (`website_id`),
    KEY `idx_test_executions_status` (`status`),
    KEY `idx_test_executions_type` (`execution_type`),
    KEY `idx_test_executions_created` (`created_at`),
    KEY `idx_test_executions_start_time` (`start_time`),
    KEY `idx_test_executions_website_status` (`website_id`, `status`),
    CONSTRAINT `fk_test_executions_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks test execution sessions';

-- ============================================================================
-- TABLE: test_results
-- Stores individual test results
-- ============================================================================
CREATE TABLE IF NOT EXISTS `test_results` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `test_execution_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to test_executions table',
    `website_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to websites table',
    `available_test_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to available_tests table',
    `test_name` VARCHAR(255) NOT NULL COMMENT 'Name of the test that was run',
    `status` ENUM('passed', 'failed', 'error', 'skipped', 'timeout') NOT NULL COMMENT 'Test result status',
    `severity` ENUM('info', 'low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' COMMENT 'Severity level of the result',
    `score` DECIMAL(5,2) UNSIGNED NULL COMMENT 'Numeric score (0.00-100.00) if applicable',
    `message` TEXT NOT NULL COMMENT 'Human-readable test result message',
    `details` JSON NULL COMMENT 'Detailed test results as JSON',
    `evidence` JSON NULL COMMENT 'Evidence/proof of findings as JSON',
    `recommendations` TEXT NULL COMMENT 'Recommendations for fixing issues',
    `external_references` JSON NULL COMMENT 'External reference URLs as JSON array',
    `start_time` TIMESTAMP NOT NULL COMMENT 'When this test started',
    `end_time` TIMESTAMP NOT NULL COMMENT 'When this test completed',
    `execution_time_ms` INT UNSIGNED NOT NULL COMMENT 'Test execution time in milliseconds',
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of retries attempted',
    `request_url` VARCHAR(2048) NULL COMMENT 'The actual URL that was tested',
    `response_status` SMALLINT UNSIGNED NULL COMMENT 'HTTP response status code',
    `response_headers` JSON NULL COMMENT 'HTTP response headers as JSON',
    `response_size` INT UNSIGNED NULL COMMENT 'Response size in bytes',
    `ssl_info` JSON NULL COMMENT 'SSL certificate information as JSON',
    `raw_output` LONGTEXT NULL COMMENT 'Raw test output for debugging',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_test_results_execution` (`test_execution_id`),
    KEY `idx_test_results_website` (`website_id`),
    KEY `idx_test_results_test` (`available_test_id`),
    KEY `idx_test_results_status` (`status`),
    KEY `idx_test_results_severity` (`severity`),
    KEY `idx_test_results_website_test` (`website_id`, `available_test_id`),
    KEY `idx_test_results_created` (`created_at`),
    KEY `idx_test_results_start_time` (`start_time`),
    CONSTRAINT `fk_test_results_execution` FOREIGN KEY (`test_execution_id`) REFERENCES `test_executions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_test_results_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_test_results_test` FOREIGN KEY (`available_test_id`) REFERENCES `available_tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual security test results';

-- ============================================================================
-- TABLE: scheduler_log
-- Logs scheduler activity and status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `scheduler_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type` ENUM('start', 'stop', 'scan_queued', 'scan_started', 'scan_completed', 'scan_failed', 'error', 'maintenance') NOT NULL COMMENT 'Type of scheduler event',
    `website_id` BIGINT UNSIGNED NULL COMMENT 'Reference to websites table (if applicable)',
    `test_execution_id` BIGINT UNSIGNED NULL COMMENT 'Reference to test_executions table (if applicable)',
    `message` TEXT NOT NULL COMMENT 'Log message',
    `details` JSON NULL COMMENT 'Additional details as JSON',
    `severity` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info' COMMENT 'Log severity level',
    `process_id` INT UNSIGNED NULL COMMENT 'Process ID of scheduler',
    `memory_usage_bytes` BIGINT UNSIGNED NULL COMMENT 'Memory usage at time of log',
    `execution_time_ms` INT UNSIGNED NULL COMMENT 'Time taken for operation in milliseconds',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scheduler_log_event_type` (`event_type`),
    KEY `idx_scheduler_log_website` (`website_id`),
    KEY `idx_scheduler_log_execution` (`test_execution_id`),
    KEY `idx_scheduler_log_severity` (`severity`),
    KEY `idx_scheduler_log_created` (`created_at`),
    KEY `idx_scheduler_log_process` (`process_id`),
    CONSTRAINT `fk_scheduler_log_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_scheduler_log_execution` FOREIGN KEY (`test_execution_id`) REFERENCES `test_executions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scheduler activity and error logs';

-- ============================================================================
-- TABLE: notifications
-- Stores notification history and status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` BIGINT UNSIGNED NULL COMMENT 'Reference to websites table',
    `test_execution_id` BIGINT UNSIGNED NULL COMMENT 'Reference to test_executions table',
    `test_result_id` BIGINT UNSIGNED NULL COMMENT 'Reference to test_results table',
    `notification_type` ENUM('email', 'webhook', 'slack', 'teams', 'discord') NOT NULL COMMENT 'Type of notification',
    `recipient` VARCHAR(500) NOT NULL COMMENT 'Notification recipient (email, URL, etc.)',
    `subject` VARCHAR(500) NOT NULL COMMENT 'Notification subject/title',
    `message` LONGTEXT NOT NULL COMMENT 'Notification message content',
    `status` ENUM('pending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Notification status',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of delivery attempts',
    `last_attempt_at` TIMESTAMP NULL COMMENT 'When the last delivery attempt was made',
    `sent_at` TIMESTAMP NULL COMMENT 'When the notification was successfully sent',
    `error_message` TEXT NULL COMMENT 'Error message if delivery failed',
    `metadata` JSON NULL COMMENT 'Additional notification metadata as JSON',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_website` (`website_id`),
    KEY `idx_notifications_execution` (`test_execution_id`),
    KEY `idx_notifications_result` (`test_result_id`),
    KEY `idx_notifications_type` (`notification_type`),
    KEY `idx_notifications_status` (`status`),
    KEY `idx_notifications_created` (`created_at`),
    CONSTRAINT `fk_notifications_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_execution` FOREIGN KEY (`test_execution_id`) REFERENCES `test_executions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_result` FOREIGN KEY (`test_result_id`) REFERENCES `test_results` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notification delivery tracking';

-- ============================================================================
-- TABLE: api_keys
-- API authentication keys for external access
-- ============================================================================
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Descriptive name for this API key',
    `key_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed API key',
    `permissions` JSON NOT NULL COMMENT 'API permissions as JSON array',
    `rate_limit_per_minute` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Rate limit for this API key',
    `allowed_ips` JSON NULL COMMENT 'Allowed IP addresses as JSON array',
    `enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this API key is active',
    `last_used_at` TIMESTAMP NULL COMMENT 'When this API key was last used',
    `last_used_ip` VARCHAR(45) NULL COMMENT 'Last IP address that used this key',
    `expires_at` TIMESTAMP NULL COMMENT 'When this API key expires',
    `created_by` VARCHAR(255) NULL COMMENT 'Who created this API key',
    `notes` TEXT NULL COMMENT 'Notes about this API key',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_api_keys_hash` (`key_hash`),
    KEY `idx_api_keys_enabled` (`enabled`),
    KEY `idx_api_keys_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API authentication keys';

-- ============================================================================
-- TABLE: system_settings
-- Application-wide configuration settings
-- ============================================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category` VARCHAR(100) NOT NULL COMMENT 'Setting category (scheduler, notifications, etc.)',
    `key` VARCHAR(255) NOT NULL COMMENT 'Setting key/name',
    `value` LONGTEXT NOT NULL COMMENT 'Setting value',
    `type` ENUM('string', 'integer', 'boolean', 'json', 'encrypted') NOT NULL DEFAULT 'string' COMMENT 'Value data type',
    `description` TEXT NULL COMMENT 'Description of what this setting does',
    `is_sensitive` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this setting contains sensitive data',
    `validation_rule` VARCHAR(500) NULL COMMENT 'Validation rule for this setting',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_system_settings_key` (`category`, `key`),
    KEY `idx_system_settings_category` (`category`),
    KEY `idx_system_settings_sensitive` (`is_sensitive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System-wide configuration settings';

-- ============================================================================
-- VIEWS
-- Useful views for common queries
-- ============================================================================

-- View for active website configurations
CREATE OR REPLACE VIEW `active_website_configs` AS
SELECT
    w.id as website_id,
    w.name as website_name,
    w.url as website_url,
    w.status as website_status,
    w.scan_frequency,
    w.next_scan_at,
    COUNT(wtc.id) as total_tests,
    COUNT(CASE WHEN wtc.enabled = 1 THEN 1 END) as enabled_tests
FROM websites w
LEFT JOIN website_test_config wtc ON w.id = wtc.website_id
WHERE w.scan_enabled = 1 AND w.status = 'active'
GROUP BY w.id;

-- View for recent test execution summaries
CREATE OR REPLACE VIEW `recent_execution_summary` AS
SELECT
    te.id as execution_id,
    te.website_id,
    w.name as website_name,
    w.url as website_url,
    te.status as execution_status,
    te.total_tests,
    te.passed_tests,
    te.failed_tests,
    te.error_tests,
    te.start_time,
    te.end_time,
    te.execution_time_ms,
    ROUND((te.passed_tests / NULLIF(te.total_tests, 0)) * 100, 2) as success_rate
FROM test_executions te
JOIN websites w ON te.website_id = w.id
WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY te.start_time DESC;

-- View for critical security findings
CREATE OR REPLACE VIEW `critical_findings` AS
SELECT
    tr.id as result_id,
    tr.website_id,
    w.name as website_name,
    w.url as website_url,
    tr.test_name,
    tr.severity,
    tr.status,
    tr.message,
    tr.created_at,
    te.execution_type
FROM test_results tr
JOIN websites w ON tr.website_id = w.id
JOIN test_executions te ON tr.test_execution_id = te.id
WHERE tr.severity IN ('high', 'critical')
    AND tr.status IN ('failed', 'error')
    AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY
    CASE tr.severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 END,
    tr.created_at DESC;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- Additional composite indexes for common query patterns
-- ============================================================================

-- Website scanning indexes
CREATE INDEX IF NOT EXISTS idx_websites_scan_scheduling ON websites (next_scan_at, scan_enabled, status);
CREATE INDEX IF NOT EXISTS idx_websites_active_scanning ON websites (status, scan_enabled, scan_frequency);

-- Test execution performance indexes
CREATE INDEX IF NOT EXISTS idx_test_executions_website_date ON test_executions (website_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_test_executions_status_date ON test_executions (status, start_time DESC);

-- Test results performance indexes
CREATE INDEX IF NOT EXISTS idx_test_results_website_severity ON test_results (website_id, severity, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_test_results_execution_status ON test_results (test_execution_id, status);
CREATE INDEX IF NOT EXISTS idx_test_results_recent_critical ON test_results (severity, status, created_at DESC);

-- Scheduler log performance indexes
CREATE INDEX IF NOT EXISTS idx_scheduler_log_recent_events ON scheduler_log (event_type, created_at DESC);
-- MySQL doesn't support partial indexes, use a regular index instead
CREATE INDEX IF NOT EXISTS idx_scheduler_log_errors ON scheduler_log (severity, created_at);

-- ============================================================================
-- SAMPLE DATA
-- Insert some basic test configurations
-- ============================================================================

-- Insert default available tests
INSERT INTO `available_tests` (`name`, `display_name`, `description`, `category`, `severity`, `test_class`, `version`) VALUES
('ssl_certificate', 'SSL Certificate Check', 'Validates SSL certificate validity, expiration, and security', 'ssl', 'high', 'SecurityScanner\\Tests\\SSLCertificateTest', '1.0.0'),
('security_headers', 'Security Headers Check', 'Checks for presence and configuration of security headers', 'headers', 'medium', 'SecurityScanner\\Tests\\SecurityHeadersTest', '1.0.0'),
('sql_injection', 'SQL Injection Test', 'Tests for SQL injection vulnerabilities', 'vulnerabilities', 'critical', 'SecurityScanner\\Tests\\SQLInjectionTest', '1.0.0'),
('xss_test', 'Cross-Site Scripting Test', 'Tests for XSS vulnerabilities', 'vulnerabilities', 'critical', 'SecurityScanner\\Tests\\XSSTest', '1.0.0'),
('directory_traversal', 'Directory Traversal Test', 'Tests for directory traversal vulnerabilities', 'vulnerabilities', 'high', 'SecurityScanner\\Tests\\DirectoryTraversalTest', '1.0.0'),
('port_scan', 'Port Scan', 'Scans for open ports and services', 'network', 'medium', 'SecurityScanner\\Tests\\PortScanTest', '1.0.0'),
('cms_detection', 'CMS Detection', 'Detects content management system and version', 'reconnaissance', 'info', 'SecurityScanner\\Tests\\CMSDetectionTest', '1.0.0'),
('robots_txt', 'Robots.txt Analysis', 'Analyzes robots.txt file for information disclosure', 'information', 'low', 'SecurityScanner\\Tests\\RobotsTxtTest', '1.0.0'),
('dns_security', 'DNS Security Check', 'Checks DNS configuration and security', 'dns', 'medium', 'SecurityScanner\\Tests\\DNSSecurityTest', '1.0.0'),
('cookie_security', 'Cookie Security Check', 'Analyzes cookie security attributes', 'headers', 'medium', 'SecurityScanner\\Tests\\CookieSecurityTest', '1.0.0');

-- Insert default system settings
INSERT INTO `system_settings` (`category`, `key`, `value`, `type`, `description`) VALUES
('scheduler', 'enabled', 'true', 'boolean', 'Whether the scheduler is enabled'),
('scheduler', 'max_concurrent_executions', '10', 'integer', 'Maximum number of concurrent test executions'),
('scheduler', 'default_timeout', '300', 'integer', 'Default timeout for test executions in seconds'),
('scheduler', 'cleanup_days', '90', 'integer', 'Number of days to keep old test results'),
('notifications', 'email_enabled', 'false', 'boolean', 'Whether email notifications are enabled'),
('notifications', 'webhook_timeout', '30', 'integer', 'Timeout for webhook notifications in seconds'),
('notifications', 'max_retry_attempts', '3', 'integer', 'Maximum retry attempts for failed notifications'),
('security', 'api_rate_limit_default', '60', 'integer', 'Default API rate limit per minute'),
('security', 'session_timeout', '3600', 'integer', 'Session timeout in seconds'),
('maintenance', 'backup_enabled', 'true', 'boolean', 'Whether automatic backups are enabled');

-- ============================================================================
-- COMMENTS AND DOCUMENTATION
-- ============================================================================

/*
Database Design Notes:

1. NORMALIZATION:
   - The schema follows 3NF principles
   - Relationships are properly defined with foreign keys
   - JSON columns are used for flexible, non-relational data

2. PERFORMANCE CONSIDERATIONS:
   - Strategic indexing for common query patterns
   - Composite indexes for multi-column queries
   - Views for frequently accessed data combinations

3. SCALABILITY:
   - BIGINT primary keys for large datasets
   - Partitioning-ready design (by date/website_id)
   - Separate tables for different concerns

4. SECURITY:
   - Encrypted storage for sensitive data (auth_password, auth_token)
   - API key hashing
   - Audit trail through created_at/updated_at timestamps

5. MONITORING:
   - Comprehensive logging in scheduler_log
   - Notification tracking
   - Execution metrics and timing

6. FLEXIBILITY:
   - JSON columns for extensible configuration
   - Enum types for controlled vocabularies
   - Metadata columns for future expansion

7. DATA INTEGRITY:
   - Foreign key constraints with appropriate CASCADE actions
   - NOT NULL constraints where appropriate
   - Check constraints through application layer

8. MAINTENANCE:
   - Regular cleanup of old data based on retention policies
   - Index maintenance for optimal performance
   - Backup and recovery considerations
*/