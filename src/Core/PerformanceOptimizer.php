<?php

namespace SecurityScanner\Core;

class PerformanceOptimizer
{
    private Profiler $profiler;
    private Config $config;
    private Logger $logger;
    private array $optimizationCache = [];
    private array $appliedOptimizations = [];

    public function __construct()
    {
        $this->profiler = Profiler::getInstance();
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance('performance_optimizer');
    }

    public function optimizeHotPaths(): array
    {
        $hotPaths = $this->profiler->getHotPaths();
        $optimizations = [];

        foreach ($hotPaths as $hotPath) {
            $optimization = $this->analyzeAndOptimize($hotPath);
            if ($optimization) {
                $optimizations[] = $optimization;
            }
        }

        return $optimizations;
    }

    private function analyzeAndOptimize(array $hotPath): ?array
    {
        $label = $hotPath['label'];
        $duration = $hotPath['duration'];
        $memoryUsed = $hotPath['memory_used'];

        // Skip if already optimized recently
        if ($this->wasRecentlyOptimized($label)) {
            return null;
        }

        $optimizations = [];

        // CPU-bound optimization
        if ($duration > 0.1) {
            $optimizations = array_merge($optimizations, $this->optimizeCpuBound($hotPath));
        }

        // Memory-bound optimization
        if ($memoryUsed > 10 * 1024 * 1024) { // 10MB
            $optimizations = array_merge($optimizations, $this->optimizeMemoryBound($hotPath));
        }

        // I/O-bound optimization
        if ($this->isIoBound($hotPath)) {
            $optimizations = array_merge($optimizations, $this->optimizeIoBound($hotPath));
        }

        if (!empty($optimizations)) {
            $this->appliedOptimizations[$label] = [
                'timestamp' => time(),
                'optimizations' => $optimizations,
                'original_duration' => $duration,
                'original_memory' => $memoryUsed
            ];

            $this->logger->info('Applied optimizations to hot path', [
                'label' => $label,
                'optimizations' => $optimizations,
                'duration' => $duration
            ]);

            return [
                'label' => $label,
                'optimizations' => $optimizations,
                'expected_improvement' => $this->calculateExpectedImprovement($optimizations)
            ];
        }

        return null;
    }

    private function optimizeCpuBound(array $hotPath): array
    {
        $optimizations = [];
        $label = $hotPath['label'];

        // Enable function result caching
        if ($this->canBeCached($hotPath)) {
            $optimizations[] = [
                'type' => 'caching',
                'strategy' => 'function_result_cache',
                'config' => [
                    'ttl' => 300,
                    'max_size' => 1000
                ]
            ];
        }

        // Enable memoization for recursive functions
        if ($this->isRecursive($hotPath)) {
            $optimizations[] = [
                'type' => 'memoization',
                'strategy' => 'recursive_memoization',
                'config' => [
                    'max_depth' => 100
                ]
            ];
        }

        // Suggest algorithm optimization
        if ($hotPath['duration'] > 1.0) {
            $optimizations[] = [
                'type' => 'algorithm',
                'strategy' => 'complexity_reduction',
                'recommendation' => $this->suggestAlgorithmOptimization($hotPath)
            ];
        }

        return $optimizations;
    }

    private function optimizeMemoryBound(array $hotPath): array
    {
        $optimizations = [];

        // Enable memory-efficient data structures
        $optimizations[] = [
            'type' => 'memory',
            'strategy' => 'efficient_data_structures',
            'config' => [
                'use_generators' => true,
                'lazy_loading' => true
            ]
        ];

        // Enable garbage collection hints
        $optimizations[] = [
            'type' => 'memory',
            'strategy' => 'garbage_collection',
            'config' => [
                'force_gc' => true,
                'gc_frequency' => 100
            ]
        ];

        return $optimizations;
    }

