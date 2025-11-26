<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\AlertEscalationService;
use SecurityScanner\Services\NotificationService;
use SecurityScanner\Core\Database;

class AlertEscalationServiceTest extends TestCase
{
    private AlertEscalationService $alertEscalationService;
    private NotificationService $notificationService;
    

    protected function setUp(): void
    {
        parent::setUp();

        $notificationConfig = [
            'smtp_host' => 'test.smtp.com',
            'smtp_port' => 587,
            'smtp_username' => 'test@example.com',
            'smtp_password' => 'password',
            'from_email' => 'scanner@example.com',
            'from_name' => 'Test Scanner'
        ];

        $this->notificationService = new NotificationService($notificationConfig);

        $escalationConfig = [
            'escalation_thresholds' => [
                'consecutive_failures' => 2,
                'failures_in_period' => 3,
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
                'level_2_minutes' => 15,
                'level_3_minutes' => 60
            ],
            'cooldown_hours' => 2
        ];

        $this->alertEscalationService = new AlertEscalationService($this->notificationService, $escalationConfig);
        
    }

    public function test_evaluate_escalation_with_no_failures(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        $scanResult = [
            'success' => true,
            'results' => [
                ['test_name' => 'ssl_test', 'success' => true, 'message' => 'SSL certificate valid'],
                ['test_name' => 'headers_test', 'success' => true, 'message' => 'Security headers present']
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['escalation_level']);
        $this->assertEquals('no_escalation_needed', $result['action']);
    }

    public function test_evaluate_escalation_with_critical_failure(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'ssl_certificate_test',
                    'success' => false,
                    'error_message' => 'SSL certificate expired',
                    'details' => 'Certificate expired 30 days ago'
                ]
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['escalation_level']);
        $this->assertEquals('escalation_triggered', $result['action']);
        $this->assertArrayHasKey('escalation_id', $result);
        $this->assertArrayHasKey('notifications_sent', $result);

