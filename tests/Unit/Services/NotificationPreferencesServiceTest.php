<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\NotificationPreferencesService;
use SecurityScanner\Core\Database;

class NotificationPreferencesServiceTest extends TestCase
{
    private NotificationPreferencesService $preferencesService;
    

    protected function setUp(): void
    {
        parent::setUp();
        $this->preferencesService = new NotificationPreferencesService();
        
    }

    public function test_set_website_preferences(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'conditions' => [
                    ['field' => 'severity', 'operator' => 'equals', 'value' => 'high']
                ],
                'is_enabled' => true
            ],
            [
                'notification_type' => 'recovery',
                'notification_channel' => 'sms',
                'recipient' => '+1234567890',
                'conditions' => [],
                'is_enabled' => true
            ]
        ];

        $result = $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $this->assertTrue($result);

        // Verify preferences were saved
        $savedPreferences = $this->preferencesService->getWebsitePreferences($website['id']);
        $this->assertCount(2, $savedPreferences);

        // Check first preference
        $this->assertEquals('test_failure', $savedPreferences[0]['notification_type']);
        $this->assertEquals('email', $savedPreferences[0]['notification_channel']);
        $this->assertEquals('admin@example.com', $savedPreferences[0]['recipient']);
        $this->assertEquals([['field' => 'severity', 'operator' => 'equals', 'value' => 'high']], $savedPreferences[0]['conditions']);

        // Check second preference
        $this->assertEquals('recovery', $savedPreferences[1]['notification_type']);
        $this->assertEquals('sms', $savedPreferences[1]['notification_channel']);
        $this->assertEquals('+1234567890', $savedPreferences[1]['recipient']);
        $this->assertEquals([], $savedPreferences[1]['conditions']);
    }

    public function test_set_website_preferences_replaces_existing(): void
    {
        $website = $this->createTestWebsite();

        // Set initial preferences
        $initialPreferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'old@example.com',
                'is_enabled' => true
            ]
        ];

        $this->preferencesService->setWebsitePreferences($website['id'], $initialPreferences);

        // Set new preferences (should replace)
        $newPreferences = [
            [
                'notification_type' => 'escalation',
                'notification_channel' => 'webhook',
                'recipient' => 'https://webhook.example.com',
                'is_enabled' => true
            ]
        ];

        $result = $this->preferencesService->setWebsitePreferences($website['id'], $newPreferences);

        $this->assertTrue($result);

        // Verify old preferences were replaced
        $savedPreferences = $this->preferencesService->getWebsitePreferences($website['id']);
        $this->assertCount(1, $savedPreferences);
        $this->assertEquals('escalation', $savedPreferences[0]['notification_type']);
        $this->assertEquals('webhook', $savedPreferences[0]['notification_channel']);
    }

    public function test_set_test_specific_preferences(): void
    {
        $website = $this->createTestWebsite();
        $testName = 'ssl_certificate_test';

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'ssl-admin@example.com',
                'conditions' => [
                    ['field' => 'certificate_days_remaining', 'operator' => 'less_than', 'value' => 30]
                ],
                'is_enabled' => true
            ]
        ];

        $result = $this->preferencesService->setTestSpecificPreferences($website['id'], $testName, $preferences);

        $this->assertTrue($result);

        // Verify test-specific preferences were saved
        $savedPreferences = $this->preferencesService->getTestSpecificPreferences($website['id'], $testName);
        $this->assertCount(1, $savedPreferences);
        $this->assertEquals($testName, $savedPreferences[0]['test_name']);
        $this->assertEquals('ssl-admin@example.com', $savedPreferences[0]['recipient']);
    }

    public function test_get_all_preferences_for_website(): void
    {
        $website = $this->createTestWebsite();

        // Set website-level preferences
        $websitePrefs = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $websitePrefs);

        // Set test-specific preferences
        $testPrefs = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'sms',
                'recipient' => '+1234567890',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setTestSpecificPreferences($website['id'], 'ssl_test', $testPrefs);

        $allPreferences = $this->preferencesService->getAllPreferencesForWebsite($website['id']);

        $this->assertArrayHasKeys(['website_level', 'test_specific'], $allPreferences);
        $this->assertCount(1, $allPreferences['website_level']);
        $this->assertArrayHasKey('ssl_test', $allPreferences['test_specific']);
        $this->assertCount(1, $allPreferences['test_specific']['ssl_test']);
    }

    public function test_get_applicable_preferences_prefers_test_specific(): void
    {
        $website = $this->createTestWebsite();
        $testName = 'ssl_certificate_test';

        // Set website-level preference
        $websitePrefs = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'general@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $websitePrefs);

        // Set test-specific preference
        $testPrefs = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'ssl-specific@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setTestSpecificPreferences($website['id'], $testName, $testPrefs);

        $applicable = $this->preferencesService->getApplicablePreferences($website['id'], $testName, 'test_failure');

        // Should return test-specific preference, not website-level
        $this->assertCount(1, $applicable);
        $this->assertEquals('ssl-specific@example.com', $applicable[0]['recipient']);
        $this->assertEquals($testName, $applicable[0]['test_name']);
    }

    public function test_get_applicable_preferences_falls_back_to_website_level(): void
    {
        $website = $this->createTestWebsite();
        $testName = 'response_time_test';

        // Set only website-level preference
        $websitePrefs = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'general@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $websitePrefs);

        $applicable = $this->preferencesService->getApplicablePreferences($website['id'], $testName, 'test_failure');

        // Should return website-level preference
        $this->assertCount(1, $applicable);
        $this->assertEquals('general@example.com', $applicable[0]['recipient']);
        $this->assertNull($applicable[0]['test_name']);
    }

    public function test_should_send_notification_with_matching_conditions(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'conditions' => [
                    ['field' => 'severity', 'operator' => 'equals', 'value' => 'high']
                ],
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $context = ['severity' => 'high', 'test_name' => 'ssl_test'];

        $shouldSend = $this->preferencesService->shouldSendNotification($website['id'], 'ssl_test', 'test_failure', $context);

        $this->assertTrue($shouldSend);
    }

    public function test_should_send_notification_with_non_matching_conditions(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'conditions' => [
                    ['field' => 'severity', 'operator' => 'equals', 'value' => 'high']
                ],
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $context = ['severity' => 'low', 'test_name' => 'ssl_test'];

        $shouldSend = $this->preferencesService->shouldSendNotification($website['id'], 'ssl_test', 'test_failure', $context);

        $this->assertFalse($shouldSend);
    }

    public function test_should_send_notification_default_behavior(): void
    {
        $website = $this->createTestWebsite();

        // No preferences set, should use default behavior
        $shouldSendFailure = $this->preferencesService->shouldSendNotification($website['id'], 'ssl_test', 'test_failure', []);
        $shouldSendRecovery = $this->preferencesService->shouldSendNotification($website['id'], 'ssl_test', 'recovery', []);
        $shouldSendReport = $this->preferencesService->shouldSendNotification($website['id'], 'ssl_test', 'scheduled_report', []);

        $this->assertTrue($shouldSendFailure); // Default true for test_failure
        $this->assertTrue($shouldSendRecovery); // Default true for recovery
        $this->assertFalse($shouldSendReport); // Default false for scheduled_report
    }

    public function test_get_notification_recipients(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'conditions' => [
                    ['field' => 'severity', 'operator' => 'equals', 'value' => 'high']
                ],
                'is_enabled' => true
            ],
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'sms',
                'recipient' => '+1234567890',
                'conditions' => [
                    ['field' => 'severity', 'operator' => 'equals', 'value' => 'high']
                ],
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $context = ['severity' => 'high'];
        $recipients = $this->preferencesService->getNotificationRecipients($website['id'], 'ssl_test', 'test_failure', $context);

        $this->assertCount(2, $recipients);
        $this->assertEquals('email', $recipients[0]['channel']);
        $this->assertEquals('admin@example.com', $recipients[0]['recipient']);
        $this->assertEquals('sms', $recipients[1]['channel']);
        $this->assertEquals('+1234567890', $recipients[1]['recipient']);
    }

    public function test_update_preference(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'old@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $savedPreferences = $this->preferencesService->getWebsitePreferences($website['id']);
        $preferenceId = $savedPreferences[0]['id'];

        $updates = [
            'recipient' => 'new@example.com',
            'notification_channel' => 'sms',
            'is_enabled' => false
        ];

        $result = $this->preferencesService->updatePreference($preferenceId, $updates);

        $this->assertTrue($result);

        // Verify updates
        $updatedPrefs = $this->preferencesService->getWebsitePreferences($website['id']);
        $this->assertEquals('new@example.com', $updatedPrefs[0]['recipient']);
        $this->assertEquals('sms', $updatedPrefs[0]['notification_channel']);
        $this->assertFalse((bool)$updatedPrefs[0]['is_enabled']);
    }

    public function test_delete_preference(): void
    {
        $website = $this->createTestWebsite();

        $preferences = [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin@example.com',
                'is_enabled' => true
            ]
        ];
        $this->preferencesService->setWebsitePreferences($website['id'], $preferences);

        $savedPreferences = $this->preferencesService->getWebsitePreferences($website['id']);
        $preferenceId = $savedPreferences[0]['id'];

        $result = $this->preferencesService->deletePreference($preferenceId);

        $this->assertTrue($result);

        // Verify deletion
        $remainingPrefs = $this->preferencesService->getWebsitePreferences($website['id']);
        $this->assertEmpty($remainingPrefs);
    }

    public function test_delete_nonexistent_preference_returns_false(): void
    {
        $result = $this->preferencesService->deletePreference(999999);
        $this->assertFalse($result);
    }

    public function test_get_preferences_statistics(): void
    {
        $website1 = $this->createTestWebsite();
        $website2 = $this->createTestWebsite();

        // Add various preferences
        $this->preferencesService->setWebsitePreferences($website1['id'], [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'admin1@example.com',
                'is_enabled' => true
            ]
        ]);

        $this->preferencesService->setWebsitePreferences($website2['id'], [
            [
                'notification_type' => 'recovery',
                'notification_channel' => 'sms',
                'recipient' => '+1234567890',
                'is_enabled' => false
            ]
        ]);

        $this->preferencesService->setTestSpecificPreferences($website1['id'], 'ssl_test', [
            [
                'notification_type' => 'escalation',
                'notification_channel' => 'webhook',
                'recipient' => 'https://webhook.example.com',
                'is_enabled' => true
            ]
        ]);

        $stats = $this->preferencesService->getPreferencesStatistics();

        $this->assertArrayHasKeys([
            'total_preferences',
            'enabled_preferences',
            'website_level',
            'test_specific',
            'by_channel',
            'by_type',
            'websites_with_preferences'
        ], $stats);

        $this->assertEquals(3, $stats['total_preferences']);
        $this->assertEquals(2, $stats['enabled_preferences']);
        $this->assertEquals(2, $stats['website_level']);
        $this->assertEquals(1, $stats['test_specific']);
        $this->assertEquals(2, $stats['websites_with_preferences']);

        $this->assertArrayHasKey('email', $stats['by_channel']);
        $this->assertArrayHasKey('sms', $stats['by_channel']);
        $this->assertArrayHasKey('webhook', $stats['by_channel']);

        $this->assertArrayHasKey('test_failure', $stats['by_type']);
        $this->assertArrayHasKey('recovery', $stats['by_type']);
        $this->assertArrayHasKey('escalation', $stats['by_type']);
    }

    public function test_copy_preferences_from_website(): void
    {
        $sourceWebsite = $this->createTestWebsite();
        $targetWebsite = $this->createTestWebsite();

        // Set preferences for source website
        $this->preferencesService->setWebsitePreferences($sourceWebsite['id'], [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'email',
                'recipient' => 'source@example.com',
                'is_enabled' => true
            ]
        ]);

        $this->preferencesService->setTestSpecificPreferences($sourceWebsite['id'], 'ssl_test', [
            [
                'notification_type' => 'test_failure',
                'notification_channel' => 'sms',
                'recipient' => '+1234567890',
                'is_enabled' => true
            ]
        ]);

        $result = $this->preferencesService->copyPreferencesFromWebsite($sourceWebsite['id'], $targetWebsite['id']);

        $this->assertTrue($result);

        // Verify preferences were copied
        $targetPrefs = $this->preferencesService->getAllPreferencesForWebsite($targetWebsite['id']);

        $this->assertCount(1, $targetPrefs['website_level']);
        $this->assertEquals('source@example.com', $targetPrefs['website_level'][0]['recipient']);
        $this->assertEquals($targetWebsite['id'], $targetPrefs['website_level'][0]['website_id']);

        $this->assertArrayHasKey('ssl_test', $targetPrefs['test_specific']);
        $this->assertCount(1, $targetPrefs['test_specific']['ssl_test']);
        $this->assertEquals('+1234567890', $targetPrefs['test_specific']['ssl_test'][0]['recipient']);
        $this->assertEquals($targetWebsite['id'], $targetPrefs['test_specific']['ssl_test'][0]['website_id']);
    }

    public function test_evaluate_conditions_with_various_operators(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->preferencesService);
        $method = $reflection->getMethod('evaluateConditions');
        $method->setAccessible(true);

        // Test equals
        $conditions = [['field' => 'severity', 'operator' => 'equals', 'value' => 'high']];
        $context = ['severity' => 'high'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test not_equals
        $conditions = [['field' => 'severity', 'operator' => 'not_equals', 'value' => 'low']];
        $context = ['severity' => 'high'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test greater_than
        $conditions = [['field' => 'count', 'operator' => 'greater_than', 'value' => 5]];
        $context = ['count' => 10];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test less_than
        $conditions = [['field' => 'count', 'operator' => 'less_than', 'value' => 10]];
        $context = ['count' => 5];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test contains
        $conditions = [['field' => 'message', 'operator' => 'contains', 'value' => 'error']];
        $context = ['message' => 'SSL error detected'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test not_contains
        $conditions = [['field' => 'message', 'operator' => 'not_contains', 'value' => 'success']];
        $context = ['message' => 'SSL error detected'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test in_array
        $conditions = [['field' => 'test_name', 'operator' => 'in_array', 'value' => ['ssl_test', 'headers_test']]];
        $context = ['test_name' => 'ssl_test'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));

        // Test not_in_array
        $conditions = [['field' => 'test_name', 'operator' => 'not_in_array', 'value' => ['performance_test']]];
        $context = ['test_name' => 'ssl_test'];
        $this->assertTrue($method->invoke($this->preferencesService, $conditions, $context));
    }

    public function test_evaluate_conditions_with_failing_conditions(): void
    {
        $reflection = new \ReflectionClass($this->preferencesService);
        $method = $reflection->getMethod('evaluateConditions');
        $method->setAccessible(true);

        // Test failing equals
        $conditions = [['field' => 'severity', 'operator' => 'equals', 'value' => 'high']];
        $context = ['severity' => 'low'];
        $this->assertFalse($method->invoke($this->preferencesService, $conditions, $context));

        // Test failing greater_than
        $conditions = [['field' => 'count', 'operator' => 'greater_than', 'value' => 10]];
        $context = ['count' => 5];
        $this->assertFalse($method->invoke($this->preferencesService, $conditions, $context));

        // Test unknown operator
        $conditions = [['field' => 'test', 'operator' => 'unknown_operator', 'value' => 'value']];
        $context = ['test' => 'value'];
        $this->assertFalse($method->invoke($this->preferencesService, $conditions, $context));
    }

    public function test_evaluate_conditions_with_empty_conditions_returns_true(): void
    {
        $reflection = new \ReflectionClass($this->preferencesService);
        $method = $reflection->getMethod('evaluateConditions');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->preferencesService, [], ['any' => 'context']));
    }

    public function test_get_default_behavior(): void
    {
        $reflection = new \ReflectionClass($this->preferencesService);
        $method = $reflection->getMethod('getDefaultBehavior');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->preferencesService, 'test_failure'));
        $this->assertTrue($method->invoke($this->preferencesService, 'recovery'));
        $this->assertTrue($method->invoke($this->preferencesService, 'escalation'));
        $this->assertFalse($method->invoke($this->preferencesService, 'scheduled_report'));
        $this->assertFalse($method->invoke($this->preferencesService, 'unknown_type'));
    }
}