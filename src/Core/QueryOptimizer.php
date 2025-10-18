<?php

namespace SecurityScanner\Core;

class QueryOptimizer
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private array $queryCache = [];
    private array $performanceStats = [];

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::channel('query_optimizer');

        $this->config = array_merge([
            'enable_query_cache' => true,
            'cache_ttl_seconds' => 300,
            'slow_query_threshold_ms' => 1000,
            'enable_explain_analysis' => true,
            'max_cached_queries' => 1000,
            'enable_index_suggestions' => true,
            'performance_monitoring' => true
        ], $config);
    }

    public function optimizeQuery(string $sql, array $params = []): array
    {
        try {
            $queryHash = $this->generateQueryHash($sql, $params);
            $startTime = microtime(true);

            // Check if query is cached
            if ($this->config['enable_query_cache'] && $this->isCached($queryHash)) {
                return $this->getCachedResult($queryHash);
            }

            // Analyze query performance
            $analysis = $this->analyzeQuery($sql, $params);

            // Execute query with monitoring
            $result = $this->executeWithMonitoring($sql, $params);

            $executionTime = (microtime(true) - $startTime) * 1000;

            // Store performance data
            $this->recordPerformance($sql, $params, $executionTime, $analysis);

            // Cache result if appropriate
            if ($this->shouldCacheQuery($sql, $executionTime)) {
                $this->cacheResult($queryHash, $result, $analysis);
            }

            return [
                'result' => $result,
                'execution_time_ms' => $executionTime,
                'analysis' => $analysis,
                'optimizations_applied' => $this->getAppliedOptimizations($analysis),
                'cached' => false
            ];

        } catch (\Exception $e) {
            $this->logger->error("Query optimization failed", [
                'sql' => substr($sql, 0, 200),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function analyzeQuery(string $sql, array $params = []): array
    {
        try {
            $analysis = [
                'query_type' => $this->getQueryType($sql),
                'estimated_cost' => 0,
                'index_usage' => [],
                'table_scans' => [],
                'join_analysis' => [],
                'suggestions' => [],
                'performance_score' => 100
            ];

            if ($this->config['enable_explain_analysis']) {
                $explainResult = $this->explainQuery($sql, $params);
                $analysis = array_merge($analysis, $this->parseExplainResult($explainResult));
            }

            $analysis['suggestions'] = $this->generateOptimizationSuggestions($sql, $analysis);

            return $analysis;

        } catch (\Exception $e) {
            $this->logger->warning("Query analysis failed", [
                'sql' => substr($sql, 0, 200),
                'error' => $e->getMessage()
            ]);

            return [
                'query_type' => $this->getQueryType($sql),
                'error' => $e->getMessage(),
                'suggestions' => ['Unable to analyze query performance']
            ];
        }
    }

    public function suggestIndexes(string $tableName = null): array
    {
        try {
            $suggestions = [];

            if ($tableName) {
                $suggestions[$tableName] = $this->analyzeTableIndexes($tableName);
            } else {
                $tables = $this->getAllTables();
                foreach ($tables as $table) {
                    $tableAnalysis = $this->analyzeTableIndexes($table);
                    if (!empty($tableAnalysis['suggestions'])) {
                        $suggestions[$table] = $tableAnalysis;
                    }
                }
            }

            return $suggestions;

        } catch (\Exception $e) {
            $this->logger->error("Index analysis failed", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function getPerformanceReport(int $hours = 24): array
    {
        try {
            $startTime = date('Y-m-d H:i:s', time() - ($hours * 3600));

            return [
                'period' => "{$hours} hours",
                'query_statistics' => $this->getQueryStatistics($startTime),
                'slow_queries' => $this->getSlowQueries($startTime),
                'index_usage' => $this->getIndexUsageStats($startTime),
                'table_statistics' => $this->getTableStatistics(),
                'cache_performance' => $this->getCachePerformance(),
                'optimization_opportunities' => $this->identifyOptimizationOpportunities()
            ];

        } catch (\Exception $e) {
            $this->logger->error("Performance report generation failed", [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function optimizeDatabase(): array
    {
        try {
            $optimizations = [];

            // Analyze table optimization opportunities
            $tables = $this->getAllTables();
            foreach ($tables as $table) {
                $optimization = $this->optimizeTable($table);
                if (!empty($optimization['actions'])) {
                    $optimizations[$table] = $optimization;
                }
            }

            // Update table statistics
            $this->updateTableStatistics();

            // Cleanup old performance data
            $this->cleanupPerformanceData();

            $this->logger->info("Database optimization completed", [
                'tables_optimized' => count($optimizations)
            ]);

            return $optimizations;

        } catch (\Exception $e) {
            $this->logger->error("Database optimization failed", [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    private function executeWithMonitoring(string $sql, array $params): \PDOStatement
    {
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        $statement = $this->db->query($sql, $params);

        $executionTime = (microtime(true) - $startTime) * 1000;
        $memoryUsed = memory_get_usage() - $memoryBefore;

        if ($this->config['performance_monitoring']) {
            $this->recordQueryMetrics($sql, $executionTime, $memoryUsed, $statement->rowCount());
        }

        return $statement;
    }

    private function explainQuery(string $sql, array $params): array
    {
        try {
            $explainSql = "EXPLAIN " . $sql;
            $statement = $this->db->query($explainSql, $params);
            return $statement->fetchAll();

        } catch (\Exception $e) {
            // Some queries can't be explained (e.g., INSERT, UPDATE, DELETE)
            return [];
        }
    }

    private function parseExplainResult(array $explainResult): array
    {
        $analysis = [
            'estimated_cost' => 0,
            'index_usage' => [],
            'table_scans' => [],
            'join_analysis' => [],
            'performance_score' => 100
        ];

        foreach ($explainResult as $row) {
            // MySQL EXPLAIN format
            if (isset($row['type'])) {
                $this->analyzeMysqlExplain($row, $analysis);
            }
            // PostgreSQL EXPLAIN format
            elseif (isset($row['Node Type'])) {
                $this->analyzePostgresExplain($row, $analysis);
            }
        }

        return $analysis;
    }

    private function analyzeMysqlExplain(array $row, array &$analysis): void
    {
        // Analyze MySQL EXPLAIN output
        $type = $row['type'] ?? '';
        $key = $row['key'] ?? null;
        $rows = (int) ($row['rows'] ?? 0);

        $analysis['estimated_cost'] += $rows;

        if ($key) {
            $analysis['index_usage'][] = [
                'table' => $row['table'] ?? '',
                'index' => $key,
                'type' => $type
            ];
        } else {
            $analysis['table_scans'][] = [
                'table' => $row['table'] ?? '',
                'rows' => $rows,
                'type' => $type
            ];
        }

        // Penalize full table scans
        if ($type === 'ALL') {
            $analysis['performance_score'] -= min(50, $rows / 1000 * 10);
        }

        // Penalize file sorts
        if (isset($row['Extra']) && strpos($row['Extra'], 'Using filesort') !== false) {
            $analysis['performance_score'] -= 20;
        }

        // Penalize temporary tables
        if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
            $analysis['performance_score'] -= 15;
        }
    }

    private function analyzePostgresExplain(array $row, array &$analysis): void
    {
        // Analyze PostgreSQL EXPLAIN output
        $nodeType = $row['Node Type'] ?? '';
        $totalCost = (float) ($row['Total Cost'] ?? 0);

        $analysis['estimated_cost'] += $totalCost;

        if (strpos($nodeType, 'Seq Scan') !== false) {
            $analysis['table_scans'][] = [
                'table' => $row['Relation Name'] ?? '',
                'cost' => $totalCost,
                'type' => $nodeType
            ];
            $analysis['performance_score'] -= min(30, $totalCost / 1000 * 10);
        }

        if (strpos($nodeType, 'Index') !== false) {
            $analysis['index_usage'][] = [
                'table' => $row['Relation Name'] ?? '',
                'index' => $row['Index Name'] ?? '',
                'type' => $nodeType
            ];
        }
    }

    private function generateOptimizationSuggestions(string $sql, array $analysis): array
    {
        $suggestions = [];

        // Suggest indexes for table scans
        foreach ($analysis['table_scans'] as $scan) {
            if (isset($scan['rows']) && $scan['rows'] > 1000) {
                $suggestions[] = "Consider adding an index to table '{$scan['table']}' to avoid full table scan";
            }
        }

        // Suggest query rewriting for complex queries
        if ($analysis['estimated_cost'] > 10000) {
            $suggestions[] = "Query has high estimated cost - consider breaking into smaller queries or optimizing joins";
        }

        // Suggest LIMIT for large result sets
        if (preg_match('/SELECT\s+.*\s+FROM/i', $sql) && !preg_match('/LIMIT\s+\d+/i', $sql)) {
            $suggestions[] = "Consider adding LIMIT clause to reduce result set size";
        }

        // Suggest EXISTS instead of IN for subqueries
        if (preg_match('/\s+IN\s*\(\s*SELECT\s+/i', $sql)) {
            $suggestions[] = "Consider using EXISTS instead of IN with subqueries for better performance";
        }

        return $suggestions;
    }

    private function analyzeTableIndexes(string $tableName): array
    {
        try {
            $analysis = [
                'table' => $tableName,
                'current_indexes' => $this->getTableIndexes($tableName),
                'suggestions' => [],
                'unused_indexes' => [],
                'duplicate_indexes' => []
            ];

            // Analyze slow queries for this table
            $slowQueries = $this->getSlowQueriesForTable($tableName);
            foreach ($slowQueries as $query) {
                $indexSuggestions = $this->suggestIndexesForQuery($query, $tableName);
                $analysis['suggestions'] = array_merge($analysis['suggestions'], $indexSuggestions);
            }

            // Find unused indexes
            $analysis['unused_indexes'] = $this->findUnusedIndexes($tableName);

            // Find duplicate indexes
            $analysis['duplicate_indexes'] = $this->findDuplicateIndexes($tableName);

            return $analysis;

        } catch (\Exception $e) {
            $this->logger->error("Table index analysis failed", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);

            return ['table' => $tableName, 'error' => $e->getMessage()];
        }
    }

    private function getTableIndexes(string $tableName): array
    {
        try {
            return $this->db->fetchAll(
                "SHOW INDEX FROM `{$tableName}`"
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    private function findUnusedIndexes(string $tableName): array
    {
        // This would require analysis of query logs and index usage statistics
        // For now, return empty array - in production, you'd implement proper index usage tracking
        return [];
    }

    private function findDuplicateIndexes(string $tableName): array
    {
        try {
            $indexes = $this->getTableIndexes($tableName);
            $duplicates = [];
            $indexGroups = [];

            foreach ($indexes as $index) {
                $key = $index['Column_name'];
                if (!isset($indexGroups[$key])) {
                    $indexGroups[$key] = [];
                }
                $indexGroups[$key][] = $index['Key_name'];
            }

            foreach ($indexGroups as $column => $indexNames) {
                if (count($indexNames) > 1) {
                    $duplicates[] = [
                        'column' => $column,
                        'indexes' => $indexNames
                    ];
                }
            }

            return $duplicates;

        } catch (\Exception $e) {
            return [];
        }
    }

    private function optimizeTable(string $tableName): array
    {
        try {
            $actions = [];

            // Analyze table
            $this->db->query("ANALYZE TABLE `{$tableName}`");
            $actions[] = "Analyzed table statistics";

            // Optimize table
            $result = $this->db->query("OPTIMIZE TABLE `{$tableName}`")->fetch();
            if ($result && $result['Msg_type'] === 'status') {
                $actions[] = "Optimized table structure";
            }

            return ['actions' => $actions];

        } catch (\Exception $e) {
            $this->logger->warning("Table optimization failed", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);

            return ['actions' => [], 'error' => $e->getMessage()];
        }
    }

    private function generateQueryHash(string $sql, array $params): string
    {
        return hash('sha256', $sql . serialize($params));
    }

    private function isCached(string $queryHash): bool
    {
        return isset($this->queryCache[$queryHash]) &&
               $this->queryCache[$queryHash]['expires'] > time();
    }

    private function getCachedResult(string $queryHash): array
    {
        $cached = $this->queryCache[$queryHash];
        return [
            'result' => $cached['result'],
            'execution_time_ms' => 0,
            'analysis' => $cached['analysis'],
            'optimizations_applied' => [],
            'cached' => true
        ];
    }

    private function shouldCacheQuery(string $sql, float $executionTime): bool
    {
        return $this->config['enable_query_cache'] &&
               $executionTime > 100 && // Cache queries that take more than 100ms
               preg_match('/^SELECT\s+/i', trim($sql)) && // Only cache SELECT queries
               count($this->queryCache) < $this->config['max_cached_queries'];
    }

    private function cacheResult(string $queryHash, $result, array $analysis): void
    {
        $this->queryCache[$queryHash] = [
            'result' => $result,
            'analysis' => $analysis,
            'expires' => time() + $this->config['cache_ttl_seconds']
        ];
    }

    private function getQueryType(string $sql): string
    {
        $sql = trim(strtoupper($sql));

        if (str_starts_with($sql, 'SELECT')) return 'SELECT';
        if (str_starts_with($sql, 'INSERT')) return 'INSERT';
        if (str_starts_with($sql, 'UPDATE')) return 'UPDATE';
        if (str_starts_with($sql, 'DELETE')) return 'DELETE';
        if (str_starts_with($sql, 'CREATE')) return 'CREATE';
        if (str_starts_with($sql, 'ALTER')) return 'ALTER';
        if (str_starts_with($sql, 'DROP')) return 'DROP';

        return 'OTHER';
    }

    private function getAllTables(): array
    {
        try {
            $result = $this->db->fetchAll("SHOW TABLES");
            return array_column($result, array_keys($result[0])[0]);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getAppliedOptimizations(array $analysis): array
    {
        $optimizations = [];

        if (!empty($analysis['index_usage'])) {
            $optimizations[] = 'Index usage detected';
        }

        if (empty($analysis['table_scans'])) {
            $optimizations[] = 'No full table scans';
        }

        return $optimizations;
    }

    private function recordPerformance(string $sql, array $params, float $executionTime, array $analysis): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }

        try {
            $this->db->insert('query_performance', [
                'query_hash' => $this->generateQueryHash($sql, $params),
                'query_type' => $analysis['query_type'] ?? $this->getQueryType($sql),
                'execution_time_ms' => $executionTime,
                'estimated_cost' => $analysis['estimated_cost'] ?? 0,
                'performance_score' => $analysis['performance_score'] ?? 100,
                'suggestions_count' => count($analysis['suggestions'] ?? []),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Don't fail if performance recording fails
            $this->logger->debug("Performance recording failed", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function recordQueryMetrics(string $sql, float $executionTime, int $memoryUsed, int $rowCount): void
    {
        $queryType = $this->getQueryType($sql);

        if (!isset($this->performanceStats[$queryType])) {
            $this->performanceStats[$queryType] = [
                'count' => 0,
                'total_time' => 0,
                'total_memory' => 0,
                'total_rows' => 0
            ];
        }

        $this->performanceStats[$queryType]['count']++;
        $this->performanceStats[$queryType]['total_time'] += $executionTime;
        $this->performanceStats[$queryType]['total_memory'] += $memoryUsed;
        $this->performanceStats[$queryType]['total_rows'] += $rowCount;
    }

    private function getQueryStatistics(string $startTime): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT
                    query_type,
                    COUNT(*) as query_count,
                    AVG(execution_time_ms) as avg_execution_time,
                    MAX(execution_time_ms) as max_execution_time,
                    AVG(performance_score) as avg_performance_score
                 FROM query_performance
                 WHERE created_at >= ?
                 GROUP BY query_type
                 ORDER BY query_count DESC",
                [$startTime]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getSlowQueries(string $startTime): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT
                    query_hash,
                    query_type,
                    execution_time_ms,
                    performance_score,
                    suggestions_count,
                    created_at
                 FROM query_performance
                 WHERE created_at >= ?
                 AND execution_time_ms > ?
                 ORDER BY execution_time_ms DESC
                 LIMIT 20",
                [$startTime, $this->config['slow_query_threshold_ms']]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getSlowQueriesForTable(string $tableName): array
    {
        // This would require more sophisticated query log analysis
        // For now, return empty array
        return [];
    }

    private function suggestIndexesForQuery(array $query, string $tableName): array
    {
        // This would analyze WHERE clauses, JOIN conditions, ORDER BY clauses
        // For now, return empty array
        return [];
    }

    private function getIndexUsageStats(string $startTime): array
    {
        // This would require index usage tracking
        return [];
    }

    private function getTableStatistics(): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT
                    table_name,
                    table_rows,
                    data_length,
                    index_length,
                    (data_length + index_length) as total_size
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 ORDER BY total_size DESC"
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getCachePerformance(): array
    {
        return [
            'cache_size' => count($this->queryCache),
            'max_cache_size' => $this->config['max_cached_queries'],
            'cache_hit_ratio' => 0 // Would be calculated based on actual cache hits/misses
        ];
    }

    private function identifyOptimizationOpportunities(): array
    {
        $opportunities = [];

        // Identify tables without primary keys
        try {
            $tablesWithoutPK = $this->db->fetchAll(
                "SELECT table_name
                 FROM information_schema.tables t
                 LEFT JOIN information_schema.table_constraints tc
                   ON t.table_name = tc.table_name
                   AND tc.constraint_type = 'PRIMARY KEY'
                 WHERE t.table_schema = DATABASE()
                 AND tc.constraint_name IS NULL"
            );

            foreach ($tablesWithoutPK as $table) {
                $opportunities[] = [
                    'type' => 'missing_primary_key',
                    'table' => $table['table_name'],
                    'priority' => 'high',
                    'description' => "Table '{$table['table_name']}' is missing a primary key"
                ];
            }
        } catch (\Exception $e) {
            // Ignore if information_schema is not available
        }

        return $opportunities;
    }

    private function updateTableStatistics(): void
    {
        try {
            $tables = $this->getAllTables();
            foreach ($tables as $table) {
                $this->db->query("ANALYZE TABLE `{$table}`");
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to update table statistics", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cleanupPerformanceData(): void
    {
        try {
            $retentionDays = 7;
            $this->db->execute(
                "DELETE FROM query_performance WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$retentionDays]
            );
        } catch (\Exception $e) {
            // Ignore cleanup failures
        }
    }
}