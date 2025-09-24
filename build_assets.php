<?php

// Asset Build Script
// Usage: php build_assets.php [--clear-cache]

require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\AssetManager;

echo "Security Scanner Asset Builder\n";
echo "==============================\n\n";

// Parse command line arguments
$clearCache = in_array('--clear-cache', $argv);

try {
    $assetManager = AssetManager::getInstance();

    // Clear cache if requested
    if ($clearCache) {
        echo "Clearing build cache...\n";
        if ($assetManager->clearBuildCache()) {
            echo "✓ Build cache cleared successfully\n\n";
        } else {
            echo "✗ Failed to clear build cache\n\n";
        }
    }

    // Get current asset stats
    echo "Current Asset Statistics:\n";
    $stats = $assetManager->getAssetStats();
    echo "- CSS files: {$stats['css_files']}\n";
    echo "- JS files: {$stats['js_files']}\n";
    echo "- Image files: {$stats['image_files']}\n";
    echo "- Total size: " . formatBytes($stats['total_size']) . "\n\n";

    // Build assets
    echo "Building assets...\n";
    $startTime = microtime(true);
    $built = $assetManager->buildAssets();
    $buildTime = round((microtime(true) - $startTime) * 1000, 2);

    if (empty($built)) {
        echo "✓ No assets to build\n";
    } else {
        echo "✓ Built " . count($built) . " assets in {$buildTime}ms\n\n";

        // Show build results
        echo "Build Results:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-20s %-20s %-12s %-12s %-10s\n", "Type", "File", "Original", "Built", "Savings");
        echo str_repeat("-", 80) . "\n";

        $totalOriginal = 0;
        $totalBuilt = 0;

        foreach ($built as $asset) {
            $savings = $asset['size_original'] > 0
                ? round((($asset['size_original'] - $asset['size_built']) / $asset['size_original']) * 100, 1) . '%'
                : '0%';

            printf("%-20s %-20s %-12s %-12s %-10s\n",
                strtoupper($asset['type']),
                $asset['original'],
                formatBytes($asset['size_original']),
                formatBytes($asset['size_built']),
                $savings
            );

            $totalOriginal += $asset['size_original'];
            $totalBuilt += $asset['size_built'];
        }

        echo str_repeat("-", 80) . "\n";
        $totalSavings = $totalOriginal > 0
            ? round((($totalOriginal - $totalBuilt) / $totalOriginal) * 100, 1) . '%'
            : '0%';

        printf("%-20s %-20s %-12s %-12s %-10s\n",
            "TOTAL", "", formatBytes($totalOriginal), formatBytes($totalBuilt), $totalSavings
        );
        echo str_repeat("-", 80) . "\n";
    }

    // Show manifest
    echo "\nGenerated Manifest:\n";
    $manifest = $assetManager->getManifest();
    if (empty($manifest)) {
        echo "- No manifest entries\n";
    } else {
        foreach ($manifest as $original => $built) {
            echo "- {$original} -> {$built}\n";
        }
    }

    echo "\n✓ Asset build completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Asset build failed: " . $e->getMessage() . "\n";
    if (isset($argv) && in_array('--debug', $argv)) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}