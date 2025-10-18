<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;

class AlertEscalationService
{
    private Database $db;
    private NotificationService $notificationService;
    private Logger $logger;
    private array $config;

    public function __construct(NotificationService $notificationService, array $config = [])
    {
        $this->db = Database::getInstance();
        $this->notificationService = $notificationService;
        $this->logger = Logger::channel('alert_escalation');

        $this->config = array_merge([
            'escalation_thresholds' => [
                'consecutive_failures' => 3,
                'failures_in_period' => 5,
                'period_hours' => 24,
                'critical_test_failures' => 1
            ],
            'escalation_levels' => [
                'level_1' => ['email'],
                'level_2' => ['email', 'sms'],
                'level_3' => ['email', 'sms', 'webhook']
            ],
            'escalation_delays' => [
                'level_1_minutes' => 0,
                'level_2_minutes' => 30,
                'level_3_minutes' => 120
            ],
            'cooldown_hours' => 4
        ], $config);
    }

    public function evaluateEscalation(array $website, array $scanResult): array
    {
        try {
            $websiteId = $website['id'];
            $escalationLevel = $this->determineEscalationLevel($websiteId, $scanResult);

            if ($escalationLevel === 0) {
                return [
                    'success' => true,
                    'escalation_level' => 0,
                    'action' => 'no_escalation_needed'
                ];
            }

            $activeEscalation = $this->getActiveEscalation($websiteId);

            if ($activeEscalation && $this->isInCooldownPeriod($activeEscalation)) {
                return [
                    'success' => true,
                    'escalation_level' => $escalationLevel,
                    'action' => 'in_cooldown',
                    'cooldown_until' => $activeEscalation['cooldown_until']
                ];
            }

            if ($activeEscalation && $activeEscalation['escalation_level'] >= $escalationLevel) {
                return [
                    'success' => true,
                    'escalation_level' => $escalationLevel,
                    'action' => 'no_escalation_increase_needed'
                ];
            }

            return $this->triggerEscalation($website, $scanResult, $escalationLevel);

        } catch (\Exception $e) {
            $this->logger->error("Escalation evaluation failed", [
                'website_id' => $website['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function resolveEscalation(int $websiteId, string $reason = 'tests_passing'): bool
    {
        try {
            $activeEscalation = $this->getActiveEscalation($websiteId);

            if (!$activeEscalation) {
                return true;
            }

            $this->db->update('alert_escalations', [
                'status' => 'resolved',
                'resolved_at' => date('Y-m-d H:i:s'),
                'resolution_reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $activeEscalation['id']]);

            $this->logger->info("Escalation resolved", [
                'website_id' => $websiteId,
                'escalation_id' => $activeEscalation['id'],
                'reason' => $reason
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to resolve escalation", [
                'website_id' => $websiteId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getEscalationHistory(int $websiteId, int $days = 30): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM alert_escalations
             WHERE website_id = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC",
            [$websiteId, $days]
        );
    }

    public function getEscalationStatistics(int $days = 7): array
    {
        $stats = [
            'total_escalations' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM alert_escalations WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'active_escalations' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM alert_escalations WHERE status = 'active'"
            ),
            'by_level' => [],
            'avg_resolution_time_hours' => 0,
            'websites_with_escalations' => 0
        ];

        $levelStats = $this->db->fetchAll(
            "SELECT escalation_level, COUNT(*) as count
             FROM alert_escalations
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY escalation_level",
            [$days]
        );

        foreach ($levelStats as $stat) {
            $stats['by_level'][$stat['escalation_level']] = (int)$stat['count'];
        }

        $avgResolutionTime = $this->db->fetchColumn(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at))
             FROM alert_escalations
             WHERE status = 'resolved'
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        $stats['avg_resolution_time_hours'] = round($avgResolutionTime ?: 0, 2);

        $stats['websites_with_escalations'] = $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT website_id) FROM alert_escalations WHERE status = 'active'"
        );

        return $stats;
    }

    private function determineEscalationLevel(int $websiteId, array $scanResult): int
    {
        $failedTests = $this->getFailedTestsFromScan($scanResult);

        if (empty($failedTests)) {
            return 0;
        }

        $criticalFailures = $this->countCriticalFailures($failedTests);
        if ($criticalFailures >= $this->config['escalation_thresholds']['critical_test_failures']) {
            return 3;
        }

        $consecutiveFailures = $this->getConsecutiveFailureCount($websiteId);
        if ($consecutiveFailures >= $this->config['escalation_thresholds']['consecutive_failures']) {
            return 2;
        }

        $recentFailures = $this->getRecentFailureCount($websiteId, $this->config['escalation_thresholds']['period_hours']);
        if ($recentFailures >= $this->config['escalation_thresholds']['failures_in_period']) {
            return 2;
        }

        return 1;
    }

    private function triggerEscalation(array $website, array $scanResult, int $level): array
    {
        $escalationId = $this->createEscalationRecord($website['id'], $level, $scanResult);

        $escalationConfig = $this->config['escalation_levels']["level_{$level}"] ?? ['email'];
        $delay = $this->config['escalation_delays']["level_{$level}_minutes"] ?? 0;

        $scheduledAt = date('Y-m-d H:i:s', time() + ($delay * 60));

        $notificationResults = [];

        foreach ($escalationConfig as $notificationType) {
            $result = $this->sendEscalationNotification($website, $scanResult, $level, $notificationType);
            $notificationResults[$notificationType] = $result;
        }

        $this->updateEscalationStatus($escalationId, $notificationResults);

        $this->logger->info("Escalation triggered", [
            'website_id' => $website['id'],
            'escalation_id' => $escalationId,
            'level' => $level,
            'notifications' => array_keys($escalationConfig),
            'delay_minutes' => $delay
        ]);

        return [
            'success' => true,
            'escalation_id' => $escalationId,
            'escalation_level' => $level,
            'action' => 'escalation_triggered',
            'notifications_sent' => $notificationResults,
            'scheduled_at' => $scheduledAt
        ];
    }

    private function sendEscalationNotification(array $website, array $scanResult, int $level, string $type): array
    {
        $failedTests = $this->getFailedTestsFromScan($scanResult);

        $template = $this->getEscalationTemplate($type, $level);
        $context = [
            'website_name' => $website['name'],
            'website_url' => $website['url'],
            'escalation_level' => $level,
            'escalation_level_text' => $this->getEscalationLevelText($level),
            'failed_count' => count($failedTests),
            'failed_tests' => $failedTests,
            'scan_time' => date('Y-m-d H:i:s'),
            'total_tests' => count($scanResult['results'] ?? [])
        ];

        switch ($type) {
            case 'email':
                if (empty($website['notification_email'])) {
                    return ['success' => false, 'error' => 'No email configured'];
                }
                return $this->notificationService->sendNotification('email', $website['notification_email'], $template, $context, [
                    'escalation_level' => $level,
                    'notification_type' => 'escalation'
                ]);

            case 'sms':
                if (empty($website['notification_phone'])) {
                    return ['success' => false, 'error' => 'No phone configured'];
                }
                return $this->notificationService->sendNotification('sms', $website['notification_phone'], $template, $context, [
                    'escalation_level' => $level,
                    'notification_type' => 'escalation'
                ]);

            case 'webhook':
                if (empty($website['webhook_url'])) {
                    return ['success' => false, 'error' => 'No webhook configured'];
                }
                return $this->notificationService->sendNotification('webhook', $website['webhook_url'], $template, $context, [
                    'escalation_level' => $level,
                    'notification_type' => 'escalation'
                ]);

            default:
                return ['success' => false, 'error' => 'Unknown notification type'];
        }
    }

    private function getEscalationTemplate(string $type, int $level): array
    {
        $levelText = $this->getEscalationLevelText($level);

        switch ($type) {
            case 'email':
                return [
                    'subject' => "ESCALATED ALERT (Level {$level}): {{website_name}} - Critical Security Issues",
                    'email_body' => $this->getEscalationEmailTemplate($level)
                ];

            case 'sms':
                return [
                    'sms_body' => "URGENT: {{website_name}} security alert escalated to Level {$level}. {{failed_count}} tests failed. Immediate attention required."
                ];

            case 'webhook':
                return [
                    'webhook_body' => json_encode([
                        'alert_type' => 'escalation',
                        'escalation_level' => $level,
                        'escalation_level_text' => $levelText,
                        'urgency' => $level >= 3 ? 'critical' : ($level === 2 ? 'high' : 'medium')
                    ])
                ];

            default:
                return [];
        }
    }

    private function getEscalationLevelText(int $level): string
    {
        switch ($level) {
            case 1: return 'Low';
            case 2: return 'Medium';
            case 3: return 'Critical';
            default: return 'Unknown';
        }
    }

    private function getEscalationEmailTemplate(int $level): string
    {
        $urgencyClass = $level >= 3 ? 'critical' : ($level === 2 ? 'high' : 'medium');
        $backgroundColor = $level >= 3 ? '#b71c1c' : ($level === 2 ? '#e65100' : '#f57c00');

        return '<html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: ' . $backgroundColor . '; color: white; padding: 20px; text-align: center; }
                .escalation-badge { font-size: 24px; font-weight: bold; margin: 10px 0; }
                .content { padding: 20px; }
                .website-info { background: #ffebee; border: 2px solid #f44336; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .test-failure { background: #ffcdd2; border-left: 4px solid #d32f2f; padding: 10px; margin: 10px 0; }
                .urgency-notice { background: #fff3e0; border: 2px solid #ff9800; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="escalation-badge">🚨 ESCALATED SECURITY ALERT 🚨</div>
                <h1>Level {{escalation_level}} - {{escalation_level_text}} Priority</h1>
                <p>Critical security issues detected for {{website_name}}</p>
            </div>

            <div class="content">
                <div class="urgency-notice">
                    <h3>⚠️ IMMEDIATE ATTENTION REQUIRED</h3>
                    <p>This alert has been escalated to Level {{escalation_level}} due to repeated failures or critical security test failures. Please address these issues immediately.</p>
                </div>

                <div class="website-info">
                    <h3>Website Information</h3>
                    <p><strong>Name:</strong> {{website_name}}</p>
                    <p><strong>URL:</strong> <a href="{{website_url}}">{{website_url}}</a></p>
                    <p><strong>Escalation Level:</strong> {{escalation_level}} ({{escalation_level_text}})</p>
                    <p><strong>Scan Time:</strong> {{scan_time}}</p>
                    <p><strong>Failed Tests:</strong> {{failed_count}} out of {{total_tests}}</p>
                </div>

                <h3>Failed Security Tests</h3>
                {{failed_tests_list}}

                <div class="urgency-notice">
                    <h3>Next Steps</h3>
                    <ul>
                        <li>Review and fix the failed security tests immediately</li>
                        <li>Check your website\'s security configuration</li>
                        <li>Monitor your dashboard for real-time updates</li>
                        <li>Contact your security team if additional support is needed</li>
                    </ul>
                </div>
            </div>

            <div class="footer">
                <p>This escalated alert was generated by Security Scanner due to critical security failures.</p>
                <p>Escalation Level {{escalation_level}} indicates {{escalation_level_text}} priority - immediate action required.</p>
            </div>
        </body>
        </html>';
    }

    private function createEscalationRecord(int $websiteId, int $level, array $scanResult): int
    {
        return $this->db->insert('alert_escalations', [
            'website_id' => $websiteId,
            'escalation_level' => $level,
            'trigger_reason' => $this->determineTriggerReason($websiteId, $scanResult),
            'scan_data' => json_encode($scanResult),
            'status' => 'active',
            'cooldown_until' => date('Y-m-d H:i:s', time() + ($this->config['cooldown_hours'] * 3600)),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function updateEscalationStatus(int $escalationId, array $notificationResults): void
    {
        $success = array_reduce($notificationResults, function($carry, $result) {
            return $carry && ($result['success'] ?? false);
        }, true);

        // Ensure boolean is properly converted for database
        $notificationsSent = $success ? 1 : 0;

        $this->db->update('alert_escalations', [
            'notification_results' => json_encode($notificationResults),
            'notifications_sent' => $notificationsSent,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $escalationId]);
    }

    private function determineTriggerReason(int $websiteId, array $scanResult): string
    {
        $failedTests = $this->getFailedTestsFromScan($scanResult);
        $criticalFailures = $this->countCriticalFailures($failedTests);

        if ($criticalFailures > 0) {
            return "critical_test_failure ({$criticalFailures} critical tests failed)";
        }

        $consecutiveFailures = $this->getConsecutiveFailureCount($websiteId);
        if ($consecutiveFailures >= $this->config['escalation_thresholds']['consecutive_failures']) {
            return "consecutive_failures ({$consecutiveFailures} in a row)";
        }

        $recentFailures = $this->getRecentFailureCount($websiteId, $this->config['escalation_thresholds']['period_hours']);
        if ($recentFailures >= $this->config['escalation_thresholds']['failures_in_period']) {
            return "frequent_failures ({$recentFailures} in {$this->config['escalation_thresholds']['period_hours']} hours)";
        }

        return 'unknown';
    }

    private function getFailedTestsFromScan(array $scanResult): array
    {
        $failedTests = [];

        if (isset($scanResult['results'])) {
            foreach ($scanResult['results'] as $result) {
                if (!($result['success'] ?? true)) {
                    $failedTests[] = [
                        'test_name' => $result['test_name'] ?? 'Unknown',
                        'message' => $result['error_message'] ?? $result['message'] ?? 'Test failed',
                        'severity' => $this->getTestSeverity($result['test_name'] ?? ''),
                        'details' => $result['details'] ?? 'No additional details available'
                    ];
                }
            }
        }

        return $failedTests;
    }

    private function countCriticalFailures(array $failedTests): int
    {
        return count(array_filter($failedTests, function($test) {
            return ($test['severity'] ?? 'medium') === 'critical';
        }));
    }

    private function getTestSeverity(string $testName): string
    {
        $criticalTests = [
            'ssl_certificate_test',
            'security_headers_test',
            'csrf_protection_test',
            'sql_injection_test',
            'xss_protection_test'
        ];

        return in_array($testName, $criticalTests) ? 'critical' : 'medium';
    }

    private function getConsecutiveFailureCount(int $websiteId): int
    {
        $recentExecutions = $this->db->fetchAll(
            "SELECT success FROM test_executions
             WHERE website_id = ?
             ORDER BY created_at DESC
             LIMIT 10",
            [$websiteId]
        );

        $consecutive = 0;
        foreach ($recentExecutions as $execution) {
            if (!$execution['success']) {
                $consecutive++;
            } else {
                break;
            }
        }

        return $consecutive;
    }

    private function getRecentFailureCount(int $websiteId, int $hours): int
    {
        return $this->db->fetchColumn(
            "SELECT COUNT(*) FROM test_executions
             WHERE website_id = ?
             AND success = 0
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$websiteId, $hours]
        );
    }

    private function getActiveEscalation(int $websiteId): ?array
    {
        $escalation = $this->db->fetchRow(
            "SELECT * FROM alert_escalations
             WHERE website_id = ?
             AND status = 'active'
             ORDER BY created_at DESC
             LIMIT 1",
            [$websiteId]
        );

        return $escalation ?: null;
    }

    private function isInCooldownPeriod(array $escalation): bool
    {
        return !empty($escalation['cooldown_until']) &&
               strtotime($escalation['cooldown_until']) > time();
    }
}