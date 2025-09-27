-- Migration: Create notification_templates table
-- Description: Store customizable notification templates

CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    template_type ENUM('test_failure', 'recovery', 'escalation', 'scheduled_report') NOT NULL,
    notification_channel ENUM('email', 'sms', 'webhook') NOT NULL,
    subject_template TEXT NULL,
    body_template TEXT NOT NULL,
    variables JSON NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY unique_template (name, template_type, notification_channel),
    INDEX idx_template_type (template_type),
    INDEX idx_notification_channel (notification_channel),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;