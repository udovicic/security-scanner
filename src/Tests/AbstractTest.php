<?php

namespace SecurityScanner\Tests;

abstract class AbstractTest
{
    protected string $name;
    protected string $description;
    protected array $config;
    protected array $metadata;
    protected float $timeout;
    protected int $maxRetries;
    protected string $category;
    protected array $tags;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->name = $this->getName();
        $this->description = $this->getDescription();
        $this->metadata = $this->getMetadata();
        $this->timeout = $this->config['timeout'] ?? 30.0;
        $this->maxRetries = $this->config['max_retries'] ?? 3;
        $this->category = $this->getCategory();
        $this->tags = $this->getTags();
    }

    /**
     * Execute the test and return result
     */
    abstract public function run(string $target, array $context = []): TestResult;

    /**
     * Get test name
     */
    abstract public function getName(): string;

    /**
     * Get test description
     */
    abstract public function getDescription(): string;

    /**
     * Get test category
     */
    abstract public function getCategory(): string;

    /**
     * Get default configuration for this test
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30.0,
            'max_retries' => 3,
            'retry_delay' => 1.0,
            'priority' => 5,
            'parallel_safe' => true,
            'cache_results' => false,
            'cache_duration' => 300
        ];
    }

    /**
     * Get test metadata
     */
    protected function getMetadata(): array
    {
        return [
            'version' => '1.0.0',
            'author' => 'Security Scanner Tool',
            'created' => date('Y-m-d'),
            'updated' => date('Y-m-d'),
            'requires' => [],
            'conflicts' => []
        ];
    }

    /**
     * Get test tags
     */
    protected function getTags(): array
    {
        return ['general'];
    }

    /**
     * Validate target before running test
     */
    protected function validateTarget(string $target): bool
    {
        if (empty($target)) {
            return false;
        }

        // Basic URL validation
        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return true;
        }

        // IP address validation
        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Domain name validation
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $target)) {
            return true;
        }

        return false;
    }

    /**
     * Setup test environment
     */
    protected function setUp(): void
    {
        // Override in subclasses if needed
    }

    /**
     * Cleanup test environment
     */
    protected function tearDown(): void
    {
        // Override in subclasses if needed
    }

    /**
     * Handle test execution with error handling
     */
    public function execute(string $target, array $context = []): TestResult
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);

        try {
            // Validate target
            if (!$this->validateTarget($target)) {
                return new TestResult(
                    $this->name,
                    TestResult::STATUS_ERROR,
                    "Invalid target: {$target}",
                    [],
                    microtime(true) - $startTime,
                    memory_get_usage(true) - $memoryStart
                );
            }

            // Setup test environment
            $this->setUp();

            // Execute the actual test
            $result = $this->run($target, $context);

            // Add execution metadata
            $result->setExecutionTime(microtime(true) - $startTime);
            $result->setMemoryUsage(memory_get_usage(true) - $memoryStart);

            return $result;

        } catch (\Exception $e) {
            return new TestResult(
                $this->name,
                TestResult::STATUS_ERROR,
                "Test execution failed: " . $e->getMessage(),
                ['exception' => $e->getTraceAsString()],
                microtime(true) - $startTime,
                memory_get_usage(true) - $memoryStart
            );
        } finally {
            // Always cleanup
            $this->tearDown();
        }
    }

    /**
     * Create success result
     */
    protected function createSuccessResult(string $message = '', array $data = []): TestResult
    {
        return new TestResult(
            $this->name,
            TestResult::STATUS_PASS,
            $message ?: "Test passed successfully",
            $data
        );
    }

    /**
     * Create failure result
     */
    protected function createFailureResult(string $message = '', array $data = []): TestResult
    {
        return new TestResult(
            $this->name,
            TestResult::STATUS_FAIL,
            $message ?: "Test failed",
            $data
        );
    }

    /**
     * Create warning result
     */
    protected function createWarningResult(string $message = '', array $data = []): TestResult
    {
        return new TestResult(
            $this->name,
            TestResult::STATUS_WARNING,
            $message ?: "Test completed with warnings",
            $data
        );
    }

    /**
     * Create error result
     */
    protected function createErrorResult(string $message = '', array $data = []): TestResult
    {
        return new TestResult(
            $this->name,
            TestResult::STATUS_ERROR,
            $message ?: "Test encountered an error",
            $data
        );
    }

    /**
     * Create skipped result
     */
    protected function createSkippedResult(string $message = '', array $data = []): TestResult
    {
        return new TestResult(
            $this->name,
            TestResult::STATUS_SKIP,
            $message ?: "Test was skipped",
            $data
        );
    }

    /**
     * Make HTTP request with timeout and error handling
     */
    protected function makeHttpRequest(string $url, array $options = []): array
    {
        $defaultOptions = [
            'method' => 'GET',
            'headers' => [
                'User-Agent' => 'SecurityScanner/1.0 (+https://github.com/security-scanner/tool)'
            ],
            'timeout' => $this->timeout,
            'follow_redirects' => true,
            'max_redirects' => 10,
            'ssl_verify' => true
        ];

        $options = array_merge($defaultOptions, $options);

        $context = stream_context_create([
            'http' => [
                'method' => $options['method'],
                'header' => $this->formatHeaders($options['headers']),
                'timeout' => $options['timeout'],
                'follow_location' => $options['follow_redirects'] ? 1 : 0,
                'max_redirects' => $options['max_redirects'],
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => $options['ssl_verify'],
                'verify_peer_name' => $options['ssl_verify']
            ]
        ]);

        $startTime = microtime(true);
        $content = @file_get_contents($url, false, $context);
        $responseTime = microtime(true) - $startTime;

        $httpResponseHeader = $http_response_header ?? [];

        return [
            'content' => $content,
            'headers' => $httpResponseHeader,
            'response_time' => $responseTime,
            'status_code' => $this->extractStatusCode($httpResponseHeader),
            'success' => $content !== false
        ];
    }

    /**
     * Format headers for HTTP context
     */
    private function formatHeaders(array $headers): string
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return implode("\r\n", $formatted);
    }

    /**
     * Extract status code from response headers
     */
    private function extractStatusCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        $statusLine = $headers[0] ?? '';
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Parse URL components safely
     */
    protected function parseUrl(string $url): array
    {
        $components = parse_url($url);

        if ($components === false) {
            return [];
        }

        return array_merge([
            'scheme' => '',
            'host' => '',
            'port' => 0,
            'path' => '',
            'query' => '',
            'fragment' => ''
        ], $components);
    }

    /**
     * Check if test should be skipped
     */
    public function shouldSkip(string $target, array $context = []): bool
    {
        // Check if test is enabled
        if (!$this->config['enabled']) {
            return true;
        }

        // Check if target is valid
        if (!$this->validateTarget($target)) {
            return true;
        }

        // Check requirements
        foreach ($this->metadata['requires'] as $requirement) {
            if (!$this->checkRequirement($requirement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if requirement is met
     */
    private function checkRequirement(string $requirement): bool
    {
        // Check PHP extensions
        if (str_starts_with($requirement, 'ext:')) {
            $extension = substr($requirement, 4);
            return extension_loaded($extension);
        }

        // Check PHP functions
        if (str_starts_with($requirement, 'func:')) {
            $function = substr($requirement, 5);
            return function_exists($function);
        }

        // Check PHP version
        if (str_starts_with($requirement, 'php:')) {
            $version = substr($requirement, 4);
            return version_compare(PHP_VERSION, $version, '>=');
        }

        return true;
    }

    /**
     * Get test configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set configuration option
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Get test information for registry
     */
    public function getTestInfo(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'config' => $this->config,
            'class' => get_class($this)
        ];
    }

    /**
     * Log debug information
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        // Simple logging - could be enhanced with proper logger
        if ($this->config['debug'] ?? false) {
            $timestamp = date('Y-m-d H:i:s');
            echo "[{$timestamp}] [{$level}] {$this->name}: {$message}\n";
            if (!empty($context)) {
                echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
            }
        }
    }
}