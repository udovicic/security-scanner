-- Migration: Create alert_escalations table
-- Description: Store escalation records and their status

CREATE TABLE IF NOT EXISTS alert_escalations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    website_id INT NOT NULL,
    escalation_level TINYINT NOT NULL DEFAULT 1,
    trigger_reason VARCHAR(255) NOT NULL,
    scan_data JSON NULL,
    notification_results JSON NULL,
    notifications_sent BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'resolved', 'expired') DEFAULT 'active',
    cooldown_until DATETIME NULL,
    resolved_at DATETIME NULL,
    resolution_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,

    INDEX idx_website_status (website_id, status),
    INDEX idx_escalation_level (escalation_level),
    INDEX idx_created_at (created_at),
    INDEX idx_cooldown_until (cooldown_until),
    INDEX idx_status (status)
) ENGINE=InnoDB;