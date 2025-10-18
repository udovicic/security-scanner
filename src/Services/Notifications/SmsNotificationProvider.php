<?php

namespace SecurityScanner\Services\Notifications;

use SecurityScanner\Core\Logger;

class SmsNotificationProvider implements NotificationProviderInterface
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'provider' => 'twilio',
            'api_key' => '',
            'api_secret' => '',
            'from_number' => '',
            'timeout' => 30,
            'max_message_length' => 160,
            'test_mode' => false
        ], $config);

        $this->logger = Logger::channel('sms_notifications');
    }

    public function send(string $recipient, array $template, array $context): bool
    {
        try {
            $phoneNumber = $this->normalizePhoneNumber($recipient);

            if (!$this->isValidPhoneNumber($phoneNumber)) {
                $this->logger->error("Invalid phone number", ['phone' => $this->maskPhoneNumber($recipient)]);
                return false;
            }

            $message = $this->buildSmsMessage($template, $context);

            if (strlen($message) > $this->config['max_message_length']) {
                $message = substr($message, 0, $this->config['max_message_length'] - 3) . '...';
            }

            return $this->sendSmsMessage($phoneNumber, $message);

        } catch (\Exception $e) {
            $this->logger->error("SMS sending failed", [
                'phone' => $this->maskPhoneNumber($recipient),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function test(): bool
    {
        try {
            $testNumber = $this->config['test_phone_number'] ?? null;

            if (!$testNumber) {
                $this->logger->info("No test phone number configured");
                return true;
            }

            $testMessage = 'Test SMS from Security Scanner - ' . date('Y-m-d H:i:s');

            return $this->sendSmsMessage($testNumber, $testMessage);

        } catch (\Exception $e) {
            $this->logger->error("SMS test failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getStatus(): array
    {
        return [
            'provider' => 'sms',
            'sms_provider' => $this->config['provider'],
            'from_number' => $this->maskPhoneNumber($this->config['from_number']),
            'max_message_length' => $this->config['max_message_length'],
            'test_mode' => $this->config['test_mode'],
            'connection_test' => $this->test()
        ];
    }

    private function sendSmsMessage(string $phoneNumber, string $message): bool
    {
        if ($this->config['test_mode']) {
            $this->logger->info("SMS test mode - message would be sent", [
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'message' => $message
            ]);
            return true;
        }

        switch ($this->config['provider']) {
            case 'twilio':
                return $this->sendViaTwilio($phoneNumber, $message);

            case 'aws_sns':
                return $this->sendViaAwsSns($phoneNumber, $message);

            case 'nexmo':
                return $this->sendViaNexmo($phoneNumber, $message);

            default:
                $this->logger->error("Unsupported SMS provider", [
                    'provider' => $this->config['provider']
                ]);
                return false;
        }
    }

    private function sendViaTwilio(string $phoneNumber, string $message): bool
    {
        try {
            if (empty($this->config['api_key']) || empty($this->config['api_secret'])) {
                $this->logger->error("Twilio API credentials not configured");
                return false;
            }

            $twilioUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->config['api_key']}/Messages.json";

            $data = [
                'From' => $this->config['from_number'],
                'To' => $phoneNumber,
                'Body' => $message
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Authorization: Basic ' . base64_encode($this->config['api_key'] . ':' . $this->config['api_secret']),
                        'Content-Type: application/x-www-form-urlencoded'
                    ],
                    'content' => http_build_query($data),
                    'timeout' => $this->config['timeout']
                ]
            ]);

            $response = file_get_contents($twilioUrl, false, $context);

            if ($response === false) {
                $this->logger->error("Twilio API request failed");
                return false;
            }

            $result = json_decode($response, true);

            if (isset($result['sid'])) {
                $this->logger->info("SMS sent via Twilio", [
                    'phone' => $this->maskPhoneNumber($phoneNumber),
                    'message_sid' => $result['sid'],
                    'status' => $result['status'] ?? 'unknown'
                ]);
                return true;
            } else {
                $this->logger->error("Twilio API error", [
                    'phone' => $this->maskPhoneNumber($phoneNumber),
                    'error' => $result['message'] ?? 'Unknown error'
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error("Twilio SMS failed", [
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function sendViaAwsSns(string $phoneNumber, string $message): bool
    {
        $this->logger->warning("AWS SNS SMS provider not yet implemented", [
            'phone' => $this->maskPhoneNumber($phoneNumber)
        ]);
        return false;
    }

    private function sendViaNexmo(string $phoneNumber, string $message): bool
    {
        $this->logger->warning("Nexmo SMS provider not yet implemented", [
            'phone' => $this->maskPhoneNumber($phoneNumber)
        ]);
        return false;
    }

    private function buildSmsMessage(array $template, array $context): string
    {
        $message = $template['sms_body'] ?? $template['subject'] ?? 'Security Alert';

        $message = $this->processTemplate($message, $context);

        if (isset($context['failed_tests']) && is_array($context['failed_tests'])) {
            $failedCount = count($context['failed_tests']);
            $websiteName = $context['website_name'] ?? 'Website';

            $message = "SECURITY ALERT: {$websiteName} - {$failedCount} test(s) failed. Check your dashboard for details.";
        }

        return $message;
    }

    private function processTemplate(string $template, array $context): string
    {
        $processed = $template;

        foreach ($context as $key => $value) {
            if (!is_array($value)) {
                $processed = str_replace('{{' . $key . '}}', (string)$value, $processed);
            } elseif ($key === 'failed_tests') {
                $processed = str_replace('{{failed_count}}', count($value), $processed);
            }
        }

        $processed = preg_replace('/\{\{[^}]+\}\}/', '', $processed);

        return trim($processed);
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $normalized = preg_replace('/[^\d+]/', '', $phoneNumber);

        if (!str_starts_with($normalized, '+')) {
            if (str_starts_with($normalized, '1') && strlen($normalized) === 11) {
                $normalized = '+' . $normalized;
            } elseif (strlen($normalized) === 10) {
                $normalized = '+1' . $normalized;
            } else {
                $normalized = '+' . $normalized;
            }
        }

        return $normalized;
    }

    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        return preg_match('/^\+\d{10,15}$/', $phoneNumber) === 1;
    }

    private function maskPhoneNumber(string $phone): string
    {
        if (strlen($phone) <= 6) {
            return '***';
        }

        return substr($phone, 0, 3) . str_repeat('*', max(0, strlen($phone) - 6)) . substr($phone, -3);
    }
}