<?php

namespace SecurityScanner\Tests\SecurityTests;

use SecurityScanner\Tests\{AbstractTest, TestResult};

class SecurityHeadersTest extends AbstractTest
{
    public function getName(): string
    {
        return 'security_headers_check';
    }

    public function getDescription(): string
    {
        return 'Checks for presence and configuration of security headers';
    }

    public function getCategory(): string
    {
        return 'security';
    }

    protected function getTags(): array
    {
        return ['security', 'headers', 'xss', 'csrf', 'clickjacking'];
    }

    public function run(string $target, array $context = []): TestResult
    {
        $response = $this->makeHttpRequest($target);

        if (!$response['success']) {
            return $this->createErrorResult('Could not fetch headers from target');
        }

        $headers = $this->parseHeaders($response['headers']);
        return $this->analyzeSecurityHeaders($headers);
    }

    private function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }

    private function analyzeSecurityHeaders(array $headers): TestResult
    {
        $requiredHeaders = [
            'x-frame-options' => 'Prevents clickjacking attacks',
            'x-content-type-options' => 'Prevents MIME type sniffing',
            'x-xss-protection' => 'Enables XSS protection',
            'strict-transport-security' => 'Enforces HTTPS connections',
            'content-security-policy' => 'Prevents XSS and data injection'
        ];

        $recommendedHeaders = [
            'referrer-policy' => 'Controls referrer information',
            'permissions-policy' => 'Controls browser features'
        ];

        $missing = [];
        $present = [];
        $misonfigured = [];
        $score = 100;

        foreach ($requiredHeaders as $header => $description) {
            if (isset($headers[$header])) {
                $present[$header] = $headers[$header];
                $issues = $this->validateHeaderValue($header, $headers[$header]);
                if (!empty($issues)) {
                    $misonfigured[$header] = $issues;
                    $score -= 15;
                }
            } else {
                $missing[] = $header;
                $score -= 20;
            }
        }

        foreach ($recommendedHeaders as $header => $description) {
            if (!isset($headers[$header])) {
                $score -= 5;
            }
        }

        $data = [
            'present_headers' => $present,
            'missing_headers' => $missing,
            'misconfigured_headers' => $misonfigured,
            'all_headers' => $headers
        ];

        if (!empty($missing) || !empty($misonfigured)) {
            $issues = [];
            if (!empty($missing)) {
                $issues[] = 'Missing headers: ' . implode(', ', $missing);
            }
            if (!empty($misonfigured)) {
                $issues[] = 'Misconfigured headers: ' . implode(', ', array_keys($misonfigured));
            }
            $result = $this->createFailureResult(implode('; ', $issues), $data);
        } else {
            $result = $this->createSuccessResult('All security headers are properly configured', $data);
        }

        $result->setScore(max(0, $score));
        return $result;
    }

    private function validateHeaderValue(string $header, string $value): array
    {
        $issues = [];

        switch ($header) {
            case 'x-frame-options':
                if (!in_array(strtoupper($value), ['DENY', 'SAMEORIGIN'])) {
                    $issues[] = 'Should be DENY or SAMEORIGIN';
                }
                break;

            case 'x-content-type-options':
                if (strtolower($value) !== 'nosniff') {
                    $issues[] = 'Should be "nosniff"';
                }
                break;

            case 'strict-transport-security':
                if (!str_contains($value, 'max-age=')) {
                    $issues[] = 'Missing max-age directive';
                }
                if (!str_contains($value, 'includeSubDomains')) {
                    $issues[] = 'Missing includeSubDomains directive';
                }
                break;
        }

        return $issues;
    }
}