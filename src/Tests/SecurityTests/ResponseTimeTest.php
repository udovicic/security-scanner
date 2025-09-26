<?php

namespace SecurityScanner\Tests\SecurityTests;

use SecurityScanner\Tests\{AbstractTest, TestResult};

class ResponseTimeTest extends AbstractTest
{
    public function getName(): string
    {
        return 'response_time_check';
    }

    public function getDescription(): string
    {
        return 'Checks website response time performance';
    }

    public function getCategory(): string
    {
        return 'performance';
    }

    protected function getTags(): array
    {
        return ['performance', 'monitoring', 'speed'];
    }

    protected function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'warning_threshold' => 2.0,
            'critical_threshold' => 5.0,
            'samples' => 3
        ]);
    }

    public function run(string $target, array $context = []): TestResult
    {
        $samples = $this->config['samples'];
        $times = [];

        for ($i = 0; $i < $samples; $i++) {
            $response = $this->makeHttpRequest($target);
            if ($response['success']) {
                $times[] = $response['response_time'];
            }
        }

        if (empty($times)) {
            return $this->createErrorResult('All response time measurements failed');
        }

        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);

        $data = [
            'average_time' => $avgTime,
            'min_time' => $minTime,
            'max_time' => $maxTime,
            'samples' => count($times),
            'all_times' => $times
        ];

        if ($avgTime <= $this->config['warning_threshold']) {
            return $this->createSuccessResult(
                sprintf('Response time is good (%.2fs avg)', $avgTime),
                $data
            );
        } elseif ($avgTime <= $this->config['critical_threshold']) {
            return $this->createWarningResult(
                sprintf('Response time is slow (%.2fs avg)', $avgTime),
                $data
            );
        } else {
            return $this->createFailureResult(
                sprintf('Response time is critical (%.2fs avg)', $avgTime),
                $data
            );
        }
    }
}