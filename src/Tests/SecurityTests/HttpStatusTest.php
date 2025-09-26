<?php

namespace SecurityScanner\Tests\SecurityTests;

use SecurityScanner\Tests\{AbstractTest, TestResult};

class HttpStatusTest extends AbstractTest
{
    public function getName(): string
    {
        return 'http_status_check';
    }

    public function getDescription(): string
    {
        return 'Checks HTTP status code and basic availability';
    }

    public function getCategory(): string
    {
        return 'availability';
    }

    protected function getTags(): array
    {
        return ['availability', 'status', 'monitoring'];
    }

    public function run(string $target, array $context = []): TestResult
    {
        $response = $this->makeHttpRequest($target);

        if (!$response['success']) {
            return $this->createFailureResult('HTTP request failed', [
                'error' => 'Connection failed or timed out'
            ]);
        }

        $statusCode = $response['status_code'];
        $responseTime = $response['response_time'];

        $data = [
            'status_code' => $statusCode,
            'response_time' => $responseTime,
            'headers_count' => count($response['headers'])
        ];

        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->createSuccessResult("HTTP status OK ({$statusCode})", $data);
        } elseif ($statusCode >= 300 && $statusCode < 400) {
            return $this->createWarningResult("HTTP redirect ({$statusCode})", $data);
        } else {
            return $this->createFailureResult("HTTP error status ({$statusCode})", $data);
        }
    }
}