    private function optimizeIoBound(array $hotPath): array
    {
        $optimizations = [];

        // Enable connection pooling
        $optimizations[] = [
            'type' => 'io',
            'strategy' => 'connection_pooling',
            'config' => [
                'pool_size' => 10,
                'keep_alive' => true
            ]
        ];

        // Enable async I/O where possible
        if ($this->supportsAsync($hotPath)) {
            $optimizations[] = [
                'type' => 'io',
                'strategy' => 'async_operations',
                'config' => [
                    'batch_size' => 20,
                    'concurrent_limit' => 5
                ]
            ];
        }

        return $optimizations;
    }

    private function canBeCached(array $hotPath): bool
    {
        // Check if function is pure (no side effects)
        $context = $hotPath['context'] ?? [];

        // Simple heuristics - in real implementation would use more sophisticated analysis
        return !isset($context['has_side_effects']) || !$context['has_side_effects'];
    }

    private function isRecursive(array $hotPath): bool
    {
        // Check if function appears multiple times in call stack
        return ($hotPath['call_depth'] ?? 0) > 2;
    }

    private function isIoBound(array $hotPath): bool
    {
        $label = $hotPath['label'];

        // Check if function involves I/O operations
        $ioPatterns = ['database', 'http', 'file', 'network', 'api', 'curl'];

        foreach ($ioPatterns as $pattern) {
            if (stripos($label, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function supportsAsync(array $hotPath): bool
    {
        // Check if operation can be made asynchronous
        $context = $hotPath['context'] ?? [];
        return isset($context['async_capable']) && $context['async_capable'];
    }

    private function suggestAlgorithmOptimization(array $hotPath): string
    {
        $duration = $hotPath['duration'];

        if ($duration > 5.0) {
            return 'Consider algorithmic optimization - current complexity appears high';
        } elseif ($duration > 1.0) {
            return 'Consider caching or memoization for repeated calculations';
        } else {
            return 'Consider micro-optimizations or profiling at instruction level';
        }
    }

    private function wasRecentlyOptimized(string $label): bool
    {
        if (!isset($this->appliedOptimizations[$label])) {
            return false;
        }

        $lastOptimization = $this->appliedOptimizations[$label]['timestamp'];
        $cooldownPeriod = 3600; // 1 hour

        return (time() - $lastOptimization) < $cooldownPeriod;
    }

    private function calculateExpectedImprovement(array $optimizations): array
    {
        $cpuImprovement = 0;
        $memoryImprovement = 0;
        $ioImprovement = 0;

        foreach ($optimizations as $optimization) {
            switch ($optimization['type']) {
                case 'caching':
                case 'memoization':
                    $cpuImprovement += 50; // 50% improvement
                    break;
                case 'algorithm':
                    $cpuImprovement += 30; // 30% improvement
                    break;
                case 'memory':
                    $memoryImprovement += 25; // 25% memory reduction
                    break;
                case 'io':
                    $ioImprovement += 40; // 40% I/O improvement
                    break;
            }
        }

        return [
            'cpu' => min($cpuImprovement, 80), // Cap at 80%
            'memory' => min($memoryImprovement, 60), // Cap at 60%
            'io' => min($ioImprovement, 70) // Cap at 70%
        ];
    }

    public function createOptimizedFunction(string $originalFunction, array $optimizations): string
    {
        $optimizedCode = "function optimized_{$originalFunction}() {\n";

        foreach ($optimizations as $optimization) {
            switch ($optimization['type']) {
                case 'caching':
                    $optimizedCode .= $this->generateCachingCode($optimization);
                    break;
                case 'memoization':
                    $optimizedCode .= $this->generateMemoizationCode($optimization);
                    break;
                case 'memory':
                    $optimizedCode .= $this->generateMemoryOptimizationCode($optimization);
                    break;
            }
        }

        $optimizedCode .= "    // Original function logic here\n";
        $optimizedCode .= "}\n";

        return $optimizedCode;
    }

    private function generateCachingCode(array $optimization): string
    {
        $ttl = $optimization['config']['ttl'] ?? 300;

        return <<<PHP
    // Function result caching
    \$cacheKey = 'func_' . md5(serialize(func_get_args()));
    if (\$cached = apcu_fetch(\$cacheKey)) {
        return \$cached;
    }

    // Execute function and cache result
    \$result = /* original function logic */;
    apcu_store(\$cacheKey, \$result, {$ttl});
    return \$result;

PHP;
    }

    private function generateMemoizationCode(array $optimization): string
    {
        return <<<PHP
    // Memoization for recursive calls
    static \$memo = [];
    \$key = serialize(func_get_args());

    if (isset(\$memo[\$key])) {
        return \$memo[\$key];
    }

    \$result = /* original function logic */;
    \$memo[\$key] = \$result;
    return \$result;

PHP;
    }

    private function generateMemoryOptimizationCode(array $optimization): string
    {
        return <<<PHP
    // Memory optimization
    if (memory_get_usage(true) > 100 * 1024 * 1024) {
        gc_collect_cycles();
    }

    // Use generators where possible
    // Use unset() for large variables when done

PHP;
    }

    public function measureOptimizationImpact(string $label): ?array
    {
        if (!isset($this->appliedOptimizations[$label])) {
            return null;
        }

        $original = $this->appliedOptimizations[$label];
        $currentProfile = $this->findCurrentProfile($label);

        if (!$currentProfile) {
            return null;
        }

        $improvement = [
            'duration_improvement' => $this->calculateImprovement(
                $original['original_duration'],
                $currentProfile['duration'] ?? 0
            ),
            'memory_improvement' => $this->calculateImprovement(
                $original['original_memory'],
                $currentProfile['memory_used'] ?? 0
            ),
            'optimization_effectiveness' => $this->calculateEffectiveness($original, $currentProfile)
        ];

        return $improvement;
    }

    private function findCurrentProfile(string $label): ?array
    {
        $profiles = $this->profiler->getProfilingReport()['function_analysis'] ?? [];
        return $profiles[$label] ?? null;
    }

    private function calculateImprovement(float $original, float $current): float
    {
        if ($original == 0) {
            return 0;
        }

        return (($original - $current) / $original) * 100;
    }

    private function calculateEffectiveness(array $original, array $current): string
    {
        $durationImprovement = $this->calculateImprovement(
            $original['original_duration'],
            $current['avg_duration'] ?? 0
        );

        if ($durationImprovement > 50) {
            return 'highly_effective';
        } elseif ($durationImprovement > 20) {
            return 'effective';
        } elseif ($durationImprovement > 5) {
            return 'moderately_effective';
        } else {
            return 'low_effectiveness';
        }
    }

    public function generateOptimizationReport(): array
    {
        $report = [
            'total_optimizations' => count($this->appliedOptimizations),
            'optimization_summary' => [],
            'effectiveness_analysis' => [],
            'recommendations' => []
        ];

        foreach ($this->appliedOptimizations as $label => $optimization) {
            $impact = $this->measureOptimizationImpact($label);

            $report['optimization_summary'][$label] = [
                'optimizations' => $optimization['optimizations'],
                'applied_at' => $optimization['timestamp'],
                'impact' => $impact
            ];

            if ($impact) {
                $report['effectiveness_analysis'][] = [
                    'label' => $label,
                    'effectiveness' => $impact['optimization_effectiveness'],
                    'duration_improvement' => $impact['duration_improvement'],
                    'memory_improvement' => $impact['memory_improvement']
                ];
            }
        }

        // Generate new recommendations
        $report['recommendations'] = $this->profiler->generateOptimizationRecommendations();

        return $report;
    }

    public function autoOptimize(): array
    {
        $this->logger->info('Starting automatic optimization');

        $optimizations = $this->optimizeHotPaths();
        $report = $this->generateOptimizationReport();

        $this->logger->info('Automatic optimization completed', [
            'optimizations_applied' => count($optimizations),
            'total_optimizations' => count($this->appliedOptimizations)
        ]);

        return [
            'new_optimizations' => $optimizations,
            'report' => $report
        ];
    }
}