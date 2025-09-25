<?php

namespace SecurityScanner\Core;

abstract class AbstractTest
{
    protected Config $config;
    protected Logger $logger;
    protected string $name;
    protected string $description;
    protected array $supportedMethods = ['GET', 'POST', 'HEAD'];
    protected int $timeout = 30;
    protected bool $followRedirects = true;
    protected int $maxRedirects = 5;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::scheduler();

        // Set default timeout from config
        $this->timeout = $this->config->get('tests.timeout', 30);
        $this->maxRedirects = $this->config->get('tests.max_redirects', 5);
    }

    /**
     * Execute the test against a URL
     */
    abstract public function execute(string $url, array $options = []): TestResult;

    /**
     * Get test name
     */
    public function getName(): string
    {
        if (isset($this->name)) {
            return $this->name;
        }

        // Generate name from class name
        $className = (new \ReflectionClass($this))->getShortName();
        return preg_replace('/Test$/', '', $className);
    }

    /**
     * Get test description
     */
    public function getDescription(): string
    {
        return $this->description ?? 'No description provided';
    }

    /**
     * Get supported HTTP methods for this test
     */
    public function getSupportedMethods(): array
    {
        return $this->supportedMethods;
    }

    /**
     * Check if test supports a specific HTTP method
     */
    public function supportsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->supportedMethods);
    }

    /**
     * Get test timeout in seconds
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set test timeout
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Check if test follows redirects
     */
    public function followsRedirects(): bool
    {
        return $this->followRedirects;
    }

    /**
     * Set redirect following behavior
     */
    public function setFollowRedirects(bool $follow): self
    {
        $this->followRedirects = $follow;
        return $this;
    }

    /**
     * Get maximum number of redirects to follow
     */
    public function getMaxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /**
     * Set maximum redirects
     */
    public function setMaxRedirects(int $max): self
    {
        $this->maxRedirects = $max;
        return $this;
    }

    /**
     * Make HTTP request with common configuration
     */
    protected function makeRequest(string $url, array $options = []): array
    {
        $defaultOptions = [
            'timeout' => $this->timeout,
            'follow_redirects' => $this->followRedirects,
            'max_redirects' => $this->maxRedirects,
            'user_agent' => $this->config->get('tests.user_agent', 'Security Scanner Bot/1.0'),
            'verify_ssl' => $this->config->get('tests.verify_ssl', true),
        ];

        $options = array_merge($defaultOptions, $options);

        return $this->performHttpRequest($url, $options);
    }

    /**
     * Perform actual HTTP request
     */
    protected function performHttpRequest(string $url, array $options): array
    {
        $startTime = microtime(true);

        try {
            // Initialize cURL
            $ch = curl_init();

            // Set basic cURL options
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $options['timeout'],
                CURLOPT_USERAGENT => $options['user_agent'],
                CURLOPT_FOLLOWLOCATION => $options['follow_redirects'],
                CURLOPT_MAXREDIRS => $options['max_redirects'],
                CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'],
                CURLOPT_SSL_VERIFYHOST => $options['verify_ssl'] ? 2 : 0,
            ]);

            // Set HTTP method
            if (isset($options['method'])) {
                $method = strtoupper($options['method']);
                switch ($method) {
                    case 'POST':
                        curl_setopt($ch, CURLOPT_POST, true);
                        if (isset($options['data'])) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
                        }
                        break;
                    case 'HEAD':
                        curl_setopt($ch, CURLOPT_NOBODY, true);
                        break;
                    case 'PUT':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                        if (isset($options['data'])) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
                        }
                        break;
                }
            }

            // Set custom headers
            if (isset($options['headers']) && is_array($options['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
            }

            // Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            if ($response === false) {
                throw new \Exception('cURL error: ' . curl_error($ch));
            }

            curl_close($ch);

            // Parse response
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'headers' => $this->parseHeaders($headers),
                'body' => $body,
                'response_time' => $responseTime,
                'curl_time' => $totalTime,
                'url' => $url,
            ];

        } catch (\Exception $e) {
            if (isset($ch)) {
                curl_close($ch);
            }

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'response_time' => $responseTime,
                'url' => $url,
            ];
        }
    }

    /**
     * Parse HTTP headers from cURL response
     */
    protected function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($headerString));

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim(strtolower($key));
                $value = trim($value);
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    /**
     * Create a successful test result
     */
    protected function createSuccessResult(string $message, array $details = []): TestResult
    {
        return new TestResult(
            $this->getName(),
            TestResult::STATUS_PASSED,
            $message,
            $details
        );
    }

    /**
     * Create a failed test result
     */
    protected function createFailureResult(string $message, array $details = []): TestResult
    {
        return new TestResult(
            $this->getName(),
            TestResult::STATUS_FAILED,
            $message,
            $details
        );
    }

    /**
     * Create an error test result
     */
    protected function createErrorResult(string $message, array $details = []): TestResult
    {
        return new TestResult(
            $this->getName(),
            TestResult::STATUS_ERROR,
            $message,
            $details
        );
    }

    /**
     * Log test execution
     */
    protected function logExecution(string $url, TestResult $result): void
    {
        $this->logger->info("Test executed", [
            'test' => $this->getName(),
            'url' => $url,
            'status' => $result->getStatus(),
            'message' => $result->getMessage(),
            'response_time' => $result->getDetail('response_time'),
        ]);
    }

    /**
     * Validate URL before testing
     */
    protected function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Get test configuration
     */
    protected function getTestConfig(string $key, $default = null)
    {
        return $this->config->get("tests.{$key}", $default);
    }
}