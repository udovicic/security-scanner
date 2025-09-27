<?php

namespace SecurityScanner\Core;

class SecurityAuditor
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private array $complianceStandards;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->logger = new Logger('security_audit');

        $this->config = array_merge([
            'enable_continuous_monitoring' => true,
            'audit_retention_days' => 365,
            'compliance_standards' => ['OWASP', 'SOC2', 'GDPR'],
            'critical_threshold' => 5,
            'medium_threshold' => 10
        ], $config);

        $this->initializeComplianceStandards();
    }

    public function performFullSecurityAudit(): array
    {
        try {
            $auditId = $this->createAuditRecord();
            $results = [];

            $results['system_security'] = $this->auditSystemSecurity();
            $results['authentication'] = $this->auditAuthentication();
            $results['authorization'] = $this->auditAuthorization();
            $results['data_protection'] = $this->auditDataProtection();
            $results['input_validation'] = $this->auditInputValidation();
            $results['session_management'] = $this->auditSessionManagement();
            $results['logging_monitoring'] = $this->auditLoggingMonitoring();
            $results['configuration'] = $this->auditConfiguration();
            $results['database_security'] = $this->auditDatabaseSecurity();
            $results['compliance'] = $this->auditCompliance();

            $summary = $this->generateAuditSummary($results);

            $this->updateAuditRecord($auditId, $results, $summary);

            $this->logger->info("Security audit completed", [
                'audit_id' => $auditId,
                'overall_score' => $summary['overall_score'],
                'critical_issues' => $summary['critical_issues'],
                'high_issues' => $summary['high_issues']
            ]);

            return [
                'audit_id' => $auditId,
                'timestamp' => date('Y-m-d H:i:s'),
                'summary' => $summary,
                'results' => $results,
                'recommendations' => $this->generateRecommendations($results)
            ];

        } catch (\Exception $e) {
            $this->logger->error("Security audit failed", [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function auditSystemSecurity(): array
    {
        $results = [
            'category' => 'System Security',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.1.0', '<')) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => "PHP version {$phpVersion} may have security vulnerabilities",
                'recommendation' => 'Upgrade to PHP 8.1 or later'
            ];
            $results['score'] -= 10;
        }

        $results['checks'][] = [
            'name' => 'PHP Version',
            'status' => version_compare($phpVersion, '8.1.0', '>=') ? 'pass' : 'warning',
            'value' => $phpVersion
        ];

        // Check dangerous PHP functions
        $dangerousFunctions = ['exec', 'shell_exec', 'system', 'passthru', 'eval'];
        foreach ($dangerousFunctions as $function) {
            if (function_exists($function)) {
                $results['issues'][] = [
                    'severity' => 'high',
                    'message' => "Dangerous function '{$function}' is available",
                    'recommendation' => "Disable '{$function}' function in php.ini"
                ];
                $results['score'] -= 15;
            }
        }

        // Check security headers
        $headers = getallheaders();
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => ['DENY', 'SAMEORIGIN'],
            'X-XSS-Protection' => '1; mode=block'
        ];

        foreach ($securityHeaders as $header => $expectedValue) {
            $present = isset($headers[$header]);
            $results['checks'][] = [
                'name' => "Security Header: {$header}",
                'status' => $present ? 'pass' : 'fail',
                'value' => $present ? $headers[$header] : 'missing'
            ];

            if (!$present) {
                $results['issues'][] = [
                    'severity' => 'medium',
                    'message' => "Missing security header: {$header}",
                    'recommendation' => "Add {$header} header to HTTP responses"
                ];
                $results['score'] -= 8;
            }
        }

        // Check HTTPS
        $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $results['checks'][] = [
            'name' => 'HTTPS Enabled',
            'status' => $isHttps ? 'pass' : 'fail',
            'value' => $isHttps ? 'enabled' : 'disabled'
        ];

        if (!$isHttps) {
            $results['issues'][] = [
                'severity' => 'critical',
                'message' => 'HTTPS is not enabled',
                'recommendation' => 'Enable HTTPS for all communications'
            ];
            $results['score'] -= 25;
        }

        return $results;
    }

    public function auditAuthentication(): array
    {
        $results = [
            'category' => 'Authentication',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check password policies
        $authManager = new AuthenticationManager();
        $passwordConfig = $authManager->getPasswordConfig ?? [
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_numbers' => true,
            'password_require_symbols' => true
        ];

        if ($passwordConfig['password_min_length'] < 8) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => 'Minimum password length is too short',
                'recommendation' => 'Set minimum password length to at least 8 characters'
            ];
            $results['score'] -= 10;
        }

        $results['checks'][] = [
            'name' => 'Password Minimum Length',
            'status' => $passwordConfig['password_min_length'] >= 8 ? 'pass' : 'fail',
            'value' => $passwordConfig['password_min_length']
        ];

        // Check session configuration
        $sessionConfig = [
            'session.cookie_httponly' => ini_get('session.cookie_httponly'),
            'session.cookie_secure' => ini_get('session.cookie_secure'),
            'session.use_strict_mode' => ini_get('session.use_strict_mode')
        ];

        foreach ($sessionConfig as $setting => $value) {
            $isSecure = $value === '1';
            $results['checks'][] = [
                'name' => $setting,
                'status' => $isSecure ? 'pass' : 'fail',
                'value' => $isSecure ? 'enabled' : 'disabled'
            ];

            if (!$isSecure) {
                $results['issues'][] = [
                    'severity' => 'medium',
                    'message' => "Insecure session setting: {$setting}",
                    'recommendation' => "Set {$setting} = 1 in php.ini"
                ];
                $results['score'] -= 8;
            }
        }

        // Check for default credentials
        $defaultCredentials = $this->checkDefaultCredentials();
        if (!empty($defaultCredentials)) {
            $results['issues'][] = [
                'severity' => 'critical',
                'message' => 'Default credentials detected',
                'recommendation' => 'Change all default passwords immediately'
            ];
            $results['score'] -= 30;
        }

        $results['checks'][] = [
            'name' => 'Default Credentials',
            'status' => empty($defaultCredentials) ? 'pass' : 'fail',
            'value' => empty($defaultCredentials) ? 'none found' : count($defaultCredentials) . ' found'
        ];

        return $results;
    }

    public function auditAuthorization(): array
    {
        $results = [
            'category' => 'Authorization',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check for users with excessive privileges
        $adminUsers = $this->db->fetchAll(
            "SELECT COUNT(*) as count FROM users WHERE role = 'admin'"
        );

        $adminCount = $adminUsers[0]['count'] ?? 0;
        if ($adminCount > 5) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => "High number of admin users ({$adminCount})",
                'recommendation' => 'Review and limit admin privileges'
            ];
            $results['score'] -= 10;
        }

        $results['checks'][] = [
            'name' => 'Admin User Count',
            'status' => $adminCount <= 5 ? 'pass' : 'warning',
            'value' => $adminCount
        ];

        // Check for orphaned permissions
        $orphanedPermissions = $this->db->fetchAll(
            "SELECT COUNT(*) as count FROM user_permissions up
             LEFT JOIN users u ON up.user_id = u.id
             WHERE u.id IS NULL"
        );

        $orphanedCount = $orphanedPermissions[0]['count'] ?? 0;
        if ($orphanedCount > 0) {
            $results['issues'][] = [
                'severity' => 'low',
                'message' => "Orphaned permissions detected ({$orphanedCount})",
                'recommendation' => 'Clean up orphaned permissions'
            ];
            $results['score'] -= 5;
        }

        $results['checks'][] = [
            'name' => 'Orphaned Permissions',
            'status' => $orphanedCount === 0 ? 'pass' : 'warning',
            'value' => $orphanedCount
        ];

        return $results;
    }

    public function auditDataProtection(): array
    {
        $results = [
            'category' => 'Data Protection',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check database encryption
        $dbSecurity = $this->db->verifyConnectionSecurity();
        $encryptionEnabled = $dbSecurity['encryption_status'] === 'enabled';

        $results['checks'][] = [
            'name' => 'Database Encryption',
            'status' => $encryptionEnabled ? 'pass' : 'fail',
            'value' => $dbSecurity['encryption_status']
        ];

        if (!$encryptionEnabled) {
            $results['issues'][] = [
                'severity' => 'high',
                'message' => 'Database encryption is not enabled',
                'recommendation' => 'Enable SSL/TLS encryption for database connections'
            ];
            $results['score'] -= 20;
        }

        // Check for sensitive data in logs
        $sensitivePatterns = [
            '/password\s*[:=]\s*[\'"][^\'"]+[\'"]/i',
            '/api[_-]?key\s*[:=]\s*[\'"][^\'"]+[\'"]/i',
            '/token\s*[:=]\s*[\'"][^\'"]+[\'"]/i'
        ];

        $logFiles = glob('/var/log/*.log');
        $sensitiveDataFound = false;

        foreach ($logFiles as $logFile) {
            if (is_readable($logFile)) {
                $content = file_get_contents($logFile);
                foreach ($sensitivePatterns as $pattern) {
                    if (preg_match($pattern, $content)) {
                        $sensitiveDataFound = true;
                        break 2;
                    }
                }
            }
        }

        $results['checks'][] = [
            'name' => 'Sensitive Data in Logs',
            'status' => !$sensitiveDataFound ? 'pass' : 'fail',
            'value' => $sensitiveDataFound ? 'found' : 'none found'
        ];

        if ($sensitiveDataFound) {
            $results['issues'][] = [
                'severity' => 'high',
                'message' => 'Sensitive data found in log files',
                'recommendation' => 'Remove sensitive data from logs and implement data masking'
            ];
            $results['score'] -= 15;
        }

        return $results;
    }

    public function auditInputValidation(): array
    {
        $results = [
            'category' => 'Input Validation',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check if input sanitization is enabled
        $sanitizer = new InputSanitizer();
        $results['checks'][] = [
            'name' => 'Input Sanitizer Available',
            'status' => 'pass',
            'value' => 'enabled'
        ];

        // Check for SQL injection prevention
        $sqlValidator = new SqlSecurityValidator();
        $testQuery = "SELECT * FROM users WHERE id = ?";
        $validation = $sqlValidator->validateQuery($testQuery, [1]);

        $results['checks'][] = [
            'name' => 'SQL Injection Prevention',
            'status' => $validation['is_safe'] ? 'pass' : 'fail',
            'value' => $validation['is_safe'] ? 'active' : 'inactive'
        ];

        // Check XSS protection
        $xssProtection = new XssProtection();
        $testInput = '<script>alert("xss")</script>';
        $xssResult = $xssProtection->detectXss($testInput);

        $results['checks'][] = [
            'name' => 'XSS Protection',
            'status' => !$xssResult['is_safe'] ? 'pass' : 'fail',
            'value' => !$xssResult['is_safe'] ? 'active' : 'inactive'
        ];

        return $results;
    }

    public function auditSessionManagement(): array
    {
        $results = [
            'category' => 'Session Management',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check session timeout
        $sessionLifetime = ini_get('session.gc_maxlifetime');
        $maxLifetime = 24 * 3600; // 24 hours

        $results['checks'][] = [
            'name' => 'Session Lifetime',
            'status' => $sessionLifetime <= $maxLifetime ? 'pass' : 'warning',
            'value' => $sessionLifetime . ' seconds'
        ];

        if ($sessionLifetime > $maxLifetime) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => 'Session lifetime is too long',
                'recommendation' => 'Reduce session lifetime to improve security'
            ];
            $results['score'] -= 10;
        }

        // Check for active sessions
        $activeSessions = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()"
        );

        $results['checks'][] = [
            'name' => 'Active Sessions',
            'status' => 'info',
            'value' => $activeSessions
        ];

        return $results;
    }

    public function auditLoggingMonitoring(): array
    {
        $results = [
            'category' => 'Logging & Monitoring',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check if security event logging is active
        $recentEvents = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM security_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $results['checks'][] = [
            'name' => 'Security Event Logging',
            'status' => $recentEvents > 0 ? 'pass' : 'warning',
            'value' => $recentEvents . ' events in last 24h'
        ];

        if ($recentEvents === 0) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => 'No security events logged in the last 24 hours',
                'recommendation' => 'Verify security event logging is working correctly'
            ];
            $results['score'] -= 15;
        }

        // Check log retention
        $oldestEvent = $this->db->fetchColumn(
            "SELECT MIN(created_at) FROM security_events"
        );

        if ($oldestEvent) {
            $retentionDays = (time() - strtotime($oldestEvent)) / (24 * 3600);
            $results['checks'][] = [
                'name' => 'Log Retention',
                'status' => $retentionDays <= $this->config['audit_retention_days'] ? 'pass' : 'warning',
                'value' => round($retentionDays) . ' days'
            ];
        }

        return $results;
    }

    public function auditConfiguration(): array
    {
        $results = [
            'category' => 'Configuration',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        // Check debug mode
        $debugMode = defined('APP_DEBUG') && APP_DEBUG === true;
        $results['checks'][] = [
            'name' => 'Debug Mode',
            'status' => !$debugMode ? 'pass' : 'fail',
            'value' => $debugMode ? 'enabled' : 'disabled'
        ];

        if ($debugMode) {
            $results['issues'][] = [
                'severity' => 'critical',
                'message' => 'Debug mode is enabled in production',
                'recommendation' => 'Disable debug mode in production environment'
            ];
            $results['score'] -= 25;
        }

        // Check error reporting
        $errorReporting = error_reporting();
        $showErrors = ini_get('display_errors');

        $results['checks'][] = [
            'name' => 'Error Display',
            'status' => !$showErrors ? 'pass' : 'fail',
            'value' => $showErrors ? 'enabled' : 'disabled'
        ];

        if ($showErrors) {
            $results['issues'][] = [
                'severity' => 'medium',
                'message' => 'Error display is enabled',
                'recommendation' => 'Disable error display in production'
            ];
            $results['score'] -= 10;
        }

        return $results;
    }

    public function auditDatabaseSecurity(): array
    {
        $results = [
            'category' => 'Database Security',
            'score' => 100,
            'issues' => [],
            'checks' => []
        ];

        $dbSecurity = $this->db->testAllConnectionsSecurity();

        foreach ($dbSecurity as $connectionName => $security) {
            $results['checks'][] = [
                'name' => "Database Connection: {$connectionName}",
                'status' => $security['is_secure'] ? 'pass' : 'fail',
                'value' => $security['encryption_status']
            ];

            if (!$security['is_secure']) {
                foreach ($security['issues'] as $issue) {
                    $results['issues'][] = [
                        'severity' => 'high',
                        'message' => "Database {$connectionName}: {$issue}",
                        'recommendation' => 'Fix database security issues'
                    ];
                    $results['score'] -= 15;
                }
            }
        }

        return $results;
    }

    public function auditCompliance(): array
    {
        $results = [
            'category' => 'Compliance',
            'score' => 100,
            'issues' => [],
            'checks' => [],
            'standards' => []
        ];

        foreach ($this->config['compliance_standards'] as $standard) {
            $complianceCheck = $this->checkComplianceStandard($standard);
            $results['standards'][$standard] = $complianceCheck;

            $results['checks'][] = [
                'name' => "{$standard} Compliance",
                'status' => $complianceCheck['compliant'] ? 'pass' : 'fail',
                'value' => $complianceCheck['score'] . '%'
            ];

            if (!$complianceCheck['compliant']) {
                $results['score'] -= 10;
            }
        }

        return $results;
    }

    public function generateComplianceReport(string $standard): array
    {
        if (!isset($this->complianceStandards[$standard])) {
            throw new \InvalidArgumentException("Unknown compliance standard: {$standard}");
        }

        $requirements = $this->complianceStandards[$standard];
        $report = [
            'standard' => $standard,
            'timestamp' => date('Y-m-d H:i:s'),
            'requirements' => [],
            'overall_compliance' => 0,
            'recommendations' => []
        ];

        $totalRequirements = count($requirements);
        $metRequirements = 0;

        foreach ($requirements as $requirement) {
            $check = $this->evaluateComplianceRequirement($requirement);
            $report['requirements'][] = $check;

            if ($check['compliant']) {
                $metRequirements++;
            } else {
                $report['recommendations'][] = $check['recommendation'];
            }
        }

        $report['overall_compliance'] = $totalRequirements > 0 ? ($metRequirements / $totalRequirements) * 100 : 0;

        return $report;
    }

    private function createAuditRecord(): int
    {
        return $this->db->insert('security_audits', [
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function updateAuditRecord(int $auditId, array $results, array $summary): void
    {
        $this->db->update('security_audits', [
            'results' => json_encode($results),
            'summary' => json_encode($summary),
            'completed_at' => date('Y-m-d H:i:s'),
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $auditId]);
    }

    private function generateAuditSummary(array $results): array
    {
        $totalScore = 0;
        $totalCategories = 0;
        $criticalIssues = 0;
        $highIssues = 0;
        $mediumIssues = 0;
        $lowIssues = 0;

        foreach ($results as $category => $result) {
            if (isset($result['score'])) {
                $totalScore += $result['score'];
                $totalCategories++;
            }

            if (isset($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    switch ($issue['severity']) {
                        case 'critical':
                            $criticalIssues++;
                            break;
                        case 'high':
                            $highIssues++;
                            break;
                        case 'medium':
                            $mediumIssues++;
                            break;
                        case 'low':
                            $lowIssues++;
                            break;
                    }
                }
            }
        }

        $overallScore = $totalCategories > 0 ? round($totalScore / $totalCategories, 1) : 0;

        return [
            'overall_score' => $overallScore,
            'security_grade' => $this->calculateSecurityGrade($overallScore),
            'critical_issues' => $criticalIssues,
            'high_issues' => $highIssues,
            'medium_issues' => $mediumIssues,
            'low_issues' => $lowIssues,
            'total_issues' => $criticalIssues + $highIssues + $mediumIssues + $lowIssues,
            'risk_level' => $this->calculateRiskLevel($criticalIssues, $highIssues, $mediumIssues)
        ];
    }

    private function generateRecommendations(array $results): array
    {
        $recommendations = [];
        $priorityMap = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];

        foreach ($results as $category => $result) {
            if (isset($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    $recommendations[] = [
                        'category' => $category,
                        'severity' => $issue['severity'],
                        'priority' => $priorityMap[$issue['severity']],
                        'message' => $issue['message'],
                        'recommendation' => $issue['recommendation']
                    ];
                }
            }
        }

        usort($recommendations, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return array_slice($recommendations, 0, 20); // Top 20 recommendations
    }

    private function calculateSecurityGrade(float $score): string
    {
        if ($score >= 95) return 'A+';
        if ($score >= 90) return 'A';
        if ($score >= 85) return 'A-';
        if ($score >= 80) return 'B+';
        if ($score >= 75) return 'B';
        if ($score >= 70) return 'B-';
        if ($score >= 65) return 'C+';
        if ($score >= 60) return 'C';
        if ($score >= 55) return 'C-';
        if ($score >= 50) return 'D';
        return 'F';
    }

    private function calculateRiskLevel(int $critical, int $high, int $medium): string
    {
        if ($critical >= $this->config['critical_threshold']) return 'critical';
        if ($critical > 0 || $high >= $this->config['medium_threshold']) return 'high';
        if ($high > 0 || $medium >= $this->config['medium_threshold']) return 'medium';
        return 'low';
    }

    private function checkDefaultCredentials(): array
    {
        $defaultCreds = [];

        $commonDefaults = [
            ['email' => 'admin@example.com', 'password' => 'admin'],
            ['email' => 'admin@admin.com', 'password' => 'password'],
            ['email' => 'test@test.com', 'password' => 'test']
        ];

        foreach ($commonDefaults as $creds) {
            $user = $this->db->fetchRow(
                "SELECT id FROM users WHERE email = ?",
                [$creds['email']]
            );

            if ($user) {
                $defaultCreds[] = $creds['email'];
            }
        }

        return $defaultCreds;
    }

    private function checkComplianceStandard(string $standard): array
    {
        $requirements = $this->complianceStandards[$standard] ?? [];
        $met = 0;
        $total = count($requirements);

        foreach ($requirements as $requirement) {
            $check = $this->evaluateComplianceRequirement($requirement);
            if ($check['compliant']) {
                $met++;
            }
        }

        $score = $total > 0 ? ($met / $total) * 100 : 0;

        return [
            'compliant' => $score >= 80, // 80% threshold for compliance
            'score' => round($score, 1),
            'met_requirements' => $met,
            'total_requirements' => $total
        ];
    }

    private function evaluateComplianceRequirement(array $requirement): array
    {
        // This is a simplified compliance check
        // In a real system, each requirement would have specific validation logic

        switch ($requirement['type']) {
            case 'encryption':
                $dbSecurity = $this->db->verifyConnectionSecurity();
                return [
                    'requirement' => $requirement['name'],
                    'compliant' => $dbSecurity['encryption_status'] === 'enabled',
                    'recommendation' => 'Enable database encryption'
                ];

            case 'logging':
                $recentEvents = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM security_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                );
                return [
                    'requirement' => $requirement['name'],
                    'compliant' => $recentEvents > 0,
                    'recommendation' => 'Ensure security event logging is active'
                ];

            case 'access_control':
                return [
                    'requirement' => $requirement['name'],
                    'compliant' => class_exists('SecurityScanner\\Core\\AccessControlManager'),
                    'recommendation' => 'Implement role-based access control'
                ];

            default:
                return [
                    'requirement' => $requirement['name'],
                    'compliant' => true,
                    'recommendation' => 'No action needed'
                ];
        }
    }

    private function initializeComplianceStandards(): void
    {
        $this->complianceStandards = [
            'OWASP' => [
                ['name' => 'Input Validation', 'type' => 'validation'],
                ['name' => 'Authentication', 'type' => 'authentication'],
                ['name' => 'Session Management', 'type' => 'session'],
                ['name' => 'Access Control', 'type' => 'access_control'],
                ['name' => 'Security Configuration', 'type' => 'configuration'],
                ['name' => 'Cryptographic Storage', 'type' => 'encryption'],
                ['name' => 'Error Handling', 'type' => 'error_handling'],
                ['name' => 'Data Protection', 'type' => 'data_protection'],
                ['name' => 'Logging', 'type' => 'logging']
            ],

            'SOC2' => [
                ['name' => 'Access Controls', 'type' => 'access_control'],
                ['name' => 'Encryption', 'type' => 'encryption'],
                ['name' => 'Monitoring', 'type' => 'logging'],
                ['name' => 'Data Backup', 'type' => 'backup'],
                ['name' => 'Incident Response', 'type' => 'incident_response']
            ],

            'GDPR' => [
                ['name' => 'Data Protection by Design', 'type' => 'data_protection'],
                ['name' => 'Data Encryption', 'type' => 'encryption'],
                ['name' => 'Audit Logging', 'type' => 'logging'],
                ['name' => 'Access Controls', 'type' => 'access_control'],
                ['name' => 'Data Retention', 'type' => 'retention']
            ]
        ];
    }
}