<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;

class NotificationTemplateService
{
    private Database $db;
    private Logger $logger;
    private array $defaultTemplates;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = new Logger('notification_templates');
        $this->initializeDefaultTemplates();
    }

    public function getTemplate(string $templateType, string $notificationChannel = 'email'): array
    {
        $customTemplate = $this->getCustomTemplate($templateType, $notificationChannel);

        if ($customTemplate) {
            return $customTemplate;
        }

        return $this->getDefaultTemplate($templateType, $notificationChannel);
    }

    public function createCustomTemplate(array $templateData): int
    {
        $templateId = $this->db->insert('notification_templates', [
            'name' => $templateData['name'],
            'template_type' => $templateData['template_type'],
            'notification_channel' => $templateData['notification_channel'],
            'subject_template' => $templateData['subject_template'] ?? '',
            'body_template' => $templateData['body_template'],
            'variables' => json_encode($templateData['variables'] ?? []),
            'is_active' => $templateData['is_active'] ?? true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->logger->info("Custom template created", [
            'template_id' => $templateId,
            'name' => $templateData['name'],
            'type' => $templateData['template_type'],
            'channel' => $templateData['notification_channel']
        ]);

        return $templateId;
    }

    public function updateTemplate(int $templateId, array $templateData): bool
    {
        $updateData = array_filter([
            'name' => $templateData['name'] ?? null,
            'subject_template' => $templateData['subject_template'] ?? null,
            'body_template' => $templateData['body_template'] ?? null,
            'variables' => isset($templateData['variables']) ? json_encode($templateData['variables']) : null,
            'is_active' => $templateData['is_active'] ?? null,
            'updated_at' => date('Y-m-d H:i:s')
        ], function($value) {
            return $value !== null;
        });

        $result = $this->db->update('notification_templates', $updateData, ['id' => $templateId]);

        if ($result) {
            $this->logger->info("Template updated", [
                'template_id' => $templateId,
                'updated_fields' => array_keys($updateData)
            ]);
        }

        return $result;
    }

    public function deleteTemplate(int $templateId): bool
    {
        $template = $this->getTemplateById($templateId);

        if (!$template) {
            return false;
        }

        $result = $this->db->delete('notification_templates', ['id' => $templateId]);

        if ($result) {
            $this->logger->info("Template deleted", [
                'template_id' => $templateId,
                'name' => $template['name']
            ]);
        }

        return $result;
    }

    public function getTemplateById(int $templateId): ?array
    {
        $template = $this->db->fetchRow(
            "SELECT * FROM notification_templates WHERE id = ?",
            [$templateId]
        );

        if ($template) {
            $template['variables'] = json_decode($template['variables'], true) ?: [];
        }

        return $template ?: null;
    }

    public function getAllTemplates(): array
    {
        $templates = $this->db->fetchAll(
            "SELECT * FROM notification_templates ORDER BY template_type, notification_channel, name"
        );

        foreach ($templates as &$template) {
            $template['variables'] = json_decode($template['variables'], true) ?: [];
        }

        return $templates;
    }

    public function getTemplatesByType(string $templateType): array
    {
        $templates = $this->db->fetchAll(
            "SELECT * FROM notification_templates WHERE template_type = ? AND is_active = 1 ORDER BY notification_channel, name",
            [$templateType]
        );

        foreach ($templates as &$template) {
            $template['variables'] = json_decode($template['variables'], true) ?: [];
        }

        return $templates;
    }

    public function validateTemplate(array $templateData): array
    {
        $errors = [];

        if (empty($templateData['name'])) {
            $errors[] = 'Template name is required';
        }

        if (empty($templateData['template_type'])) {
            $errors[] = 'Template type is required';
        }

        if (empty($templateData['notification_channel'])) {
            $errors[] = 'Notification channel is required';
        }

        if (empty($templateData['body_template'])) {
            $errors[] = 'Template body is required';
        }

        $validTypes = ['test_failure', 'recovery', 'escalation', 'scheduled_report'];
        if (!in_array($templateData['template_type'] ?? '', $validTypes)) {
            $errors[] = 'Invalid template type. Must be one of: ' . implode(', ', $validTypes);
        }

        $validChannels = ['email', 'sms', 'webhook'];
        if (!in_array($templateData['notification_channel'] ?? '', $validChannels)) {
            $errors[] = 'Invalid notification channel. Must be one of: ' . implode(', ', $validChannels);
        }

        if (!empty($templateData['variables']) && !is_array($templateData['variables'])) {
            $errors[] = 'Variables must be an array';
        }

        if (empty($errors)) {
            $duplicateCheck = $this->db->fetchRow(
                "SELECT id FROM notification_templates WHERE name = ? AND template_type = ? AND notification_channel = ?",
                [$templateData['name'], $templateData['template_type'], $templateData['notification_channel']]
            );

            if ($duplicateCheck) {
                $errors[] = 'A template with this name, type, and channel already exists';
            }
        }

        return $errors;
    }

    public function processTemplate(string $template, array $context): string
    {
        $processed = $template;

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                if ($key === 'failed_tests') {
                    $testsList = '';
                    foreach ($value as $test) {
                        $testsList .= "• " . ($test['test_name'] ?? 'Unknown') . ": " . ($test['message'] ?? 'Failed') . "\n";
                    }
                    $processed = str_replace('{{failed_tests_list}}', $testsList, $processed);
                    $processed = str_replace('{{failed_count}}', count($value), $processed);
                }
            } else {
                $processed = str_replace('{{' . $key . '}}', (string)$value, $processed);
            }
        }

        $processed = preg_replace('/\{\{[^}]+\}\}/', '', $processed);

        return $processed;
    }

    public function getAvailableVariables(string $templateType): array
    {
        $commonVariables = [
            'website_name' => 'Name of the website',
            'website_url' => 'URL of the website',
            'scan_time' => 'Time when the scan was performed',
            'total_tests' => 'Total number of tests run',
        ];

        $typeSpecificVariables = [
            'test_failure' => [
                'failed_count' => 'Number of failed tests',
                'failed_tests' => 'Array of failed test details',
                'failed_tests_list' => 'Formatted list of failed tests'
            ],
            'recovery' => [
                'previous_failure_count' => 'Number of tests that were previously failing',
                'recovery_time' => 'Time when the recovery was detected'
            ],
            'escalation' => [
                'escalation_level' => 'Escalation level (1-3)',
                'escalation_level_text' => 'Escalation level in text (Low/Medium/Critical)',
                'trigger_reason' => 'Reason for escalation',
                'failed_count' => 'Number of failed tests',
                'failed_tests_list' => 'Formatted list of failed tests'
            ],
            'scheduled_report' => [
                'report_period' => 'Reporting period (daily/weekly/monthly)',
                'total_scans' => 'Total number of scans in period',
                'successful_scans' => 'Number of successful scans',
                'success_rate' => 'Success rate percentage',
                'avg_scan_time' => 'Average scan duration',
                'websites_monitored' => 'Number of websites monitored'
            ]
        ];

        return array_merge($commonVariables, $typeSpecificVariables[$templateType] ?? []);
    }

    public function previewTemplate(array $templateData, array $sampleContext = []): string
    {
        if (empty($sampleContext)) {
            $sampleContext = $this->getSampleContext($templateData['template_type'] ?? 'test_failure');
        }

        $template = $templateData['body_template'] ?? '';
        return $this->processTemplate($template, $sampleContext);
    }

    private function getCustomTemplate(string $templateType, string $notificationChannel): ?array
    {
        $template = $this->db->fetchRow(
            "SELECT * FROM notification_templates
             WHERE template_type = ?
             AND notification_channel = ?
             AND is_active = 1
             ORDER BY created_at DESC
             LIMIT 1",
            [$templateType, $notificationChannel]
        );

        if ($template) {
            return [
                'subject' => $template['subject_template'],
                $notificationChannel . '_body' => $template['body_template'],
                'variables' => json_decode($template['variables'], true) ?: []
            ];
        }

        return null;
    }

    private function getDefaultTemplate(string $templateType, string $notificationChannel): array
    {
        $key = $templateType . '_' . $notificationChannel;
        return $this->defaultTemplates[$key] ?? $this->defaultTemplates['test_failure_email'];
    }

    private function getSampleContext(string $templateType): array
    {
        $baseContext = [
            'website_name' => 'Example Website',
            'website_url' => 'https://example.com',
            'scan_time' => date('Y-m-d H:i:s'),
            'total_tests' => 5
        ];

        switch ($templateType) {
            case 'test_failure':
                return array_merge($baseContext, [
                    'failed_count' => 2,
                    'failed_tests' => [
                        ['test_name' => 'SSL Certificate Test', 'message' => 'Certificate expired'],
                        ['test_name' => 'Security Headers Test', 'message' => 'Missing HSTS header']
                    ]
                ]);

            case 'recovery':
                return array_merge($baseContext, [
                    'previous_failure_count' => 3,
                    'recovery_time' => date('Y-m-d H:i:s')
                ]);

            case 'escalation':
                return array_merge($baseContext, [
                    'escalation_level' => 2,
                    'escalation_level_text' => 'Medium',
                    'trigger_reason' => 'consecutive_failures',
                    'failed_count' => 3,
                    'failed_tests' => [
                        ['test_name' => 'SSL Certificate Test', 'message' => 'Certificate expired'],
                        ['test_name' => 'Security Headers Test', 'message' => 'Missing headers'],
                        ['test_name' => 'Response Time Test', 'message' => 'Timeout']
                    ]
                ]);

            case 'scheduled_report':
                return array_merge($baseContext, [
                    'report_period' => 'weekly',
                    'total_scans' => 42,
                    'successful_scans' => 38,
                    'success_rate' => 90.5,
                    'avg_scan_time' => 12.3,
                    'websites_monitored' => 6
                ]);

            default:
                return $baseContext;
        }
    }

    private function initializeDefaultTemplates(): void
    {
        $this->defaultTemplates = [
            'test_failure_email' => [
                'subject' => 'Security Alert: {{website_name}} - {{failed_count}} Tests Failed',
                'email_body' => '<html><body><h1>Security Test Failure</h1><p>{{failed_count}} tests failed for {{website_name}} ({{website_url}}) at {{scan_time}}.</p><h2>Failed Tests:</h2>{{failed_tests_list}}</body></html>'
            ],
            'test_failure_sms' => [
                'sms_body' => 'ALERT: {{failed_count}} security tests failed for {{website_name}}. Check dashboard for details.'
            ],
            'test_failure_webhook' => [
                'webhook_body' => '{"event":"test_failure","website":"{{website_name}}","failed_count":{{failed_count}},"timestamp":"{{scan_time}}"}'
            ],
            'recovery_email' => [
                'subject' => 'Recovery: {{website_name}} - All Tests Passing',
                'email_body' => '<html><body><h1>Security Recovery</h1><p>All security tests are now passing for {{website_name}} ({{website_url}}) as of {{scan_time}}.</p></body></html>'
            ],
            'recovery_sms' => [
                'sms_body' => 'RECOVERY: All security tests now passing for {{website_name}}.'
            ],
            'escalation_email' => [
                'subject' => 'ESCALATED ALERT Level {{escalation_level}}: {{website_name}} Critical Issues',
                'email_body' => '<html><body><h1 style="color:red;">🚨 ESCALATED SECURITY ALERT 🚨</h1><p><strong>Level {{escalation_level}} ({{escalation_level_text}})</strong></p><p>Critical issues detected for {{website_name}} requiring immediate attention.</p><h2>Failed Tests:</h2>{{failed_tests_list}}</body></html>'
            ],
            'escalation_sms' => [
                'sms_body' => 'URGENT Level {{escalation_level}}: {{website_name}} critical security alert. {{failed_count}} tests failed. Immediate action required.'
            ]
        ];
    }
}