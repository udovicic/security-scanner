<?php

// Test script to verify logging system works correctly
require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\Logger;
use SecurityScanner\Core\ErrorHandler;

echo "Testing Security Scanner Logging System...\n\n";

try {
    // Test different log channels
    echo "1. Testing different log channels:\n";

    Logger::access()->info('User accessed dashboard', ['user_id' => 123, 'ip' => '192.168.1.100']);
    Logger::errors()->warning('Database query took longer than expected', ['query_time' => 2.5]);
    Logger::scheduler()->info('Starting scheduled scan', ['website_id' => 456]);
    Logger::security()->warning('Multiple failed login attempts', ['ip' => '192.168.1.200', 'attempts' => 5]);

    echo "✓ All log channels working\n\n";

    // Test different log levels
    echo "2. Testing log levels:\n";

    Logger::errors()->debug('Debug message');
    Logger::errors()->info('Info message');
    Logger::errors()->warning('Warning message');
    Logger::errors()->error('Error message');
    Logger::errors()->critical('Critical message');

    echo "✓ All log levels working\n\n";

    // Test security event logging
    echo "3. Testing security event logging:\n";

    ErrorHandler::logSecurityEvent('brute_force', 'Multiple failed login attempts detected', [
        'ip' => '192.168.1.200',
        'username' => 'admin',
        'attempts' => 10
    ]);

    echo "✓ Security event logging working\n\n";

    // Test context data
    echo "4. Testing context logging:\n";

    Logger::access()->info('Page view recorded', [
        'url' => '/dashboard',
        'response_time' => 0.25,
        'memory_usage' => memory_get_usage(),
        'user_agent' => 'Mozilla/5.0 Test Browser'
    ]);

    echo "✓ Context logging working\n\n";

    echo "All tests completed successfully!\n";
    echo "Check the logs directory for output files:\n";
    echo "- logs/access.log\n";
    echo "- logs/error.log\n";
    echo "- logs/scheduler.log\n";
    echo "- logs/security.log\n";

} catch (Exception $e) {
    echo "Test failed with error: " . $e->getMessage() . "\n";
    exit(1);
}