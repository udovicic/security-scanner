<?php

namespace SecurityScanner\Tests;

use SecurityScanner\Core\Logger;

class ResourcePool
{
    private array $pool = [];
    private array $inUse = [];
    private array $config;
    private Logger $logger;
    private int $nextResourceId = 1;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_connections' => 10,
            'connection_timeout' => 30,
            'pool_size' => 20,
            'idle_timeout' => 300,
            'health_check_interval' => 60
        ], $config);

        $this->logger = Logger::getInstance('resource_pool');
        $this->initializePool();
    }

    private function initializePool(): void
    {
        for ($i = 0; $i < $this->config['pool_size']; $i++) {
            $resource = $this->createResource();
            $this->pool[$resource['id']] = $resource;
        }

        $this->logger->info('Resource pool initialized', [
            'pool_size' => count($this->pool),
            'max_connections' => $this->config['max_connections']
        ]);
    }

    private function createResource(): array
    {
        return [
            'id' => $this->nextResourceId++,
            'type' => 'http_client',
            'created_at' => microtime(true),
            'last_used' => microtime(true),
            'usage_count' => 0,
            'is_healthy' => true,
            'connection' => $this->createConnection(),
            'metadata' => [
                'user_agent' => 'SecurityScanner/1.0',
                'timeout' => $this->config['connection_timeout'],
                'max_redirects' => 5
            ]
        ];
    }

    private function createConnection(): array
    {
        // Simulate HTTP client configuration
        return [
            'curl_handle' => null, // Would be actual curl handle in real implementation
            'options' => [
                CURLOPT_TIMEOUT => $this->config['connection_timeout'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'SecurityScanner/1.0'
            ]
        ];
    }

    public function acquire(): array
    {
        // Try to get available resource from pool
        foreach ($this->pool as $id => $resource) {
            if ($this->isResourceAvailable($resource)) {
                $resource['last_used'] = microtime(true);
                $resource['usage_count']++;

                $this->inUse[$id] = $resource;
                unset($this->pool[$id]);

                $this->logger->debug('Resource acquired', [
                    'resource_id' => $id,
                    'usage_count' => $resource['usage_count']
                ]);

                return $resource;
            }
        }

        // No available resources, create new one if under limit
        if (count($this->inUse) < $this->config['max_connections']) {
            $resource = $this->createResource();
            $this->inUse[$resource['id']] = $resource;

            $this->logger->info('New resource created and acquired', [
                'resource_id' => $resource['id'],
                'total_in_use' => count($this->inUse)
            ]);

            return $resource;
        }

        // Wait for resource to become available
        return $this->waitForResource();
    }

    private function isResourceAvailable(array $resource): bool
    {
        return $resource['is_healthy'] &&
               !$this->isResourceExpired($resource);
    }

    private function isResourceExpired(array $resource): bool
    {
        $now = microtime(true);
        $idleTime = $now - $resource['last_used'];

        return $idleTime > $this->config['idle_timeout'];
    }

    private function waitForResource(): array
    {
        $waitStart = microtime(true);
        $maxWait = 30; // 30 seconds max wait

        while ((microtime(true) - $waitStart) < $maxWait) {
            // Check if any resource became available
            foreach ($this->pool as $id => $resource) {
                if ($this->isResourceAvailable($resource)) {
                    return $this->acquire();
                }
            }

            usleep(100000); // Wait 100ms
        }

        throw new \RuntimeException('Timeout waiting for available resource');
    }

    public function release(array $resource): void
    {
        $id = $resource['id'];

        if (!isset($this->inUse[$id])) {
            $this->logger->warning('Attempted to release resource not in use', [
                'resource_id' => $id
            ]);
            return;
        }

        // Update resource state
        $resource['last_used'] = microtime(true);

        // Check if resource should be returned to pool or discarded
        if ($this->shouldReturnToPool($resource)) {
            $this->pool[$id] = $resource;
        } else {
            $this->destroyResource($resource);
        }

        unset($this->inUse[$id]);

        $this->logger->debug('Resource released', [
            'resource_id' => $id,
            'returned_to_pool' => isset($this->pool[$id])
        ]);
    }

    private function shouldReturnToPool(array $resource): bool
    {
        // Don't return expired or unhealthy resources
        if (!$resource['is_healthy'] || $this->isResourceExpired($resource)) {
            return false;
        }

        // Don't return if pool is full
        if (count($this->pool) >= $this->config['pool_size']) {
            return false;
        }

        return true;
    }

    private function destroyResource(array $resource): void
    {
        // Clean up resource connections
        if (isset($resource['connection']['curl_handle'])) {
            // curl_close($resource['connection']['curl_handle']);
        }

        $this->logger->debug('Resource destroyed', [
            'resource_id' => $resource['id'],
            'usage_count' => $resource['usage_count']
        ]);
    }

    public function healthCheck(): array
    {
        $healthyResources = 0;
        $unhealthyResources = 0;

        // Check pool resources
        foreach ($this->pool as $id => $resource) {
            if ($this->performHealthCheck($resource)) {
                $healthyResources++;
            } else {
                $unhealthyResources++;
                $this->markResourceUnhealthy($id);
            }
        }

        // Check in-use resources
        foreach ($this->inUse as $id => $resource) {
            if ($this->performHealthCheck($resource)) {
                $healthyResources++;
            } else {
                $unhealthyResources++;
                $this->markResourceUnhealthy($id);
            }
        }

        $results = [
            'healthy_resources' => $healthyResources,
            'unhealthy_resources' => $unhealthyResources,
            'total_resources' => $healthyResources + $unhealthyResources,
            'pool_size' => count($this->pool),
            'in_use' => count($this->inUse)
        ];

        $this->logger->info('Health check completed', $results);

        return $results;
    }

    private function performHealthCheck(array $resource): bool
    {
        // Simple health check - in real implementation would test actual connection
        $age = microtime(true) - $resource['created_at'];
        $maxAge = 3600; // 1 hour

        return $age < $maxAge && $resource['is_healthy'];
    }

    private function markResourceUnhealthy(int $resourceId): void
    {
        if (isset($this->pool[$resourceId])) {
            $this->pool[$resourceId]['is_healthy'] = false;
        }

        if (isset($this->inUse[$resourceId])) {
            $this->inUse[$resourceId]['is_healthy'] = false;
        }
    }

    public function cleanup(): void
    {
        $cleaned = 0;

        // Clean up expired resources in pool
        foreach ($this->pool as $id => $resource) {
            if (!$resource['is_healthy'] || $this->isResourceExpired($resource)) {
                $this->destroyResource($resource);
                unset($this->pool[$id]);
                $cleaned++;
            }
        }

        $this->logger->info('Pool cleanup completed', [
            'resources_cleaned' => $cleaned,
            'pool_size' => count($this->pool)
        ]);
    }

    public function getStats(): array
    {
        $totalUsage = 0;
        $oldestResource = null;
        $newestResource = null;

        foreach (array_merge($this->pool, $this->inUse) as $resource) {
            $totalUsage += $resource['usage_count'];

            if ($oldestResource === null || $resource['created_at'] < $oldestResource) {
                $oldestResource = $resource['created_at'];
            }

            if ($newestResource === null || $resource['created_at'] > $newestResource) {
                $newestResource = $resource['created_at'];
            }
        }

        $totalResources = count($this->pool) + count($this->inUse);

        return [
            'pool_size' => count($this->pool),
            'in_use' => count($this->inUse),
            'total_resources' => $totalResources,
            'utilization_rate' => $totalResources > 0 ? (count($this->inUse) / $totalResources) * 100 : 0,
            'average_usage' => $totalResources > 0 ? $totalUsage / $totalResources : 0,
            'oldest_resource_age' => $oldestResource ? microtime(true) - $oldestResource : 0,
            'newest_resource_age' => $newestResource ? microtime(true) - $newestResource : 0,
            'config' => $this->config
        ];
    }

    public function resize(int $newPoolSize): void
    {
        $currentSize = count($this->pool);

        if ($newPoolSize > $currentSize) {
            // Add resources
            for ($i = $currentSize; $i < $newPoolSize; $i++) {
                $resource = $this->createResource();
                $this->pool[$resource['id']] = $resource;
            }
        } elseif ($newPoolSize < $currentSize) {
            // Remove resources
            $toRemove = $currentSize - $newPoolSize;
            $removed = 0;

            foreach ($this->pool as $id => $resource) {
                if ($removed >= $toRemove) {
                    break;
                }

                $this->destroyResource($resource);
                unset($this->pool[$id]);
                $removed++;
            }
        }

        $this->config['pool_size'] = $newPoolSize;

        $this->logger->info('Pool resized', [
            'new_size' => $newPoolSize,
            'old_size' => $currentSize,
            'current_pool_size' => count($this->pool)
        ]);
    }

    public function getResourceById(int $resourceId): ?array
    {
        return $this->pool[$resourceId] ?? $this->inUse[$resourceId] ?? null;
    }

    public function forceReleaseAll(): void
    {
        $releasedCount = count($this->inUse);

        foreach ($this->inUse as $id => $resource) {
            if ($this->shouldReturnToPool($resource)) {
                $this->pool[$id] = $resource;
            } else {
                $this->destroyResource($resource);
            }
        }

        $this->inUse = [];

        $this->logger->warning('Force released all resources', [
            'released_count' => $releasedCount
        ]);
    }

    public function __destruct()
    {
        // Clean up all resources
        foreach (array_merge($this->pool, $this->inUse) as $resource) {
            $this->destroyResource($resource);
        }
    }
}