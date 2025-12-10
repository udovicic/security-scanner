-- Migration: Create notification_preferences table
-- Description: Store notification preferences per website and test

CREATE TABLE IF NOT EXISTS notification_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    website_id INT NOT NULL,
    test_name VARCHAR(100) NULL,
    notification_type VARCHAR(50) NOT NULL,
    notification_channel VARCHAR(50) DEFAULT NULL,
    recipient VARCHAR(255) NOT NULL,
    conditions JSON NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,

    INDEX idx_website_test (website_id, test_name),
    INDEX idx_notification_type (notification_type),
    INDEX idx_notification_channel (notification_channel),
    INDEX idx_is_enabled (is_enabled),
    INDEX idx_website_enabled (website_id, is_enabled)
) ENGINE=InnoDB;