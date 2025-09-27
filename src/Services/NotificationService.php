<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Services\Notifications\EmailNotificationProvider;
use SecurityScanner\Services\Notifications\WebhookNotificationProvider;
use SecurityScanner\Services\Notifications\SmsNotificationProvider;
use SecurityScanner\Services\Notifications\NotificationProviderInterface;

class NotificationService
{
    private Database $db;
    private array $config;
    private array $providers = [];

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();

        $this->config = array_merge([
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@securityscanner.local',
            'from_name' => 'Security Scanner',
            'max_retries' => 3,
            'retry_delay' => 300, // 5 minutes
            'webhook_timeout' => 30,
            'rate_limit_per_hour' => 100
        ], $config);

        $this->initializeProviders();
    }

    private function initializeProviders(): void
    {
        $this->providers['email'] = new EmailNotificationProvider($this->config);
        $this->providers['webhook'] = new WebhookNotificationProvider($this->config);
        $this->providers['sms'] = new SmsNotificationProvider($this->config);
    }

    /**
     * Send test failure notification
     */
    public function sendTestFailureNotification(array $website, array $scanResult): array
    {
        if (empty($website['notification_email'])) {
            return [
                'success' => false,
                'error' => 'No notification email configured for website'
            ];
        }

        if (!$this->checkRateLimit($website['notification_email'])) {
            return [
                'success' => false,
                'error' => 'Rate limit exceeded for this email address'
            ];
        }

        $failedTests = $this->getFailedTestsFromScan($scanResult);

        $template = [
            'subject' => 'Security Scan Alert: {{website_name}} - {{failed_count}} Tests Failed',
            'email_body' => $this->getFailureEmailTemplate()
        ];

        $context = [
            'website_name' => $website['name'],
            'website_url' => $website['url'],
            'scan_time' => date('Y-m-d H:i:s'),
            'total_tests' => count($scanResult['results'] ?? []),
            'failed_count' => count($failedTests),
            'failed_tests' => $failedTests
        ];

        $notificationResult = $this->sendNotification('email', $website['notification_email'], $template, $context, [
            'website_id' => $website['id'],
            'scan_id' => $scanResult['scan_id'] ?? null,
            'notification_type' => 'test_failure'
        ]);

        $this->evaluateEscalation($website, $scanResult);

        return $notificationResult;
    }

    public function evaluateEscalation(array $website, array $scanResult): array
    {
        try {
            if (!class_exists('SecurityScanner\\Services\\AlertEscalationService')) {
                return [
                    'success' => false,
                    'error' => 'AlertEscalationService not available'
                ];
            }

            $escalationService = new \SecurityScanner\Services\AlertEscalationService($this);
            return $escalationService->evaluateEscalation($website, $scanResult);

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send website recovery notification
     */
    public function sendRecoveryNotification(array $website, array $scanResult): array
    {
        if (empty($website['notification_email'])) {
            return [
                'success' => false,
                'error' => 'No notification email configured for website'
            ];
        }

        $subject = "Security Scan Recovery: {$website['name']} - Tests Passed";
        $body = $this->generateRecoveryEmailBody($website, $scanResult);

        return $this->sendEmail(
            $website['notification_email'],
            $subject,
            $body,
            [
                'website_id' => $website['id'],
                'scan_id' => $scanResult['scan_id'] ?? null,
                'notification_type' => 'recovery'
            ]
        );
    }

    /**
     * Send scheduled report
     */
    public function sendScheduledReport(string $recipientEmail, array $reportData, string $period = 'weekly'): array
    {
        $subject = "Security Scanner {$period} Report";
        $body = $this->generateReportEmailBody($reportData, $period);

        return $this->sendEmail(
            $recipientEmail,
            $subject,
            $body,
            [
                'notification_type' => 'scheduled_report',
                'period' => $period
            ]
        );
    }

    /**
     * Send webhook notification
     */
    public function sendWebhookNotification(string $webhookUrl, array $payload): array
    {
        $template = [
            'webhook_body' => json_encode($payload)
        ];

        $context = array_merge($payload, [
            'notification_type' => 'webhook'
        ]);

        $result = $this->sendNotification('webhook', $webhookUrl, $template, $context);

        if ($result['success']) {
            $this->logNotification('webhook_sent', [
                'webhook_url' => $this->maskUrl($webhookUrl),
                'payload_size' => strlen(json_encode($payload))
            ]);
        } else {
            $this->logNotification('webhook_failed', [
                'webhook_url' => $this->maskUrl($webhookUrl),
                'error' => $result['error']
            ]);
        }

        return $result;
    }

    private function maskUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed) {
            return '***';
        }

        $masked = ($parsed['scheme'] ?? 'http') . '://';

        if (isset($parsed['host'])) {
            $host = $parsed['host'];
            if (strlen($host) > 6) {
                $masked .= substr($host, 0, 3) . '***' . substr($host, -3);
            } else {
                $masked .= '***';
            }
        }

        if (isset($parsed['path'])) {
            $masked .= '/***';
        }

        return $masked;
    }

    /**
     * Send SMS notification (placeholder for SMS service integration)
     */
    public function sendSmsNotification(string $phoneNumber, string $message): array
    {
        $template = [
            'sms_body' => $message
        ];

        $context = [
            'notification_type' => 'sms',
            'message' => $message
        ];

        $result = $this->sendNotification('sms', $phoneNumber, $template, $context);

        if ($result['success']) {
            $this->logNotification('sms_sent', [
                'phone_number' => $this->maskPhoneNumber($phoneNumber),
                'message_length' => strlen($message)
            ]);
        } else {
            $this->logNotification('sms_failed', [
                'phone_number' => $this->maskPhoneNumber($phoneNumber),
                'error' => $result['error']
            ]);
        }

        return $result;
    }

    /**
     * Send email notification
     */
    public function sendEmail(string $to, string $subject, string $body, array $metadata = []): array
    {
        try {
            // Validate email address
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'error' => 'Invalid email address'
                ];
            }

            // Create notification record
            $notificationId = $this->db->insert('notifications', [
                'type' => 'email',
                'recipient' => $to,
                'subject' => $subject,
                'body' => $body,
                'metadata' => json_encode($metadata),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Attempt to send email
            $sendResult = $this->sendEmailViaSMTP($to, $subject, $body);

            if ($sendResult['success']) {
                $this->db->update('notifications', [
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notificationId]);

                $this->logNotification('email_sent', [
                    'notification_id' => $notificationId,
                    'recipient' => $this->maskEmail($to),
                    'subject' => $subject
                ]);

                return [
                    'success' => true,
                    'notification_id' => $notificationId
                ];
            } else {
                $this->db->update('notifications', [
                    'status' => 'failed',
                    'error_message' => $sendResult['error'],
                    'retry_count' => 0,
                    'next_retry_at' => date('Y-m-d H:i:s', time() + $this->config['retry_delay']),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notificationId]);

                return [
                    'success' => false,
                    'error' => $sendResult['error'],
                    'notification_id' => $notificationId
                ];
            }

        } catch (\Exception $e) {
            $this->logNotification('email_error', [
                'recipient' => $this->maskEmail($to),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Retry failed notifications
     */
    public function retryFailedNotifications(): array
    {
        $failedNotifications = $this->db->fetchAll(
            "SELECT * FROM notifications
             WHERE status = 'failed'
             AND retry_count < ?
             AND (next_retry_at IS NULL OR next_retry_at <= NOW())
             ORDER BY created_at DESC
             LIMIT 50",
            [$this->config['max_retries']]
        );

        $results = [
            'attempted' => count($failedNotifications),
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];

        foreach ($failedNotifications as $notification) {
            $metadata = json_decode($notification['metadata'], true) ?: [];

            if ($notification['type'] === 'email') {
                $result = $this->sendEmailViaSMTP(
                    $notification['recipient'],
                    $notification['subject'],
                    $notification['body']
                );
            } else {
                $result = ['success' => false, 'error' => 'Unsupported notification type'];
            }

            if ($result['success']) {
                $this->db->update('notifications', [
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'retry_count' => $notification['retry_count'] + 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notification['id']]);

                $results['successful']++;
            } else {
                $nextRetryDelay = $this->config['retry_delay'] * pow(2, $notification['retry_count']);
                $nextRetryAt = date('Y-m-d H:i:s', time() + $nextRetryDelay);

                $this->db->update('notifications', [
                    'retry_count' => $notification['retry_count'] + 1,
                    'next_retry_at' => $nextRetryAt,
                    'error_message' => $result['error'],
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notification['id']]);

                $results['failed']++;
            }

            $results['details'][] = [
                'notification_id' => $notification['id'],
                'type' => $notification['type'],
                'success' => $result['success'],
                'error' => $result['error'] ?? null
            ];
        }

        return $results;
    }

    /**
     * Get notification statistics
     */
    public function getNotificationStatistics(int $days = 7): array
    {
        $stats = [
            'total_sent' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications WHERE status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'total_failed' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            ),
            'total_pending' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications WHERE status = 'pending'"
            ),
            'by_type' => [],
            'recent_failures' => []
        ];

        // Get stats by type
        $typeStats = $this->db->fetchAll(
            "SELECT type, status, COUNT(*) as count
             FROM notifications
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY type, status",
            [$days]
        );

        foreach ($typeStats as $stat) {
            if (!isset($stats['by_type'][$stat['type']])) {
                $stats['by_type'][$stat['type']] = [];
            }
            $stats['by_type'][$stat['type']][$stat['status']] = (int)$stat['count'];
        }

        // Get recent failures
        $stats['recent_failures'] = $this->db->fetchAll(
            "SELECT type, recipient, error_message, created_at
             FROM notifications
             WHERE status = 'failed'
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC
             LIMIT 10",
            [$days]
        );

        return $stats;
    }

    /**
     * Send email via SMTP
     */
    private function sendEmailViaSMTP(string $to, string $subject, string $body): array
    {
        // In a production environment, you would use PHPMailer or similar
        // For this implementation, we'll simulate email sending

        $headers = [
            'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
            'Reply-To: ' . $this->config['from_email'],
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: SecurityScanner/1.0'
        ];

        // Simulate email sending with mail() function
        // In production, replace with proper SMTP implementation
        $success = mail($to, $subject, $body, implode("\r\n", $headers));

        if ($success) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Failed to send email via SMTP'];
        }
    }

    /**
     * Generate failure email body
     */
    private function generateFailureEmailBody(array $website, array $scanResult, array $failedTests): string
    {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #d32f2f; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .website-info { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .test-failure { background: #ffebee; border-left: 4px solid #d32f2f; padding: 10px; margin: 10px 0; }
                .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Security Scan Alert</h1>
                <p>Tests failed for {$website['name']}</p>
            </div>

            <div class='content'>
                <div class='website-info'>
                    <h3>Website Information</h3>
                    <p><strong>Name:</strong> {$website['name']}</p>
                    <p><strong>URL:</strong> <a href='{$website['url']}'>{$website['url']}</a></p>
                    <p><strong>Scan Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <p><strong>Total Tests:</strong> " . count($scanResult['results'] ?? []) . "</p>
                    <p><strong>Failed Tests:</strong> " . count($failedTests) . "</p>
                </div>

                <h3>Failed Tests</h3>";

        foreach ($failedTests as $test) {
            $html .= "
                <div class='test-failure'>
                    <h4>{$test['test_name']}</h4>
                    <p><strong>Error:</strong> {$test['error_message']}</p>
                    <p><strong>Details:</strong> {$test['details']}</p>
                </div>";
        }

        $html .= "
                <p>Please review your website's security configuration and address these issues as soon as possible.</p>
            </div>

            <div class='footer'>
                <p>This alert was generated by Security Scanner. To modify notification settings, please log into your dashboard.</p>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Generate recovery email body
     */
    private function generateRecoveryEmailBody(array $website, array $scanResult): string
    {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #2e7d32; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .website-info { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .success { background: #e8f5e8; border-left: 4px solid #2e7d32; padding: 10px; margin: 10px 0; }
                .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Security Scan Recovery</h1>
                <p>All tests are now passing for {$website['name']}</p>
            </div>

            <div class='content'>
                <div class='website-info'>
                    <h3>Website Information</h3>
                    <p><strong>Name:</strong> {$website['name']}</p>
                    <p><strong>URL:</strong> <a href='{$website['url']}'>{$website['url']}</a></p>
                    <p><strong>Scan Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <p><strong>Total Tests:</strong> " . count($scanResult['results'] ?? []) . "</p>
                </div>

                <div class='success'>
                    <h3>✓ All Tests Passed</h3>
                    <p>Your website has recovered from previous security test failures. All configured security tests are now passing successfully.</p>
                </div>

                <p>Great job addressing the security issues! Continue monitoring your website with regular security scans.</p>
            </div>

            <div class='footer'>
                <p>This notification was generated by Security Scanner. To modify notification settings, please log into your dashboard.</p>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Generate report email body
     */
    private function generateReportEmailBody(array $reportData, string $period): string
    {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #1976d2; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .stats { display: flex; justify-content: space-around; margin: 20px 0; }
                .stat-box { background: #f5f5f5; padding: 15px; text-align: center; border-radius: 5px; }
                .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Security Scanner Report</h1>
                <p>" . ucfirst($period) . " Summary</p>
            </div>

            <div class='content'>
                <div class='stats'>
                    <div class='stat-box'>
                        <h3>" . ($reportData['total_scans'] ?? 0) . "</h3>
                        <p>Total Scans</p>
                    </div>
                    <div class='stat-box'>
                        <h3>" . ($reportData['successful_scans'] ?? 0) . "</h3>
                        <p>Successful Scans</p>
                    </div>
                    <div class='stat-box'>
                        <h3>" . round($reportData['success_rate'] ?? 0, 1) . "%</h3>
                        <p>Success Rate</p>
                    </div>
                </div>

                <h3>Summary</h3>
                <p>During the past {$period}, we monitored " . ($reportData['active_websites'] ?? 0) . " websites and performed " . ($reportData['total_scans'] ?? 0) . " security scans.</p>

                <h3>Key Metrics</h3>
                <ul>
                    <li>Average scan duration: " . round($reportData['avg_scan_time'] ?? 0, 2) . " seconds</li>
                    <li>Most common test failure: " . ($reportData['common_failure'] ?? 'N/A') . "</li>
                    <li>Websites requiring attention: " . ($reportData['websites_with_issues'] ?? 0) . "</li>
                </ul>
            </div>

            <div class='footer'>
                <p>This report was automatically generated by Security Scanner.</p>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Get failed tests from scan result
     */
    private function getFailedTestsFromScan(array $scanResult): array
    {
        $failedTests = [];

        if (isset($scanResult['results'])) {
            foreach ($scanResult['results'] as $result) {
                if (!$result['success']) {
                    $failedTests[] = [
                        'test_name' => $result['test_name'] ?? 'Unknown',
                        'error_message' => $result['error_message'] ?? 'Test failed',
                        'details' => $result['details'] ?? 'No additional details available'
                    ];
                }
            }
        }

        return $failedTests;
    }

    /**
     * Check rate limiting for email address
     */
    private function checkRateLimit(string $email): bool
    {
        $recentNotifications = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notifications
             WHERE recipient = ?
             AND type = 'email'
             AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$email]
        );

        return $recentNotifications < $this->config['rate_limit_per_hour'];
    }

    /**
     * Log notification activity
     */
    private function logNotification(string $action, array $context = []): void
    {
        $this->db->insert('notification_log', [
            'action' => $action,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Mask email address for logging
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***';

        $username = $parts[0];
        $domain = $parts[1];

        $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(0, strlen($username) - 2));
        return $maskedUsername . '@' . $domain;
    }

    /**
     * Mask phone number for logging
     */
    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 3) . str_repeat('*', max(0, strlen($phone) - 6)) . substr($phone, -3);
    }

    public function sendNotification(string $providerType, string $recipient, array $template, array $context, array $metadata = []): array
    {
        if (!isset($this->providers[$providerType])) {
            return [
                'success' => false,
                'error' => "Provider type '{$providerType}' not available"
            ];
        }

        try {
            $notificationId = $this->db->insert('notifications', [
                'type' => $providerType,
                'recipient' => $recipient,
                'subject' => $template['subject'] ?? '',
                'body' => $template['email_body'] ?? $template['body'] ?? '',
                'metadata' => json_encode($metadata),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $provider = $this->providers[$providerType];
            $result = $provider->send($recipient, $template, $context);

            if ($result) {
                $this->db->update('notifications', [
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notificationId]);

                return [
                    'success' => true,
                    'notification_id' => $notificationId
                ];
            } else {
                $this->db->update('notifications', [
                    'status' => 'failed',
                    'error_message' => 'Provider send failed',
                    'retry_count' => 0,
                    'next_retry_at' => date('Y-m-d H:i:s', time() + $this->config['retry_delay']),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $notificationId]);

                return [
                    'success' => false,
                    'error' => 'Failed to send notification',
                    'notification_id' => $notificationId
                ];
            }

        } catch (\Exception $e) {
            $this->logNotification('notification_error', [
                'provider_type' => $providerType,
                'recipient' => $this->maskEmail($recipient),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getFailureEmailTemplate(): string
    {
        return '<html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #d32f2f; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .website-info { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .test-failure { background: #ffebee; border-left: 4px solid #d32f2f; padding: 10px; margin: 10px 0; }
                .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Security Scan Alert</h1>
                <p>Tests failed for {{website_name}}</p>
            </div>

            <div class="content">
                <div class="website-info">
                    <h3>Website Information</h3>
                    <p><strong>Name:</strong> {{website_name}}</p>
                    <p><strong>URL:</strong> <a href="{{website_url}}">{{website_url}}</a></p>
                    <p><strong>Scan Time:</strong> {{scan_time}}</p>
                    <p><strong>Total Tests:</strong> {{total_tests}}</p>
                    <p><strong>Failed Tests:</strong> {{failed_count}}</p>
                </div>

                <h3>Failed Tests</h3>
                {{failed_tests_list}}

                <p>Please review your website\'s security configuration and address these issues as soon as possible.</p>
            </div>

            <div class="footer">
                <p>This alert was generated by Security Scanner. To modify notification settings, please log into your dashboard.</p>
            </div>
        </body>
        </html>';
    }

    public function getProviderStatus(string $providerType): array
    {
        if (!isset($this->providers[$providerType])) {
            return [
                'available' => false,
                'error' => "Provider type '{$providerType}' not found"
            ];
        }

        return array_merge([
            'available' => true
        ], $this->providers[$providerType]->getStatus());
    }

    public function testProvider(string $providerType): bool
    {
        if (!isset($this->providers[$providerType])) {
            return false;
        }

        return $this->providers[$providerType]->test();
    }
}