<?php

namespace SecurityScanner\Tests;

class TestScheduler
{
    private array $strategies;
    private array $metrics = [];

    public function __construct(array $strategies = [])
    {
        $this->strategies = array_merge([
            'priority_scheduling' => true,
            'load_balancing' => true,
            'resource_aware' => true,
            'adaptive_batching' => true
        ], $strategies);
    }

    public function optimize(array $testJobs, array $dependencyMap): array
    {
        $optimized = $testJobs;

        // Apply dependency-based ordering
        if (isset($dependencyMap['topological_order'])) {
            $optimized = $this->applyTopologicalOrder($optimized, $dependencyMap['topological_order']);
        }

        // Apply priority scheduling
        if ($this->strategies['priority_scheduling']) {
            $optimized = $this->applyPriorityScheduling($optimized);
        }

        // Apply load balancing
        if ($this->strategies['load_balancing']) {
            $optimized = $this->applyLoadBalancing($optimized);
        }

        // Apply adaptive batching
        if ($this->strategies['adaptive_batching']) {
            $optimized = $this->applyAdaptiveBatching($optimized);
        }

        return $optimized;
    }

    private function applyTopologicalOrder(array $jobs, array $topologicalOrder): array
    {
        $ordered = [];
        $jobsById = [];

        // Create lookup by ID
        foreach ($jobs as $job) {
            $jobsById[$job['id']] = $job;
        }

        // Order by topological sort
        foreach ($topologicalOrder as $jobId) {
            if (isset($jobsById[$jobId])) {
                $ordered[] = $jobsById[$jobId];
            }
        }

        // Add any remaining jobs not in topological order
        foreach ($jobs as $job) {
            if (!in_array($job['id'], $topologicalOrder)) {
                $ordered[] = $job;
            }
        }

        return $ordered;
    }

    private function applyPriorityScheduling(array $jobs): array
    {
        // Sort by priority (higher priority first)
        usort($jobs, function($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;

            if ($priorityA === $priorityB) {
                // Secondary sort by estimated duration (shorter first)
                $durationA = $a['estimated_duration'] ?? 1.0;
                $durationB = $b['estimated_duration'] ?? 1.0;
                return $durationA <=> $durationB;
            }

            return $priorityB <=> $priorityA; // Higher priority first
        });

        return $jobs;
    }

    private function applyLoadBalancing(array $jobs): array
    {
        if (count($jobs) <= 1) {
            return $jobs;
        }

        // Group jobs by estimated resource usage
        $lightJobs = [];
        $mediumJobs = [];
        $heavyJobs = [];

        foreach ($jobs as $job) {
            $complexity = $job['complexity'] ?? 1.0;

            if ($complexity <= 0.5) {
                $lightJobs[] = $job;
            } elseif ($complexity <= 2.0) {
                $mediumJobs[] = $job;
            } else {
                $heavyJobs[] = $job;
            }
        }

        // Interleave jobs to balance load
        return $this->interleaveJobs([$heavyJobs, $mediumJobs, $lightJobs]);
    }

    private function interleaveJobs(array $jobGroups): array
    {
        $result = [];
        $maxLength = max(array_map('count', $jobGroups));

        for ($i = 0; $i < $maxLength; $i++) {
            foreach ($jobGroups as $group) {
                if (isset($group[$i])) {
                    $result[] = $group[$i];
                }
            }
        }

        return $result;
    }

    private function applyAdaptiveBatching(array $jobs): array
    {
        // Group similar jobs together for better cache efficiency
        $batches = [];
        $batchSize = $this->calculateOptimalBatchSize(count($jobs));

        // Group by test type and target similarity
        $grouped = $this->groupSimilarJobs($jobs);

        foreach ($grouped as $group) {
            $chunks = array_chunk($group, $batchSize);
            $batches = array_merge($batches, $chunks);
        }

        // Flatten batches back to single array
        return array_merge(...$batches);
    }

    private function groupSimilarJobs(array $jobs): array
    {
        $groups = [];

        foreach ($jobs as $job) {
            $key = $this->generateGroupKey($job);

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $job;
        }

        return array_values($groups);
    }

