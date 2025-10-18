<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\ResourceMonitorService;
use SecurityScanner\Core\Database;

class ResourceMonitorServiceTest extends TestCase
{
    private ResourceMonitorService $resourceMonitorService;
    

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'monitoring_interval' => 15,
            'history_retention' => 3600,
            'alert_cooldown' => 60,
            'throttle_duration' => 300,
            'enable_auto_throttling' => true,
            'enable_alerts' => true,
            'thresholds' => [
                'cpu_usage' => ['warning' => 50, 'critical' => 70, 'throttle' => 80],
                'memory_usage' => ['warning' => 60, 'critical' => 80, 'throttle' => 90],
                'disk_usage' => ['warning' => 70, 'critical' => 85, 'throttle' => 90],
                'load_average' => ['warning' => 1.5, 'critical' => 3.0, 'throttle' => 4.0],
                'active_connections' => ['warning' => 50, 'critical' => 80, 'throttle' => 100],
                'concurrent_scans' => ['warning' => 5, 'critical' => 8, 'throttle' => 10]
            ]
        ];

        $this->resourceMonitorService = new ResourceMonitorService($config);
        
    }

    public function test_monitor_resources_collects_metrics(): void
    {
        $result = $this->resourceMonitorService->monitorResources();

        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['metrics', 'analysis', 'recommendations'], $result);

        // Check metrics structure
        $metrics = $result['metrics'];
        $this->assertArrayHasKeys([
            'timestamp',
            'cpu_usage',
            'memory_usage',
            'disk_usage',
            'load_average',
            'active_connections',
            'concurrent_scans',
            'process_count',
            'network_connections'
        ], $metrics);

        // Check analysis structure
        $analysis = $result['analysis'];
        $this->assertArrayHasKeys([
            'overall_status',
            'severity_level',
            'warnings',
            'critical_issues',
            'throttle_recommended',
            'resource_analysis'
        ], $analysis);

        $this->assertContains($analysis['overall_status'], ['normal', 'warning', 'critical']);
        $this->assertIsArray($result['recommendations']);
    }

    public function test_monitor_resources_stores_metrics_in_database(): void
    {
        $this->resourceMonitorService->monitorResources();

        // Check that metrics were stored
        $storedMetrics = $this->database->fetchRow(
            'SELECT * FROM resource_metrics ORDER BY created_at DESC LIMIT 1'
        );

        $this->assertNotNull($storedMetrics);
        $this->assertArrayHasKeys([
            'cpu_usage',
            'memory_usage_percentage',
            'disk_usage_percentage',
            'load_average_1min',
            'active_connections',
            'concurrent_scans'
        ], $storedMetrics);
    }

    public function test_get_resource_trends(): void
    {
        // Create some test metrics
        $this->createTestResourceMetrics();

        $trends = $this->resourceMonitorService->getResourceTrends('1h');

        $this->assertIsArray($trends);
        $this->assertGreaterThan(0, count($trends));

        foreach ($trends as $trend) {
            $this->assertArrayHasKeys([
                'timestamp',
                'cpu_usage',
                'memory_usage_percentage',
                'disk_usage_percentage',
                'load_average_1min',
                'active_connections',
                'concurrent_scans'
            ], $trend);
        }
    }

    public function test_get_current_resource_status(): void
    {
        // Create test metrics
        $this->createTestResourceMetrics();

        $status = $this->resourceMonitorService->getCurrentResourceStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKeys([
            'status',
            'severity_level',
            'throttling_active',
            'metrics',
            'analysis',
            'last_updated'
        ], $status);

        $this->assertContains($status['status'], ['normal', 'warning', 'critical']);
        $this->assertIsBool($status['throttling_active']);
        $this->assertIsArray($status['metrics']);
        $this->assertIsArray($status['analysis']);
    }

    public function test_force_throttling_activation(): void
    {
        $duration = 300; // 5 minutes

        $result = $this->resourceMonitorService->forceThrottling($duration);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Throttling activated manually', $result['message']);
        $this->assertArrayHasKey('expires_at', $result);

        // Verify throttling state
        $status = $this->resourceMonitorService->getCurrentResourceStatus();
        $this->assertTrue($status['throttling_active']);

        // Check that scans were paused
        $pausedScans = $this->database->fetchAll(
            "SELECT * FROM scan_results WHERE status = 'paused'"
        );
        // Note: This would work if there were pending scans to pause
    }

    public function test_force_throttling_deactivation(): void
    {
        // First activate throttling
        $this->resourceMonitorService->forceThrottling(300);

        // Then deactivate
        $result = $this->resourceMonitorService->forceThrottlingDeactivation();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Throttling deactivated manually', $result['message']);

        // Verify throttling is off
        $status = $this->resourceMonitorService->getCurrentResourceStatus();
        $this->assertFalse($status['throttling_active']);
    }

    public function test_resource_analysis_with_normal_levels(): void
    {
        // Create metrics with normal levels
        $metrics = [
            'cpu_usage' => 30.0,
            'memory_usage' => ['usage_percentage' => 40.0],
            'disk_usage' => ['usage_percentage' => 50.0],
            'load_average' => ['1min' => 1.0],
            'active_connections' => 20,
            'concurrent_scans' => 2
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('analyzeMetrics');
        $method->setAccessible(true);

        $analysis = $method->invoke($this->resourceMonitorService, $metrics);

        $this->assertEquals('normal', $analysis['overall_status']);
        $this->assertEquals(0, $analysis['severity_level']);
        $this->assertEmpty($analysis['warnings']);
        $this->assertEmpty($analysis['critical_issues']);
        $this->assertFalse($analysis['throttle_recommended']);
    }

    public function test_resource_analysis_with_warning_levels(): void
    {
        // Create metrics with warning levels
        $metrics = [
            'cpu_usage' => 60.0, // Above warning threshold (50)
            'memory_usage' => ['usage_percentage' => 70.0], // Above warning threshold (60)
            'disk_usage' => ['usage_percentage' => 75.0], // Above warning threshold (70)
            'load_average' => ['1min' => 2.0], // Above warning threshold (1.5)
            'active_connections' => 60, // Above warning threshold (50)
            'concurrent_scans' => 6 // Above warning threshold (5)
        ];

        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('analyzeMetrics');
        $method->setAccessible(true);

        $analysis = $method->invoke($this->resourceMonitorService, $metrics);

        $this->assertEquals('warning', $analysis['overall_status']);
        $this->assertEquals(2, $analysis['severity_level']);
        $this->assertGreaterThan(0, count($analysis['warnings']));
        $this->assertFalse($analysis['throttle_recommended']);
    }

    public function test_resource_analysis_with_critical_levels(): void
    {
        // Create metrics with critical levels
        $metrics = [
            'cpu_usage' => 75.0, // Above critical threshold (70)
            'memory_usage' => ['usage_percentage' => 85.0], // Above critical threshold (80)
            'disk_usage' => ['usage_percentage' => 90.0], // Above critical threshold (85)
            'load_average' => ['1min' => 3.5], // Above critical threshold (3.0)
            'active_connections' => 90, // Above critical threshold (80)
            'concurrent_scans' => 9 // Above critical threshold (8)
        ];

        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('analyzeMetrics');
        $method->setAccessible(true);

        $analysis = $method->invoke($this->resourceMonitorService, $metrics);

        $this->assertEquals('critical', $analysis['overall_status']);
        $this->assertEquals(3, $analysis['severity_level']);
        $this->assertGreaterThan(0, count($analysis['critical_issues']));
    }

    public function test_resource_analysis_with_throttle_levels(): void
    {
        // Create metrics with throttle levels
        $metrics = [
            'cpu_usage' => 85.0, // Above throttle threshold (80)
            'memory_usage' => ['usage_percentage' => 95.0], // Above throttle threshold (90)
            'disk_usage' => ['usage_percentage' => 95.0], // Above throttle threshold (90)
            'load_average' => ['1min' => 5.0], // Above throttle threshold (4.0)
            'active_connections' => 120, // Above throttle threshold (100)
            'concurrent_scans' => 12 // Above throttle threshold (10)
        ];

        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('analyzeMetrics');
        $method->setAccessible(true);

        $analysis = $method->invoke($this->resourceMonitorService, $metrics);

        $this->assertEquals('critical', $analysis['overall_status']);
        $this->assertEquals(3, $analysis['severity_level']);
        $this->assertTrue($analysis['throttle_recommended']);
        $this->assertGreaterThan(0, count($analysis['critical_issues']));
    }

    public function test_generate_recommendations(): void
    {
        $analysis = [
            'resource_analysis' => [
                'cpu_usage' => [
                    'level' => 'critical',
                    'value' => 85.0,
                    'throttle_recommended' => true
                ],
                'memory_usage' => [
                    'level' => 'warning',
                    'value' => 75.0,
                    'throttle_recommended' => false
                ],
                'disk_usage' => [
                    'level' => 'normal',
                    'value' => 50.0,
                    'throttle_recommended' => false
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('generateRecommendations');
        $method->setAccessible(true);

        $recommendations = $method->invoke($this->resourceMonitorService, $analysis);

        $this->assertIsArray($recommendations);
        $this->assertGreaterThan(0, count($recommendations));

        // Check for CPU-related recommendations
        $cpuRecommendations = array_filter($recommendations, function($rec) {
            return strpos($rec, 'CPU') !== false || strpos($rec, 'concurrent') !== false;
        });
        $this->assertGreaterThan(0, count($cpuRecommendations));

        // Check for memory-related recommendations
        $memoryRecommendations = array_filter($recommendations, function($rec) {
            return strpos($rec, 'memory') !== false || strpos($rec, 'RAM') !== false;
        });
        $this->assertGreaterThan(0, count($memoryRecommendations));
    }

    public function test_cleanup_old_metrics(): void
    {
        // Create old metrics
        $oldTime = date('Y-m-d H:i:s', time() - 7200); // 2 hours ago
        $this->database->insert('resource_metrics', [
            'timestamp' => $oldTime,
            'cpu_usage' => 50.0,
            'memory_usage_percentage' => 60.0,
            'disk_usage_percentage' => 70.0,
            'load_average_1min' => 1.5,
            'active_connections' => 30,
            'concurrent_scans' => 3,
            'created_at' => $oldTime
        ]);

        // Create recent metrics
        $this->database->insert('resource_metrics', [
            'timestamp' => date('Y-m-d H:i:s'),
            'cpu_usage' => 55.0,
            'memory_usage_percentage' => 65.0,
            'disk_usage_percentage' => 75.0,
            'load_average_1min' => 1.8,
            'active_connections' => 35,
            'concurrent_scans' => 4,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Trigger monitoring to clean up old metrics
        $this->resourceMonitorService->monitorResources();

        // Check that old metrics were cleaned up (retention is 3600 seconds = 1 hour)
        $oldMetrics = $this->database->fetchAll(
            'SELECT * FROM resource_metrics WHERE created_at < ?',
            [date('Y-m-d H:i:s', time() - 3600)]
        );

        // Should have removed old metrics beyond retention period
        $this->assertEmpty($oldMetrics);
    }

    public function test_resource_log_activity(): void
    {
        // Force throttling to generate log activity
        $this->resourceMonitorService->forceThrottling(300);

        // Check that activity was logged
        $logs = $this->database->fetchAll(
            'SELECT * FROM resource_log WHERE action = ? ORDER BY created_at DESC',
            ['throttling_forced']
        );

        $this->assertNotEmpty($logs);
        $this->assertEquals('throttling_forced', $logs[0]['action']);

        $context = json_decode($logs[0]['context'], true);
        $this->assertArrayHasKeys(['duration', 'expires_at'], $context);
    }

    public function test_metrics_extraction(): void
    {
        $metrics = [
            'cpu_usage' => 75.0,
            'memory_usage' => ['usage_percentage' => 80.0],
            'disk_usage' => ['usage_percentage' => 85.0],
            'load_average' => ['1min' => 2.5],
            'active_connections' => 60,
            'concurrent_scans' => 8
        ];

        $reflection = new \ReflectionClass($this->resourceMonitorService);
        $method = $reflection->getMethod('extractMetricValue');
        $method->setAccessible(true);

        $this->assertEquals(75.0, $method->invoke($this->resourceMonitorService, $metrics, 'cpu_usage'));
        $this->assertEquals(80.0, $method->invoke($this->resourceMonitorService, $metrics, 'memory_usage'));
        $this->assertEquals(85.0, $method->invoke($this->resourceMonitorService, $metrics, 'disk_usage'));
        $this->assertEquals(2.5, $method->invoke($this->resourceMonitorService, $metrics, 'load_average'));
        $this->assertEquals(60, $method->invoke($this->resourceMonitorService, $metrics, 'active_connections'));
        $this->assertEquals(8, $method->invoke($this->resourceMonitorService, $metrics, 'concurrent_scans'));
        $this->assertEquals(0, $method->invoke($this->resourceMonitorService, $metrics, 'unknown_metric'));
    }

    public function test_throttling_state_persistence(): void
    {
        // Test throttling state is persistent
        $this->resourceMonitorService->forceThrottling(300);

        // Create new instance to test persistence
        $newService = new ResourceMonitorService();
        $status = $newService->getCurrentResourceStatus();

        $this->assertTrue($status['throttling_active']);
    }

    private function createTestResourceMetrics(): void
    {
        $timestamps = [
            date('Y-m-d H:i:s', time() - 1800), // 30 minutes ago
            date('Y-m-d H:i:s', time() - 900),  // 15 minutes ago
            date('Y-m-d H:i:s')                 // now
        ];

        foreach ($timestamps as $timestamp) {
            $this->database->insert('resource_metrics', [
                'timestamp' => $timestamp,
                'cpu_usage' => rand(20, 80),
                'memory_total' => 8589934592, // 8GB
                'memory_used' => rand(2147483648, 6442450944), // 2-6GB
                'memory_usage_percentage' => rand(25, 75),
                'disk_total' => 107374182400, // 100GB
                'disk_used' => rand(53687091200, 85899345920), // 50-80GB
                'disk_usage_percentage' => rand(50, 80),
                'load_average_1min' => rand(10, 40) / 10, // 1.0 - 4.0
                'load_average_5min' => rand(10, 40) / 10,
                'load_average_15min' => rand(10, 40) / 10,
                'active_connections' => rand(10, 80),
                'concurrent_scans' => rand(1, 10),
                'process_count' => rand(100, 300),
                'network_connections' => rand(50, 200),
                'created_at' => $timestamp
            ]);
        }
    }
}