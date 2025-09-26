<?php

namespace SecurityScanner\Core;

class CacheManager
{
    private array $config;
    private string $driver;
    private array $memoryCache = [];
    private array $stats = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver' => 'memory', // memory, file, redis, memcached
            'default_ttl' => 3600, // 1 hour
            'cache_path' => sys_get_temp_dir() . '/cache/',
            'prefix' => 'cache:',
            'serialize' => true,
            'compression' => false,
            'hash_keys' => true,
            'max_memory_items' => 1000,
            'cleanup_probability' => 0.01, // 1%
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'timeout' => 5
            ],
            'memcached' => [
                'servers' => [['127.0.0.1', 11211]]
            ]
        ], $config);

        $this->driver = $this->config['driver'];
        $this->initializeDriver();
    }

    /**
     * Get cached value
     */
    public function get(string $key, $default = null)
    {
        $hashedKey = $this->hashKey($key);

        try {
            $value = $this->getFromDriver($hashedKey);

            if ($value !== null) {
                $this->stats['hits']++;
                return $this->unserializeValue($value);
            }

            $this->stats['misses']++;
            return $default;
        } catch (\Exception $e) {
            error_log("Cache get error for key {$key}: " . $e->getMessage());
            $this->stats['misses']++;
            return $default;
        }
    }

    /**
     * Set cache value
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $hashedKey = $this->hashKey($key);
        $serializedValue = $this->serializeValue($value);

        try {
            $result = $this->setToDriver($hashedKey, $serializedValue, $ttl);
            if ($result) {
                $this->stats['sets']++;
            }
            return $result;
        } catch (\Exception $e) {
            error_log("Cache set error for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        $hashedKey = $this->hashKey($key);

        try {
            $result = $this->deleteFromDriver($hashedKey);
            if ($result) {
                $this->stats['deletes']++;
            }
            return $result;
        } catch (\Exception $e) {
            error_log("Cache delete error for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if key exists in cache
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        try {
            return $this->clearDriver();
        } catch (\Exception $e) {
            error_log("Cache clear error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get multiple values at once
     */
    public function getMultiple(array $keys, $default = null): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * Set multiple values at once
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Delete multiple keys at once
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Remember (get or set if not exists)
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value === null) {
            $value = $callback();
            $this->set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Increment numeric value
     */
    public function increment(string $key, int $value = 1): int
    {
        $current = $this->get($key, 0);
        $new = (int)$current + $value;
        $this->set($key, $new);
        return $new;
    }

    /**
     * Decrement numeric value
     */
    public function decrement(string $key, int $value = 1): int
    {
        $current = $this->get($key, 0);
        $new = (int)$current - $value;
        $this->set($key, $new);
        return $new;
    }

    /**
     * Add to cache if key doesn't exist
     */
    public function add(string $key, $value, ?int $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'driver' => $this->driver,
            'hit_rate' => $this->calculateHitRate(),
            'total_operations' => array_sum($this->stats),
            'memory_usage' => $this->getMemoryUsage()
        ]);
    }

    /**
     * Initialize cache driver
     */
    private function initializeDriver(): void
    {
        switch ($this->driver) {
            case 'file':
                $this->initializeFileDriver();
                break;
            case 'redis':
                $this->initializeRedisDriver();
                break;
            case 'memcached':
                $this->initializeMemcachedDriver();
                break;
            case 'memory':
            default:
                // Memory driver needs no initialization
                break;
        }
    }

    /**
     * Initialize file cache driver
     */
    private function initializeFileDriver(): void
    {
        $path = $this->config['cache_path'];
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Initialize Redis driver (placeholder)
     */
    private function initializeRedisDriver(): void
    {
        // TODO: Implement Redis driver when Redis extension is available
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension not available');
        }
    }

    /**
     * Initialize Memcached driver (placeholder)
     */
    private function initializeMemcachedDriver(): void
    {
        // TODO: Implement Memcached driver when Memcached extension is available
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException('Memcached extension not available');
        }
    }

    /**
     * Get value from driver
     */
    private function getFromDriver(string $key)
    {
        switch ($this->driver) {
            case 'memory':
                return $this->getFromMemory($key);
            case 'file':
                return $this->getFromFile($key);
            case 'redis':
                return $this->getFromRedis($key);
            case 'memcached':
                return $this->getFromMemcached($key);
            default:
                throw new \InvalidArgumentException("Unsupported cache driver: {$this->driver}");
        }
    }

    /**
     * Set value to driver
     */
    private function setToDriver(string $key, string $value, int $ttl): bool
    {
        switch ($this->driver) {
            case 'memory':
                return $this->setToMemory($key, $value, $ttl);
            case 'file':
                return $this->setToFile($key, $value, $ttl);
            case 'redis':
                return $this->setToRedis($key, $value, $ttl);
            case 'memcached':
                return $this->setToMemcached($key, $value, $ttl);
            default:
                throw new \InvalidArgumentException("Unsupported cache driver: {$this->driver}");
        }
    }

    /**
     * Delete from driver
     */
    private function deleteFromDriver(string $key): bool
    {
        switch ($this->driver) {
            case 'memory':
                return $this->deleteFromMemory($key);
            case 'file':
                return $this->deleteFromFile($key);
            case 'redis':
                return $this->deleteFromRedis($key);
            case 'memcached':
                return $this->deleteFromMemcached($key);
            default:
                throw new \InvalidArgumentException("Unsupported cache driver: {$this->driver}");
        }
    }

    /**
     * Clear driver
     */
    private function clearDriver(): bool
    {
        switch ($this->driver) {
            case 'memory':
                return $this->clearMemory();
            case 'file':
                return $this->clearFiles();
            case 'redis':
                return $this->clearRedis();
            case 'memcached':
                return $this->clearMemcached();
            default:
                throw new \InvalidArgumentException("Unsupported cache driver: {$this->driver}");
        }
    }

    /**
     * Memory driver methods
     */
    private function getFromMemory(string $key): ?string
    {
        if (!isset($this->memoryCache[$key])) {
            return null;
        }

        $item = $this->memoryCache[$key];

        if ($item['expires'] < time()) {
            unset($this->memoryCache[$key]);
            return null;
        }

        return $item['data'];
    }

    private function setToMemory(string $key, string $value, int $ttl): bool
    {
        // Memory management - remove oldest items if limit exceeded
        if (count($this->memoryCache) >= $this->config['max_memory_items']) {
            $this->cleanupMemory();
        }

        $this->memoryCache[$key] = [
            'data' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        return true;
    }

    private function deleteFromMemory(string $key): bool
    {
        if (isset($this->memoryCache[$key])) {
            unset($this->memoryCache[$key]);
            return true;
        }
        return false;
    }

    private function clearMemory(): bool
    {
        $this->memoryCache = [];
        return true;
    }

    /**
     * File driver methods
     */
    private function getFromFile(string $key): ?string
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

        if ($data['expires'] < time()) {
            unlink($filename);
            return null;
        }

        return $data['value'];
    }

    private function setToFile(string $key, string $value, int $ttl): bool
    {
        $filename = $this->getFilename($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        $content = json_encode($data);

        // Probabilistic cleanup
        if (mt_rand(1, 100) <= ($this->config['cleanup_probability'] * 100)) {
            $this->cleanupFiles();
        }

        return file_put_contents($filename, $content, LOCK_EX) !== false;
    }

    private function deleteFromFile(string $key): bool
    {
        $filename = $this->getFilename($key);
        if (file_exists($filename)) {
            return unlink($filename);
        }
        return true;
    }

    private function clearFiles(): bool
    {
        $files = glob($this->config['cache_path'] . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }

    /**
     * Redis driver methods (placeholder)
     */
    private function getFromRedis(string $key): ?string
    {
        // TODO: Implement when Redis is available
        throw new \RuntimeException('Redis driver not implemented');
    }

    private function setToRedis(string $key, string $value, int $ttl): bool
    {
        // TODO: Implement when Redis is available
        throw new \RuntimeException('Redis driver not implemented');
    }

    private function deleteFromRedis(string $key): bool
    {
        // TODO: Implement when Redis is available
        throw new \RuntimeException('Redis driver not implemented');
    }

    private function clearRedis(): bool
    {
        // TODO: Implement when Redis is available
        throw new \RuntimeException('Redis driver not implemented');
    }

    /**
     * Memcached driver methods (placeholder)
     */
    private function getFromMemcached(string $key): ?string
    {
        // TODO: Implement when Memcached is available
        throw new \RuntimeException('Memcached driver not implemented');
    }

    private function setToMemcached(string $key, string $value, int $ttl): bool
    {
        // TODO: Implement when Memcached is available
        throw new \RuntimeException('Memcached driver not implemented');
    }

    private function deleteFromMemcached(string $key): bool
    {
        // TODO: Implement when Memcached is available
        throw new \RuntimeException('Memcached driver not implemented');
    }

    private function clearMemcached(): bool
    {
        // TODO: Implement when Memcached is available
        throw new \RuntimeException('Memcached driver not implemented');
    }

    /**
     * Utility methods
     */
    private function hashKey(string $key): string
    {
        if (!$this->config['hash_keys']) {
            return $this->config['prefix'] . $key;
        }

        return $this->config['prefix'] . hash('sha256', $key);
    }

    private function serializeValue($value): string
    {
        if (!$this->config['serialize']) {
            return (string)$value;
        }

        $serialized = serialize($value);

        if ($this->config['compression'] && function_exists('gzcompress')) {
            $serialized = gzcompress($serialized);
        }

        return $serialized;
    }

    private function unserializeValue(string $value)
    {
        if (!$this->config['serialize']) {
            return $value;
        }

        if ($this->config['compression'] && function_exists('gzuncompress')) {
            $value = gzuncompress($value);
        }

        return unserialize($value);
    }

    private function getFilename(string $key): string
    {
        return $this->config['cache_path'] . $key . '.cache';
    }

    private function calculateHitRate(): float
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        if ($total === 0) {
            return 0.0;
        }

        return round(($this->stats['hits'] / $total) * 100, 2);
    }

    private function getMemoryUsage(): int
    {
        return count($this->memoryCache);
    }

    private function cleanupMemory(): void
    {
        $currentTime = time();
        $removed = 0;

        // Remove expired items first
        foreach ($this->memoryCache as $key => $item) {
            if ($item['expires'] < $currentTime) {
                unset($this->memoryCache[$key]);
                $removed++;
            }
        }

        // If still too many items, remove oldest
        if (count($this->memoryCache) >= $this->config['max_memory_items']) {
            uasort($this->memoryCache, function($a, $b) {
                return $a['created'] - $b['created'];
            });

            $toRemove = count($this->memoryCache) - ($this->config['max_memory_items'] * 0.8);
            $keys = array_keys($this->memoryCache);

            for ($i = 0; $i < $toRemove; $i++) {
                unset($this->memoryCache[$keys[$i]]);
                $removed++;
            }
        }
    }

    private function cleanupFiles(): int
    {
        $cleaned = 0;
        $currentTime = time();
        $files = glob($this->config['cache_path'] . '*.cache');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && isset($data['expires']) && $data['expires'] < $currentTime) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Create cache manager for different use cases
     */
    public static function forQueries(array $overrides = []): self
    {
        return new self(array_merge([
            'driver' => 'file',
            'default_ttl' => 1800, // 30 minutes
            'prefix' => 'query:',
            'serialize' => true
        ], $overrides));
    }

    public static function forSessions(array $overrides = []): self
    {
        return new self(array_merge([
            'driver' => 'memory',
            'default_ttl' => 3600,
            'prefix' => 'session:',
            'serialize' => true
        ], $overrides));
    }

    public static function forApi(array $overrides = []): self
    {
        return new self(array_merge([
            'driver' => 'memory',
            'default_ttl' => 300, // 5 minutes
            'prefix' => 'api:',
            'serialize' => true,
            'max_memory_items' => 500
        ], $overrides));
    }
}