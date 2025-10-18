<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\MetricsService;
use SecurityScanner\Core\Database;

class MetricsServiceTest extends TestCase
{
    private MetricsService $metricsService;
    

    protected function setUp(): void
    {
        parent::setUp();
        $this->metricsService = new MetricsService();
        
    }

    public function test_collect_website_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test results for metrics calculation
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'passed',
            'execution_time' => 1.5
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'failed',
            'execution_time' => 2.3
        ]);

        $metrics = $this->metricsService->collectWebsiteMetrics($website['id']);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'website_id',
            'total_tests',
            'passed_tests',
            'failed_tests',
            'success_rate',
            'average_execution_time',
            'last_scan_at'
        ], $metrics);

        $this->assertEquals($website['id'], $metrics['website_id']);
        $this->assertEquals(2, $metrics['total_tests']);
        $this->assertEquals(1, $metrics['passed_tests']);
        $this->assertEquals(1, $metrics['failed_tests']);
        $this->assertEquals(50.0, $metrics['success_rate']);
    }

    public function test_get_dashboard_metrics(): void
    {
        // Create test data
        $website1 = $this->createTestWebsite(['status' => 'active']);
        $website2 = $this->createTestWebsite(['status' => 'active']);
        $website3 = $this->createTestWebsite(['status' => 'inactive']);

        $execution1 = $this->createTestExecution(['website_id' => $website1['id'], 'status' => 'completed']);
        $execution2 = $this->createTestExecution(['website_id' => $website2['id'], 'status' => 'failed']);

        $this->createTestResult(['execution_id' => $execution1['id'], 'status' => 'passed']);
        $this->createTestResult(['execution_id' => $execution2['id'], 'status' => 'failed']);

        $metrics = $this->metricsService->getDashboardMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'total_websites',
            'active_websites',
            'total_executions',
            'overall_success_rate',
            'recent_failures',
            'average_response_time'
        ], $metrics);

        $this->assertEquals(3, $metrics['total_websites']);
        $this->assertEquals(2, $metrics['active_websites']);
        $this->assertEquals(2, $metrics['total_executions']);
    }

    public function test_get_performance_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test results with different execution times
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'passed',
            'execution_time' => 1.0
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'passed',
            'execution_time' => 2.0
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'passed',
            'execution_time' => 3.0
        ]);

        $metrics = $this->metricsService->getPerformanceMetrics($website['id']);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'average_execution_time',
            'min_execution_time',
            'max_execution_time',
            'total_tests_run',
            'performance_trend'
        ], $metrics);

        $this->assertEquals(2.0, $metrics['average_execution_time']);
        $this->assertEquals(1.0, $metrics['min_execution_time']);
        $this->assertEquals(3.0, $metrics['max_execution_time']);
        $this->assertEquals(3, $metrics['total_tests_run']);
    }

    public function test_get_security_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test results for security tests
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'passed'
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'security_headers_test',
            'status' => 'failed'
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'xss_protection_test',
            'status' => 'passed'
        ]);

        $metrics = $this->metricsService->getSecurityMetrics($website['id']);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'security_score',
            'total_security_tests',
            'passed_security_tests',
            'failed_security_tests',
            'critical_issues',
            'security_trend'
        ], $metrics);

        $this->assertEquals(3, $metrics['total_security_tests']);
        $this->assertEquals(2, $metrics['passed_security_tests']);
        $this->assertEquals(1, $metrics['failed_security_tests']);
        $this->assertIsNumeric($metrics['security_score']);
    }

    public function test_get_historical_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create historical data (30 days)
        $startDate = date('Y-m-d', time() - (30 * 24 * 60 * 60));
        $endDate = date('Y-m-d');

        // Create executions over time
        for ($i = 0; $i < 5; $i++) {
            $date = date('Y-m-d H:i:s', time() - ($i * 7 * 24 * 60 * 60)); // Weekly intervals
            $execution = $this->createTestExecution([
                'website_id' => $website['id'],
                'created_at' => $date
            ]);
            $this->createTestResult([
                'execution_id' => $execution['id'],
                'status' => $i % 2 === 0 ? 'passed' : 'failed',
                'created_at' => $date
            ]);
        }

        $metrics = $this->metricsService->getHistoricalMetrics($website['id'], $startDate, $endDate);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKeys([
            'date_range',
            'daily_metrics',
            'trends'
        ], $metrics);

        $this->assertEquals($startDate, $metrics['date_range']['start']);
        $this->assertEquals($endDate, $metrics['date_range']['end']);
        $this->assertIsArray($metrics['daily_metrics']);
        $this->assertIsArray($metrics['trends']);
    }

    public function test_get_test_failure_analysis(): void
    {
        $website = $this->createTestWebsite();

        // Create failed test results
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'failed',
            'message' => 'Certificate expired'
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'failed',
            'message' => 'Certificate expired'
        ]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'security_headers_test',
            'status' => 'failed',
            'message' => 'Missing HSTS header'
        ]);

        $analysis = $this->metricsService->getTestFailureAnalysis($website['id']);

        $this->assertIsArray($analysis);
        $this->assertArrayHasKeys([
            'most_failed_tests',
            'common_failure_reasons',
            'failure_patterns',
            'recommendations'
        ], $analysis);

        $this->assertIsArray($analysis['most_failed_tests']);
        $this->assertIsArray($analysis['common_failure_reasons']);
    }

    public function test_calculate_uptime_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create availability test results
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        // 8 successful availability tests and 2 failures
        for ($i = 0; $i < 8; $i++) {
            $this->createTestResult([
                'execution_id' => $execution['id'],
                'test_name' => 'availability_test',
                'status' => 'passed'
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createTestResult([
                'execution_id' => $execution['id'],
                'test_name' => 'availability_test',
                'status' => 'failed'
            ]);
        }

        $uptime = $this->metricsService->calculateUptimeMetrics($website['id']);

        $this->assertIsArray($uptime);
        $this->assertArrayHasKeys([
            'uptime_percentage',
            'total_checks',
            'successful_checks',
            'failed_checks',
            'downtime_incidents'
        ], $uptime);

        $this->assertEquals(80.0, $uptime['uptime_percentage']);
        $this->assertEquals(10, $uptime['total_checks']);
        $this->assertEquals(8, $uptime['successful_checks']);
        $this->assertEquals(2, $uptime['failed_checks']);
    }

    public function test_export_metrics_to_csv(): void
    {
        $website = $this->createTestWebsite();

        // Create test data
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'passed',
            'execution_time' => 1.5
        ]);

        $csvData = $this->metricsService->exportMetricsToCsv($website['id']);

        $this->assertIsString($csvData);
        $this->assertStringContainsString('Test Name,Status,Execution Time', $csvData);
        $this->assertStringContainsString('ssl_certificate_test,passed,1.5', $csvData);
    }

    public function test_get_comparative_metrics(): void
    {
        $website1 = $this->createTestWebsite(['name' => 'Website 1']);
        $website2 = $this->createTestWebsite(['name' => 'Website 2']);

        // Create different performance data for each website
        $execution1 = $this->createTestExecution(['website_id' => $website1['id']]);
        $execution2 = $this->createTestExecution(['website_id' => $website2['id']]);

        $this->createTestResult([
            'execution_id' => $execution1['id'],
            'status' => 'passed',
            'execution_time' => 1.0
        ]);
        $this->createTestResult([
            'execution_id' => $execution2['id'],
            'status' => 'passed',
            'execution_time' => 2.0
        ]);

        $comparison = $this->metricsService->getComparativeMetrics([$website1['id'], $website2['id']]);

        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('websites', $comparison);
        $this->assertCount(2, $comparison['websites']);

        foreach ($comparison['websites'] as $websiteMetrics) {
            $this->assertArrayHasKeys([
                'website_id',
                'name',
                'average_execution_time',
                'success_rate'
            ], $websiteMetrics);
        }
    }

    public function test_get_alert_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test results that would trigger alerts
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'status' => 'failed',
            'message' => 'Critical security issue detected'
        ]);

        $alertMetrics = $this->metricsService->getAlertMetrics($website['id']);

        $this->assertIsArray($alertMetrics);
        $this->assertArrayHasKeys([
            'total_alerts',
            'critical_alerts',
            'warning_alerts',
            'info_alerts',
            'recent_alerts'
        ], $alertMetrics);

        $this->assertIsNumeric($alertMetrics['total_alerts']);
        $this->assertIsArray($alertMetrics['recent_alerts']);
    }

    public function test_calculate_cost_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create test executions for cost calculation
        for ($i = 0; $i < 10; $i++) {
            $execution = $this->createTestExecution([
                'website_id' => $website['id'],
                'created_at' => date('Y-m-d H:i:s', time() - ($i * 3600)) // Hourly
            ]);
            $this->createTestResult([
                'execution_id' => $execution['id'],
                'execution_time' => 60 // 1 minute each
            ]);
        }

        $costMetrics = $this->metricsService->calculateCostMetrics($website['id']);

        $this->assertIsArray($costMetrics);
        $this->assertArrayHasKeys([
            'total_execution_time',
            'estimated_cost',
            'cost_per_test',
            'monthly_projection'
        ], $costMetrics);

        $this->assertIsNumeric($costMetrics['total_execution_time']);
        $this->assertIsNumeric($costMetrics['estimated_cost']);
    }

    public function test_get_real_time_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create current running execution
        $this->createTestExecution([
            'website_id' => $website['id'],
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s')
        ]);

        $realTimeMetrics = $this->metricsService->getRealTimeMetrics($website['id']);

        $this->assertIsArray($realTimeMetrics);
        $this->assertArrayHasKeys([
            'current_status',
            'running_tests',
            'last_update',
            'next_scheduled_scan'
        ], $realTimeMetrics);

        $this->assertEquals(1, $realTimeMetrics['running_tests']);
        $this->assertIsValidTimestamp($realTimeMetrics['last_update']);
    }

    public function test_aggregate_system_metrics(): void
    {
        // Create multiple websites with test data
        $website1 = $this->createTestWebsite();
        $website2 = $this->createTestWebsite();

        $execution1 = $this->createTestExecution(['website_id' => $website1['id']]);
        $execution2 = $this->createTestExecution(['website_id' => $website2['id']]);

        $this->createTestResult(['execution_id' => $execution1['id'], 'status' => 'passed']);
        $this->createTestResult(['execution_id' => $execution2['id'], 'status' => 'failed']);

        $systemMetrics = $this->metricsService->aggregateSystemMetrics();

        $this->assertIsArray($systemMetrics);
        $this->assertArrayHasKeys([
            'total_websites',
            'total_tests_run',
            'system_success_rate',
            'system_uptime',
            'resource_usage'
        ], $systemMetrics);

        $this->assertEquals(2, $systemMetrics['total_websites']);
        $this->assertEquals(2, $systemMetrics['total_tests_run']);
        $this->assertIsNumeric($systemMetrics['system_success_rate']);
    }

    public function test_generate_metrics_report(): void
    {
        $website = $this->createTestWebsite();

        // Create comprehensive test data
        $execution = $this->createTestExecution(['website_id' => $website['id']]);
        $this->createTestResult([
            'execution_id' => $execution['id'],
            'test_name' => 'ssl_certificate_test',
            'status' => 'passed',
            'execution_time' => 1.5
        ]);

        $report = $this->metricsService->generateMetricsReport($website['id'], 'weekly');

        $this->assertIsArray($report);
        $this->assertArrayHasKeys([
            'report_type',
            'period',
            'website_info',
            'summary',
            'detailed_metrics',
            'recommendations',
            'generated_at'
        ], $report);

        $this->assertEquals('weekly', $report['report_type']);
        $this->assertEquals($website['id'], $report['website_info']['id']);
        $this->assertIsValidTimestamp($report['generated_at']);
    }
}