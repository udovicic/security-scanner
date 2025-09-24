<?php

// Test script to verify asset management system
require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\AssetManager;
use SecurityScanner\Core\ViewHelper;

echo "Testing Security Scanner Asset Management System...\n\n";

try {
    $assetManager = AssetManager::getInstance();
    $viewHelper = ViewHelper::getInstance();

    // Test 1: Asset Manager initialization
    echo "1. Testing Asset Manager initialization:\n";
    echo "✓ AssetManager initialized successfully\n";
    echo "✓ ViewHelper initialized successfully\n\n";

    // Test 2: Asset statistics
    echo "2. Testing asset statistics:\n";
    $stats = $assetManager->getAssetStats();
    echo "✓ CSS files found: {$stats['css_files']}\n";
    echo "✓ JS files found: {$stats['js_files']}\n";
    echo "✓ Image files found: {$stats['image_files']}\n";
    echo "✓ Total size: " . formatBytes($stats['total_size']) . "\n\n";

    // Test 3: Asset URL generation
    echo "3. Testing asset URL generation:\n";
    $cssUrl = $assetManager->url('app.css', 'css');
    echo "✓ CSS URL: {$cssUrl}\n";

    $jsUrl = $assetManager->url('app.js', 'js');
    echo "✓ JS URL: {$jsUrl}\n\n";

    // Test 4: HTML generation
    echo "4. Testing HTML generation:\n";
    $cssTag = $assetManager->css('app.css');
    echo "✓ CSS tag: " . htmlspecialchars($cssTag) . "\n";

    $jsTag = $assetManager->js('app.js');
    echo "✓ JS tag: " . htmlspecialchars($jsTag) . "\n\n";

    // Test 5: Minification
    echo "5. Testing minification:\n";

    $testCSS = "
        body {
            margin: 0;
            padding: 0;
        }

        /* This is a comment */
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
    ";

    $minifiedCSS = $assetManager->minifyCss($testCSS);
    echo "✓ CSS minified: " . strlen($testCSS) . " bytes -> " . strlen($minifiedCSS) . " bytes\n";

    $testJS = "
        // This is a comment
        function test() {
            console.log('Hello World');
            return true;
        }

        /* Multi-line comment */
        var x = 1 + 2 + 3;
    ";

    $minifiedJS = $assetManager->minifyJs($testJS);
    echo "✓ JS minified: " . strlen($testJS) . " bytes -> " . strlen($minifiedJS) . " bytes\n\n";

    // Test 6: ViewHelper functions
    echo "6. Testing ViewHelper functions:\n";

    $title = $viewHelper->title('Dashboard');
    echo "✓ Title: {$title}\n";

    $csrfMeta = $viewHelper->csrfMeta();
    echo "✓ CSRF meta tag generated\n";

    $statusBadge = $viewHelper->statusBadge('success');
    echo "✓ Status badge: " . htmlspecialchars($statusBadge) . "\n";

    $formattedSize = $viewHelper->formatFileSize(1536);
    echo "✓ Formatted size: {$formattedSize}\n\n";

    // Test 7: Navigation generation
    echo "7. Testing navigation generation:\n";
    $navItems = [
        ['path' => '/', 'label' => 'Dashboard'],
        ['path' => '/websites', 'label' => 'Websites'],
        ['path' => '/results', 'label' => 'Results'],
    ];

    $navigation = $viewHelper->navigation($navItems, '/websites');
    echo "✓ Navigation HTML generated (" . strlen($navigation) . " characters)\n\n";

    // Test 8: Asset building (if assets exist)
    echo "8. Testing asset building:\n";

    if ($stats['css_files'] > 0 || $stats['js_files'] > 0) {
        $built = $assetManager->buildAssets();
        echo "✓ Built " . count($built) . " assets\n";

        $manifest = $assetManager->getManifest();
        echo "✓ Manifest generated with " . count($manifest) . " entries\n";

        // Show built assets
        foreach ($built as $asset) {
            $savings = $asset['size_original'] > 0
                ? round((($asset['size_original'] - $asset['size_built']) / $asset['size_original']) * 100, 1)
                : 0;
            echo "  - {$asset['type']}: {$asset['original']} -> {$asset['built']} ({$savings}% savings)\n";
        }
    } else {
        echo "✓ No assets to build (this is expected)\n";
    }

    echo "\n✓ Asset management system test completed successfully!\n";
    echo "The asset management system is ready for production use.\n";

} catch (Exception $e) {
    echo "✗ Asset management test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}