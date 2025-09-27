<?php

namespace SecurityScanner\Services\Notifications;

use SecurityScanner\Core\Logger;

/**
 * EmailNotificationProvider
 *
 * Handles email notification sending using various SMTP providers
 */
class EmailNotificationProvider implements NotificationProviderInterface
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@securityscanner.local',
            'from_name' => 'Security Scanner',
            'timeout' => 30,
            'use_phpmailer' => true
        ], $config);

        $this->logger = new Logger('email_notifications');
    }

    /**
     * Send notification
     */
    public function send(string $recipient, array $template, array $context): bool
    {
        try {
            $subject = $this->processTemplate($template['subject'], $context);
            $body = $this->processTemplate($template['email_body'], $context);

            // Validate email address
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->logger->error("Invalid email address", ['recipient' => $recipient]);
                return false;
            }

            if ($this->config['use_phpmailer']) {
                return $this->sendWithPHPMailer($recipient, $subject, $body);
            } else {
                return $this->sendWithBuiltInMail($recipient, $subject, $body);
            }

        } catch (\Exception $e) {
            $this->logger->error("Email sending failed", [
                'recipient' => $this->maskEmail($recipient),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send email using PHPMailer
     */
    private function sendWithPHPMailer(string $recipient, string $subject, string $body): bool
    {
        // Check if PHPMailer is available
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendWithBuiltInMail($recipient, $subject, $body);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->config['smtp_host'];
            $mail->SMTPAuth = !empty($this->config['smtp_username']);
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'];
            $mail->SMTPSecure = $this->config['smtp_encryption'];
            $mail->Port = $this->config['smtp_port'];
            $mail->Timeout = $this->config['timeout'];

            // Recipients
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($recipient);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();

            $this->logger->info("Email sent successfully", [
                'recipient' => $this->maskEmail($recipient),
                'subject' => $subject
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("PHPMailer failed", [
                'recipient' => $this->maskEmail($recipient),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send email using built-in mail function
     */
    private function sendWithBuiltInMail(string $recipient, string $subject, string $body): bool
    {
        try {
            $headers = [
                'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
                'Reply-To: ' . $this->config['from_email'],
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'X-Mailer: SecurityScanner/1.0'
            ];

            $success = mail($recipient, $subject, $body, implode("\r\n", $headers));

            if ($success) {
                $this->logger->info("Email sent via built-in mail", [
                    'recipient' => $this->maskEmail($recipient),
                    'subject' => $subject
                ]);
                return true;
            } else {
                $this->logger->error("Built-in mail function failed", [
                    'recipient' => $this->maskEmail($recipient),
                    'subject' => $subject
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error("Built-in mail error", [
                'recipient' => $this->maskEmail($recipient),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process template with context variables
     */
    private function processTemplate(string $template, array $context): string
    {
        $processed = $template;

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                // Handle arrays (like failed_tests)
                if ($key === 'failed_tests') {
                    $testsList = '';
                    foreach ($value as $test) {
                        $testsList .= "• " . ($test['test_name'] ?? 'Unknown') . ": " . ($test['message'] ?? 'Failed') . "\n";
                    }
                    $processed = str_replace('{{failed_tests_list}}', $testsList, $processed);
                    $processed = str_replace('{{failed_count}}', count($value), $processed);
                }
            } else {
                // Handle simple placeholders
                $processed = str_replace('{{' . $key . '}}', (string)$value, $processed);
            }
        }

        // Clean up any remaining placeholders
        $processed = preg_replace('/\{\{[^}]+\}\}/', '', $processed);

        return $processed;
    }

    /**
     * Test connection
     */
    public function test(): bool
    {
        try {
            if ($this->config['use_phpmailer'] && class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $this->config['smtp_host'];
                $mail->SMTPAuth = !empty($this->config['smtp_username']);
                $mail->Username = $this->config['smtp_username'];
                $mail->Password = $this->config['smtp_password'];
                $mail->SMTPSecure = $this->config['smtp_encryption'];
                $mail->Port = $this->config['smtp_port'];
                $mail->Timeout = $this->config['timeout'];

                // Test SMTP connection
                $mail->smtpConnect();
                $mail->smtpClose();

                return true;
            }

            // For built-in mail, just check if the function exists
            return function_exists('mail');

        } catch (\Exception $e) {
            $this->logger->error("Email provider test failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get provider status
     */
    public function getStatus(): array
    {
        return [
            'provider' => 'email',
            'smtp_host' => $this->config['smtp_host'],
            'smtp_port' => $this->config['smtp_port'],
            'from_email' => $this->config['from_email'],
            'connection_test' => $this->test()
        ];
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
}