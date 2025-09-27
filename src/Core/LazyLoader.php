<?php

namespace SecurityScanner\Core;

class LazyLoader
{
    private static ?LazyLoader $instance = null;
    private array $factories = [];
    private array $instances = [];
    private array $singletons = [];
    private Logger $logger;
    private array $loadTimes = [];
    private array $dependencies = [];

    private function __construct()
    {
        $this->logger = Logger::getInstance('lazy_loading');
    }

    public static function getInstance(): LazyLoader
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register(string $key, callable $factory, bool $singleton = true): void
    {
        $this->factories[$key] = $factory;
        $this->singletons[$key] = $singleton;

        $this->logger->debug("Registered lazy loadable dependency", [
            'key' => $key,
            'singleton' => $singleton
        ]);
    }

    public function registerWithDependencies(string $key, callable $factory, array $dependencies = [], bool $singleton = true): void
    {
        $this->register($key, $factory, $singleton);
        $this->dependencies[$key] = $dependencies;
    }

    public function get(string $key): mixed
    {
        // Check if already instantiated
        if ($this->singletons[$key] && isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (!isset($this->factories[$key])) {
            throw new \InvalidArgumentException("No factory registered for key: {$key}");
        }

        $startTime = microtime(true);

        try {
            // Load dependencies first
            $this->loadDependencies($key);

            // Create instance
            $factory = $this->factories[$key];
            $instance = $factory();

            $loadTime = (microtime(true) - $startTime) * 1000;
            $this->loadTimes[$key] = $loadTime;

            if ($this->singletons[$key]) {
                $this->instances[$key] = $instance;
            }

            $this->logger->info("Lazy loaded dependency", [
                'key' => $key,
                'load_time_ms' => round($loadTime, 2),
                'singleton' => $this->singletons[$key],
                'memory_usage' => memory_get_usage(true)
            ]);

            return $instance;

        } catch (\Exception $e) {
            $this->logger->error("Failed to lazy load dependency", [
                'key' => $key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Failed to load dependency '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    public function isLoaded(string $key): bool
    {
        return isset($this->instances[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    public function unload(string $key): void
    {
        if (isset($this->instances[$key])) {
            unset($this->instances[$key]);

            $this->logger->debug("Unloaded dependency", [
                'key' => $key
            ]);
        }
    }

    public function unloadAll(): void
    {
        $count = count($this->instances);
        $this->instances = [];

        $this->logger->info("Unloaded all dependencies", [
            'count' => $count
        ]);
    }

    public function getLoadStats(): array
    {
        $totalLoadTime = array_sum($this->loadTimes);
        $loadedCount = count($this->instances);
        $registeredCount = count($this->factories);

        return [
            'registered_dependencies' => $registeredCount,
            'loaded_dependencies' => $loadedCount,
            'load_percentage' => $registeredCount > 0 ? round(($loadedCount / $registeredCount) * 100, 2) : 0,
            'total_load_time_ms' => round($totalLoadTime, 2),
            'average_load_time_ms' => $loadedCount > 0 ? round($totalLoadTime / $loadedCount, 2) : 0,
            'slowest_dependency' => $this->getSlowestDependency(),
            'memory_usage_bytes' => memory_get_usage(true),
            'peak_memory_bytes' => memory_get_peak_usage(true)
        ];
    }

    public function getDetailedStats(): array
    {
        $stats = [];

        foreach ($this->factories as $key => $factory) {
            $stats[$key] = [
                'key' => $key,
                'registered' => true,
                'loaded' => $this->isLoaded($key),
                'singleton' => $this->singletons[$key] ?? false,
                'load_time_ms' => $this->loadTimes[$key] ?? null,
                'dependencies' => $this->dependencies[$key] ?? [],
                'memory_impact' => $this->getMemoryImpact($key)
            ];
        }

        return $stats;
    }

    public function preload(array $keys = []): void
    {
        $keysToLoad = empty($keys) ? array_keys($this->factories) : $keys;
        $startTime = microtime(true);
        $loadedCount = 0;

        foreach ($keysToLoad as $key) {
            if (!$this->isLoaded($key) && $this->has($key)) {
                try {
                    $this->get($key);
                    $loadedCount++;
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to preload dependency", [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->logger->info("Preloading completed", [
            'requested_keys' => count($keysToLoad),
            'loaded_count' => $loadedCount,
            'total_time_ms' => round($totalTime, 2),
            'memory_usage' => memory_get_usage(true)
        ]);
    }

    public function warmUp(): void
    {
        $this->logger->info("Starting dependency warm-up");

        // Preload critical dependencies
        $criticalDependencies = [
            'database',
            'config',
            'logger',
            'cache'
        ];

        $this->preload($criticalDependencies);
    }

    public function reset(): void
    {
        $this->unloadAll();
        $this->factories = [];
        $this->singletons = [];
        $this->loadTimes = [];
        $this->dependencies = [];

        $this->logger->info("Lazy loader reset completed");
    }

    private function loadDependencies(string $key): void
    {
        if (!isset($this->dependencies[$key])) {
            return;
        }

        foreach ($this->dependencies[$key] as $dependency) {
            if (!$this->isLoaded($dependency)) {
                $this->get($dependency);
            }
        }
    }

    private function getSlowestDependency(): ?array
    {
        if (empty($this->loadTimes)) {
            return null;
        }

        $slowestKey = array_keys($this->loadTimes, max($this->loadTimes))[0];

        return [
            'key' => $slowestKey,
            'load_time_ms' => round($this->loadTimes[$slowestKey], 2)
        ];
    }

    private function getMemoryImpact(string $key): ?int
    {
        if (!$this->isLoaded($key)) {
            return null;
        }

        // This is an approximation - in reality measuring exact memory impact per object is complex
        $beforeMemory = memory_get_usage(true);

        // We can't easily measure memory impact after loading, so return null for now
        // In production, you might want to implement more sophisticated memory tracking
        return null;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}