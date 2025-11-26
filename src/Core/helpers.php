<?php

if (!function_exists('app')) {
    /**
     * Get the container instance
     */
    function app(?string $abstract = null, array $parameters = [])
    {
        $container = \SecurityScanner\Core\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->get($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     */
    function config(?string $key = null, $default = null)
    {
        $config = app(\SecurityScanner\Core\Config::class);

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }
}

if (!function_exists('database')) {
    /**
     * Get database instance
     */
    function database(?string $connection = null): \SecurityScanner\Core\Database
    {
        return app(\SecurityScanner\Core\Database::class);
    }
}

if (!function_exists('logger')) {
    /**
     * Get logger instance
     */
    function logger(string $channel = 'access'): \SecurityScanner\Core\Logger
    {
        return app("logger.{$channel}");
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the path to the base of the install
     */
    function base_path(string $path = ''): string
    {
        return realpath(__DIR__ . '/../../') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the path to the public folder
     */
    function public_path(string $path = ''): string
    {
        return base_path('public') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage folder
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('database_path')) {
    /**
     * Get the path to the database folder
     */
    function database_path(string $path = ''): string
    {
        return base_path('database') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('lazy_load')) {
    /**
     * Lazy load a dependency
     */
    function lazy_load(string $key)
    {
        return \SecurityScanner\Core\LazyLoader::getInstance()->get($key);
    }
}

if (!function_exists('asset_url')) {
    /**
     * Generate URL for an asset
     */
    function asset_url(string $key): ?string
    {
        return app(\SecurityScanner\Core\AssetLazyLoader::class)->getAssetUrl($key);
    }
}

if (!function_exists('load_asset')) {
    /**
     * Load and render an asset tag
     */
    function load_asset(string $key): ?string
    {
        return app(\SecurityScanner\Core\AssetLazyLoader::class)->loadAsset($key);
    }
}

if (!function_exists('preload_assets')) {
    /**
     * Generate preload tags for critical assets
     */
    function preload_assets(): array
    {
        return app(\SecurityScanner\Core\AssetLazyLoader::class)->generatePreloadTags();
    }
}

if (!function_exists('deferred_assets_script')) {
    /**
     * Generate script for deferred asset loading
     */
    function deferred_assets_script(): string
    {
        return app(\SecurityScanner\Core\AssetLazyLoader::class)->getDeferredLoadingScript();
    }
}

if (!function_exists('memory_usage')) {
    /**
     * Get current memory usage in human readable format
     */
    function memory_usage(bool $peak = false): string
    {
        $bytes = $peak ? memory_get_peak_usage(true) : memory_get_usage(true);

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
    }
}

if (!function_exists('microtime_diff')) {
    /**
     * Calculate difference between two microtime values in milliseconds
     */
    function microtime_diff(float $start, ?float $end = null): float
    {
        $end = $end ?? microtime(true);
        return round(($end - $start) * 1000, 2);
    }
}

if (!function_exists('benchmark')) {
    /**
     * Benchmark a function execution
     */
    function benchmark(callable $callback, array $args = []): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $result = call_user_func_array($callback, $args);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'result' => $result,
            'execution_time_ms' => microtime_diff($startTime, $endTime),
            'memory_used_bytes' => $endMemory - $startMemory,
            'memory_used' => memory_usage(),
            'peak_memory' => memory_usage(true)
        ];
    }
}

if (!function_exists('cache_key')) {
    /**
     * Generate a cache key
     */
    function cache_key(string $prefix, ...$parts): string
    {
        $key = $prefix;

        foreach ($parts as $part) {
            if (is_array($part) || is_object($part)) {
                $part = serialize($part);
            }
            $key .= ':' . md5($part);
        }

        return $key;
    }
}

if (!function_exists('is_production')) {
    /**
     * Check if app is in production environment
     */
    function is_production(): bool
    {
        return config('app.environment', 'production') === 'production';
    }
}

if (!function_exists('is_development')) {
    /**
     * Check if app is in development environment
     */
    function is_development(): bool
    {
        return config('app.environment', 'production') === 'development';
    }
}

if (!function_exists('abort_if')) {
    /**
     * Throw an exception if condition is true
     */
    function abort_if(bool $condition, string $message = 'Operation aborted', int $code = 500): void
    {
        if ($condition) {
            throw new \RuntimeException($message, $code);
        }
    }
}

if (!function_exists('retry')) {
    /**
     * Retry a function call
     */
    function retry(callable $callback, int $maxAttempts = 3, int $delay = 100)
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $maxAttempts) {
            try {
                return $callback();
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt === $maxAttempts) {
                    throw $e;
                }

                if ($delay > 0) {
                    usleep($delay * 1000); // Convert to microseconds
                    $delay *= 2; // Exponential backoff
                }

                $attempt++;
            }
        }

        throw $lastException;
    }
}