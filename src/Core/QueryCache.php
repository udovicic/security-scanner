<?php

namespace SecurityScanner\Core;

class QueryCache
{
    private CacheManager $cache;
    private array $config;
    private array $queryStats = [];

    public function __construct(CacheManager $cache = null, array $config = [])
    {
        $this->cache = $cache ?? CacheManager::forQueries();
        $this->config = array_merge([
            'enabled' => true,
            'default_ttl' => 1800, // 30 minutes
            'cache_reads' => true,
            'cache_writes' => false,
            'cache_selects' => true,
            'invalidate_on_write' => true,
            'tag_based_invalidation' => true,
            'compress_large_results' => true,
            'max_result_size' => 1024 * 1024, // 1MB
            'debug' => false
        ], $config);
    }

    /**
     * Cache a query result
     */
    public function cacheQuery(string $sql, array $params, $result, ?int $ttl = null, array $tags = []): bool
    {
        if (!$this->config['enabled'] || !$this->shouldCacheQuery($sql)) {
            return false;
        }

        $key = $this->generateQueryKey($sql, $params);
        $ttl = $ttl ?? $this->config['default_ttl'];

        // Check result size
        $serializedSize = strlen(serialize($result));
        if ($serializedSize > $this->config['max_result_size']) {
            if ($this->config['debug']) {
                error_log("Query result too large to cache: {$serializedSize} bytes");
            }
            return false;
        }

        $cacheData = [
            'result' => $result,
            'sql' => $sql,
            'params' => $params,
            'tags' => $tags,
            'cached_at' => time(),
            'size' => $serializedSize
        ];

        $success = $this->cache->set($key, $cacheData, $ttl);

        if ($success && $this->config['tag_based_invalidation']) {
            $this->storeTags($key, $tags);
        }

        if ($this->config['debug']) {
            error_log("Query cached: {$key} (TTL: {$ttl}s, Size: {$serializedSize} bytes)");
        }

        return $success;
    }

    /**
     * Get cached query result
     */
    public function getCachedQuery(string $sql, array $params)
    {
        if (!$this->config['enabled']) {
            return null;
        }

        $key = $this->generateQueryKey($sql, $params);
        $cacheData = $this->cache->get($key);

        if ($cacheData === null) {
            $this->recordQueryStat($sql, 'miss');
            return null;
        }

        $this->recordQueryStat($sql, 'hit');

        if ($this->config['debug']) {
            error_log("Query cache hit: {$key}");
        }

        return $cacheData['result'];
    }

    /**
     * Invalidate cached queries by tags
     */
    public function invalidateByTags(array $tags): int
    {
        if (!$this->config['tag_based_invalidation']) {
            return 0;
        }

        $invalidated = 0;

        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $queryKeys = $this->cache->get($tagKey, []);

            foreach ($queryKeys as $queryKey) {
                if ($this->cache->delete($queryKey)) {
                    $invalidated++;
                }
            }

            // Clean up the tag key itself
            $this->cache->delete($tagKey);
        }

        if ($this->config['debug']) {
            error_log("Invalidated {$invalidated} queries by tags: " . implode(', ', $tags));
        }