    private function generateGroupKey(array $job): string
    {
        $testType = $job['test_name'] ?? 'unknown';
        $domain = parse_url($job['target'] ?? '', PHP_URL_HOST) ?? 'unknown';

        return $testType . ':' . $domain;
    }

    private function calculateOptimalBatchSize(int $totalJobs): int
    {
        // Adaptive batch size based on total job count
        if ($totalJobs <= 10) {
            return 3;
        } elseif ($totalJobs <= 50) {
            return 5;
        } elseif ($totalJobs <= 200) {
            return 10;
        } else {
            return 20;
        }
    }

    public function schedulePriorityJob(array $job, array $currentQueue): array
    {
        $priority = $job['priority'] ?? 100;
        $insertPosition = 0;

        // Find insertion position based on priority
        foreach ($currentQueue as $index => $queuedJob) {
            $queuedPriority = $queuedJob['priority'] ?? 100;

            if ($priority > $queuedPriority) {
                $insertPosition = $index;
                break;
            }

            $insertPosition = $index + 1;
        }

        // Insert at calculated position
        array_splice($currentQueue, $insertPosition, 0, [$job]);

        return $currentQueue;
    }

    public function estimateExecutionTime(array $jobs): float
    {
        $totalSequential = 0;
        $maxParallel = 0;

        foreach ($jobs as $job) {
            $duration = $job['estimated_duration'] ?? 1.0;
            $totalSequential += $duration;
            $maxParallel = max($maxParallel, $duration);
        }

        // Estimate based on parallelization potential
        $parallelizationFactor = $this->estimateParallelizationFactor($jobs);

        return $totalSequential * (1 - $parallelizationFactor) + $maxParallel * $parallelizationFactor;
    }

    private function estimateParallelizationFactor(array $jobs): float
    {
        // Simple heuristic based on job independence
        $independentJobs = 0;

        foreach ($jobs as $job) {
            if (empty($job['dependencies'])) {
                $independentJobs++;
            }
        }

        return count($jobs) > 0 ? $independentJobs / count($jobs) : 0;
    }

    public function getSchedulingMetrics(): array
    {
        return $this->metrics;
    }

    public function optimizeForDeadline(array $jobs, float $deadline): array
    {
        // Sort jobs by deadline impact ratio
        usort($jobs, function($a, $b) {
            $impactA = $this->calculateDeadlineImpact($a);
            $impactB = $this->calculateDeadlineImpact($b);

            return $impactB <=> $impactA; // Higher impact first
        });

        // Check if deadline is achievable
        $estimatedTime = $this->estimateExecutionTime($jobs);

        if ($estimatedTime > $deadline) {
            // Consider dropping low-priority jobs or increasing parallelization
            $jobs = $this->optimizeForTimeConstraint($jobs, $deadline);
        }

        return $jobs;
    }

    private function calculateDeadlineImpact(array $job): float
    {
        $priority = $job['priority'] ?? 100;
        $duration = $job['estimated_duration'] ?? 1.0;

        // Higher priority and shorter duration = higher impact
        return $duration > 0 ? $priority / $duration : $priority;
    }

    private function optimizeForTimeConstraint(array $jobs, float $timeLimit): array
    {
        $optimized = [];
        $estimatedTime = 0;

        // Sort by efficiency (priority / duration)
        usort($jobs, function($a, $b) {
            $efficiencyA = $this->calculateEfficiency($a);
            $efficiencyB = $this->calculateEfficiency($b);

            return $efficiencyB <=> $efficiencyA;
        });

        foreach ($jobs as $job) {
            $jobDuration = $job['estimated_duration'] ?? 1.0;

            if ($estimatedTime + $jobDuration <= $timeLimit) {
                $optimized[] = $job;
                $estimatedTime += $jobDuration;
            }
        }

        return $optimized;
    }

    private function calculateEfficiency(array $job): float
    {
        $priority = $job['priority'] ?? 100;
        $duration = $job['estimated_duration'] ?? 1.0;

        return $duration > 0 ? $priority / $duration : 0;
    }
}