<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{CacheManager, QueryCache};

echo "💾 Testing Cache System (Task 33)\n";
echo "=================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing Cache Manager Creation:\n";

    // Test default configuration
    $totalTests++;
    $cache = new CacheManager();
    if ($cache instanceof CacheManager) {
        echo "   ✅ Cache manager creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Cache manager creation failed\n";
    }

    // Test preset configurations
    $totalTests++;
    $queryCache = CacheManager::forQueries();
    $sessionCache = CacheManager::forSessions();
    $apiCache = CacheManager::forApi();

    if ($queryCache instanceof CacheManager &&
        $sessionCache instanceof CacheManager &&
        $apiCache instanceof CacheManager) {
        echo "   ✅ Preset configurations: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Preset configurations failed\n";
    }

    echo "\n2. Testing Basic Cache Operations:\n";

    // Test set and get
    $totalTests++;
    $success = $cache->set('test_key', 'test_value', 60);
    $value = $cache->get('test_key');

    if ($success && $value === 'test_value') {
        echo "   ✅ Set/Get operations: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Set/Get operations failed\n";
    }

    // Test has
    $totalTests++;
    if ($cache->has('test_key') && !$cache->has('non_existent_key')) {
        echo "   ✅ Has operation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Has operation failed\n";
    }

    // Test default values
    $totalTests++;
    $defaultValue = $cache->get('non_existent_key', 'default');
    if ($defaultValue === 'default') {
        echo "   ✅ Default value handling: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Default value handling failed\n";
    }

    // Test complex data types
    $totalTests++;
    $complexData = ['array' => [1, 2, 3], 'object' => (object)['prop' => 'value'], 'number' => 42];
    $cache->set('complex_data', $complexData, 60);
    $retrievedData = $cache->get('complex_data');

    if ($retrievedData['array'] === [1, 2, 3] &&
        $retrievedData['object']->prop === 'value' &&
        $retrievedData['number'] === 42) {
        echo "   ✅ Complex data storage: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Complex data storage failed\n";
    }

    echo "\n3. Testing Cache Expiration:\n";

    // Test TTL functionality
    $totalTests++;
    $cache->set('short_lived', 'expires_soon', 1); // 1 second TTL
    sleep(2);
    $expiredValue = $cache->get('short_lived');

    if ($expiredValue === null) {
        echo "   ✅ Cache expiration: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Cache should have expired\n";
    }

    echo "\n4. Testing Multiple Operations:\n";

    // Test multiple set/get
    $totalTests++;
    $multipleData = [
        'key1' => 'value1',
        'key2' => 'value2',
        'key3' => 'value3'
    ];

    $setResult = $cache->setMultiple($multipleData, 60);
    $getResult = $cache->getMultiple(['key1', 'key2', 'key3']);

    if ($setResult &&
        $getResult['key1'] === 'value1' &&
        $getResult['key2'] === 'value2' &&
        $getResult['key3'] === 'value3') {
        echo "   ✅ Multiple operations: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Multiple operations failed\n";
    }

    // Test multiple delete
    $totalTests++;
    $deleteResult = $cache->deleteMultiple(['key1', 'key3']);
    $remainingValue = $cache->get('key2');
    $deletedValue = $cache->get('key1');

    if ($deleteResult && $remainingValue === 'value2' && $deletedValue === null) {
        echo "   ✅ Multiple delete: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Multiple delete failed\n";
    }

    echo "\n5. Testing Remember Function:\n";

    // Test remember functionality
    $totalTests++;
    $callCount = 0;
    $callback = function() use (&$callCount) {
        $callCount++;
        return 'computed_value_' . $callCount;
    };

    $value1 = $cache->remember('remember_key', $callback, 60);
    $value2 = $cache->remember('remember_key', $callback, 60);

    if ($value1 === 'computed_value_1' && $value2 === 'computed_value_1' && $callCount === 1) {
        echo "   ✅ Remember function: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Remember function failed\n";
    }

    echo "\n6. Testing Increment/Decrement:\n";

    // Test numeric operations
    $totalTests++;
    $cache->set('counter', 10, 60);
    $incremented = $cache->increment('counter', 5);
    $decremented = $cache->decrement('counter', 3);
    $finalValue = $cache->get('counter');

    if ($incremented === 15 && $decremented === 12 && $finalValue === 12) {
        echo "   ✅ Increment/Decrement: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Increment/Decrement failed\n";
    }

    echo "\n7. Testing Add Operation:\n";

    // Test add (only if key doesn't exist)
    $totalTests++;
    $cache->delete('add_test');
    $added1 = $cache->add('add_test', 'first_value', 60);
    $added2 = $cache->add('add_test', 'second_value', 60);
    $value = $cache->get('add_test');

    if ($added1 && !$added2 && $value === 'first_value') {
        echo "   ✅ Add operation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Add operation failed\n";
    }

    echo "\n8. Testing File Cache Driver:\n";

    // Test file-based caching
    $totalTests++;
    try {
        $fileCache = new CacheManager([
            'driver' => 'file',
            'cache_path' => '/tmp/claude/cache_test/'
        ]);

        $fileCache->set('file_test', 'file_value', 60);
        $fileValue = $fileCache->get('file_test');

        if ($fileValue === 'file_value') {
            echo "   ✅ File cache driver: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ File cache driver failed\n";
        }
    } catch (Exception $e) {
        echo "   ❌ File cache driver failed: " . $e->getMessage() . "\n";
    }

    echo "\n9. Testing Query Cache:\n";

    // Test query cache creation
    $totalTests++;
    $queryCache = new QueryCache();
    if ($queryCache instanceof QueryCache) {
        echo "   ✅ Query cache creation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Query cache creation failed\n";
    }

    // Test query caching and retrieval
    $totalTests++;
    $sql = "SELECT * FROM users WHERE active = ?";
    $params = [1];
    $mockResult = [
        ['id' => 1, 'name' => 'John', 'active' => 1],
        ['id' => 2, 'name' => 'Jane', 'active' => 1]
    ];

    $cached = $queryCache->cacheQuery($sql, $params, $mockResult, 300);
    $retrieved = $queryCache->getCachedQuery($sql, $params);

    if ($cached && $retrieved === $mockResult) {
        echo "   ✅ Query caching: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Query caching failed\n";
    }

    // Test query cache with executor
    $totalTests++;
    $executorCalled = false;
    $executor = function($sql, $params) use (&$executorCalled, $mockResult) {
        $executorCalled = true;
        return $mockResult;
    };

    // First call should execute
    $result1 = $queryCache->query($sql, $params, $executor, 300);

    // Second call should use cache
    $executorCalled = false;
    $result2 = $queryCache->query($sql, $params, $executor, 300);

    if ($result1 === $mockResult && $result2 === $mockResult && !$executorCalled) {
        echo "   ✅ Query cache executor: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Query cache executor failed\n";
    }

    echo "\n10. Testing Cache Invalidation:\n";

    // Test tag-based invalidation
    $totalTests++;
    $queryCache->cacheQuery("SELECT * FROM users", [], $mockResult, 300, ['users', 'active_users']);
    $queryCache->cacheQuery("SELECT * FROM posts", [], [['id' => 1, 'title' => 'Test']], 300, ['posts']);

    $invalidated = $queryCache->invalidateByTags(['users']);
    $usersResult = $queryCache->getCachedQuery("SELECT * FROM users", []);
    $postsResult = $queryCache->getCachedQuery("SELECT * FROM posts", []);

    if ($invalidated > 0 && $usersResult === null && $postsResult !== null) {
        echo "   ✅ Tag-based invalidation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Tag-based invalidation failed\n";
    }

    echo "\n11. Testing Cache Statistics:\n";

    // Test statistics
    $totalTests++;
    $stats = $cache->getStats();
    $queryStats = $queryCache->getStats();

    if (isset($stats['hits'], $stats['misses'], $stats['driver']) &&
        isset($queryStats['query_stats'], $queryStats['cache_efficiency'])) {
        echo "   ✅ Cache statistics: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Cache statistics failed\n";
    }

    echo "\n12. Testing Preset Query Caches:\n";

    // Test preset configurations
    $totalTests++;
    $aggressiveCache = QueryCache::aggressive();
    $conservativeCache = QueryCache::conservative();

    if ($aggressiveCache instanceof QueryCache && $conservativeCache instanceof QueryCache) {
        echo "   ✅ Preset query caches: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Preset query caches failed\n";
    }

    echo "\n13. Testing Cache Clear:\n";

    // Test clearing cache
    $totalTests++;
    $cache->set('clear_test', 'value', 60);
    $clearResult = $cache->clear();
    $clearedValue = $cache->get('clear_test');

    if ($clearResult && $clearedValue === null) {
        echo "   ✅ Cache clear: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Cache clear failed\n";
    }

    echo "\nCache System Test Summary:\n";
    echo "==========================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed === $totalTests) {
        echo "🎉 All cache system tests passed! System is working correctly.\n";
        echo "\nCache System Features:\n";
        echo "- Multiple cache drivers (memory, file, Redis-ready, Memcached-ready)\n";
        echo "- Configurable TTL and serialization options\n";
        echo "- Complex data type support with automatic serialization\n";
        echo "- Multiple operations (set/get/delete multiple keys)\n";
        echo "- Remember function for lazy loading\n";
        echo "- Increment/decrement for numeric values\n";
        echo "- Add operation for conditional setting\n";
        echo "- Comprehensive statistics and monitoring\n";
        echo "- Query-specific caching with automatic invalidation\n";
        echo "- Tag-based cache invalidation system\n";
        echo "- SQL query normalization and optimization\n";
        echo "- Automatic table extraction and tagging\n";
        echo "- Configurable result size limits and compression\n";
        echo "- Cache efficiency tracking and reporting\n";
        echo "- Preset configurations for different use cases\n";
    } else {
        echo "⚠️  Some cache system tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}