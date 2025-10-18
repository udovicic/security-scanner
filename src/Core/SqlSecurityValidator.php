<?php

namespace SecurityScanner\Core;

class SqlSecurityValidator
{
    private array $dangerousPatterns;
    private array $allowedFunctions;
    private $logger;

    public function __construct()
    {
        try {
            $this->logger = Logger::channel('sql_security');
        } catch (\Exception $e) {
            // Fallback logger for testing
            $this->logger = new class {
                public function debug($message, $context = []) {}
                public function info($message, $context = []) {}
                public function warning($message, $context = []) {}
                public function error($message, $context = []) {}
                public function critical($message, $context = []) {}
            };
        }
        $this->initializeDangerousPatterns();
        $this->initializeAllowedFunctions();
    }

    public function validateQuery(string $query, array $params = []): array
    {
        $result = [
            'is_safe' => true,
            'issues' => [],
            'risk_level' => 'low',
            'recommendations' => []
        ];

        $query = trim($query);

        if (empty($query)) {
            $result['is_safe'] = false;
            $result['issues'][] = 'Empty query';
            return $result;
        }

        $this->checkForInjectionPatterns($query, $result);
        $this->validatePreparedStatementUsage($query, $params, $result);
        $this->checkForDangerousFunctions($query, $result);
        $this->validateQueryComplexity($query, $result);
        $this->checkForPrivilegedOperations($query, $result);

        $this->calculateRiskLevel($result);

        if (!$result['is_safe']) {
            $this->logger->warning("Potentially unsafe SQL query detected", [
                'query_hash' => hash('sha256', $query),
                'issues' => $result['issues'],
                'risk_level' => $result['risk_level']
            ]);
        }

        return $result;
    }

    public function sanitizeQueryInput(string $input): string
    {
        $input = trim($input);

        $input = preg_replace('/[\'";\\\\]/', '', $input);

        $input = preg_replace('/--.*$/', '', $input);
        $input = preg_replace('/\/\*.*?\*\//', '', $input);

        $sqlKeywords = [
            'UNION', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
            'TRUNCATE', 'EXEC', 'EXECUTE', 'DECLARE', 'CAST', 'CONVERT'
        ];

        foreach ($sqlKeywords as $keyword) {
            $input = preg_replace('/\b' . preg_quote($keyword, '/') . '\b/i', '', $input);
        }

        return $input;
    }

    public function validatePreparedStatement(string $query, array $params): bool
    {
        $placeholderCount = substr_count($query, '?');
        $namedPlaceholderCount = preg_match_all('/:\w+/', $query);

        $totalPlaceholders = $placeholderCount + $namedPlaceholderCount;
        $paramCount = count($params);

        if ($totalPlaceholders !== $paramCount) {
            $this->logger->error("Parameter mismatch in prepared statement", [
                'query_hash' => hash('sha256', $query),
                'expected_params' => $totalPlaceholders,
                'actual_params' => $paramCount
            ]);
            return false;
        }

        return true;
    }

    public function isQueryWhitelisted(string $query): bool
    {
        $normalizedQuery = $this->normalizeQuery($query);

        $whitelistedPatterns = [
            '/^SELECT\s+\*\s+FROM\s+\w+\s+WHERE\s+\w+\s*=\s*\?\s*$/i',
            '/^SELECT\s+[\w,\s]+\s+FROM\s+\w+\s+WHERE\s+[\w\s=?AND]+\s*$/i',
            '/^INSERT\s+INTO\s+\w+\s*\([\w,\s]+\)\s*VALUES\s*\([\?,\s]+\)\s*$/i',
            '/^UPDATE\s+\w+\s+SET\s+[\w\s=?,]+\s+WHERE\s+[\w\s=?AND]+\s*$/i',
            '/^DELETE\s+FROM\s+\w+\s+WHERE\s+[\w\s=?AND]+\s*$/i'
        ];

        foreach ($whitelistedPatterns as $pattern) {
            if (preg_match($pattern, $normalizedQuery)) {
                return true;
            }
        }

        return false;
    }

