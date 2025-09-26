<?php

namespace SecurityScanner\Tests;

class TestResult
{
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIP = 'skip';
    public const STATUS_TIMEOUT = 'timeout';

    private string $testName;
    private string $status;
    private string $message;
    private array $data;
    private float $executionTime;
    private int $memoryUsage;
    private \DateTime $timestamp;
    private ?string $target;
    private array $context;
    private ?int $score;
    private array $recommendations;

    public function __construct(
        string $testName,
        string $status,
        string $message = '',
        array $data = [],
        float $executionTime = 0.0,
        int $memoryUsage = 0,
        ?string $target = null
    ) {
        $this->testName = $testName;
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
        $this->executionTime = $executionTime;
        $this->memoryUsage = $memoryUsage;
        $this->timestamp = new \DateTime();
        $this->target = $target;
        $this->context = [];
        $this->score = null;
        $this->recommendations = [];

        $this->validateStatus();
    }

    /**
     * Validate status is valid
     */
    private function validateStatus(): void
    {
        $validStatuses = [
            self::STATUS_PASS,
            self::STATUS_FAIL,
            self::STATUS_WARNING,
            self::STATUS_ERROR,
            self::STATUS_SKIP,
            self::STATUS_TIMEOUT
        ];

        if (!in_array($this->status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid test result status: {$this->status}");
        }
    }

    /**
     * Get test name
     */
    public function getTestName(): string
    {
        return $this->testName;
    }

    /**
     * Get status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set status
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->validateStatus();
        return $this;
    }

    /**
     * Get message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set message
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Get data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Add data item
     */
    public function addData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Get execution time
     */
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    /**
     * Set execution time
     */
    public function setExecutionTime(float $executionTime): self
    {
        $this->executionTime = $executionTime;
        return $this;
    }

    /**
     * Get memory usage
     */
    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }

    /**
     * Set memory usage
     */
    public function setMemoryUsage(int $memoryUsage): self
    {
        $this->memoryUsage = $memoryUsage;
        return $this;
    }

    /**
     * Get timestamp
     */
    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }

    /**
     * Set timestamp
     */
    public function setTimestamp(\DateTime $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * Get target
     */
    public function getTarget(): ?string
    {
        return $this->target;
    }

    /**
     * Set target
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }

    /**
     * Get context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set context
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add context item
     */
    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get score (0-100)
     */
    public function getScore(): ?int
    {
        return $this->score;
    }

    /**
     * Set score (0-100)
     */
    public function setScore(int $score): self
    {
        if ($score < 0 || $score > 100) {
            throw new \InvalidArgumentException("Score must be between 0 and 100, got: {$score}");
        }
        $this->score = $score;
        return $this;
    }

    /**
     * Get recommendations
     */
    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    /**
     * Set recommendations
     */
    public function setRecommendations(array $recommendations): self
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    /**
     * Add recommendation
     */
    public function addRecommendation(string $recommendation): self
    {
        $this->recommendations[] = $recommendation;
        return $this;
    }

    /**
     * Check if test passed
     */
    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    /**
     * Check if test failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    /**
     * Check if test has warning
     */
    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    /**
     * Check if test has error
     */
    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Check if test was skipped
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIP;
    }

    /**
     * Check if test timed out
     */
    public function isTimeout(): bool
    {
        return $this->status === self::STATUS_TIMEOUT;
    }

    /**
     * Check if result is successful (pass or warning)
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_PASS, self::STATUS_WARNING]);
    }

    /**
     * Check if result indicates a problem (fail, error, timeout)
     */
    public function hasProblems(): bool
    {
        return in_array($this->status, [self::STATUS_FAIL, self::STATUS_ERROR, self::STATUS_TIMEOUT]);
    }

    /**
     * Get severity level (0-4, higher is more severe)
     */
    public function getSeverityLevel(): int
    {
        return match ($this->status) {
            self::STATUS_PASS => 0,
            self::STATUS_WARNING => 1,
            self::STATUS_SKIP => 1,
            self::STATUS_FAIL => 2,
            self::STATUS_TIMEOUT => 3,
            self::STATUS_ERROR => 4,
            default => 4
        };
    }

    /**
     * Get status emoji for display
     */
    public function getStatusEmoji(): string
    {
        return match ($this->status) {
            self::STATUS_PASS => '✅',
            self::STATUS_WARNING => '⚠️',
            self::STATUS_SKIP => '⏭️',
            self::STATUS_FAIL => '❌',
            self::STATUS_TIMEOUT => '⏰',
            self::STATUS_ERROR => '🔥',
            default => '❓'
        };
    }

    /**
     * Get status color for terminal output
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PASS => 'green',
            self::STATUS_WARNING => 'yellow',
            self::STATUS_SKIP => 'blue',
            self::STATUS_FAIL => 'red',
            self::STATUS_TIMEOUT => 'magenta',
            self::STATUS_ERROR => 'red',
            default => 'white'
        };
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'test_name' => $this->testName,
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
            'execution_time' => $this->executionTime,
            'memory_usage' => $this->memoryUsage,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'target' => $this->target,
            'context' => $this->context,
            'score' => $this->score,
            'recommendations' => $this->recommendations,
            'severity_level' => $this->getSeverityLevel()
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $result = new self(
            $data['test_name'],
            $data['status'],
            $data['message'] ?? '',
            $data['data'] ?? [],
            $data['execution_time'] ?? 0.0,
            $data['memory_usage'] ?? 0,
            $data['target'] ?? null
        );

        if (isset($data['timestamp'])) {
            $result->setTimestamp(new \DateTime($data['timestamp']));
        }

        if (isset($data['context'])) {
            $result->setContext($data['context']);
        }

        if (isset($data['score'])) {
            $result->setScore($data['score']);
        }

        if (isset($data['recommendations'])) {
            $result->setRecommendations($data['recommendations']);
        }

        return $result;
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Create from JSON
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON provided');
        }
        return self::fromArray($data);
    }

    /**
     * Get formatted summary for display
     */
    public function getSummary(): string
    {
        $emoji = $this->getStatusEmoji();
        $status = strtoupper($this->status);
        $time = number_format($this->executionTime * 1000, 2);
        $memory = $this->formatBytes($this->memoryUsage);

        $summary = "{$emoji} {$status}: {$this->testName}";

        if ($this->message) {
            $summary .= " - {$this->message}";
        }

        $summary .= " [{$time}ms, {$memory}]";

        if ($this->score !== null) {
            $summary .= " [Score: {$this->score}/100]";
        }

        return $summary;
    }

    /**
     * Format bytes for display
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }

    /**
     * Merge with another result (for aggregation)
     */
    public function mergeWith(TestResult $other): self
    {
        // Take the worst status
        if ($other->getSeverityLevel() > $this->getSeverityLevel()) {
            $this->status = $other->getStatus();
            $this->message = $other->getMessage();
        }

        // Merge data
        $this->data = array_merge($this->data, $other->getData());

        // Add execution times
        $this->executionTime += $other->getExecutionTime();

        // Add memory usage
        $this->memoryUsage += $other->getMemoryUsage();

        // Merge recommendations
        $this->recommendations = array_merge($this->recommendations, $other->getRecommendations());

        // Use lower score if both have scores
        if ($this->score !== null && $other->getScore() !== null) {
            $this->score = min($this->score, $other->getScore());
        } elseif ($other->getScore() !== null) {
            $this->score = $other->getScore();
        }

        return $this;
    }
}