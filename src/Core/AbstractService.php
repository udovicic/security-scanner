<?php

namespace SecurityScanner\Core;

abstract class AbstractService
{
    protected Config $config;
    protected Logger $logger;
    protected Database $db;
    protected array $dependencies = [];

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::access(); // Use access channel for service operations
        $this->db = Database::getInstance();
    }

    /**
     * Set service dependency
     */
    public function setDependency(string $key, $service): self
    {
        $this->dependencies[$key] = $service;
        return $this;
    }

    /**
     * Get service dependency
     */
    protected function getDependency(string $key)
    {
        if (!isset($this->dependencies[$key])) {
            throw new \Exception("Dependency '{$key}' not found in " . get_class($this));
        }
        return $this->dependencies[$key];
    }

    /**
     * Check if dependency exists
     */
    protected function hasDependency(string $key): bool
    {
        return isset($this->dependencies[$key]);
    }

    /**
     * Execute service operation with error handling
     */
    protected function execute(callable $operation, string $operationName = 'operation')
    {
        $startTime = microtime(true);

        try {
            $this->logOperation($operationName, 'started');

            $result = $operation();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logOperation($operationName, 'completed', [
                'execution_time' => $executionTime . 'ms'
            ]);

            return $result;

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error("Service operation failed", [
                'service' => get_class($this),
                'operation' => $operationName,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime . 'ms',
                'trace' => $e->getTraceAsString()
            ]);

            throw new ServiceException(
                "Service operation '{$operationName}' failed: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Execute operation with retry logic
     */
    protected function executeWithRetry(
        callable $operation,
        int $maxRetries = 3,
        int $delayMs = 1000,
        string $operationName = 'operation'
    ) {
        $attempt = 1;

        while ($attempt <= $maxRetries) {
            try {
                return $this->execute($operation, $operationName);
            } catch (ServiceException $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }

                $this->logger->warning("Service operation retry", [
                    'service' => get_class($this),
                    'operation' => $operationName,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage()
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $attempt++;
            }
        }
    }

    /**
     * Execute operation with database transaction
     */
    protected function executeWithTransaction(callable $operation, string $operationName = 'transaction')
    {
        return $this->execute(function() use ($operation, $operationName) {
            $this->db->getConnection()->beginTransaction();

            try {
                $result = $operation();
                $this->db->getConnection()->commit();
                return $result;
            } catch (\Exception $e) {
                $this->db->getConnection()->rollBack();
                throw $e;
            }
        }, $operationName);
    }

    /**
     * Validate input data
     */
    protected function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;

            if (is_string($rule)) {
                $rule = explode('|', $rule);
            }

            foreach ($rule as $r) {
                [$ruleName, $ruleParam] = explode(':', $r . ':');

                switch ($ruleName) {
                    case 'required':
                        if (empty($value)) {
                            $errors[$field][] = "The {$field} field is required";
                        }
                        break;
                    case 'string':
                        if (!is_null($value) && !is_string($value)) {
                            $errors[$field][] = "The {$field} must be a string";
                        }
                        break;
                    case 'integer':
                        if (!is_null($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                            $errors[$field][] = "The {$field} must be an integer";
                        }
                        break;
                    case 'email':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "The {$field} must be a valid email address";
                        }
                        break;
                    case 'url':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field][] = "The {$field} must be a valid URL";
                        }
                        break;
                    case 'min':
                        if (!empty($value) && strlen($value) < (int)$ruleParam) {
                            $errors[$field][] = "The {$field} must be at least {$ruleParam} characters";
                        }
                        break;
                    case 'max':
                        if (!empty($value) && strlen($value) > (int)$ruleParam) {
                            $errors[$field][] = "The {$field} must not exceed {$ruleParam} characters";
                        }
                        break;
                    case 'in':
                        $allowedValues = explode(',', $ruleParam);
                        if (!empty($value) && !in_array($value, $allowedValues)) {
                            $errors[$field][] = "The {$field} must be one of: " . implode(', ', $allowedValues);
                        }
                        break;
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $data;
    }

    /**
     * Cache operation result
     */
    protected function cache(string $key, callable $operation, int $ttl = 3600)
    {
        $cacheKey = $this->getCacheKey($key);

        // Simple file-based caching (in production, use Redis/Memcached)
        $cacheFile = $this->config->get('cache.path', '/tmp') . '/' . md5($cacheKey) . '.cache';

        if (file_exists($cacheFile) && (filemtime($cacheFile) + $ttl) > time()) {
            $cached = file_get_contents($cacheFile);
            return unserialize($cached);
        }

        $result = $operation();

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cacheFile, serialize($result));
        return $result;
    }

    /**
     * Clear cache by key pattern
     */
    protected function clearCache(string $pattern = '*'): int
    {
        $cacheDir = $this->config->get('cache.path', '/tmp');
        $pattern = str_replace('*', '.*', $pattern);
        $cleared = 0;

        if (is_dir($cacheDir)) {
            $files = scandir($cacheDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                if (preg_match("/{$pattern}/", $file)) {
                    unlink($cacheDir . '/' . $file);
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $key): string
    {
        return get_class($this) . ':' . $key;
    }

    /**
     * Send notification
     */
    protected function notify(string $type, string $message, array $data = []): void
    {
        $this->logger->info('Notification sent', [
            'type' => $type,
            'message' => $message,
            'data' => $data
        ]);

        // In production, implement actual notification sending
        // (email, SMS, webhook, etc.)
    }

    /**
     * Log service operation
     */
    protected function logOperation(string $operation, string $status, array $context = []): void
    {
        $this->logger->info("Service operation {$status}", array_merge([
            'service' => get_class($this),
            'operation' => $operation,
            'status' => $status
        ], $context));
    }

    /**
     * Get configuration value with service prefix
     */
    protected function getConfig(string $key, $default = null)
    {
        $serviceKey = $this->getServiceConfigKey($key);
        return $this->config->get($serviceKey, $default);
    }

    /**
     * Generate service-specific config key
     */
    protected function getServiceConfigKey(string $key): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        $serviceName = strtolower(preg_replace('/Service$/', '', $className));
        return "services.{$serviceName}.{$key}";
    }

    /**
     * Format data for API response
     */
    protected function formatApiResponse(array $data, string $message = 'Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
            'service' => get_class($this)
        ];
    }

    /**
     * Format error response
     */
    protected function formatErrorResponse(string $message, array $errors = []): array
    {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c'),
            'service' => get_class($this)
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $response;
    }

    /**
     * Measure execution time
     */
    protected function measureTime(callable $operation): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $result = $operation();

        return [
            'result' => $result,
            'execution_time' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_usage' => memory_get_usage() - $startMemory,
            'peak_memory' => memory_get_peak_usage()
        ];
    }

    /**
     * Check service health
     */
    public function healthCheck(): array
    {
        return [
            'service' => get_class($this),
            'status' => 'healthy',
            'timestamp' => date('c'),
            'dependencies' => array_keys($this->dependencies)
        ];
    }
}