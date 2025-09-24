<?php

// Test script to verify the entry point works correctly
echo "Testing Security Scanner Entry Point...\n\n";

// Test the entry point by capturing its output
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/health';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'Test Browser';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

try {
    // Capture output buffer
    ob_start();

    // Include the entry point
    include __DIR__ . '/public/index.php';

    // Get the output
    $output = ob_get_clean();

    // Check if we got JSON output
    $healthData = json_decode($output, true);

    if ($healthData && isset($healthData['status'])) {
        echo "✓ Entry point working correctly\n";
        echo "✓ Health check endpoint accessible\n";
        echo "✓ Status: " . $healthData['status'] . "\n";
        echo "✓ Environment: " . ($healthData['environment'] ?? 'unknown') . "\n";
        echo "✓ Database: " . ($healthData['database'] ?? 'unknown') . "\n";
    } else {
        echo "✗ Unexpected output from entry point:\n";
        echo $output . "\n";
    }

} catch (\Exception $e) {
    // Clean output buffer if there's an error
    if (ob_get_level()) {
        ob_end_clean();
    }

    echo "Entry point test failed: " . $e->getMessage() . "\n";
    echo "This is expected if controllers are not yet implemented.\n";
}

echo "\nEntry point test completed.\n";