        return $invalidated;
    }

    /**
     * Invalidate all queries containing specific table names
     */
    public function invalidateByTables(array $tables): int
    {
        $tags = array_map(function($table) {
            return "table:{$table}";
        }, $tables);

        return $this->invalidateByTags($tags);
    }

    /**
     * Execute query with caching
     */
    public function query(string $sql, array $params = [], callable $executor = null, ?int $ttl = null, array $tags = [])
    {
        // Check cache first
        $result = $this->getCachedQuery($sql, $params);
        if ($result !== null) {
            return $result;
        }

        // Execute query if not cached
        if ($executor === null) {
            throw new \InvalidArgumentException('Query executor callback is required when result is not cached');
        }

        $result = $executor($sql, $params);

        // Cache the result if it's a read operation
        if ($this->shouldCacheQuery($sql)) {
            $this->cacheQuery($sql, $params, $result, $ttl, $tags);
        }

        // Invalidate related caches if it's a write operation
        if ($this->config['invalidate_on_write'] && $this->isWriteQuery($sql)) {
            $affectedTables = $this->extractTablesFromQuery($sql);
            $this->invalidateByTables($affectedTables);
        }

        return $result;
    }

    /**
     * Clear all query cache
     */
    public function clearAll(): bool
    {
        return $this->cache->clear();
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $cacheStats = $this->cache->getStats();

        return array_merge($cacheStats, [
            'query_stats' => $this->queryStats,
            'total_queries' => array_sum(array_column($this->queryStats, 'total')),
            'cache_efficiency' => $this->calculateCacheEfficiency()
        ]);
    }

    /**
     * Generate cache key for query
     */
    private function generateQueryKey(string $sql, array $params): string
    {
        // Normalize SQL for consistent caching
        $normalizedSql = $this->normalizeQuery($sql);

        // Create unique key from SQL and parameters
        $keyData = $normalizedSql . '|' . serialize($params);

        return 'query:' . hash('sha256', $keyData);
    }

    /**
     * Normalize query for consistent caching
     */
    private function normalizeQuery(string $sql): string
    {
        // Remove extra whitespace and normalize case
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $sql = strtolower($sql);

        return $sql;
    }

    /**
     * Check if query should be cached
     */
    private function shouldCacheQuery(string $sql): bool
    {
        $sql = strtolower(trim($sql));

        // Only cache SELECT queries by default
        if ($this->config['cache_selects'] && str_starts_with($sql, 'select')) {
            return true;
        }

        // Cache reads if enabled
        if ($this->config['cache_reads'] && $this->isReadQuery($sql)) {
            return true;
        }

        // Cache writes if enabled (usually not recommended)
        if ($this->config['cache_writes'] && $this->isWriteQuery($sql)) {
            return true;
        }

        return false;
    }

    /**
     * Check if query is a read operation
     */
    private function isReadQuery(string $sql): bool
    {
        $sql = strtolower(trim($sql));
        $readOperations = ['select', 'show', 'describe', 'explain'];

        foreach ($readOperations as $operation) {
            if (str_starts_with($sql, $operation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if query is a write operation
     */
    private function isWriteQuery(string $sql): bool
    {
        $sql = strtolower(trim($sql));
        $writeOperations = ['insert', 'update', 'delete', 'replace', 'truncate', 'drop', 'create', 'alter'];

        foreach ($writeOperations as $operation) {
            if (str_starts_with($sql, $operation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract table names from SQL query
     */
    private function extractTablesFromQuery(string $sql): array
    {
        $tables = [];
        $sql = strtolower($sql);

        // Simple regex patterns for common cases
        $patterns = [
            '/(?:from|join|into|update)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i',
            '/(?:insert\s+into|update)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sql, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }
        }

        return array_unique($tables);
    }

    /**
     * Store tags for cache invalidation
     */
    private function storeTags(string $queryKey, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $queryKeys = $this->cache->get($tagKey, []);

            if (!in_array($queryKey, $queryKeys)) {
                $queryKeys[] = $queryKey;
                $this->cache->set($tagKey, $queryKeys, $this->config['default_ttl'] * 2);
            }
        }

        // Auto-tag with extracted table names
        $tables = $this->extractTablesFromQuery($queryKey);
        foreach ($tables as $table) {
            $tableTag = "table:{$table}";
            $tagKey = $this->getTagKey($tableTag);
            $queryKeys = $this->cache->get($tagKey, []);

            if (!in_array($queryKey, $queryKeys)) {
                $queryKeys[] = $queryKey;
                $this->cache->set($tagKey, $queryKeys, $this->config['default_ttl'] * 2);
            }
        }
    }

    /**
     * Get tag key for cache invalidation
     */
    private function getTagKey(string $tag): string
    {
        return 'tag:' . hash('sha256', $tag);
    }

    /**
     * Record query statistics
     */
    private function recordQueryStat(string $sql, string $type): void
    {
        $operation = $this->getQueryOperation($sql);

        if (!isset($this->queryStats[$operation])) {
            $this->queryStats[$operation] = ['hits' => 0, 'misses' => 0, 'total' => 0];
        }

        $this->queryStats[$operation][$type]++;
        $this->queryStats[$operation]['total']++;
    }

    /**
     * Get query operation type
     */
    private function getQueryOperation(string $sql): string
    {
        $sql = strtolower(trim($sql));

        if (str_starts_with($sql, 'select')) return 'select';
        if (str_starts_with($sql, 'insert')) return 'insert';
        if (str_starts_with($sql, 'update')) return 'update';
        if (str_starts_with($sql, 'delete')) return 'delete';
        if (str_starts_with($sql, 'show')) return 'show';
        if (str_starts_with($sql, 'describe')) return 'describe';

        return 'other';
    }

    /**
     * Calculate cache efficiency
     */
    private function calculateCacheEfficiency(): float
    {
        $totalHits = 0;
        $totalRequests = 0;

        foreach ($this->queryStats as $stats) {
            $totalHits += $stats['hits'];
            $totalRequests += $stats['total'];
        }

        if ($totalRequests === 0) {
            return 0.0;
        }

        return round(($totalHits / $totalRequests) * 100, 2);
    }

    /**
     * Create query cache with specific configuration
     */
    public static function create(array $config = []): self
    {
        $cacheManager = CacheManager::forQueries($config['cache'] ?? []);
        return new self($cacheManager, $config);
    }

    /**
     * Create aggressive caching instance
     */
    public static function aggressive(array $overrides = []): self
    {
        return self::create(array_merge([
            'default_ttl' => 3600, // 1 hour
            'cache_reads' => true,
            'cache_selects' => true,
            'max_result_size' => 5 * 1024 * 1024, // 5MB
            'compress_large_results' => true
        ], $overrides));
    }

    /**
     * Create conservative caching instance
     */
    public static function conservative(array $overrides = []): self
    {
        return self::create(array_merge([
            'default_ttl' => 300, // 5 minutes
            'cache_reads' => true,
            'cache_selects' => true,
            'cache_writes' => false,
            'max_result_size' => 512 * 1024, // 512KB
            'invalidate_on_write' => true
        ], $overrides));
    }
}