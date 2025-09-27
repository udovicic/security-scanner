<?php

namespace SecurityScanner\Core;

class HotPathDetector
{
    private array $callGraphs = [];
    private array $executionPaths = [];
    private array $heatMap = [];
    private Config $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance('hot_path_detector');
    }

    public function analyzeCallGraph(array $profiles): array
    {
        $this->buildCallGraph($profiles);
        $this->calculateHeatMap();
        $hotPaths = $this->identifyHotPaths();

        return [
            'hot_paths' => $hotPaths,
            'call_graph' => $this->callGraphs,
            'heat_map' => $this->heatMap,
            'optimization_opportunities' => $this->findOptimizationOpportunities($hotPaths)
        ];
    }

    private function buildCallGraph(array $profiles): void
    {
        $this->callGraphs = [];

        foreach ($profiles as $profile) {
            $caller = $profile['parent'] ?? 'root';
            $callee = $profile['label'];

            if (!isset($this->callGraphs[$caller])) {
                $this->callGraphs[$caller] = [];
            }

            if (!isset($this->callGraphs[$caller][$callee])) {
                $this->callGraphs[$caller][$callee] = [
                    'call_count' => 0,
                    'total_duration' => 0,
                    'max_duration' => 0,
                    'total_memory' => 0,
                    'max_memory' => 0
                ];
            }

            $edge = &$this->callGraphs[$caller][$callee];
            $edge['call_count']++;
            $edge['total_duration'] += $profile['duration'] ?? 0;
            $edge['max_duration'] = max($edge['max_duration'], $profile['duration'] ?? 0);
            $edge['total_memory'] += $profile['memory_used'] ?? 0;
            $edge['max_memory'] = max($edge['max_memory'], $profile['memory_used'] ?? 0);
        }
    }

    private function calculateHeatMap(): void
    {
        $this->heatMap = [];

        foreach ($this->callGraphs as $caller => $callees) {
            foreach ($callees as $callee => $metrics) {
                $heat = $this->calculateHeatScore($metrics);

                $this->heatMap[$callee] = [
                    'heat_score' => $heat,
                    'call_frequency' => $metrics['call_count'],
                    'avg_duration' => $metrics['call_count'] > 0 ?
                        $metrics['total_duration'] / $metrics['call_count'] : 0,
                    'total_impact' => $metrics['total_duration'],
                    'memory_impact' => $metrics['total_memory']
                ];
            }
        }

        // Sort by heat score
        uasort($this->heatMap, fn($a, $b) => $b['heat_score'] <=> $a['heat_score']);
    }

    private function calculateHeatScore(array $metrics): float
    {
        $frequency = $metrics['call_count'];
        $totalDuration = $metrics['total_duration'];
        $avgDuration = $frequency > 0 ? $totalDuration / $frequency : 0;
        $memoryImpact = $metrics['total_memory'];

        // Weighted heat score
        $frequencyWeight = 0.3;
        $durationWeight = 0.5;
        $memoryWeight = 0.2;

        $normalizedFrequency = min($frequency / 100, 1); // Normalize to 0-1
        $normalizedDuration = min($avgDuration, 1); // Cap at 1 second
        $normalizedMemory = min($memoryImpact / (10 * 1024 * 1024), 1); // Cap at 10MB

        return ($normalizedFrequency * $frequencyWeight) +
               ($normalizedDuration * $durationWeight) +
               ($normalizedMemory * $memoryWeight);
    }

    private function identifyHotPaths(): array
    {
        $hotPaths = [];
        $threshold = 0.1; // Heat score threshold

        foreach ($this->heatMap as $function => $metrics) {
            if ($metrics['heat_score'] >= $threshold) {
                $hotPath = [
                    'function' => $function,
                    'heat_score' => $metrics['heat_score'],
                    'optimization_priority' => $this->calculateOptimizationPriority($metrics),
                    'bottleneck_type' => $this->identifyBottleneckType($metrics),
                    'execution_paths' => $this->findExecutionPaths($function),
                    'metrics' => $metrics
                ];

                $hotPaths[] = $hotPath;
            }
        }

        // Sort by optimization priority
        usort($hotPaths, fn($a, $b) => $b['optimization_priority'] <=> $a['optimization_priority']);

        return $hotPaths;
    }

    private function calculateOptimizationPriority(array $metrics): float
    {
        $impact = $metrics['total_impact'];
        $frequency = $metrics['call_frequency'];
        $avgDuration = $metrics['avg_duration'];

        // Higher impact and frequency = higher priority
        return ($impact * 0.5) + ($frequency * 0.3) + ($avgDuration * 0.2);
    }

    private function identifyBottleneckType(array $metrics): string
    {
        $frequency = $metrics['call_frequency'];
        $avgDuration = $metrics['avg_duration'];
        $memoryImpact = $metrics['memory_impact'];

        if ($frequency > 50 && $avgDuration < 0.01) {
            return 'high_frequency_low_duration';
        } elseif ($frequency < 10 && $avgDuration > 0.1) {
            return 'low_frequency_high_duration';
        } elseif ($memoryImpact > 5 * 1024 * 1024) {
            return 'memory_intensive';
        } elseif ($avgDuration > 0.05) {
            return 'cpu_intensive';
        } else {
            return 'balanced';
        }
    }

    private function findExecutionPaths(string $targetFunction): array
    {
        $paths = [];
        $this->findPathsToFunction('root', $targetFunction, [], $paths);

        return array_slice($paths, 0, 5); // Top 5 paths
    }

    private function findPathsToFunction(string $current, string $target, array $path, array &$allPaths): void
    {
        $path[] = $current;

        if ($current === $target) {
            $allPaths[] = $path;
            return;
        }

        if (count($path) > 10) { // Prevent infinite recursion
            return;
        }

        if (isset($this->callGraphs[$current])) {
            foreach ($this->callGraphs[$current] as $child => $metrics) {
                if (!in_array($child, $path)) { // Prevent cycles
                    $this->findPathsToFunction($child, $target, $path, $allPaths);
                }
            }
        }
    }

    private function findOptimizationOpportunities(array $hotPaths): array
    {
        $opportunities = [];

        foreach ($hotPaths as $hotPath) {
            $function = $hotPath['function'];
            $bottleneckType = $hotPath['bottleneck_type'];
            $metrics = $hotPath['metrics'];

            $opportunity = [
                'function' => $function,
                'type' => $bottleneckType,
                'strategies' => $this->suggestOptimizationStrategies($bottleneckType, $metrics),
                'estimated_impact' => $this->estimateOptimizationImpact($metrics),
                'implementation_complexity' => $this->assessImplementationComplexity($bottleneckType)
            ];

            $opportunities[] = $opportunity;
        }

        return $opportunities;
    }

    private function suggestOptimizationStrategies(string $bottleneckType, array $metrics): array
    {
        switch ($bottleneckType) {
            case 'high_frequency_low_duration':
                return [
                    'memoization',
                    'result_caching',
                    'function_inlining',
                    'batch_processing'
                ];

            case 'low_frequency_high_duration':
                return [
                    'algorithm_optimization',
                    'async_processing',
                    'lazy_evaluation',
                    'divide_and_conquer'
                ];

            case 'memory_intensive':
                return [
                    'memory_pooling',
                    'object_reuse',
                    'streaming_processing',
                    'garbage_collection_tuning'
                ];

            case 'cpu_intensive':
                return [
                    'algorithm_complexity_reduction',
                    'parallel_processing',
                    'vectorization',
                    'lookup_tables'
                ];

            default:
                return [
                    'profiling_deeper',
                    'code_review',
                    'benchmarking'
                ];
        }
    }

    private function estimateOptimizationImpact(array $metrics): array
    {
        $frequency = $metrics['call_frequency'];
        $totalImpact = $metrics['total_impact'];
        $avgDuration = $metrics['avg_duration'];

        // Estimate potential improvements
        $timeImprovement = min($totalImpact * 0.3, $totalImpact); // Up to 30% improvement
        $memoryImprovement = min($metrics['memory_impact'] * 0.25, $metrics['memory_impact']); // Up to 25% improvement

        return [
            'time_savings_seconds' => $timeImprovement,
            'memory_savings_bytes' => $memoryImprovement,
            'frequency_reduction_potential' => min($frequency * 0.2, $frequency), // Up to 20% call reduction
            'overall_impact_score' => $this->calculateOverallImpactScore($timeImprovement, $memoryImprovement, $frequency)
        ];
    }

    private function calculateOverallImpactScore(float $timeSavings, float $memorySavings, int $frequency): float
    {
        $timeScore = min($timeSavings * 10, 100); // Scale time savings
        $memoryScore = min($memorySavings / (1024 * 1024), 100); // Scale memory savings (MB)
        $frequencyScore = min($frequency / 10, 100); // Scale frequency

        return ($timeScore * 0.5) + ($memoryScore * 0.3) + ($frequencyScore * 0.2);
    }

    private function assessImplementationComplexity(string $bottleneckType): string
    {
        $complexityMap = [
            'high_frequency_low_duration' => 'low',
            'memory_intensive' => 'medium',
            'cpu_intensive' => 'high',
            'low_frequency_high_duration' => 'medium',
            'balanced' => 'low'
        ];

        return $complexityMap[$bottleneckType] ?? 'unknown';
    }

    public function generateHotPathReport(): array
    {
        $profiles = Profiler::getInstance()->getProfilingReport();
        $analysis = $this->analyzeCallGraph($profiles['function_analysis'] ?? []);

        return [
            'summary' => [
                'hot_paths_found' => count($analysis['hot_paths']),
                'optimization_opportunities' => count($analysis['optimization_opportunities']),
                'total_functions_analyzed' => count($this->heatMap),
                'average_heat_score' => $this->calculateAverageHeatScore()
            ],
            'top_hot_paths' => array_slice($analysis['hot_paths'], 0, 10),
            'optimization_roadmap' => $this->createOptimizationRoadmap($analysis['optimization_opportunities']),
            'performance_insights' => $this->generatePerformanceInsights($analysis)
        ];
    }

    private function calculateAverageHeatScore(): float
    {
        if (empty($this->heatMap)) {
            return 0;
        }

        $totalHeat = array_sum(array_column($this->heatMap, 'heat_score'));
        return $totalHeat / count($this->heatMap);
    }

    private function createOptimizationRoadmap(array $opportunities): array
    {
        // Sort by impact score and complexity
        usort($opportunities, function($a, $b) {
            $scoreA = $a['estimated_impact']['overall_impact_score'];
            $scoreB = $b['estimated_impact']['overall_impact_score'];

            if ($scoreA === $scoreB) {
                // If equal impact, prefer lower complexity
                $complexityOrder = ['low' => 1, 'medium' => 2, 'high' => 3];
                return ($complexityOrder[$a['implementation_complexity']] ?? 3) <=>
                       ($complexityOrder[$b['implementation_complexity']] ?? 3);
            }

            return $scoreB <=> $scoreA;
        });

        $roadmap = [
            'phase_1_quick_wins' => [],
            'phase_2_medium_effort' => [],
            'phase_3_high_effort' => []
        ];

        foreach ($opportunities as $opportunity) {
            $complexity = $opportunity['implementation_complexity'];
            $impact = $opportunity['estimated_impact']['overall_impact_score'];

            if ($complexity === 'low' && $impact > 20) {
                $roadmap['phase_1_quick_wins'][] = $opportunity;
            } elseif ($complexity === 'medium' || ($complexity === 'low' && $impact <= 20)) {
                $roadmap['phase_2_medium_effort'][] = $opportunity;
            } else {
                $roadmap['phase_3_high_effort'][] = $opportunity;
            }
        }

        return $roadmap;
    }

    private function generatePerformanceInsights(array $analysis): array
    {
        $insights = [];

        // Analyze patterns in hot paths
        $bottleneckTypes = array_count_values(array_column($analysis['hot_paths'], 'bottleneck_type'));

        if ($bottleneckTypes['high_frequency_low_duration'] ?? 0 > 3) {
            $insights[] = [
                'type' => 'pattern',
                'message' => 'Multiple high-frequency functions detected - consider batch processing or caching',
                'priority' => 'high'
            ];
        }

        if ($bottleneckTypes['memory_intensive'] ?? 0 > 2) {
            $insights[] = [
                'type' => 'resource',
                'message' => 'Memory usage pattern suggests need for better memory management',
                'priority' => 'medium'
            ];
        }

        // Analyze call graph complexity
        $avgCallsPerFunction = count($this->callGraphs) > 0 ?
            array_sum(array_map('count', $this->callGraphs)) / count($this->callGraphs) : 0;

        if ($avgCallsPerFunction > 5) {
            $insights[] = [
                'type' => 'architecture',
                'message' => 'High call graph complexity - consider function refactoring',
                'priority' => 'low'
            ];
        }

        return $insights;
    }
}