<?php

namespace SecurityScanner\Core;

class SecurityEventLogger
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private array $criticalEvents;
    private array $eventCategories;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::channel('security_events');

        $this->config = array_merge([
            'enable_real_time_alerts' => true,
            'alert_threshold_minutes' => 5,
            'max_events_per_user_per_hour' => 1000,
            'retention_days' => 90,
            'enable_ip_tracking' => true,
            'enable_geolocation' => false,
            'critical_event_notification' => true
        ], $config);

        $this->initializeCriticalEvents();
        $this->initializeEventCategories();
    }

    public function logEvent(string $eventType, array $data = []): void
    {
        try {
            $eventData = $this->prepareEventData($eventType, $data);

            $eventId = $this->db->insert('security_events', [
                'event_type' => $eventType,
                'category' => $this->getEventCategory($eventType),
                'severity' => $this->getEventSeverity($eventType),
                'user_id' => $eventData['user_id'],
                'ip_address' => $eventData['ip_address'],
                'user_agent' => $eventData['user_agent'],
                'session_id' => $eventData['session_id'],
                'data' => json_encode($eventData['data']),
                'risk_score' => $this->calculateRiskScore($eventType, $eventData),
                'created_at' => date('Y-m-d H:i:s')
            ]);

            if ($this->isCriticalEvent($eventType)) {
                $this->handleCriticalEvent($eventType, $eventData, $eventId);
            }

            $this->checkForAnomalies($eventType, $eventData);

            $this->logger->info("Security event logged", [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'severity' => $this->getEventSeverity($eventType),
                'user_id' => $eventData['user_id'],
                'ip_address' => $eventData['ip_address']
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to log security event", [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    public function getSecurityReport(array $filters = []): array
    {
        try {
            $whereConditions = [];
            $params = [];

            if (isset($filters['start_date'])) {
                $whereConditions[] = "created_at >= ?";
                $params[] = $filters['start_date'];
            }

            if (isset($filters['end_date'])) {
                $whereConditions[] = "created_at <= ?";
                $params[] = $filters['end_date'];
            }

            if (isset($filters['severity'])) {
                $whereConditions[] = "severity = ?";
                $params[] = $filters['severity'];
            }

            if (isset($filters['category'])) {
                $whereConditions[] = "category = ?";
                $params[] = $filters['category'];
            }

            if (isset($filters['user_id'])) {
                $whereConditions[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            $summary = $this->getEventSummary($whereClause, $params);
            $trends = $this->getEventTrends($whereClause, $params);
            $topEvents = $this->getTopEvents($whereClause, $params);
            $riskAnalysis = $this->getRiskAnalysis($whereClause, $params);

            return [
                'summary' => $summary,
                'trends' => $trends,
                'top_events' => $topEvents,
                'risk_analysis' => $riskAnalysis,
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to generate security report", [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);

            return [];
        }
    }

    public function detectAnomalies(int $hours = 24): array
    {
        try {
            $anomalies = [];

            $anomalies = array_merge($anomalies, $this->detectVolumeAnomalies($hours));
            $anomalies = array_merge($anomalies, $this->detectPatternAnomalies($hours));
            $anomalies = array_merge($anomalies, $this->detectLocationAnomalies($hours));
            $anomalies = array_merge($anomalies, $this->detectTimeAnomalies($hours));

            foreach ($anomalies as $anomaly) {
                $this->logEvent('anomaly_detected', $anomaly);
            }

            return $anomalies;

        } catch (\Exception $e) {
            $this->logger->error("Anomaly detection failed", [
                'error' => $e->getMessage(),
                'hours' => $hours
            ]);

            return [];
        }
    }

    public function getSecurityMetrics(int $days = 7): array
    {
        try {
            $startDate = date('Y-m-d H:i:s', time() - ($days * 24 * 3600));

            return [
                'total_events' => $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM security_events WHERE created_at >= ?",
                    [$startDate]
                ),
                'critical_events' => $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM security_events WHERE severity = 'critical' AND created_at >= ?",
                    [$startDate]
                ),
                'unique_users' => $this->db->fetchColumn(
                    "SELECT COUNT(DISTINCT user_id) FROM security_events WHERE user_id IS NOT NULL AND created_at >= ?",
                    [$startDate]
                ),
                'unique_ips' => $this->db->fetchColumn(
                    "SELECT COUNT(DISTINCT ip_address) FROM security_events WHERE created_at >= ?",
                    [$startDate]
                ),
                'average_risk_score' => $this->db->fetchColumn(
                    "SELECT AVG(risk_score) FROM security_events WHERE created_at >= ?",
                    [$startDate]
                ),
                'events_by_category' => $this->getEventsByCategory($startDate),
                'events_by_severity' => $this->getEventsBySeverity($startDate),
                'hourly_distribution' => $this->getHourlyDistribution($startDate)
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to get security metrics", [
                'error' => $e->getMessage(),
                'days' => $days
            ]);

            return [];
        }
    }

    public function getThreatIntelligence(string $ipAddress): array
    {
        try {
            $threats = [];

            $recentEvents = $this->db->fetchAll(
                "SELECT * FROM security_events
                 WHERE ip_address = ?
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY created_at DESC",
                [$ipAddress]
            );

            $eventCounts = [];
            $maxRisk = 0;
            $totalEvents = count($recentEvents);

            foreach ($recentEvents as $event) {
                $eventCounts[$event['event_type']] = ($eventCounts[$event['event_type']] ?? 0) + 1;
                $maxRisk = max($maxRisk, $event['risk_score']);
            }

            $threatLevel = $this->calculateThreatLevel($totalEvents, $maxRisk, $eventCounts);

            return [
                'ip_address' => $ipAddress,
                'threat_level' => $threatLevel,
                'total_events_24h' => $totalEvents,
                'max_risk_score' => $maxRisk,
                'event_types' => $eventCounts,
                'is_blocked' => $this->isIpBlocked($ipAddress),
                'reputation_score' => $this->getIpReputation($ipAddress),
                'geolocation' => $this->getIpGeolocation($ipAddress),
                'last_seen' => $recentEvents[0]['created_at'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to get threat intelligence", [
                'error' => $e->getMessage(),
                'ip_address' => $ipAddress
            ]);

            return [];
        }
    }

    public function blockIp(string $ipAddress, string $reason, ?int $duration = null): bool
    {
        try {
            $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;

            $this->db->insert('blocked_ips', [
                'ip_address' => $ipAddress,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $this->logEvent('ip_blocked', [
                'ip_address' => $ipAddress,
                'reason' => $reason,
                'duration' => $duration
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to block IP", [
                'error' => $e->getMessage(),
                'ip_address' => $ipAddress,
                'reason' => $reason
            ]);

            return false;
        }
    }

    public function unblockIp(string $ipAddress): bool
    {
        try {
            $result = $this->db->delete('blocked_ips', ['ip_address' => $ipAddress]);

            if ($result) {
                $this->logEvent('ip_unblocked', [
                    'ip_address' => $ipAddress
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Failed to unblock IP", [
                'error' => $e->getMessage(),
                'ip_address' => $ipAddress
            ]);

            return false;
        }
    }

    public function isIpBlocked(string $ipAddress): bool
    {
        try {
            $block = $this->db->fetchRow(
                "SELECT * FROM blocked_ips
                 WHERE ip_address = ?
                 AND (expires_at IS NULL OR expires_at > NOW())",
                [$ipAddress]
            );

            return $block !== null;

        } catch (\Exception $e) {
            $this->logger->error("Failed to check IP block status", [
                'error' => $e->getMessage(),
                'ip_address' => $ipAddress
            ]);

            return false;
        }
    }

    public function cleanupOldEvents(): int
    {
        try {
            $retentionDate = date('Y-m-d H:i:s', time() - ($this->config['retention_days'] * 24 * 3600));

            $deletedCount = $this->db->execute(
                "DELETE FROM security_events WHERE created_at < ?",
                [$retentionDate]
            );

            $this->logger->info("Cleaned up old security events", [
                'deleted_count' => $deletedCount,
                'retention_days' => $this->config['retention_days']
            ]);

            return $deletedCount;

        } catch (\Exception $e) {
            $this->logger->error("Failed to cleanup old events", [
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    private function prepareEventData(string $eventType, array $data): array
    {
        return [
            'user_id' => $data['user_id'] ?? $this->getCurrentUserId(),
            'ip_address' => $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'user_agent' => $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            'session_id' => $data['session_id'] ?? (session_id() ?: null),
            'data' => array_diff_key($data, array_flip(['user_id', 'ip_address', 'user_agent', 'session_id']))
        ];
    }

    private function getEventCategory(string $eventType): string
    {
        foreach ($this->eventCategories as $category => $events) {
            if (in_array($eventType, $events)) {
                return $category;
            }
        }

        return 'other';
    }

    private function getEventSeverity(string $eventType): string
    {
        if (in_array($eventType, $this->criticalEvents)) {
            return 'critical';
        }

        $highSeverityEvents = [
            'login_failed', 'account_locked', 'permission_denied',
            'sql_injection_attempt', 'xss_attempt', 'csrf_token_mismatch'
        ];

        if (in_array($eventType, $highSeverityEvents)) {
            return 'high';
        }

        $mediumSeverityEvents = [
            'login_success', 'password_changed', 'email_verified',
            'suspicious_activity', 'rate_limit_exceeded'
        ];

        if (in_array($eventType, $mediumSeverityEvents)) {
            return 'medium';
        }

        return 'low';
    }

    private function calculateRiskScore(string $eventType, array $eventData): int
    {
        $baseScore = match($this->getEventSeverity($eventType)) {
            'critical' => 90,
            'high' => 70,
            'medium' => 40,
            'low' => 10,
            default => 5
        };

        $ipAddress = $eventData['ip_address'];
        $recentEventCount = $this->getRecentEventCount($ipAddress, 3600); // Last hour

        if ($recentEventCount > 50) {
            $baseScore += 30;
        } elseif ($recentEventCount > 20) {
            $baseScore += 15;
        } elseif ($recentEventCount > 10) {
            $baseScore += 5;
        }

        if ($this->isKnownThreatIp($ipAddress)) {
            $baseScore += 40;
        }

        return min(100, max(0, $baseScore));
    }

    private function isCriticalEvent(string $eventType): bool
    {
        return in_array($eventType, $this->criticalEvents);
    }

    private function handleCriticalEvent(string $eventType, array $eventData, int $eventId): void
    {
        if ($this->config['critical_event_notification']) {
            $this->sendCriticalEventAlert($eventType, $eventData, $eventId);
        }

        if ($this->shouldAutoBlock($eventType, $eventData)) {
            $this->blockIp($eventData['ip_address'], "Auto-blocked due to critical event: {$eventType}", 3600);
        }
    }

    private function sendCriticalEventAlert(string $eventType, array $eventData, int $eventId): void
    {
        // This would integrate with the NotificationService
        $this->logger->critical("Critical security event detected", [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'user_id' => $eventData['user_id'],
            'ip_address' => $eventData['ip_address'],
            'data' => $eventData['data']
        ]);
    }

    private function shouldAutoBlock(string $eventType, array $eventData): bool
    {
        $autoBlockEvents = [
            'sql_injection_attempt',
            'multiple_failed_logins',
            'brute_force_detected',
            'malware_detected'
        ];

        return in_array($eventType, $autoBlockEvents);
    }

    private function checkForAnomalies(string $eventType, array $eventData): void
    {
        // Check for rapid succession of events from same IP
        $ipAddress = $eventData['ip_address'];
        $recentCount = $this->getRecentEventCount($ipAddress, 300); // Last 5 minutes

        if ($recentCount > 100) {
            $this->logEvent('anomaly_detected', [
                'type' => 'high_frequency_events',
                'ip_address' => $ipAddress,
                'event_count' => $recentCount,
                'time_window' => '5_minutes'
            ]);
        }
    }

    private function detectVolumeAnomalies(int $hours): array
    {
        $anomalies = [];
        $threshold = $this->config['max_events_per_user_per_hour'];

        $highVolumeUsers = $this->db->fetchAll(
            "SELECT user_id, COUNT(*) as event_count
             FROM security_events
             WHERE user_id IS NOT NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY user_id
             HAVING event_count > ?",
            [$hours, $threshold]
        );

        foreach ($highVolumeUsers as $user) {
            $anomalies[] = [
                'type' => 'high_volume_user',
                'user_id' => $user['user_id'],
                'event_count' => $user['event_count'],
                'threshold' => $threshold,
                'severity' => 'medium'
            ];
        }

        return $anomalies;
    }

    private function detectPatternAnomalies(int $hours): array
    {
        // This is a simplified pattern detection
        // In a real system, you'd use more sophisticated ML algorithms
        return [];
    }

    private function detectLocationAnomalies(int $hours): array
    {
        // Detect unusual geographic patterns
        return [];
    }

    private function detectTimeAnomalies(int $hours): array
    {
        // Detect unusual time-based patterns
        return [];
    }

    private function getEventSummary(string $whereClause, array $params): array
    {
        $sql = "SELECT COUNT(*) as total, severity, COUNT(DISTINCT user_id) as unique_users
                FROM security_events {$whereClause} GROUP BY severity";

        return $this->db->fetchAll($sql, $params);
    }

    private function getEventTrends(string $whereClause, array $params): array
    {
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count
                FROM security_events {$whereClause}
                GROUP BY DATE(created_at)
                ORDER BY date DESC LIMIT 30";

        return $this->db->fetchAll($sql, $params);
    }

    private function getTopEvents(string $whereClause, array $params): array
    {
        $sql = "SELECT event_type, COUNT(*) as count
                FROM security_events {$whereClause}
                GROUP BY event_type
                ORDER BY count DESC LIMIT 10";

        return $this->db->fetchAll($sql, $params);
    }

    private function getRiskAnalysis(string $whereClause, array $params): array
    {
        $sql = "SELECT AVG(risk_score) as avg_risk, MAX(risk_score) as max_risk,
                COUNT(CASE WHEN risk_score >= 70 THEN 1 END) as high_risk_events
                FROM security_events {$whereClause}";

        return $this->db->fetchRow($sql, $params);
    }

    private function getEventsByCategory(string $startDate): array
    {
        return $this->db->fetchAll(
            "SELECT category, COUNT(*) as count FROM security_events WHERE created_at >= ? GROUP BY category",
            [$startDate]
        );
    }

    private function getEventsBySeverity(string $startDate): array
    {
        return $this->db->fetchAll(
            "SELECT severity, COUNT(*) as count FROM security_events WHERE created_at >= ? GROUP BY severity",
            [$startDate]
        );
    }

    private function getHourlyDistribution(string $startDate): array
    {
        return $this->db->fetchAll(
            "SELECT HOUR(created_at) as hour, COUNT(*) as count
             FROM security_events WHERE created_at >= ?
             GROUP BY HOUR(created_at) ORDER BY hour",
            [$startDate]
        );
    }

    private function calculateThreatLevel(int $totalEvents, int $maxRisk, array $eventCounts): string
    {
        if ($maxRisk >= 90 || $totalEvents > 1000) {
            return 'critical';
        } elseif ($maxRisk >= 70 || $totalEvents > 100) {
            return 'high';
        } elseif ($maxRisk >= 40 || $totalEvents > 20) {
            return 'medium';
        }

        return 'low';
    }

    private function getIpReputation(string $ipAddress): int
    {
        // This would integrate with external threat intelligence feeds
        // For now, return a basic score based on local data
        $recentEvents = $this->getRecentEventCount($ipAddress, 86400); // 24 hours
        return max(0, 100 - ($recentEvents * 2));
    }

    private function getIpGeolocation(string $ipAddress): ?array
    {
        if (!$this->config['enable_geolocation']) {
            return null;
        }

        // This would integrate with a geolocation service
        return [
            'country' => 'Unknown',
            'city' => 'Unknown',
            'latitude' => null,
            'longitude' => null
        ];
    }

    private function getRecentEventCount(string $ipAddress, int $seconds): int
    {
        return $this->db->fetchColumn(
            "SELECT COUNT(*) FROM security_events
             WHERE ip_address = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ipAddress, $seconds]
        );
    }

    private function isKnownThreatIp(string $ipAddress): bool
    {
        // Check against known threat databases
        return false;
    }

    private function getCurrentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    private function initializeCriticalEvents(): void
    {
        $this->criticalEvents = [
            'sql_injection_attempt',
            'xss_attack_detected',
            'malware_detected',
            'brute_force_detected',
            'privilege_escalation_attempt',
            'data_breach_suspected',
            'unauthorized_access_attempt',
            'system_compromise_detected'
        ];
    }

    private function initializeEventCategories(): void
    {
        $this->eventCategories = [
            'authentication' => [
                'login_success', 'login_failed', 'logout', 'password_changed',
                'account_locked', 'account_unlocked', 'email_verified'
            ],
            'authorization' => [
                'permission_denied', 'role_assigned', 'role_revoked',
                'permission_granted', 'permission_revoked'
            ],
            'data_access' => [
                'data_viewed', 'data_created', 'data_updated', 'data_deleted',
                'file_uploaded', 'file_downloaded', 'export_generated'
            ],
            'security_attacks' => [
                'sql_injection_attempt', 'xss_attack_detected', 'csrf_token_mismatch',
                'brute_force_detected', 'malware_detected'
            ],
            'system' => [
                'system_startup', 'system_shutdown', 'configuration_changed',
                'backup_completed', 'maintenance_mode'
            ],
            'anomalies' => [
                'anomaly_detected', 'suspicious_activity', 'unusual_pattern',
                'rate_limit_exceeded'
            ]
        ];
    }
}