<?php

namespace SecurityScanner\Core;

class TestResult
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    protected string $testName;
    protected string $status;
    protected string $message;
    protected array $details;
    protected float $executionTime;
    protected \DateTime $timestamp;

    public function __construct(
        string $testName,
        string $status,
        string $message,
        array $details = [],
        float $executionTime = 0.0
    ) {
        $this->testName = $testName;
        $this->status = $status;
        $this->message = $message;
        $this->details = $details;
        $this->executionTime = $executionTime;
        $this->timestamp = new \DateTime();

        $this->validateStatus($status);
    }

    /**
     * Get test name
     */
    public function getTestName(): string
    {
        return $this->testName;
    }

    /**
     * Get test status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get test message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get test details
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get specific detail by key
     */
    public function getDetail(string $key, $default = null)
    {
        return $this->details[$key] ?? $default;
    }

    /**
     * Get execution time in seconds
     */
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    /**
     * Get timestamp when result was created
     */
    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }

    /**
     * Check if test passed
     */
    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /**
     * Check if test failed
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if test had an error
     */
    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Check if test was skipped
     */
    public function wasSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Add detail to the result
     */
    public function addDetail(string $key, $value): self
    {
        $this->details[$key] = $value;
        return $this;
    }

    /**
     * Set execution time
     */
    public function setExecutionTime(float $time): self
    {
        $this->executionTime = $time;
        return $this;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'test_name' => $this->testName,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'execution_time' => $this->executionTime,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'passed' => $this->passed(),
            'failed' => $this->failed(),
            'has_error' => $this->hasError(),
            'was_skipped' => $this->wasSkipped()
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Get summary status
     */
    public function getSummary(): array
    {
        return [
            'test' => $this->testName,
            'status' => $this->status,
            'message' => $this->message,
            'execution_time' => $this->executionTime . 's'
        ];
    }

    /**
     * Validate status value
     */
    protected function validateStatus(string $status): void
    {
        $validStatuses = [
            self::STATUS_PASSED,
            self::STATUS_FAILED,
            self::STATUS_ERROR,
            self::STATUS_SKIPPED
        ];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Invalid test status '{$status}'. Must be one of: " . implode(', ', $validStatuses)
            );
        }
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['test_name'],
            $data['status'],
            $data['message'],
            $data['details'] ?? [],
            $data['execution_time'] ?? 0.0
        );
    }

    /**
     * Magic method for string representation
     */
    public function __toString(): string
    {
        $statusIcon = match ($this->status) {
            self::STATUS_PASSED => '✅',
            self::STATUS_FAILED => '❌',
            self::STATUS_ERROR => '💥',
            self::STATUS_SKIPPED => '⏭️',
            default => '❓'
        };

        return sprintf(
            '%s %s: %s (%.2fs)',
            $statusIcon,
            $this->testName,
            $this->message,
            $this->executionTime
        );
    }
}