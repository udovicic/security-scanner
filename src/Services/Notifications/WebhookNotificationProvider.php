<?php

namespace SecurityScanner\Services\Notifications;

use SecurityScanner\Core\Logger;

class WebhookNotificationProvider implements NotificationProviderInterface
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'timeout' => 30,
            'retry_attempts' => 3,
            'user_agent' => 'SecurityScanner/1.0',
            'verify_ssl' => true,
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ], $config);

        $this->logger = new Logger('webhook_notifications');
    }

    public function send(string $recipient, array $template, array $context): bool
    {
        try {
            $webhookUrl = $recipient;

            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                $this->logger->error("Invalid webhook URL", ['url' => $webhookUrl]);
                return false;
            }

            $payload = $this->buildPayload($template, $context);

            return $this->sendWebhookRequest($webhookUrl, $payload);

        } catch (\Exception $e) {
            $this->logger->error("Webhook sending failed", [
                'url' => $this->maskUrl($recipient),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function test(): bool
    {
        try {
            $testUrl = $this->config['test_webhook_url'] ?? null;

            if (!$testUrl) {
                $this->logger->info("No test webhook URL configured");
                return true;
            }

            $testPayload = [
                'event' => 'test',
                'timestamp' => date('c'),
                'data' => ['message' => 'Test webhook from Security Scanner']
            ];

            return $this->sendWebhookRequest($testUrl, $testPayload);

        } catch (\Exception $e) {
            $this->logger->error("Webhook test failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getStatus(): array
    {
        return [
            'provider' => 'webhook',
            'timeout' => $this->config['timeout'],
            'retry_attempts' => $this->config['retry_attempts'],
            'verify_ssl' => $this->config['verify_ssl'],
            'connection_test' => $this->test()
        ];
    }

    private function sendWebhookRequest(string $url, array $payload): bool
    {
        $attempts = 0;
        $maxAttempts = $this->config['retry_attempts'];

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => implode("\r\n", $this->config['headers']),
                        'content' => json_encode($payload),
                        'timeout' => $this->config['timeout'],
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => $this->config['verify_ssl'],
                        'verify_peer_name' => $this->config['verify_ssl']
                    ]
                ]);

                $startTime = microtime(true);
                $response = file_get_contents($url, false, $context);
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);

                $httpCode = 0;
                if (isset($http_response_header)) {
                    foreach ($http_response_header as $header) {
                        if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                            $httpCode = (int)$matches[1];
                            break;
                        }
                    }
                }

                if ($httpCode >= 200 && $httpCode < 300) {
                    $this->logger->info("Webhook sent successfully", [
                        'url' => $this->maskUrl($url),
                        'http_code' => $httpCode,
                        'response_time_ms' => $responseTime,
                        'attempt' => $attempts,
                        'response_size' => strlen($response ?: '')
                    ]);
                    return true;
                } else {
                    $this->logger->warning("Webhook failed with HTTP code", [
                        'url' => $this->maskUrl($url),
                        'http_code' => $httpCode,
                        'attempt' => $attempts,
                        'response' => substr($response ?: '', 0, 500)
                    ]);

                    if ($attempts >= $maxAttempts) {
                        return false;
                    }

                    usleep(pow(2, $attempts - 1) * 1000000);
                }

            } catch (\Exception $e) {
                $this->logger->error("Webhook request failed", [
                    'url' => $this->maskUrl($url),
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);

                if ($attempts >= $maxAttempts) {
                    return false;
                }

                usleep(pow(2, $attempts - 1) * 1000000);
            }
        }

        return false;
    }

    private function buildPayload(array $template, array $context): array
    {
        $payload = [
            'event' => $context['notification_type'] ?? 'security_alert',
            'timestamp' => date('c'),
            'version' => '1.0',
            'data' => []
        ];

        if (isset($context['website_name'])) {
            $payload['data']['website'] = [
                'name' => $context['website_name'],
                'url' => $context['website_url'] ?? null
            ];
        }

        if (isset($context['failed_tests']) && is_array($context['failed_tests'])) {
            $payload['data']['scan_results'] = [
                'total_tests' => $context['total_tests'] ?? 0,
                'failed_count' => $context['failed_count'] ?? count($context['failed_tests']),
                'failed_tests' => $context['failed_tests'],
                'scan_time' => $context['scan_time'] ?? date('Y-m-d H:i:s')
            ];
        }

        if (isset($template['subject'])) {
            $payload['data']['subject'] = $this->processTemplate($template['subject'], $context);
        }

        if (isset($template['webhook_body'])) {
            $payload['data']['message'] = $this->processTemplate($template['webhook_body'], $context);
        } elseif (isset($template['email_body'])) {
            $payload['data']['message'] = strip_tags($this->processTemplate($template['email_body'], $context));
        }

        $payload['data']['metadata'] = array_filter($context, function($key) {
            return !in_array($key, ['failed_tests', 'website_name', 'website_url', 'scan_time', 'total_tests', 'failed_count']);
        }, ARRAY_FILTER_USE_KEY);

        return $payload;
    }

    private function processTemplate(string $template, array $context): string
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
}