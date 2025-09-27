<?php

namespace SecurityScanner\Tests;

class TestDependencyGraph
{
    private array $dependencies = [];
    private array $reverseDependencies = [];
    private array $stats = [];

    public function analyze(array $testJobs): array
    {
        $this->buildGraph($testJobs);
        $this->detectCycles();
        $this->calculateMetrics();

        return [
            'dependencies' => $this->dependencies,
            'reverse_dependencies' => $this->reverseDependencies,
            'topological_order' => $this->getTopologicalOrder(),
            'critical_path' => $this->getCriticalPath(),
            'parallelizable_groups' => $this->getParallelizableGroups()
        ];
    }

    private function buildGraph(array $testJobs): void
    {
        $this->dependencies = [];
        $this->reverseDependencies = [];

        foreach ($testJobs as $job) {
            $jobId = $job['id'];
            $this->dependencies[$jobId] = $job['dependencies'] ?? [];

            foreach ($this->dependencies[$jobId] as $dependency) {
                if (!isset($this->reverseDependencies[$dependency])) {
                    $this->reverseDependencies[$dependency] = [];
                }
                $this->reverseDependencies[$dependency][] = $jobId;
            }
        }
    }

    private function detectCycles(): void
    {
        $visited = [];
        $recStack = [];
        $cycles = [];

        foreach (array_keys($this->dependencies) as $node) {
            if (!isset($visited[$node])) {
                $this->detectCyclesUtil($node, $visited, $recStack, $cycles);
            }
        }

        if (!empty($cycles)) {
            throw new \InvalidArgumentException('Circular dependencies detected: ' . implode(', ', $cycles));
        }
    }

    private function detectCyclesUtil(string $node, array &$visited, array &$recStack, array &$cycles): bool
    {
        $visited[$node] = true;
        $recStack[$node] = true;

        foreach ($this->dependencies[$node] as $dependency) {
            if (!isset($visited[$dependency])) {
                if ($this->detectCyclesUtil($dependency, $visited, $recStack, $cycles)) {
                    return true;
                }
            } elseif (isset($recStack[$dependency]) && $recStack[$dependency]) {
                $cycles[] = $node . ' -> ' . $dependency;
                return true;
            }
        }

        $recStack[$node] = false;
        return false;
    }

    public function getTopologicalOrder(): array
    {
        $inDegree = [];
        $queue = [];
        $result = [];

        // Calculate in-degrees
        foreach (array_keys($this->dependencies) as $node) {
            $inDegree[$node] = 0;
        }

        foreach ($this->dependencies as $node => $deps) {
            foreach ($deps as $dep) {
                if (isset($inDegree[$dep])) {
                    $inDegree[$dep]++;
                }
            }
        }

        // Add nodes with no dependencies to queue
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue[] = $node;
            }
        }

        // Process queue
        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            foreach ($this->reverseDependencies[$current] ?? [] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        return $result;
    }

    public function getCriticalPath(): array
    {
        // Simplified critical path calculation
        $distances = [];
        $order = $this->getTopologicalOrder();

        foreach ($order as $node) {
            $distances[$node] = 0;
            foreach ($this->dependencies[$node] as $dependency) {
                if (isset($distances[$dependency])) {
                    $distances[$node] = max($distances[$node], $distances[$dependency] + 1);
                }
            }
        }

        // Find the longest path
        $maxDistance = max($distances);
        $criticalNodes = array_keys(array_filter($distances, fn($d) => $d === $maxDistance));

        return [
            'length' => $maxDistance,
            'nodes' => $criticalNodes
        ];
    }

    public function getParallelizableGroups(): array
    {
        $groups = [];
        $processed = [];
        $order = $this->getTopologicalOrder();

        foreach ($order as $node) {
            if (isset($processed[$node])) {
                continue;
            }

            $group = [$node];
            $processed[$node] = true;

            // Find nodes that can run in parallel with this one
            foreach ($order as $otherNode) {
                if ($otherNode === $node || isset($processed[$otherNode])) {
                    continue;
                }

                if ($this->canRunInParallel($node, $otherNode)) {
                    $group[] = $otherNode;
                    $processed[$otherNode] = true;
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }

    private function canRunInParallel(string $node1, string $node2): bool
    {
        // Check if nodes have any dependency relationship
        return !$this->hasDependencyPath($node1, $node2) &&
               !$this->hasDependencyPath($node2, $node1);
    }

    private function hasDependencyPath(string $from, string $to): bool
    {
        $visited = [];
        return $this->hasDependencyPathUtil($from, $to, $visited);
    }

    private function hasDependencyPathUtil(string $from, string $to, array &$visited): bool
    {
        if ($from === $to) {
            return true;
        }

        $visited[$from] = true;

        foreach ($this->dependencies[$from] ?? [] as $dependency) {
            if (!isset($visited[$dependency])) {
                if ($this->hasDependencyPathUtil($dependency, $to, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function calculateMetrics(): void
    {
        $this->stats = [
            'total_nodes' => count($this->dependencies),
            'total_edges' => array_sum(array_map('count', $this->dependencies)),
            'max_depth' => $this->getCriticalPath()['length'],
            'parallelization_factor' => $this->calculateParallelizationFactor(),
            'dependency_density' => $this->calculateDependencyDensity()
        ];
    }

    private function calculateParallelizationFactor(): float
    {
        $groups = $this->getParallelizableGroups();
        $totalNodes = count($this->dependencies);

        if ($totalNodes === 0) {
            return 0.0;
        }

        $maxParallel = max(array_map('count', $groups));
        return $maxParallel / $totalNodes;
    }

    private function calculateDependencyDensity(): float
    {
        $totalNodes = count($this->dependencies);
        $totalEdges = array_sum(array_map('count', $this->dependencies));

        if ($totalNodes <= 1) {
            return 0.0;
        }

        $maxPossibleEdges = $totalNodes * ($totalNodes - 1);
        return $maxPossibleEdges > 0 ? $totalEdges / $maxPossibleEdges : 0.0;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function optimizeExecutionOrder(array $testJobs): array
    {
        $order = $this->getTopologicalOrder();
        $optimized = [];

        foreach ($order as $nodeId) {
            foreach ($testJobs as $job) {
                if ($job['id'] === $nodeId) {
                    $optimized[] = $job;
                    break;
                }
            }
        }

        return $optimized;
    }
}