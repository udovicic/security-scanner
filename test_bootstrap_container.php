<?php

use SecurityScanner\Core\Container;

echo "🧪 Testing Bootstrap with Container Integration...\n\n";

try {
    // Bootstrap the application (loads container)
    $config = require_once 'bootstrap.php';

    echo "1. Testing bootstrap integration...\n";

    $container = Container::getInstance();
    echo "   ✓ Container instance retrieved\n";

    // Test that core services are registered
    $configFromContainer = $container->get('config');
    echo "   ✓ Config service available: " . get_class($configFromContainer) . "\n";

    $db = $container->get('database');
    echo "   ✓ Database service available: " . get_class($db) . "\n";

    $logger = $container->get('logger.access');
    echo "   ✓ Logger service available: " . get_class($logger) . "\n";

    // Test provider manager
    $providerManager = $container->get('provider.manager');
    echo "   ✓ Provider manager available: " . get_class($providerManager) . "\n";

    echo "\n2. Testing container statistics...\n";
    $stats = $container->getStats();
    echo "   ✓ Total services: " . $stats['total_services'] . "\n";
    echo "   ✓ Resolved instances: " . $stats['resolved_instances'] . "\n";
    echo "   ✓ Aliases: " . $stats['aliases'] . "\n";

    echo "\n3. Testing tagged services...\n";
    $loggers = $container->tagged('loggers');
    echo "   ✓ Logger services count: " . count($loggers) . "\n";

    foreach ($loggers as $i => $logger) {
        echo "     - Logger " . ($i + 1) . ": " . get_class($logger) . "\n";
    }

    echo "\n✅ Bootstrap container integration test completed successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}