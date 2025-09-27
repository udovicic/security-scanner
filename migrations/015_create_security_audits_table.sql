-- Migration: Create security audits table
-- Description: Store security audit results and compliance reports

CREATE TABLE IF NOT EXISTS security_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
    results JSON NULL,
    summary JSON NULL,
    overall_score DECIMAL(5,2) NULL,
    security_grade VARCHAR(2) NULL,
    critical_issues INT DEFAULT 0,
    high_issues INT DEFAULT 0,
    medium_issues INT DEFAULT 0,
    low_issues INT DEFAULT 0,
    risk_level ENUM('low', 'medium', 'high', 'critical') NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    INDEX idx_status (status),
    INDEX idx_started_at (started_at),
    INDEX idx_completed_at (completed_at),
    INDEX idx_overall_score (overall_score),
    INDEX idx_risk_level (risk_level)
) ENGINE=InnoDB;