<?php

// Test script to verify database connection system
require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\Database;
use SecurityScanner\Core\ConnectionPool;

echo "Testing Security Scanner Database System...\n\n";

try {
    $database = Database::getInstance();

    // Test 1: Get connection info
    echo "1. Testing database configuration:\n";
    $info = $database->getConnectionInfo();
    echo "✓ Database: {$info['driver']}://{$info['host']}:{$info['port']}/{$info['database']}\n";
    echo "✓ SSL Enabled: " . ($info['ssl_enabled'] ? 'Yes' : 'No') . "\n";
    echo "✓ Charset: {$info['charset']}\n\n";

    // Test 2: Test connection
    echo "2. Testing database connection:\n";
    if ($database->testConnection()) {
        echo "✓ Database connection successful\n\n";
    } else {
        echo "✗ Database connection failed\n";
        echo "Note: This is expected if MySQL is not running or not configured\n\n";
    }

    // Test 3: Connection pool stats
    echo "3. Testing connection pool:\n";
    $pool = ConnectionPool::getInstance();
    $stats = $pool->getPoolStats();

    if (!empty($stats)) {
        foreach ($stats as $connectionName => $poolStats) {
            echo "✓ Pool '{$connectionName}':\n";
            echo "  - Available: {$poolStats['available_connections']}\n";
            echo "  - Active: {$poolStats['active_connections']}\n";
            echo "  - Max: {$poolStats['max_connections']}\n";
            echo "  - Utilization: {$poolStats['pool_utilization']}%\n";
        }
    } else {
        echo "✓ Connection pool initialized but not yet used\n";
    }
    echo "\n";

    // Test 4: Database operations (if connection works)
    echo "4. Testing database operations:\n";
    try {
        $pdo = $database->getConnection();

        // Test basic query
        $result = $database->query("SELECT 1 as test_value");
        $row = $result->fetch();

        if ($row && $row['test_value'] == 1) {
            echo "✓ Basic query execution works\n";
        }

        // Test transaction handling
        $database->beginTransaction();
        echo "✓ Transaction started\n";

        $database->rollback();
        echo "✓ Transaction rolled back\n";

    } catch (\Exception $e) {
        echo "✗ Database operations failed: " . $e->getMessage() . "\n";
        echo "Note: This is expected if MySQL is not running or not configured\n";
    }
    echo "\n";

    // Test 5: Connection cleanup
    echo "5. Testing connection cleanup:\n";
    $cleaned = $pool->cleanupIdleConnections();
    echo "✓ Cleaned up {$cleaned} idle connections\n\n";

    echo "Database system test completed!\n";
    echo "Check logs/error.log for detailed connection logs.\n";

} catch (\Exception $e) {
    echo "Test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}