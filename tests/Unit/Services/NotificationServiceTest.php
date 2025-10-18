<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\NotificationService;
use SecurityScanner\Core\Database;

class NotificationServiceTest extends TestCase
{
    private NotificationService $notificationService;
    

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'smtp_host' => 'test.smtp.com',
            'smtp_port' => 587,
            'smtp_username' => 'test@example.com',
            'smtp_password' => 'password',
            'from_email' => 'scanner@example.com',
            'from_name' => 'Test Scanner',
            'max_retries' => 2,
            'rate_limit_per_hour' => 50
        ];

        $this->notificationService = new NotificationService($config);
        
    }

    public function test_send_test_failure_notification_with_valid_data(): void
    {
        $website = [
            'id' => 1,
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'notification_email' => 'admin@example.com'
        ];

        $scanResult = [
            'success' => false,
            'results' => [
                'ssl_certificate_test' => [
                    'status' => 'failed',
                    'message' => 'SSL certificate expired',
                    'details' => ['expiry_date' => '2023-01-01']
                ],
                'security_headers_test' => [
                    'status' => 'passed',
                    'message' => 'All security headers present'
                ]
            ]
        ];

        $result = $this->notificationService->sendTestFailureNotification($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('notification_id', $result);
    }

    public function test_send_test_failure_notification_fails_without_email(): void
    {
        $website = [
            'id' => 1,
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'notification_email' => '' // No email configured
        ];

        $scanResult = [
            'success' => false,
            'results' => [
                'ssl_certificate_test' => [
                    'status' => 'failed',
                    'message' => 'SSL certificate expired'
                ]
            ]
        ];

        $result = $this->notificationService->sendTestFailureNotification($website, $scanResult);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No notification email', $result['error']);
    }

    public function test_send_recovery_notification(): void
    {
        $website = [
            'id' => 1,
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'notification_email' => 'admin@example.com'
        ];

        $scanResult = [
            'success' => true,
            'results' => [
                'ssl_certificate_test' => [
                    'status' => 'passed',
                    'message' => 'SSL certificate is valid'
                ]
            ]
        ];

        $result = $this->notificationService->sendRecoveryNotification($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('notification_id', $result);
    }

    public function test_send_custom_notification(): void
    {
        $recipients = ['test@example.com', 'admin@example.com'];
        $template = [
            'subject' => 'Custom Alert: {{website_name}}',
            'email_body' => 'Website {{website_name}} needs attention.'
        ];
        $context = [
            'website_name' => 'Example.com'
        ];

        $result = $this->notificationService->sendCustomNotification($recipients, $template, $context);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['sent_count']);
        $this->assertEquals(0, $result['failed_count']);
    }

    public function test_send_webhook_notification(): void
    {
        $webhookUrl = 'https://hooks.example.com/security-scan';
        $payload = [
            'event' => 'test_failure',
            'website' => 'example.com',
            'tests_failed' => 2,
            'timestamp' => time()
        ];

        $result = $this->notificationService->sendWebhookNotification($webhookUrl, $payload);

        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['success', 'response_code'], $result);
    }

    public function test_send_sms_notification(): void
    {
        $phoneNumber = '+1234567890';
        $message = 'Security scan alert: Example.com has 2 failed tests.';

        $result = $this->notificationService->sendSmsNotification($phoneNumber, $message);

        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['success'], $result);
    }

    public function test_rate_limiting(): void
    {
        $email = 'test@example.com';

        // First check should pass
        $this->assertTrue($this->notificationService->checkRateLimit($email));

        // Simulate many notifications
        for ($i = 0; $i < 55; $i++) { // Exceed rate limit of 50
            $this->notificationService->recordNotificationSent($email);
        }

        // Should now be rate limited
        $this->assertFalse($this->notificationService->checkRateLimit($email));
    }

    public function test_get_notification_history(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'test@example.com']);

        // Send a notification to create history
        $scanResult = [
            'success' => false,
            'results' => [
                'ssl_certificate_test' => [
                    'status' => 'failed',
                    'message' => 'SSL certificate expired'
                ]
            ]
        ];

        $this->notificationService->sendTestFailureNotification($website, $scanResult);

        $history = $this->notificationService->getNotificationHistory($website['id']);

        $this->assertIsArray($history);
        $this->assertGreaterThan(0, count($history));
        $this->assertArrayHasKeys(['id', 'type', 'recipient', 'status', 'sent_at'], $history[0]);
    }

    public function test_get_notification_by_id(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'test@example.com']);

        // Send a notification
        $scanResult = [
            'success' => false,
            'results' => [
                'ssl_certificate_test' => [
                    'status' => 'failed',
                    'message' => 'Test failure'
                ]
            ]
        ];

        $result = $this->notificationService->sendTestFailureNotification($website, $scanResult);
        $notificationId = $result['notification_id'];

        $notification = $this->notificationService->getNotificationById($notificationId);

        $this->assertNotNull($notification);
        $this->assertEquals($notificationId, $notification['id']);
        $this->assertEquals('test@example.com', $notification['recipient']);
    }

    public function test_retry_failed_notification(): void
    {
        // Create a failed notification in the database
        $notificationId = $this->database->insert('notifications', [
            'type' => 'email',
            'recipient' => 'test@example.com',
            'subject' => 'Test',
            'content' => 'Test content',
            'status' => 'failed',
            'retry_count' => 1,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->notificationService->retryFailedNotification($notificationId);

        $this->assertTrue($result['success']);

        // Verify retry count was incremented
        $notification = $this->notificationService->getNotificationById($notificationId);
        $this->assertEquals(2, $notification['retry_count']);
    }

    public function test_get_notification_statistics(): void
    {
        $website = $this->createTestWebsite();

        // Create some notifications with different statuses
        $this->database->insert('notifications', [
            'website_id' => $website['id'],
            'type' => 'email',
            'recipient' => 'test@example.com',
            'status' => 'sent',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $this->database->insert('notifications', [
            'website_id' => $website['id'],
            'type' => 'email',
            'recipient' => 'test@example.com',
            'status' => 'failed',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $stats = $this->notificationService->getNotificationStatistics($website['id']);

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys(['total_notifications', 'success_rate', 'by_type', 'by_status'], $stats);
        $this->assertEquals(2, $stats['total_notifications']);
        $this->assertIsNumeric($stats['success_rate']);
    }

    public function test_template_rendering(): void
    {
        $template = 'Hello {{name}}, your website {{website}} has {{count}} issues.';
        $context = [
            'name' => 'John',
            'website' => 'example.com',
            'count' => 3
        ];

        $rendered = $this->notificationService->renderTemplate($template, $context);

        $this->assertEquals('Hello John, your website example.com has 3 issues.', $rendered);
    }

    public function test_template_rendering_with_missing_variables(): void
    {
        $template = 'Hello {{name}}, your website {{website}} has {{missing_var}} issues.';
        $context = [
            'name' => 'John',
            'website' => 'example.com'
        ];

        $rendered = $this->notificationService->renderTemplate($template, $context);

        $this->assertEquals('Hello John, your website example.com has  issues.', $rendered);
    }

    public function test_validate_email_address(): void
    {
        $this->assertTrue($this->notificationService->validateEmailAddress('test@example.com'));
        $this->assertTrue($this->notificationService->validateEmailAddress('user.name+tag@domain.co.uk'));
        $this->assertFalse($this->notificationService->validateEmailAddress('invalid-email'));
        $this->assertFalse($this->notificationService->validateEmailAddress('test@'));
        $this->assertFalse($this->notificationService->validateEmailAddress('@example.com'));
    }

    public function test_validate_phone_number(): void
    {
        $this->assertTrue($this->notificationService->validatePhoneNumber('+1234567890'));
        $this->assertTrue($this->notificationService->validatePhoneNumber('+44 20 7946 0958'));
        $this->assertFalse($this->notificationService->validatePhoneNumber('123'));
        $this->assertFalse($this->notificationService->validatePhoneNumber('invalid-phone'));
    }

    public function test_validate_webhook_url(): void
    {
        $this->assertTrue($this->notificationService->validateWebhookUrl('https://hooks.example.com/webhook'));
        $this->assertTrue($this->notificationService->validateWebhookUrl('http://localhost:3000/webhook'));
        $this->assertFalse($this->notificationService->validateWebhookUrl('invalid-url'));
        $this->assertFalse($this->notificationService->validateWebhookUrl('ftp://example.com'));
    }

    public function test_cleanup_old_notifications(): void
    {
        $website = $this->createTestWebsite();

        // Create old notifications (30+ days ago)
        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('notifications', [
            'website_id' => $website['id'],
            'type' => 'email',
            'recipient' => 'test@example.com',
            'status' => 'sent',
            'created_at' => $oldDate
        ]);

        // Create recent notification
        $this->database->insert('notifications', [
            'website_id' => $website['id'],
            'type' => 'email',
            'recipient' => 'test@example.com',
            'status' => 'sent',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->notificationService->cleanupOldNotifications(30);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['deleted_count']);

        // Verify only recent notification remains
        $remaining = $this->database->fetchAll('SELECT * FROM notifications WHERE website_id = ?', [$website['id']]);
        $this->assertCount(1, $remaining);
    }

    public function test_get_notification_preferences(): void
    {
        $website = $this->createTestWebsite();

        $preferences = $this->notificationService->getNotificationPreferences($website['id']);

        $this->assertIsArray($preferences);
        $this->assertArrayHasKeys(['email_enabled', 'webhook_enabled', 'sms_enabled'], $preferences);
    }

    public function test_update_notification_preferences(): void
    {
        $website = $this->createTestWebsite();

        $newPreferences = [
            'email_enabled' => false,
            'webhook_enabled' => true,
            'sms_enabled' => false,
            'failure_threshold' => 3
        ];

        $result = $this->notificationService->updateNotificationPreferences($website['id'], $newPreferences);

        $this->assertTrue($result['success']);

        // Verify preferences were updated
        $updated = $this->notificationService->getNotificationPreferences($website['id']);
        $this->assertFalse($updated['email_enabled']);
        $this->assertTrue($updated['webhook_enabled']);
        $this->assertEquals(3, $updated['failure_threshold']);
    }

    public function test_should_send_notification_based_on_preferences(): void
    {
        $website = $this->createTestWebsite();

        // Set notification preferences
        $this->notificationService->updateNotificationPreferences($website['id'], [
            'email_enabled' => true,
            'failure_threshold' => 2
        ]);

        // Should not send notification for first failure
        $this->assertFalse($this->notificationService->shouldSendNotification($website['id'], 'failure', 1));

        // Should send notification after threshold is reached
        $this->assertTrue($this->notificationService->shouldSendNotification($website['id'], 'failure', 2));

        // Should always send recovery notifications
        $this->assertTrue($this->notificationService->shouldSendNotification($website['id'], 'recovery', 1));
    }
}