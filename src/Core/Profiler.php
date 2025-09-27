<?php

namespace SecurityScanner\Core;

class Profiler
{
    private static ?Profiler $instance = null;
    private array $profiles = [];
    private array $hotPaths = [];
    private array $config;
    private Logger $logger;
    private bool $enabled = false;
    private float $startTime;
    private array $callStack = [];
    private array $memorySnapshots = [];

    private function __construct()
    {
        $this->config = Config::getInstance()->get('profiler', [
            'enabled' => false,
            'sample_rate' => 0.1,
            'hot_path_threshold' => 0.1,
            'memory_tracking' => true,
            'max_call_depth' => 50,
            'profile_duration' => 3600
        ]);

        $this->logger = Logger::getInstance('profiler');
        $this->startTime = microtime(true);
        $this->enabled = $this->config['enabled'] && $this->shouldSample();
    }

    public static function getInstance(): Profiler
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function start(string $label, array $context = []): string
    {
        if (!$this->enabled) {
            return '';
        }

        $profileId = uniqid('profile_');
        $currentTime = microtime(true);

        $this->profiles[$profileId] = [
            'id' => $profileId,
            'label' => $label,
            'start_time' => $currentTime,
            'start_memory' => memory_get_usage(true),
            'context' => $context,
            'parent' => $this->getCurrentParent(),
            'depth' => count($this->callStack),
            'children' => []
        ];

        // Add to call stack
        $this->callStack[] = $profileId;

        // Track memory snapshot
        if ($this->config['memory_tracking']) {
            $this->memorySnapshots[$profileId] = [
                'start' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ];
        }

        return $profileId;
    }

    public function end(string $profileId): ?array
    {
        if (!$this->enabled || !isset($this->profiles[$profileId])) {
            return null;
        }

        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);

        $profile = &$this->profiles[$profileId];
        $profile['end_time'] = $currentTime;
        $profile['duration'] = $currentTime - $profile['start_time'];
        $profile['end_memory'] = $currentMemory;
        $profile['memory_used'] = $currentMemory - $profile['start_memory'];
        $profile['memory_peak'] = memory_get_peak_usage(true);

        // Remove from call stack
        $stackIndex = array_search($profileId, $this->callStack);
        if ($stackIndex !== false) {
            array_splice($this->callStack, $stackIndex, 1);
        }

        // Update parent's children
        if ($profile['parent'] && isset($this->profiles[$profile['parent']])) {
            $this->profiles[$profile['parent']]['children'][] = $profileId;
        }

        // Check if this is a hot path
        $this->analyzeHotPath($profile);

