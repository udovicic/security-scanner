<?php

namespace SecurityScanner\Tests;

class TestResultAggregator
{
    private array $config;
    private array $aggregatedData = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_caching' => true,
            'cache_duration' => 3600,
            'calculate_trends' => true,
            'score_weights' => [
                'security' => 0.4,
                'performance' => 0.3,
                'availability' => 0.3
            ]
        ], $config);
    }

    /**
     * Aggregate test results into summary statistics
     */
    public function aggregateResults(array $results, array $metadata = []): AggregatedResult
    {
        $startTime = microtime(true);

        $summary = $this->calculateSummaryStatistics($results);
        $categoryStats = $this->calculateCategoryStatistics($results);
        $scoreBreakdown = $this->calculateScoreBreakdown($results);
        $trends = $this->calculateTrends($results, $metadata);
        $recommendations = $this->generateRecommendations($results, $summary);

        $aggregationTime = microtime(true) - $startTime;

        return new AggregatedResult([
            'summary' => $summary,
            'category_stats' => $categoryStats,
            'score_breakdown' => $scoreBreakdown,
            'trends' => $trends,
            'recommendations' => $recommendations,
            'metadata' => array_merge($metadata, [
                'aggregation_time' => $aggregationTime,
                'aggregated_at' => new \DateTime()
            ])
        ]);
    }

    /**
     * Calculate summary statistics
     */
    private function calculateSummaryStatistics(array $results): array
    {
        $total = count($results);
        $passed = 0;
        $failed = 0;
        $warnings = 0;
        $errors = 0;
        $skipped = 0;
        $timeouts = 0;

        $totalExecutionTime = 0;
        $totalMemoryUsage = 0;
        $scores = [];

        foreach ($results as $result) {
            if (!$result instanceof TestResult) {
                continue;
            }

            switch ($result->getStatus()) {
                case TestResult::STATUS_PASS:
                    $passed++;
                    break;
                case TestResult::STATUS_FAIL:
                    $failed++;
                    break;
                case TestResult::STATUS_WARNING:
                    $warnings++;
                    break;
                case TestResult::STATUS_ERROR:
                    $errors++;
                    break;
                case TestResult::STATUS_SKIP:
                    $skipped++;
                    break;
                case TestResult::STATUS_TIMEOUT:
                    $timeouts++;
                    break;
            }

            $totalExecutionTime += $result->getExecutionTime();
            $totalMemoryUsage += $result->getMemoryUsage();

            if ($result->getScore() !== null) {
                $scores[] = $result->getScore();
            }
        }

        $successRate = $total > 0 ? ($passed / $total) * 100 : 0;
        $averageScore = !empty($scores) ? array_sum($scores) / count($scores) : null;

        return [
            'total_tests' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'errors' => $errors,
            'skipped' => $skipped,
            'timeouts' => $timeouts,
            'success_rate' => round($successRate, 2),
            'average_score' => $averageScore ? round($averageScore, 2) : null,
            'total_execution_time' => round($totalExecutionTime, 3),
            'average_execution_time' => $total > 0 ? round($totalExecutionTime / $total, 3) : 0,
            'total_memory_usage' => $totalMemoryUsage,
            'average_memory_usage' => $total > 0 ? round($totalMemoryUsage / $total) : 0
        ];
    }

    /**
     * Calculate statistics by category
     */
    private function calculateCategoryStatistics(array $results): array
    {
        $categories = [];

        foreach ($results as $result) {
            if (!$result instanceof TestResult) {
                continue;
            }

            // Extract category from test name or metadata
            $category = $this->extractCategory($result);

            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'total' => 0,
                    'passed' => 0,
                    'failed' => 0,
                    'warnings' => 0,
                    'errors' => 0,
                    'scores' => [],
                    'execution_times' => []
                ];
            }

            $cat = &$categories[$category];
            $cat['total']++;

            switch ($result->getStatus()) {
                case TestResult::STATUS_PASS:
                    $cat['passed']++;
                    break;
                case TestResult::STATUS_FAIL:
                    $cat['failed']++;
                    break;
                case TestResult::STATUS_WARNING:
                    $cat['warnings']++;
                    break;
                case TestResult::STATUS_ERROR:
                    $cat['errors']++;
                    break;
            }

            if ($result->getScore() !== null) {
                $cat['scores'][] = $result->getScore();
            }

            $cat['execution_times'][] = $result->getExecutionTime();
        }

        // Calculate derived statistics
        foreach ($categories as $category => &$stats) {
            $stats['success_rate'] = $stats['total'] > 0
                ? round(($stats['passed'] / $stats['total']) * 100, 2)
                : 0;

            $stats['average_score'] = !empty($stats['scores'])
                ? round(array_sum($stats['scores']) / count($stats['scores']), 2)
                : null;

            $stats['average_execution_time'] = !empty($stats['execution_times'])
                ? round(array_sum($stats['execution_times']) / count($stats['execution_times']), 3)
                : 0;

            // Remove raw arrays to keep output clean
            unset($stats['scores'], $stats['execution_times']);
        }

        return $categories;
    }

    /**
     * Extract category from test result
     */
    private function extractCategory(TestResult $result): string
    {
        $testName = $result->getTestName();

        // Try to extract from test name patterns
        if (str_contains($testName, 'ssl') || str_contains($testName, 'security')) {
            return 'security';
        } elseif (str_contains($testName, 'response_time') || str_contains($testName, 'performance')) {
            return 'performance';
        } elseif (str_contains($testName, 'status') || str_contains($testName, 'availability')) {
            return 'availability';
        }

        return 'general';
    }

    /**
     * Calculate score breakdown
     */
    private function calculateScoreBreakdown(array $results): array
    {
        $categoryScores = [];

        foreach ($results as $result) {
            if (!$result instanceof TestResult || $result->getScore() === null) {
                continue;
            }

            $category = $this->extractCategory($result);
            if (!isset($categoryScores[$category])) {
                $categoryScores[$category] = [];
            }
            $categoryScores[$category][] = $result->getScore();
        }

        $breakdown = [];
        $overallScore = 0;
        $totalWeight = 0;

        foreach ($categoryScores as $category => $scores) {
            $avgScore = array_sum($scores) / count($scores);
            $weight = $this->config['score_weights'][$category] ?? 0.1;

            $breakdown[$category] = [
                'average_score' => round($avgScore, 2),
                'test_count' => count($scores),
                'weight' => $weight,
                'weighted_score' => round($avgScore * $weight, 2)
            ];

            $overallScore += $avgScore * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight > 0) {
            $breakdown['overall_score'] = round($overallScore, 2);
        }

        return $breakdown;
    }

    /**
     * Calculate trends (if historical data is available)
     */
    private function calculateTrends(array $results, array $metadata): array
    {
        if (!$this->config['calculate_trends']) {
            return [];
        }

        // This would typically compare with historical data
        // For now, return basic trend indicators
        $trends = [
            'execution_time_trend' => 'stable',
            'success_rate_trend' => 'stable',
            'score_trend' => 'stable'
        ];

        // Add basic trend analysis based on variance in current results
        $executionTimes = array_map(fn($r) => $r->getExecutionTime(),
            array_filter($results, fn($r) => $r instanceof TestResult));

        if (count($executionTimes) > 1) {
            $variance = $this->calculateVariance($executionTimes);
            $trends['execution_time_variance'] = round($variance, 4);
            $trends['execution_time_stability'] = $variance < 0.1 ? 'stable' : 'variable';
        }

        return $trends;
    }

    /**
     * Generate recommendations based on results
     */
    private function generateRecommendations(array $results, array $summary): array
    {
        $recommendations = [];

        // Success rate recommendations
        if ($summary['success_rate'] < 80) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'reliability',
                'message' => 'Low success rate detected. Review failing tests and address underlying issues.',
                'priority' => 'high'
            ];
        }

        // Performance recommendations
        if ($summary['average_execution_time'] > 10) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'performance',
                'message' => 'High average execution time. Consider optimizing slow tests or infrastructure.',
                'priority' => 'medium'
            ];
        }

        // Error analysis
        if ($summary['errors'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'stability',
                'message' => 'Test execution errors detected. Review test implementations and dependencies.',
                'priority' => 'medium'
            ];
        }

        // Timeout analysis
        if ($summary['timeouts'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'performance',
                'message' => 'Test timeouts detected. Consider increasing timeout values or optimizing test performance.',
                'priority' => 'medium'
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate variance
     */
    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($x) => pow($x - $mean, 2), $values);
        return array_sum($squaredDiffs) / count($values);
    }

    /**
     * Compare with historical results
     */
    public function compareWithHistorical(AggregatedResult $current, array $historicalResults): array
    {
        if (empty($historicalResults)) {
            return ['message' => 'No historical data available for comparison'];
        }

        $comparison = [];
        $currentSummary = $current->getSummary();

        // Calculate averages from historical data
        $historicalAvgs = $this->calculateHistoricalAverages($historicalResults);

        foreach (['success_rate', 'average_score', 'average_execution_time'] as $metric) {
            if (isset($currentSummary[$metric]) && isset($historicalAvgs[$metric])) {
                $current_val = $currentSummary[$metric];
                $historical_val = $historicalAvgs[$metric];
                $change = $current_val - $historical_val;
                $percentChange = $historical_val != 0 ? ($change / $historical_val) * 100 : 0;

                $comparison[$metric] = [
                    'current' => $current_val,
                    'historical_average' => $historical_val,
                    'change' => round($change, 3),
                    'percent_change' => round($percentChange, 2),
                    'trend' => $change > 0 ? 'improved' : ($change < 0 ? 'declined' : 'stable')
                ];
            }
        }

        return $comparison;
    }

    /**
     * Calculate historical averages
     */
    private function calculateHistoricalAverages(array $historicalResults): array
    {
        $metrics = ['success_rate', 'average_score', 'average_execution_time'];
        $averages = [];

        foreach ($metrics as $metric) {
            $values = [];
            foreach ($historicalResults as $result) {
                if ($result instanceof AggregatedResult) {
                    $summary = $result->getSummary();
                    if (isset($summary[$metric])) {
                        $values[] = $summary[$metric];
                    }
                }
            }

            if (!empty($values)) {
                $averages[$metric] = array_sum($values) / count($values);
            }
        }

        return $averages;
    }
}

/**
 * Aggregated Result container
 */
class AggregatedResult
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getSummary(): array
    {
        return $this->data['summary'] ?? [];
    }

    public function getCategoryStats(): array
    {
        return $this->data['category_stats'] ?? [];
    }

    public function getScoreBreakdown(): array
    {
        return $this->data['score_breakdown'] ?? [];
    }

    public function getTrends(): array
    {
        return $this->data['trends'] ?? [];
    }

    public function getRecommendations(): array
    {
        return $this->data['recommendations'] ?? [];
    }

    public function getMetadata(): array
    {
        return $this->data['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT);
    }
}