    public function generateSecureQueryHash(string $query, array $params = []): string
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $paramHash = hash('sha256', serialize($params));
        return hash('sha256', $normalizedQuery . $paramHash);
    }

    public function detectAnomalousQueries(array $queryHistory): array
    {
        $anomalies = [];

        $patterns = $this->analyzeQueryPatterns($queryHistory);

        foreach ($queryHistory as $entry) {
            $query = $entry['query'] ?? '';
            $executionTime = $entry['execution_time'] ?? 0;
            $rowsAffected = $entry['rows_affected'] ?? 0;

            if ($executionTime > ($patterns['avg_execution_time'] * 3)) {
                $anomalies[] = [
                    'type' => 'slow_query',
                    'query_hash' => hash('sha256', $query),
                    'execution_time' => $executionTime,
                    'threshold' => $patterns['avg_execution_time'] * 3
                ];
            }

            if ($rowsAffected > ($patterns['avg_rows_affected'] * 10)) {
                $anomalies[] = [
                    'type' => 'high_impact_query',
                    'query_hash' => hash('sha256', $query),
                    'rows_affected' => $rowsAffected,
                    'threshold' => $patterns['avg_rows_affected'] * 10
                ];
            }

            if (!$this->isQueryPatternNormal($query, $patterns)) {
                $anomalies[] = [
                    'type' => 'unusual_pattern',
                    'query_hash' => hash('sha256', $query),
                    'reason' => 'Query pattern deviates from normal usage'
                ];
            }
        }

        return $anomalies;
    }

    private function checkForInjectionPatterns(string $query, array &$result): void
    {
        foreach ($this->dangerousPatterns as $pattern => $severity) {
            if (preg_match($pattern, $query, $matches)) {
                $result['is_safe'] = false;
                $result['issues'][] = "Potential SQL injection pattern detected: {$matches[0]}";

                if ($severity === 'high') {
                    $result['recommendations'][] = 'Use parameterized queries with prepared statements';
                }
            }
        }
    }

    private function validatePreparedStatementUsage(string $query, array $params, array &$result): void
    {
        $hasUserInput = !empty($params);
        $hasPlaceholders = (strpos($query, '?') !== false) || preg_match('/:\w+/', $query);

        if ($hasUserInput && !$hasPlaceholders) {
            $result['is_safe'] = false;
            $result['issues'][] = 'Query contains parameters but no placeholders';
            $result['recommendations'][] = 'Use prepared statements with parameter placeholders';
        }

        if ($hasPlaceholders && !$this->validatePreparedStatement($query, $params)) {
            $result['is_safe'] = false;
            $result['issues'][] = 'Parameter count mismatch in prepared statement';
        }
    }

    private function checkForDangerousFunctions(string $query, array &$result): void
    {
        $dangerousFunctions = [
            'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE', 'SYSTEM', 'EXEC',
            'xp_cmdshell', 'sp_configure', 'openrowset', 'opendatasource'
        ];

        foreach ($dangerousFunctions as $function) {
            if (preg_match('/\b' . preg_quote($function, '/') . '\b/i', $query)) {
                $result['is_safe'] = false;
                $result['issues'][] = "Dangerous function detected: {$function}";
                $result['recommendations'][] = 'Remove dangerous database functions from queries';
            }
        }
    }

    private function validateQueryComplexity(string $query, array &$result): void
    {
        $joinCount = preg_match_all('/\bJOIN\b/i', $query);
        $subqueryCount = preg_match_all('/\(\s*SELECT\b/i', $query);
        $unionCount = preg_match_all('/\bUNION\b/i', $query);

        if ($joinCount > 5) {
            $result['issues'][] = 'Query has excessive JOIN operations';
            $result['recommendations'][] = 'Consider query optimization or breaking into smaller queries';
        }

        if ($subqueryCount > 3) {
            $result['issues'][] = 'Query has excessive subqueries';
            $result['recommendations'][] = 'Consider using JOIN operations instead of subqueries';
        }

        if ($unionCount > 2) {
            $result['issues'][] = 'Query has multiple UNION operations';
            $result['recommendations'][] = 'Verify UNION operations are necessary and properly structured';
        }
    }

    private function checkForPrivilegedOperations(string $query, array &$result): void
    {
        $privilegedOperations = [
            'DROP\s+TABLE' => 'Table deletion detected',
            'DROP\s+DATABASE' => 'Database deletion detected',
            'TRUNCATE\s+TABLE' => 'Table truncation detected',
            'ALTER\s+TABLE' => 'Table structure modification detected',
            'CREATE\s+USER' => 'User creation detected',
            'GRANT\s+' => 'Permission granting detected',
            'REVOKE\s+' => 'Permission revocation detected'
        ];

        foreach ($privilegedOperations as $pattern => $message) {
            if (preg_match('/\b' . $pattern . '\b/i', $query)) {
                $result['is_safe'] = false;
                $result['issues'][] = $message;
                $result['recommendations'][] = 'Ensure privileged operations are properly authorized';
            }
        }
    }

    private function calculateRiskLevel(array &$result): void
    {
        $issueCount = count($result['issues']);
        $hasHighRiskPatterns = false;

        foreach ($result['issues'] as $issue) {
            if (strpos($issue, 'injection') !== false ||
                strpos($issue, 'Dangerous function') !== false ||
                strpos($issue, 'deletion') !== false) {
                $hasHighRiskPatterns = true;
                break;
            }
        }

        if ($hasHighRiskPatterns || $issueCount >= 3) {
            $result['risk_level'] = 'high';
        } elseif ($issueCount >= 1) {
            $result['risk_level'] = 'medium';
        } else {
            $result['risk_level'] = 'low';
        }
    }

    private function normalizeQuery(string $query): string
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $query = strtoupper($query);
        return $query;
    }

    private function analyzeQueryPatterns(array $queryHistory): array
    {
        $totalQueries = count($queryHistory);
        $totalExecutionTime = 0;
        $totalRowsAffected = 0;
        $queryTypes = [];

        foreach ($queryHistory as $entry) {
            $totalExecutionTime += $entry['execution_time'] ?? 0;
            $totalRowsAffected += $entry['rows_affected'] ?? 0;

            $queryType = $this->getQueryType($entry['query'] ?? '');
            $queryTypes[$queryType] = ($queryTypes[$queryType] ?? 0) + 1;
        }

        return [
            'avg_execution_time' => $totalQueries > 0 ? $totalExecutionTime / $totalQueries : 0,
            'avg_rows_affected' => $totalQueries > 0 ? $totalRowsAffected / $totalQueries : 0,
            'query_types' => $queryTypes,
            'total_queries' => $totalQueries
        ];
    }

    private function getQueryType(string $query): string
    {
        $query = trim(strtoupper($query));

        if (str_starts_with($query, 'SELECT')) return 'SELECT';
        if (str_starts_with($query, 'INSERT')) return 'INSERT';
        if (str_starts_with($query, 'UPDATE')) return 'UPDATE';
        if (str_starts_with($query, 'DELETE')) return 'DELETE';
        if (str_starts_with($query, 'CREATE')) return 'CREATE';
        if (str_starts_with($query, 'ALTER')) return 'ALTER';
        if (str_starts_with($query, 'DROP')) return 'DROP';

        return 'OTHER';
    }

    private function isQueryPatternNormal(string $query, array $patterns): bool
    {
        $queryType = $this->getQueryType($query);
        $typeFrequency = $patterns['query_types'][$queryType] ?? 0;
        $totalQueries = $patterns['total_queries'];

        if ($totalQueries === 0) {
            return true;
        }

        $typePercentage = ($typeFrequency / $totalQueries) * 100;

        if ($queryType === 'DROP' || $queryType === 'ALTER') {
            return $typePercentage < 5;
        }

        if ($queryType === 'SELECT' || $queryType === 'INSERT') {
            return $typePercentage > 10;
        }

        return true;
    }

    private function initializeDangerousPatterns(): void
    {
        $this->dangerousPatterns = [
            // SQL injection patterns
            '/(\bOR\b|\bAND\b)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i' => 'high',
            '/[\'"][\s]*(\bOR\b|\bAND\b)[\s]*[\'"]?\d+[\'"]?/i' => 'high',
            '/UNION\s+.*SELECT/i' => 'high',
            '/(\'|\").*\1\s*;\s*\w/i' => 'high',
            '/[\'"];?\s*(DROP|DELETE|INSERT|UPDATE)\s/i' => 'high',

            // Comment injection
            '/(\/\*.*\*\/|--[^\r\n]*)/i' => 'medium',
            '/#[^\r\n]*/i' => 'medium',

            // Blind injection patterns
            '/\bSLEEP\s*\(/i' => 'high',
            '/\bBENCHMARK\s*\(/i' => 'high',
            '/\bWAITFOR\s+DELAY/i' => 'high',

            // Information gathering
            '/\b(INFORMATION_SCHEMA|mysql\.user|sys\.)/i' => 'medium',
            '/\b(version\s*\(|@@version|user\s*\()/i' => 'medium',

            // File operations
            '/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)/i' => 'high',

            // Hex/Binary encoding attempts
            '/0x[0-9a-f]+/i' => 'medium',
            '/CHAR\s*\(/i' => 'medium'
        ];
    }

    private function initializeAllowedFunctions(): void
    {
        $this->allowedFunctions = [
            'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT',
            'UPPER', 'LOWER', 'TRIM', 'LENGTH', 'SUBSTRING',
            'DATE', 'NOW', 'CURDATE', 'CURTIME', 'UNIX_TIMESTAMP',
            'ROUND', 'CEIL', 'FLOOR', 'ABS',
            'COALESCE', 'IFNULL', 'NULLIF',
            'MD5', 'SHA1', 'SHA2'
        ];
    }
}