        // Verify escalation record was created
        $escalation = $this->database->fetchRow(
            'SELECT * FROM alert_escalations WHERE id = ?',
            [$result['escalation_id']]
        );
        $this->assertNotNull($escalation);
        $this->assertEquals($website['id'], $escalation['website_id']);
        $this->assertEquals(3, $escalation['escalation_level']);
        $this->assertEquals('active', $escalation['status']);
    }

    public function test_evaluate_escalation_with_consecutive_failures(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        // Create consecutive failure executions
        $this->createTestExecution(['website_id' => $website['id'], 'success' => false]);
        $this->createTestExecution(['website_id' => $website['id'], 'success' => false]);

        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'response_time_test',
                    'success' => false,
                    'error_message' => 'Response time exceeded threshold'
                ]
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['escalation_level']);
        $this->assertEquals('escalation_triggered', $result['action']);
    }

    public function test_evaluate_escalation_with_frequent_failures(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        // Create multiple failures within period
        $recentTime = date('Y-m-d H:i:s', time() - 7200); // 2 hours ago
        for ($i = 0; $i < 3; $i++) {
            $this->database->insert('test_executions', [
                'website_id' => $website['id'],
                'test_name' => 'availability_test',
                'status' => 'completed',
                'success' => 0,
                'created_at' => $recentTime,
                'updated_at' => $recentTime
            ]);
        }

        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'availability_test',
                    'success' => false,
                    'error_message' => 'Website not responding'
                ]
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['escalation_level']);
        $this->assertEquals('escalation_triggered', $result['action']);
    }

    public function test_evaluate_escalation_respects_cooldown_period(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        // Create active escalation with cooldown
        $escalationId = $this->database->insert('alert_escalations', [
            'website_id' => $website['id'],
            'escalation_level' => 2,
            'trigger_reason' => 'test_trigger',
            'status' => 'active',
            'cooldown_until' => date('Y-m-d H:i:s', time() + 3600), // 1 hour cooldown
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'ssl_certificate_test',
                    'success' => false,
                    'error_message' => 'SSL certificate expired'
                ]
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['escalation_level']);
        $this->assertEquals('in_cooldown', $result['action']);
        $this->assertArrayHasKey('cooldown_until', $result);
    }

    public function test_evaluate_escalation_does_not_increase_if_level_same_or_higher(): void
    {
        $website = $this->createTestWebsite(['notification_email' => 'admin@example.com']);

        // Create active escalation at level 3
        $this->database->insert('alert_escalations', [
            'website_id' => $website['id'],
            'escalation_level' => 3,
            'trigger_reason' => 'test_trigger',
            'status' => 'active',
            'cooldown_until' => date('Y-m-d H:i:s', time() - 3600), // Expired cooldown
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'response_time_test',
                    'success' => false,
                    'error_message' => 'Response time exceeded'
                ]
            ]
        ];

        $result = $this->alertEscalationService->evaluateEscalation($website, $scanResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['escalation_level']);
        $this->assertEquals('no_escalation_increase_needed', $result['action']);
    }

    public function test_resolve_escalation(): void
    {
        $website = $this->createTestWebsite();

        // Create active escalation
        $escalationId = $this->database->insert('alert_escalations', [
            'website_id' => $website['id'],
            'escalation_level' => 2,
            'trigger_reason' => 'consecutive_failures',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->alertEscalationService->resolveEscalation($website['id'], 'tests_passing');

        $this->assertTrue($result);

        // Verify escalation was resolved
        $escalation = $this->database->fetchRow(
            'SELECT * FROM alert_escalations WHERE id = ?',
            [$escalationId]
        );
        $this->assertEquals('resolved', $escalation['status']);
        $this->assertEquals('tests_passing', $escalation['resolution_reason']);
        $this->assertNotNull($escalation['resolved_at']);
    }

    public function test_resolve_escalation_with_no_active_escalation(): void
    {
        $website = $this->createTestWebsite();

        $result = $this->alertEscalationService->resolveEscalation($website['id']);

        $this->assertTrue($result); // Should succeed even if no active escalation
    }

    public function test_get_escalation_history(): void
    {
        $website = $this->createTestWebsite();

        // Create escalation history
        $oldDate = date('Y-m-d H:i:s', time() - (5 * 24 * 60 * 60)); // 5 days ago
        $this->database->insert('alert_escalations', [
            'website_id' => $website['id'],
            'escalation_level' => 1,
            'trigger_reason' => 'single_failure',
            'status' => 'resolved',
            'created_at' => $oldDate,
            'updated_at' => $oldDate
        ]);

        $this->database->insert('alert_escalations', [
            'website_id' => $website['id'],
            'escalation_level' => 2,
            'trigger_reason' => 'consecutive_failures',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $history = $this->alertEscalationService->getEscalationHistory($website['id'], 30);

        $this->assertIsArray($history);
        $this->assertCount(2, $history);
        $this->assertEquals(2, $history[0]['escalation_level']); // Most recent first
        $this->assertEquals(1, $history[1]['escalation_level']);
    }

    public function test_get_escalation_statistics(): void
    {
        $website1 = $this->createTestWebsite();
        $website2 = $this->createTestWebsite();

        // Create escalations with different levels and statuses
        $this->database->insert('alert_escalations', [
            'website_id' => $website1['id'],
            'escalation_level' => 1,
            'trigger_reason' => 'single_failure',
            'status' => 'resolved',
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
            'resolved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->database->insert('alert_escalations', [
            'website_id' => $website1['id'],
            'escalation_level' => 2,
            'trigger_reason' => 'consecutive_failures',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->database->insert('alert_escalations', [
            'website_id' => $website2['id'],
            'escalation_level' => 3,
            'trigger_reason' => 'critical_test_failure',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $stats = $this->alertEscalationService->getEscalationStatistics(7);

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys([
            'total_escalations',
            'active_escalations',
            'by_level',
            'avg_resolution_time_hours',
            'websites_with_escalations'
        ], $stats);

        $this->assertEquals(3, $stats['total_escalations']);
        $this->assertEquals(2, $stats['active_escalations']);
        $this->assertEquals(2, $stats['websites_with_escalations']);
        $this->assertArrayHasKey(1, $stats['by_level']);
        $this->assertArrayHasKey(2, $stats['by_level']);
        $this->assertArrayHasKey(3, $stats['by_level']);
    }

    public function test_escalation_template_generation(): void
    {
        // Test private method through reflection
        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getEscalationTemplate');
        $method->setAccessible(true);

        // Test email template
        $emailTemplate = $method->invoke($this->alertEscalationService, 'email', 2);
        $this->assertArrayHasKeys(['subject', 'email_body'], $emailTemplate);
        $this->assertStringContainsString('Level 2', $emailTemplate['subject']);
        $this->assertStringContainsString('{{website_name}}', $emailTemplate['subject']);

        // Test SMS template
        $smsTemplate = $method->invoke($this->alertEscalationService, 'sms', 3);
        $this->assertArrayHasKey('sms_body', $smsTemplate);
        $this->assertStringContainsString('Level 3', $smsTemplate['sms_body']);
        $this->assertStringContainsString('URGENT', $smsTemplate['sms_body']);

        // Test webhook template
        $webhookTemplate = $method->invoke($this->alertEscalationService, 'webhook', 1);
        $this->assertArrayHasKey('webhook_body', $webhookTemplate);
        $webhookData = json_decode($webhookTemplate['webhook_body'], true);
        $this->assertEquals('escalation', $webhookData['alert_type']);
        $this->assertEquals(1, $webhookData['escalation_level']);
    }

    public function test_escalation_level_text(): void
    {
        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getEscalationLevelText');
        $method->setAccessible(true);

        $this->assertEquals('Low', $method->invoke($this->alertEscalationService, 1));
        $this->assertEquals('Medium', $method->invoke($this->alertEscalationService, 2));
        $this->assertEquals('Critical', $method->invoke($this->alertEscalationService, 3));
        $this->assertEquals('Unknown', $method->invoke($this->alertEscalationService, 5));
    }

    public function test_failed_tests_extraction(): void
    {
        $scanResult = [
            'success' => false,
            'results' => [
                [
                    'test_name' => 'ssl_certificate_test',
                    'success' => false,
                    'error_message' => 'SSL certificate expired',
                    'details' => 'Certificate expired 30 days ago'
                ],
                [
                    'test_name' => 'security_headers_test',
                    'success' => true,
                    'message' => 'All headers present'
                ],
                [
                    'test_name' => 'response_time_test',
                    'success' => false,
                    'error_message' => 'Response time exceeded',
                    'details' => 'Took 5.2 seconds'
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getFailedTestsFromScan');
        $method->setAccessible(true);

        $failedTests = $method->invoke($this->alertEscalationService, $scanResult);

        $this->assertCount(2, $failedTests);
        $this->assertEquals('ssl_certificate_test', $failedTests[0]['test_name']);
        $this->assertEquals('critical', $failedTests[0]['severity']);
        $this->assertEquals('response_time_test', $failedTests[1]['test_name']);
        $this->assertEquals('medium', $failedTests[1]['severity']);
    }

    public function test_critical_failures_counting(): void
    {
        $failedTests = [
            [
                'test_name' => 'ssl_certificate_test',
                'severity' => 'critical',
                'message' => 'SSL failed'
            ],
            [
                'test_name' => 'response_time_test',
                'severity' => 'medium',
                'message' => 'Response slow'
            ],
            [
                'test_name' => 'security_headers_test',
                'severity' => 'critical',
                'message' => 'Headers missing'
            ]
        ];

        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('countCriticalFailures');
        $method->setAccessible(true);

        $count = $method->invoke($this->alertEscalationService, $failedTests);

        $this->assertEquals(2, $count);
    }

    public function test_test_severity_determination(): void
    {
        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getTestSeverity');
        $method->setAccessible(true);

        $this->assertEquals('critical', $method->invoke($this->alertEscalationService, 'ssl_certificate_test'));
        $this->assertEquals('critical', $method->invoke($this->alertEscalationService, 'security_headers_test'));
        $this->assertEquals('critical', $method->invoke($this->alertEscalationService, 'xss_protection_test'));
        $this->assertEquals('medium', $method->invoke($this->alertEscalationService, 'response_time_test'));
        $this->assertEquals('medium', $method->invoke($this->alertEscalationService, 'unknown_test'));
    }

    public function test_consecutive_failure_count(): void
    {
        $website = $this->createTestWebsite();

        // Create execution history: fail, fail, success, fail, fail
        $executions = [false, false, true, false, false];
        foreach (array_reverse($executions) as $success) {
            $this->database->insert('test_executions', [
                'website_id' => $website['id'],
                'test_name' => 'test',
                'status' => 'completed',
                'success' => $success ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getConsecutiveFailureCount');
        $method->setAccessible(true);

        $count = $method->invoke($this->alertEscalationService, $website['id']);

        $this->assertEquals(2, $count); // Should count 2 most recent failures
    }

    public function test_recent_failure_count(): void
    {
        $website = $this->createTestWebsite();

        // Create failures within the time period
        $recentTime = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
        for ($i = 0; $i < 3; $i++) {
            $this->database->insert('test_executions', [
                'website_id' => $website['id'],
                'test_name' => 'test',
                'status' => 'completed',
                'success' => 0,
                'created_at' => $recentTime,
                'updated_at' => $recentTime
            ]);
        }

        // Create older failure (outside period)
        $oldTime = date('Y-m-d H:i:s', time() - (25 * 3600)); // 25 hours ago
        $this->database->insert('test_executions', [
            'website_id' => $website['id'],
            'test_name' => 'test',
            'status' => 'completed',
            'success' => 0,
            'created_at' => $oldTime,
            'updated_at' => $oldTime
        ]);

        $reflection = new \ReflectionClass($this->alertEscalationService);
        $method = $reflection->getMethod('getRecentFailureCount');
        $method->setAccessible(true);

        $count = $method->invoke($this->alertEscalationService, $website['id'], 24);

        $this->assertEquals(3, $count); // Should only count failures within 24 hours
    }
}