        return $profile;
    }

    public function measure(callable $callback, string $label, array $context = [])
    {
        $profileId = $this->start($label, $context);

        try {
            $result = $callback();
            return $result;
        } finally {
            $this->end($profileId);
        }
    }

    private function getCurrentParent(): ?string
    {
        return end($this->callStack) ?: null;
    }

    private function shouldSample(): bool
    {
        return mt_rand() / mt_getrandmax() < $this->config['sample_rate'];
    }

    private function analyzeHotPath(array $profile): void
    {
        if ($profile['duration'] >= $this->config['hot_path_threshold']) {
            $hotPath = [
                'label' => $profile['label'],
                'duration' => $profile['duration'],
                'memory_used' => $profile['memory_used'],
                'context' => $profile['context'],
                'timestamp' => $profile['start_time'],
                'call_depth' => $profile['depth']
            ];

            $this->hotPaths[] = $hotPath;

            $this->logger->info('Hot path detected', $hotPath);
        }
    }

    public function getHotPaths(): array
    {
        // Sort by duration descending
        $sorted = $this->hotPaths;
        usort($sorted, fn($a, $b) => $b['duration'] <=> $a['duration']);

        return array_slice($sorted, 0, 20); // Top 20 hot paths
    }

    public function getProfilingReport(): array
    {
        $totalDuration = microtime(true) - $this->startTime;
        $profileCount = count($this->profiles);

        $report = [
            'summary' => [
                'enabled' => $this->enabled,
                'total_profiles' => $profileCount,
                'total_duration' => $totalDuration,
                'hot_paths_count' => count($this->hotPaths),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_current' => memory_get_usage(true)
            ],
            'hot_paths' => $this->getHotPaths(),
            'function_analysis' => $this->analyzeFunctions(),
            'memory_analysis' => $this->analyzeMemoryUsage(),
            'optimization_recommendations' => $this->generateOptimizationRecommendations()
        ];

        return $report;
    }

    private function analyzeFunctions(): array
    {
        $functionStats = [];

        foreach ($this->profiles as $profile) {
            $label = $profile['label'];

            if (!isset($functionStats[$label])) {
                $functionStats[$label] = [
                    'count' => 0,
                    'total_duration' => 0,
                    'max_duration' => 0,
                    'min_duration' => PHP_FLOAT_MAX,
                    'total_memory' => 0,
                    'max_memory' => 0
                ];
            }

            $stats = &$functionStats[$label];
            $stats['count']++;
            $stats['total_duration'] += $profile['duration'] ?? 0;
            $stats['max_duration'] = max($stats['max_duration'], $profile['duration'] ?? 0);
            $stats['min_duration'] = min($stats['min_duration'], $profile['duration'] ?? 0);
            $stats['total_memory'] += $profile['memory_used'] ?? 0;
            $stats['max_memory'] = max($stats['max_memory'], $profile['memory_used'] ?? 0);
        }

        // Calculate averages
        foreach ($functionStats as &$stats) {
            $stats['avg_duration'] = $stats['count'] > 0 ? $stats['total_duration'] / $stats['count'] : 0;
            $stats['avg_memory'] = $stats['count'] > 0 ? $stats['total_memory'] / $stats['count'] : 0;
        }

        // Sort by total duration
        uasort($functionStats, fn($a, $b) => $b['total_duration'] <=> $a['total_duration']);

        return array_slice($functionStats, 0, 15, true); // Top 15 functions
    }

    private function analyzeMemoryUsage(): array
    {
        if (empty($this->memorySnapshots)) {
            return [];
        }

        $memoryGrowth = [];
        $lastMemory = 0;

        foreach ($this->profiles as $profile) {
            if (isset($profile['end_memory'])) {
                $growth = $profile['end_memory'] - $lastMemory;
                if ($growth > 0) {
                    $memoryGrowth[] = [
                        'label' => $profile['label'],
                        'growth' => $growth,
                        'timestamp' => $profile['end_time']
                    ];
                }
                $lastMemory = $profile['end_memory'];
            }
        }

        // Sort by memory growth
        usort($memoryGrowth, fn($a, $b) => $b['growth'] <=> $a['growth']);

        return [
            'peak_usage' => memory_get_peak_usage(true),
            'current_usage' => memory_get_usage(true),
            'largest_allocations' => array_slice($memoryGrowth, 0, 10)
        ];
    }

    private function generateOptimizationRecommendations(): array
    {
        $recommendations = [];

        // Analyze hot paths
        foreach ($this->getHotPaths() as $hotPath) {
            if ($hotPath['duration'] > 1.0) {
                $recommendations[] = [
                    'type' => 'performance',
                    'priority' => 'high',
                    'message' => "Function '{$hotPath['label']}' takes {$hotPath['duration']}s - consider optimization",
                    'target' => $hotPath['label'],
                    'metrics' => [
                        'duration' => $hotPath['duration'],
                        'memory' => $hotPath['memory_used']
                    ]
                ];
            }
        }

        // Analyze memory usage
        $memoryAnalysis = $this->analyzeMemoryUsage();
        if (isset($memoryAnalysis['peak_usage']) && $memoryAnalysis['peak_usage'] > 128 * 1024 * 1024) {
            $recommendations[] = [
                'type' => 'memory',
                'priority' => 'medium',
                'message' => 'High memory usage detected - consider memory optimization',
                'metrics' => [
                    'peak_memory' => $memoryAnalysis['peak_usage']
                ]
            ];
        }

        // Analyze function call frequency
        $functionAnalysis = $this->analyzeFunctions();
        foreach ($functionAnalysis as $label => $stats) {
            if ($stats['count'] > 100 && $stats['avg_duration'] > 0.01) {
                $recommendations[] = [
                    'type' => 'frequency',
                    'priority' => 'medium',
                    'message' => "Function '{$label}' called {$stats['count']} times - consider caching",
                    'target' => $label,
                    'metrics' => $stats
                ];
            }
        }

        return $recommendations;
    }

    public function exportProfile(string $format = 'json'): string
    {
        $data = $this->getProfilingReport();

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            case 'csv':
                return $this->exportToCsv($data);
            default:
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }
    }

    private function exportToCsv(array $data): string
    {
        $csv = "Function,Call Count,Total Duration,Avg Duration,Max Duration,Memory Used\n";

        foreach ($data['function_analysis'] as $label => $stats) {
            $csv .= sprintf(
                "%s,%d,%.6f,%.6f,%.6f,%d\n",
                $label,
                $stats['count'],
                $stats['total_duration'],
                $stats['avg_duration'],
                $stats['max_duration'],
                $stats['total_memory']
            );
        }

        return $csv;
    }

    public function reset(): void
    {
        $this->profiles = [];
        $this->hotPaths = [];
        $this->callStack = [];
        $this->memorySnapshots = [];
        $this->startTime = microtime(true);

        $this->logger->info('Profiler reset');
    }

    public function enable(bool $enable = true): void
    {
        $this->enabled = $enable;
        $this->logger->info('Profiler ' . ($enable ? 'enabled' : 'disabled'));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'profiles_count' => count($this->profiles),
            'hot_paths_count' => count($this->hotPaths),
            'call_stack_depth' => count($this->callStack),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
}