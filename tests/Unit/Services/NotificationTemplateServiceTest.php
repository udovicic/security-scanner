<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\NotificationTemplateService;
use SecurityScanner\Core\Database;

class NotificationTemplateServiceTest extends TestCase
{
    private NotificationTemplateService $templateService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateService = new NotificationTemplateService();
    }

    public function test_get_template_returns_default_when_no_custom_exists(): void
    {
        $template = $this->templateService->getTemplate('test_failure', 'email');

        $this->assertIsArray($template);
        $this->assertArrayHasKey('subject', $template);
        $this->assertArrayHasKey('email_body', $template);
        $this->assertStringContainsString('{{website_name}}', $template['subject']);
        $this->assertStringContainsString('{{failed_count}}', $template['subject']);
    }

    public function test_get_template_returns_custom_when_exists(): void
    {
        // Create custom template
        $customTemplateData = [
            'name' => 'Custom Test Failure Email',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'subject_template' => 'Custom Alert: {{website_name}}',
            'body_template' => '<h1>Custom Template</h1><p>{{website_name}} has {{failed_count}} failures.</p>',
            'variables' => ['website_name', 'failed_count'],
            'is_active' => true
        ];

        $templateId = $this->templateService->createCustomTemplate($customTemplateData);

        $template = $this->templateService->getTemplate('test_failure', 'email');

        $this->assertStringContainsString('Custom Alert', $template['subject']);
        $this->assertStringContainsString('Custom Template', $template['email_body']);
        $this->assertArrayHasKey('variables', $template);

        // Cleanup
        $this->templateService->deleteTemplate($templateId);
    }

    public function test_create_custom_template(): void
    {
        $templateData = [
            'name' => 'Test Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'subject_template' => 'Test Alert: {{website_name}}',
            'body_template' => '<h1>Test Alert</h1><p>{{website_name}} has issues.</p>',
            'variables' => ['website_name', 'failed_count'],
            'is_active' => true
        ];

        $templateId = $this->templateService->createCustomTemplate($templateData);

        $this->assertIsInt($templateId);
        $this->assertGreaterThan(0, $templateId);

        // Verify template was created
        $template = $this->templateService->getTemplateById($templateId);
        $this->assertNotNull($template);
        $this->assertEquals('Test Template', $template['name']);
        $this->assertEquals('test_failure', $template['template_type']);
        $this->assertEquals('email', $template['notification_channel']);
        $this->assertEquals(['website_name', 'failed_count'], $template['variables']);

        // Cleanup
        $this->templateService->deleteTemplate($templateId);
    }

    public function test_update_template(): void
    {
        // Create template
        $templateData = [
            'name' => 'Original Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'subject_template' => 'Original Subject',
            'body_template' => 'Original Body',
            'is_active' => true
        ];

        $templateId = $this->templateService->createCustomTemplate($templateData);

        // Update template
        $updateData = [
            'name' => 'Updated Template',
            'subject_template' => 'Updated Subject: {{website_name}}',
            'body_template' => 'Updated Body: {{website_name}} has failures.',
            'variables' => ['website_name', 'failed_count', 'scan_time']
        ];

        $result = $this->templateService->updateTemplate($templateId, $updateData);

        $this->assertTrue($result);

        // Verify update
        $template = $this->templateService->getTemplateById($templateId);
        $this->assertEquals('Updated Template', $template['name']);
        $this->assertEquals('Updated Subject: {{website_name}}', $template['subject_template']);
        $this->assertEquals('Updated Body: {{website_name}} has failures.', $template['body_template']);
        $this->assertEquals(['website_name', 'failed_count', 'scan_time'], $template['variables']);

        // Cleanup
        $this->templateService->deleteTemplate($templateId);
    }

    public function test_delete_template(): void
    {
        // Create template
        $templateData = [
            'name' => 'Template to Delete',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'Template body'
        ];

        $templateId = $this->templateService->createCustomTemplate($templateData);

        // Delete template
        $result = $this->templateService->deleteTemplate($templateId);

        $this->assertTrue($result);

        // Verify deletion
        $template = $this->templateService->getTemplateById($templateId);
        $this->assertNull($template);
    }

    public function test_delete_nonexistent_template_returns_false(): void
    {
        $result = $this->templateService->deleteTemplate(999999);
        $this->assertFalse($result);
    }

    public function test_get_all_templates(): void
    {
        // Create multiple templates
        $template1Data = [
            'name' => 'Template 1',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'Body 1'
        ];

        $template2Data = [
            'name' => 'Template 2',
            'template_type' => 'recovery',
            'notification_channel' => 'sms',
            'body_template' => 'Body 2'
        ];

        $id1 = $this->templateService->createCustomTemplate($template1Data);
        $id2 = $this->templateService->createCustomTemplate($template2Data);

        $templates = $this->templateService->getAllTemplates();

        $this->assertIsArray($templates);
        $this->assertGreaterThanOrEqual(2, count($templates));

        // Check templates are sorted by type, channel, name
        $found1 = false;
        $found2 = false;
        foreach ($templates as $template) {
            if ($template['id'] == $id1) {
                $found1 = true;
                $this->assertEquals('Template 1', $template['name']);
            }
            if ($template['id'] == $id2) {
                $found2 = true;
                $this->assertEquals('Template 2', $template['name']);
            }
        }

        $this->assertTrue($found1);
        $this->assertTrue($found2);

        // Cleanup
        $this->templateService->deleteTemplate($id1);
        $this->templateService->deleteTemplate($id2);
    }

    public function test_get_templates_by_type(): void
    {
        // Create templates of different types
        $testFailureTemplate = [
            'name' => 'Test Failure Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'Test failure body'
        ];

        $recoveryTemplate = [
            'name' => 'Recovery Template',
            'template_type' => 'recovery',
            'notification_channel' => 'email',
            'body_template' => 'Recovery body'
        ];

        $id1 = $this->templateService->createCustomTemplate($testFailureTemplate);
        $id2 = $this->templateService->createCustomTemplate($recoveryTemplate);

        $testFailureTemplates = $this->templateService->getTemplatesByType('test_failure');

        $this->assertIsArray($testFailureTemplates);
        $this->assertGreaterThan(0, count($testFailureTemplates));

        // Should only contain test_failure templates
        foreach ($testFailureTemplates as $template) {
            $this->assertEquals('test_failure', $template['template_type']);
        }

        // Check our specific template is included
        $foundTemplate = false;
        foreach ($testFailureTemplates as $template) {
            if ($template['id'] == $id1) {
                $foundTemplate = true;
                break;
            }
        }
        $this->assertTrue($foundTemplate);

        // Cleanup
        $this->templateService->deleteTemplate($id1);
        $this->templateService->deleteTemplate($id2);
    }

    public function test_validate_template_with_valid_data(): void
    {
        $validTemplateData = [
            'name' => 'Valid Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'This is a valid template body.',
            'variables' => ['website_name', 'failed_count']
        ];

        $errors = $this->templateService->validateTemplate($validTemplateData);

        $this->assertEmpty($errors);
    }

    public function test_validate_template_with_missing_required_fields(): void
    {
        $invalidTemplateData = [
            'name' => '', // Empty name
            'template_type' => '', // Empty type
            'notification_channel' => '', // Empty channel
            'body_template' => '' // Empty body
        ];

        $errors = $this->templateService->validateTemplate($invalidTemplateData);

        $this->assertNotEmpty($errors);
        $this->assertContains('Template name is required', $errors);
        $this->assertContains('Template type is required', $errors);
        $this->assertContains('Notification channel is required', $errors);
        $this->assertContains('Template body is required', $errors);
    }

    public function test_validate_template_with_invalid_type_and_channel(): void
    {
        $invalidTemplateData = [
            'name' => 'Test Template',
            'template_type' => 'invalid_type',
            'notification_channel' => 'invalid_channel',
            'body_template' => 'Valid body'
        ];

        $errors = $this->templateService->validateTemplate($invalidTemplateData);

        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid template type. Must be one of: test_failure, recovery, escalation, scheduled_report', $errors);
        $this->assertContains('Invalid notification channel. Must be one of: email, sms, webhook', $errors);
    }

    public function test_validate_template_with_invalid_variables(): void
    {
        $invalidTemplateData = [
            'name' => 'Test Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'Valid body',
            'variables' => 'not_an_array' // Should be array
        ];

        $errors = $this->templateService->validateTemplate($invalidTemplateData);

        $this->assertNotEmpty($errors);
        $this->assertContains('Variables must be an array', $errors);
    }

    public function test_validate_template_detects_duplicates(): void
    {
        // Create a template
        $templateData = [
            'name' => 'Duplicate Test',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'body_template' => 'Template body'
        ];

        $templateId = $this->templateService->createCustomTemplate($templateData);

        // Try to validate identical template data
        $errors = $this->templateService->validateTemplate($templateData);

        $this->assertNotEmpty($errors);
        $this->assertContains('A template with this name, type, and channel already exists', $errors);

        // Cleanup
        $this->templateService->deleteTemplate($templateId);
    }

    public function test_process_template_with_simple_variables(): void
    {
        $template = 'Hello {{name}}, your website {{website}} has {{count}} issues.';
        $context = [
            'name' => 'John',
            'website' => 'example.com',
            'count' => 3
        ];

        $processed = $this->templateService->processTemplate($template, $context);

        $this->assertEquals('Hello John, your website example.com has 3 issues.', $processed);
    }

    public function test_process_template_with_failed_tests_array(): void
    {
        $template = 'Website {{website_name}} has {{failed_count}} failures:\n{{failed_tests_list}}';
        $context = [
            'website_name' => 'example.com',
            'failed_tests' => [
                ['test_name' => 'SSL Test', 'message' => 'Certificate expired'],
                ['test_name' => 'Headers Test', 'message' => 'Missing HSTS']
            ]
        ];

        $processed = $this->templateService->processTemplate($template, $context);

        $this->assertStringContainsString('example.com', $processed);
        $this->assertStringContainsString('2', $processed); // failed_count
        $this->assertStringContainsString('• SSL Test: Certificate expired', $processed);
        $this->assertStringContainsString('• Headers Test: Missing HSTS', $processed);
    }

    public function test_process_template_removes_unused_variables(): void
    {
        $template = 'Hello {{name}}, your {{unused_variable}} is {{value}}.';
        $context = [
            'name' => 'John',
            'value' => 'working'
        ];

        $processed = $this->templateService->processTemplate($template, $context);

        $this->assertEquals('Hello John, your  is working.', $processed);
        $this->assertStringNotContainsString('{{unused_variable}}', $processed);
    }

    public function test_get_available_variables_for_test_failure(): void
    {
        $variables = $this->templateService->getAvailableVariables('test_failure');

        $this->assertIsArray($variables);
        $this->assertArrayHasKey('website_name', $variables);
        $this->assertArrayHasKey('website_url', $variables);
        $this->assertArrayHasKey('failed_count', $variables);
        $this->assertArrayHasKey('failed_tests', $variables);
        $this->assertArrayHasKey('failed_tests_list', $variables);
    }

    public function test_get_available_variables_for_escalation(): void
    {
        $variables = $this->templateService->getAvailableVariables('escalation');

        $this->assertIsArray($variables);
        $this->assertArrayHasKey('escalation_level', $variables);
        $this->assertArrayHasKey('escalation_level_text', $variables);
        $this->assertArrayHasKey('trigger_reason', $variables);
    }

    public function test_get_available_variables_for_scheduled_report(): void
    {
        $variables = $this->templateService->getAvailableVariables('scheduled_report');

        $this->assertIsArray($variables);
        $this->assertArrayHasKey('report_period', $variables);
        $this->assertArrayHasKey('total_scans', $variables);
        $this->assertArrayHasKey('success_rate', $variables);
    }

    public function test_preview_template_with_sample_context(): void
    {
        $templateData = [
            'template_type' => 'test_failure',
            'body_template' => 'Website {{website_name}} has {{failed_count}} failures at {{scan_time}}.'
        ];

        $preview = $this->templateService->previewTemplate($templateData);

        $this->assertIsString($preview);
        $this->assertStringContainsString('Example Website', $preview);
        $this->assertStringContainsString('2', $preview); // sample failed_count
        $this->assertStringNotContainsString('{{', $preview); // No unprocessed variables
    }

    public function test_preview_template_with_custom_context(): void
    {
        $templateData = [
            'template_type' => 'test_failure',
            'body_template' => 'Website {{website_name}} has {{failed_count}} failures.'
        ];

        $customContext = [
            'website_name' => 'my-site.com',
            'failed_count' => 5
        ];

        $preview = $this->templateService->previewTemplate($templateData, $customContext);

        $this->assertStringContainsString('my-site.com', $preview);
        $this->assertStringContainsString('5', $preview);
    }

    public function test_get_template_for_different_notification_channels(): void
    {
        // Test email template
        $emailTemplate = $this->templateService->getTemplate('test_failure', 'email');
        $this->assertArrayHasKey('subject', $emailTemplate);
        $this->assertArrayHasKey('email_body', $emailTemplate);

        // Test SMS template
        $smsTemplate = $this->templateService->getTemplate('test_failure', 'sms');
        $this->assertArrayHasKey('sms_body', $smsTemplate);

        // Test webhook template
        $webhookTemplate = $this->templateService->getTemplate('test_failure', 'webhook');
        $this->assertArrayHasKey('webhook_body', $webhookTemplate);
    }

    public function test_template_fallback_to_default(): void
    {
        // Request non-existent template type
        $template = $this->templateService->getTemplate('nonexistent_type', 'email');

        // Should fallback to default test_failure_email template
        $this->assertArrayHasKey('subject', $template);
        $this->assertArrayHasKey('email_body', $template);
        $this->assertStringContainsString('Security Alert', $template['subject']);
    }

    public function test_template_with_inactive_custom_template(): void
    {
        // Create inactive custom template
        $templateData = [
            'name' => 'Inactive Template',
            'template_type' => 'test_failure',
            'notification_channel' => 'email',
            'subject_template' => 'Custom Subject',
            'body_template' => 'Custom Body',
            'is_active' => false // Inactive
        ];

        $templateId = $this->templateService->createCustomTemplate($templateData);

        // Should return default template since custom is inactive
        $template = $this->templateService->getTemplate('test_failure', 'email');

        $this->assertStringContainsString('Security Alert', $template['subject']);
        $this->assertStringNotContainsString('Custom Subject', $template['subject']);

        // Cleanup
        $this->templateService->deleteTemplate($templateId);
    }
}