<?php

namespace SecurityScanner\Core;

class RateLimiter
{
    private array $config;
    private string $storageType;
    private array $memoryStorage = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'storage' => 'memory', // memory, file, database
            'storage_path' => sys_get_temp_dir() . '/rate_limiter/',
            'default_limit' => 100,
            'default_window' => 3600, // 1 hour in seconds
            'cleanup_probability' => 0.01, // 1% chance to run cleanup
            'key_prefix' => 'rate_limit:',
            'burst_protection' => true,
            'burst_limit' => 10,
            'burst_window' => 60, // 1 minute
        ], $config);

        $this->storageType = $this->config['storage'];
        $this->initializeStorage();
    }

    /**
     * Check if a key is within rate limits
     */
    public function check(string $key, int $limit = null, int $window = null): bool
    {
        $limit = $limit ?? $this->config['default_limit'];
        $window = $window ?? $this->config['default_window'];

        $current = $this->getCurrentCount($key, $window);

        // Check main rate limit
        if ($current >= $limit) {
            return false;
        }

        // Check burst protection if enabled
        if ($this->config['burst_protection']) {
            $burstCount = $this->getCurrentCount($key, $this->config['burst_window']);
            if ($burstCount >= $this->config['burst_limit']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to consume from rate limit
     */
    public function attempt(string $key, int $limit = null, int $window = null): bool
    {
        if ($this->check($key, $limit, $window)) {
            $this->increment($key);
            return true;
        }
        return false;
    }

    /**
     * Get current count for a key
     */
    public function getCurrentCount(string $key, int $window = null): int
    {
        $window = $window ?? $this->config['default_window'];
        $storageKey = $this->getStorageKey($key, $window);

        $data = $this->getData($storageKey);
        if (!$data) {
            return 0;
        }

        // Clean expired entries
        $currentTime = time();
        $windowStart = $currentTime - $window;

        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        $this->setData($storageKey, $data, $window);

        return count($data['requests']);
    }

    /**
     * Get remaining attempts
     */
    public function getRemainingAttempts(string $key, int $limit = null, int $window = null): int
    {
        $limit = $limit ?? $this->config['default_limit'];
        $current = $this->getCurrentCount($key, $window);
        return max(0, $limit - $current);
    }

    /**
     * Get time until reset
     */
    public function getTimeUntilReset(string $key, int $window = null): int
    {
        $window = $window ?? $this->config['default_window'];
        $storageKey = $this->getStorageKey($key, $window);

        $data = $this->getData($storageKey);
        if (!$data || empty($data['requests'])) {
            return 0;
        }

        $oldestRequest = min($data['requests']);
        $resetTime = $oldestRequest + $window;

        return max(0, $resetTime - time());
    }

    /**
     * Increment counter for a key
     */
    public function increment(string $key, int $window = null): void
    {
        $window = $window ?? $this->config['default_window'];
        $storageKey = $this->getStorageKey($key, $window);

        $data = $this->getData($storageKey) ?? ['requests' => []];
        $data['requests'][] = time();

        $this->setData($storageKey, $data, $window * 2); // Store for longer to handle cleanup properly

        // Also increment burst protection if enabled
        if ($this->config['burst_protection']) {
            $burstKey = $this->getStorageKey($key, $this->config['burst_window']);
            $burstData = $this->getData($burstKey) ?? ['requests' => []];
            $burstData['requests'][] = time();
            $this->setData($burstKey, $burstData, $this->config['burst_window'] * 2);
        }

        // Probabilistic cleanup
        if (mt_rand(1, 100) <= ($this->config['cleanup_probability'] * 100)) {
            $this->cleanup();
        }
    }

    /**
     * Reset rate limit for a key
     */
    public function reset(string $key, int $window = null): void
    {
        $window = $window ?? $this->config['default_window'];
        $storageKey = $this->getStorageKey($key, $window);
        $this->removeData($storageKey);
    }

    /**
     * Create middleware for rate limiting
     */
    public function middleware(int $limit = null, int $window = null, callable $keyGenerator = null): \Closure
    {
        return function(Request $request, \Closure $next) use ($limit, $window, $keyGenerator) {
            $key = $keyGenerator ? $keyGenerator($request) : $this->generateKeyFromRequest($request);

            if (!$this->attempt($key, $limit, $window)) {
                $resetTime = $this->getTimeUntilReset($key, $window);

                return Response::error('Rate limit exceeded', 429)
                    ->setHeader('X-RateLimit-Limit', (string)($limit ?? $this->config['default_limit']))
                    ->setHeader('X-RateLimit-Remaining', '0')
                    ->setHeader('X-RateLimit-Reset', (string)(time() + $resetTime))
                    ->setHeader('Retry-After', (string)$resetTime);
            }

            $response = $next($request);

            // Add rate limit headers to successful responses
            if ($response instanceof Response) {
                $remaining = $this->getRemainingAttempts($key, $limit, $window);
                $resetTime = $this->getTimeUntilReset($key, $window);

                $response->setHeader('X-RateLimit-Limit', (string)($limit ?? $this->config['default_limit']))
                         ->setHeader('X-RateLimit-Remaining', (string)$remaining)
                         ->setHeader('X-RateLimit-Reset', (string)(time() + $resetTime));
            }

            return $response;
        };
    }

    /**
     * Generate rate limit key from request
     */
    private function generateKeyFromRequest(Request $request): string
    {
        $ip = $request->getClientIp();
        $userAgent = $request->getUserAgent();
        $path = $request->getPath();

        // Create a unique key based on IP, user agent hash, and path
        return sprintf('req:%s:%s:%s',
            $ip,
            substr(hash('sha256', $userAgent), 0, 8),
            md5($path)
        );
    }

    /**
     * Create rate limiter for specific use cases
     */
    public static function forApi(array $overrides = []): self
    {
        return new self(array_merge([
            'default_limit' => 1000,
            'default_window' => 3600,
            'burst_limit' => 50,
            'burst_window' => 60
        ], $overrides));
    }

    public static function forAuth(array $overrides = []): self
    {
        return new self(array_merge([
            'default_limit' => 5,
            'default_window' => 300, // 5 minutes
            'burst_limit' => 3,
            'burst_window' => 60
        ], $overrides));
    }

    public static function forUploads(array $overrides = []): self
    {
        return new self(array_merge([
            'default_limit' => 10,
            'default_window' => 3600,
            'burst_limit' => 3,
            'burst_window' => 300 // 5 minutes
        ], $overrides));
    }

    /**
     * Initialize storage system
     */
    private function initializeStorage(): void
    {
        if ($this->storageType === 'file') {
            $path = $this->config['storage_path'];
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Generate storage key
     */
    private function getStorageKey(string $key, int $window): string
    {
        return $this->config['key_prefix'] . $key . ':' . $window;
    }

    /**
     * Get data from storage
     */
    private function getData(string $key): ?array
    {
        switch ($this->storageType) {
            case 'memory':
                return $this->memoryStorage[$key] ?? null;

            case 'file':
                return $this->getFileData($key);

            case 'database':
                // TODO: Implement database storage when database layer is available
                return null;

            default:
                return null;
        }
    }

    /**
     * Set data in storage
     */
    private function setData(string $key, array $data, int $ttl): void
    {
        $data['expires_at'] = time() + $ttl;

        switch ($this->storageType) {
            case 'memory':
                $this->memoryStorage[$key] = $data;
                break;

            case 'file':
                $this->setFileData($key, $data);
                break;

            case 'database':
                // TODO: Implement database storage when database layer is available
                break;
        }
    }

    /**
     * Remove data from storage
     */
    private function removeData(string $key): void
    {
        switch ($this->storageType) {
            case 'memory':
                unset($this->memoryStorage[$key]);
                break;

            case 'file':
                $filename = $this->getFilename($key);
                if (file_exists($filename)) {
                    unlink($filename);
                }
                break;

            case 'database':
                // TODO: Implement database storage when database layer is available
                break;
        }
    }

    /**
     * Get data from file storage
     */
    private function getFileData(string $key): ?array
    {
        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!$data) {
            return null;
        }

        // Check expiration
        if (isset($data['expires_at']) && $data['expires_at'] < time()) {
            unlink($filename);
            return null;
        }

        return $data;
    }

    /**
     * Set data in file storage
     */
    private function setFileData(string $key, array $data): void
    {
        $filename = $this->getFilename($key);
        $content = json_encode($data);

        file_put_contents($filename, $content, LOCK_EX);
    }

    /**
     * Get filename for file storage
     */
    private function getFilename(string $key): string
    {
        return $this->config['storage_path'] . $key . '.json';
    }

    /**
     * Clean up expired entries
     */
    public function cleanup(): int
    {
        $cleaned = 0;

        switch ($this->storageType) {
            case 'memory':
                $cleaned = $this->cleanupMemory();
                break;

            case 'file':
                $cleaned = $this->cleanupFiles();
                break;
        }

        return $cleaned;
    }

    /**
     * Clean up memory storage
     */
    private function cleanupMemory(): int
    {
        $cleaned = 0;
        $currentTime = time();

        foreach ($this->memoryStorage as $key => $data) {
            if (isset($data['expires_at']) && $data['expires_at'] < $currentTime) {
                unset($this->memoryStorage[$key]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Clean up file storage
     */
    private function cleanupFiles(): int
    {
        $cleaned = 0;
        $path = $this->config['storage_path'];

        if (!is_dir($path)) {
            return 0;
        }

        $files = glob($path . '*.json');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && isset($data['expires_at']) && $data['expires_at'] < time()) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get rate limit status
     */
    public function getStatus(string $key, int $limit = null, int $window = null): array
    {
        $limit = $limit ?? $this->config['default_limit'];
        $window = $window ?? $this->config['default_window'];

        $current = $this->getCurrentCount($key, $window);
        $remaining = $this->getRemainingAttempts($key, $limit, $window);
        $resetTime = $this->getTimeUntilReset($key, $window);

        return [
            'key' => $key,
            'limit' => $limit,
            'window' => $window,
            'current' => $current,
            'remaining' => $remaining,
            'reset_in' => $resetTime,
            'reset_at' => time() + $resetTime,
            'blocked' => $current >= $limit
        ];
    }

    /**
     * Get global statistics
     */
    public function getStats(): array
    {
        $stats = [
            'storage_type' => $this->storageType,
            'total_keys' => 0,
            'expired_keys' => 0,
            'active_keys' => 0
        ];

        switch ($this->storageType) {
            case 'memory':
                $stats['total_keys'] = count($this->memoryStorage);
                $currentTime = time();
                foreach ($this->memoryStorage as $data) {
                    if (isset($data['expires_at']) && $data['expires_at'] < $currentTime) {
                        $stats['expired_keys']++;
                    } else {
                        $stats['active_keys']++;
                    }
                }
                break;

            case 'file':
                $files = glob($this->config['storage_path'] . '*.json');
                $stats['total_keys'] = count($files);
                // Note: Checking each file would be expensive, so we don't count active/expired here
                break;
        }

        return $stats;
    }

    /**
     * Create IP-based rate limiter
     */
    public function forIp(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /**
     * Create user-based rate limiter
     */
    public function forUser(int $userId): string
    {
        return 'user:' . $userId;
    }

    /**
     * Create endpoint-based rate limiter
     */
    public function forEndpoint(string $endpoint, string $method = 'GET'): string
    {
        return 'endpoint:' . $method . ':' . md5($endpoint);
    }

    /**
     * Create composite rate limiter key
     */
    public function forComposite(array $components): string
    {
        return 'composite:' . hash('sha256', implode(':', $components));
    }
}