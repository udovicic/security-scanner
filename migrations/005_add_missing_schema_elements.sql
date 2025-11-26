-- Migration: Add missing schema elements for test suite
-- Date: 2025-11-26
-- Description: Adds missing columns identified from test failures

-- ============================================================================
-- notification_preferences table updates
-- ============================================================================

-- Add notification_channel column (replaces old notification_type usage in some contexts)
ALTER TABLE notification_preferences
ADD COLUMN IF NOT EXISTS notification_channel VARCHAR(50) DEFAULT NULL
AFTER notification_type,
ADD INDEX idx_notification_channel (notification_channel);

-- Add is_enabled column (replaces 'enabled' column)
ALTER TABLE notification_preferences
ADD COLUMN IF NOT EXISTS is_enabled TINYINT(1) DEFAULT 1
AFTER recipient,
ADD INDEX idx_is_enabled (is_enabled);

-- Add conditions column (JSON field for complex notification rules)
ALTER TABLE notification_preferences
ADD COLUMN IF NOT EXISTS conditions JSON DEFAULT NULL
AFTER is_enabled;

-- ============================================================================
-- job_queue table updates
-- ============================================================================

-- Add scheduled_at column for job scheduling
ALTER TABLE job_queue
ADD COLUMN IF NOT EXISTS scheduled_at TIMESTAMP NULL DEFAULT NULL
AFTER created_at,
ADD INDEX idx_scheduled_at (scheduled_at);

-- ============================================================================
-- websites table updates
-- ============================================================================

-- Add total_failures column to track failure count
ALTER TABLE websites
ADD COLUMN IF NOT EXISTS total_failures INT DEFAULT 0
AFTER consecutive_failures;

-- Add last_failure_at timestamp
ALTER TABLE websites
ADD COLUMN IF NOT EXISTS last_failure_at TIMESTAMP NULL DEFAULT NULL
AFTER total_failures,
ADD INDEX idx_last_failure_at (last_failure_at);

-- ============================================================================
-- Data migration for existing records
-- ============================================================================

-- Copy enabled to is_enabled for existing records
UPDATE notification_preferences
SET is_enabled = enabled
WHERE is_enabled IS NULL AND enabled IS NOT NULL;

-- Set default empty conditions array for existing records
UPDATE notification_preferences
SET conditions = '[]'
WHERE conditions IS NULL;

-- Initialize total_failures based on consecutive_failures for existing websites
UPDATE websites
SET total_failures = consecutive_failures
WHERE total_failures = 0 AND consecutive_failures > 0;

-- ============================================================================
-- End of migration
-- ============================================================================
