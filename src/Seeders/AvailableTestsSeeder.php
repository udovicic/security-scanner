<?php

namespace SecurityScanner\Seeders;

use SecurityScanner\Core\Seeder;

class AvailableTestsSeeder extends Seeder
{
    /**
     * Seed initial available tests
     */
    public function seed(): void
    {
        $seederName = 'available_tests';

        if ($this->isSeeded($seederName)) {
            $this->logger->info('AvailableTestsSeeder: Already seeded, skipping');
            return;
        }

        $this->logger->info('AvailableTestsSeeder: Starting to seed available tests');

        $tests = $this->getInitialTests();
        $successCount = 0;
        $skipCount = 0;

        foreach ($tests as $test) {
            $inserted = $this->insertIfNotExists('available_tests', $test, ['name']);
            if ($inserted) {
                $successCount++;
                $this->logger->debug("Seeded test: {$test['name']}");
            } else {
                $skipCount++;
            }
        }

        $this->markSeeded($seederName);

        $this->logger->info('AvailableTestsSeeder: Completed', [
            'total_tests' => count($tests),
            'inserted' => $successCount,
            'skipped' => $skipCount
        ]);
    }

    /**
     * Get initial test definitions
     */
    private function getInitialTests(): array
    {
        return [
            // SSL/TLS Security Tests
            [
                'name' => 'ssl_certificate_check',
                'display_name' => 'SSL Certificate Validation',
                'description' => 'Verifies SSL certificate validity, expiration, and chain of trust',
                'category' => 'ssl',
                'test_class' => 'SecurityScanner\\Tests\\SSLCertificateTest',
                'enabled' => 1,
                'default_timeout' =>30,
                'default_config' => json_encode([
                    'check_expiry' => true,
                    'warn_days_before_expiry' => 30,
                    'verify_chain' => true,
                    'check_revocation' => false
                ]),
                'requirements' => json_encode([
                    'openssl' => '1.0.0',
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['security', 'ssl', 'certificate']),
                'severity' => 'high'
            ],
            [
                'name' => 'ssl_protocol_check',
                'display_name' => 'SSL Protocol Security',
                'description' => 'Tests for secure SSL/TLS protocol versions and cipher suites',
                'category' => 'ssl',
                'test_class' => 'SecurityScanner\\Tests\\SSLProtocolTest',
                'enabled' => 1,
                'default_timeout' =>45,
                'default_config' => json_encode([
                    'minimum_tls_version' => '1.2',
                    'weak_ciphers_check' => true,
                    'perfect_forward_secrecy' => true
                ]),
                'requirements' => json_encode([
                    'openssl' => '1.1.0',
                    'nmap' => '7.0'
                ]),
                'tags' => json_encode(['security', 'ssl', 'protocol']),
                'severity' => 'high'
            ],

            // HTTP Security Headers
            [
                'name' => 'security_headers_check',
                'display_name' => 'HTTP Security Headers',
                'description' => 'Validates presence and configuration of security headers',
                'category' => 'headers',
                'test_class' => 'SecurityScanner\\Tests\\SecurityHeadersTest',
                'enabled' => 1,
                'default_timeout' =>15,
                'default_config' => json_encode([
                    'required_headers' => [
                        'Strict-Transport-Security',
                        'X-Frame-Options',
                        'X-Content-Type-Options',
                        'X-XSS-Protection',
                        'Content-Security-Policy'
                    ],
                    'check_values' => true
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['security', 'headers', 'xss', 'csrf']),
                'severity' => 'medium'
            ],
            [
                'name' => 'hsts_check',
                'display_name' => 'HTTP Strict Transport Security',
                'description' => 'Checks HSTS header configuration and preload status',
                'category' => 'headers',
                'test_class' => 'SecurityScanner\\Tests\\HSTSTest',
                'enabled' => 1,
                'default_timeout' =>20,
                'default_config' => json_encode([
                    'min_max_age' => 31536000,
                    'require_includesubdomains' => true,
                    'check_preload' => true
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['security', 'headers', 'hsts']),
                'severity' => 'medium'
            ],

            // Content Security Policy
            [
                'name' => 'csp_check',
                'display_name' => 'Content Security Policy',
                'description' => 'Analyzes CSP header for security misconfigurations',
                'category' => 'headers',
                'test_class' => 'SecurityScanner\\Tests\\CSPTest',
                'enabled' => 1,
                'default_timeout' =>30,
                'default_config' => json_encode([
                    'check_unsafe_inline' => true,
                    'check_unsafe_eval' => true,
                    'require_nonce_or_hash' => false,
                    'allow_data_uris' => false
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['security', 'headers', 'csp', 'xss']),
                'severity' => 'medium'
            ],

            // Port and Service Scanning
            [
                'name' => 'port_scan',
                'display_name' => 'Port Scan',
                'description' => 'Scans for open ports and running services',
                'category' => 'network',
                'test_class' => 'SecurityScanner\\Tests\\PortScanTest',
                'enabled' => 0,
                'default_timeout' =>120,
                'default_config' => json_encode([
                    'ports' => [21, 22, 23, 25, 53, 80, 110, 143, 443, 993, 995, 3389],
                    'service_detection' => true,
                    'version_detection' => false
                ]),
                'requirements' => json_encode([
                    'nmap' => '7.0'
                ]),
                'tags' => json_encode(['network', 'ports', 'services']),
                'severity' => 'info'
            ],

            // Web Application Security
            [
                'name' => 'xss_check',
                'display_name' => 'Cross-Site Scripting Test',
                'description' => 'Tests for XSS vulnerabilities in web forms and parameters',
                'category' => 'webapp',
                'test_class' => 'SecurityScanner\\Tests\\XSSTest',
                'enabled' => 0,
                'default_timeout' =>60,
                'default_config' => json_encode([
                    'test_forms' => true,
                    'test_url_params' => true,
                    'payloads' => ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>']
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['webapp', 'xss', 'injection']),
                'severity' => 'high'
            ],
            [
                'name' => 'sql_injection_check',
                'display_name' => 'SQL Injection Test',
                'description' => 'Tests for SQL injection vulnerabilities',
                'category' => 'webapp',
                'test_class' => 'SecurityScanner\\Tests\\SQLInjectionTest',
                'enabled' => 0,
                'default_timeout' =>90,
                'default_config' => json_encode([
                    'test_forms' => true,
                    'test_url_params' => true,
                    'payloads' => ["'", "1' OR '1'='1", "'; DROP TABLE users; --"]
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['webapp', 'sqli', 'injection']),
                'severity' => 'critical'
            ],

            // Information Disclosure
            [
                'name' => 'directory_listing_check',
                'display_name' => 'Directory Listing',
                'description' => 'Checks for directory listing vulnerabilities',
                'category' => 'disclosure',
                'test_class' => 'SecurityScanner\\Tests\\DirectoryListingTest',
                'enabled' => 1,
                'default_timeout' =>30,
                'default_config' => json_encode([
                    'common_dirs' => ['/admin', '/backup', '/config', '/logs', '/tmp'],
                    'check_indexes' => true
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['disclosure', 'directory', 'information']),
                'severity' => 'medium'
            ],
            [
                'name' => 'sensitive_files_check',
                'display_name' => 'Sensitive File Exposure',
                'description' => 'Scans for exposed sensitive files and backups',
                'category' => 'disclosure',
                'test_class' => 'SecurityScanner\\Tests\\SensitiveFilesTest',
                'enabled' => 1,
                'default_timeout' =>45,
                'default_config' => json_encode([
                    'files' => [
                        '.env', '.htaccess', 'web.config', 'robots.txt',
                        'sitemap.xml', 'crossdomain.xml', 'phpinfo.php',
                        'backup.sql', 'config.php', '.git/config'
                    ]
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['disclosure', 'files', 'backup']),
                'severity' => 'medium'
            ],

            // Performance and Availability
            [
                'name' => 'response_time_check',
                'display_name' => 'Response Time Test',
                'description' => 'Measures website response time and availability',
                'category' => 'performance',
                'test_class' => 'SecurityScanner\\Tests\\ResponseTimeTest',
                'enabled' => 1,
                'default_timeout' =>30,
                'default_config' => json_encode([
                    'max_response_time' => 5000,
                    'measure_ttfb' => true,
                    'measure_full_load' => false
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['performance', 'availability', 'monitoring']),
                'severity' => 'info'
            ],
            [
                'name' => 'uptime_check',
                'display_name' => 'Uptime Monitor',
                'description' => 'Basic uptime monitoring with HTTP status verification',
                'category' => 'performance',
                'test_class' => 'SecurityScanner\\Tests\\UptimeTest',
                'enabled' => 1,
                'default_timeout' =>15,
                'default_config' => json_encode([
                    'expected_status' => 200,
                    'follow_redirects' => true,
                    'max_redirects' => 5
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['performance', 'uptime', 'availability']),
                'severity' => 'info'
            ],

            // DNS and Domain Security
            [
                'name' => 'dns_security_check',
                'display_name' => 'DNS Security Test',
                'description' => 'Checks DNS configuration and security settings',
                'category' => 'dns',
                'test_class' => 'SecurityScanner\\Tests\\DNSSecurityTest',
                'enabled' => 1,
                'default_timeout' =>30,
                'default_config' => json_encode([
                    'check_dnssec' => true,
                    'check_caa' => true,
                    'check_spf' => true,
                    'check_dmarc' => true
                ]),
                'requirements' => json_encode([
                    'dig' => '9.0'
                ]),
                'tags' => json_encode(['dns', 'domain', 'security']),
                'severity' => 'medium'
            ],

            // Cookie Security
            [
                'name' => 'cookie_security_check',
                'display_name' => 'Cookie Security Test',
                'description' => 'Analyzes cookie security attributes and configurations',
                'category' => 'cookies',
                'test_class' => 'SecurityScanner\\Tests\\CookieSecurityTest',
                'enabled' => 1,
                'default_timeout' =>20,
                'default_config' => json_encode([
                    'require_secure' => true,
                    'require_httponly' => true,
                    'check_samesite' => true,
                    'max_age_check' => true
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['cookies', 'session', 'security']),
                'severity' => 'medium'
            ],

            // Mixed Content
            [
                'name' => 'mixed_content_check',
                'display_name' => 'Mixed Content Detection',
                'description' => 'Detects mixed HTTP/HTTPS content issues',
                'category' => 'ssl',
                'test_class' => 'SecurityScanner\\Tests\\MixedContentTest',
                'enabled' => 1,
                'default_timeout' =>60,
                'default_config' => json_encode([
                    'check_images' => true,
                    'check_scripts' => true,
                    'check_stylesheets' => true,
                    'check_iframes' => true
                ]),
                'requirements' => json_encode([
                    'curl' => '7.0.0'
                ]),
                'tags' => json_encode(['ssl', 'mixed-content', 'security']),
                'severity' => 'medium'
            ]
        ];
    }
}