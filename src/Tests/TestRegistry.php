<?php

namespace SecurityScanner\Tests;

class TestRegistry
{
    private array $tests = [];
    private array $categories = [];
    private array $enabledTests = [];
    private array $disabledTests = [];
    private array $config;
    private string $testsDirectory;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_discover' => true,
            'tests_directory' => __DIR__ . '/SecurityTests',
            'cache_enabled' => true,
            'cache_file' => sys_get_temp_dir() . '/security_scanner_tests.cache',
            'cache_ttl' => 3600,
            'namespace_prefix' => 'SecurityScanner\\Tests\\SecurityTests\\',
            'allowed_categories' => [],
            'default_enabled' => true
        ], $config);

        $this->testsDirectory = $this->config['tests_directory'];

        if ($this->config['auto_discover']) {
            $this->discoverTests();
        }
    }

    /**
     * Discover all test classes automatically
     */
    public function discoverTests(): void
    {
        // Try to load from cache first
        if ($this->config['cache_enabled'] && $this->loadFromCache()) {
            return;
        }

        $this->tests = [];
        $this->categories = [];

        // Scan the tests directory
        if (is_dir($this->testsDirectory)) {
            $this->scanDirectory($this->testsDirectory);
        }

        // Save to cache
        if ($this->config['cache_enabled']) {
            $this->saveToCache();
        }

        $this->organizeByCategories();
    }

    /**
     * Scan directory for test files
     */
    private function scanDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->loadTestFromFile($file->getPathname());
            }
        }
    }

    /**
     * Load test class from file
     */
    private function loadTestFromFile(string $filePath): void
    {
        // Get class name from file
        $className = $this->getClassNameFromFile($filePath);

        if (!$className) {
            return;
        }

        // Check if class exists
        if (!class_exists($className)) {
            require_once $filePath;
        }

        if (!class_exists($className)) {
            return;
        }

        // Check if it's a valid test class
        if (!is_subclass_of($className, AbstractTest::class)) {
            return;
        }

        try {
            $reflection = new \ReflectionClass($className);
            if ($reflection->isAbstract()) {
                return;
            }

            // Create instance to get metadata
            $testInstance = new $className();
            $testInfo = $testInstance->getTestInfo();

            $this->registerTest($className, $testInfo);
        } catch (\Exception $e) {
            // Skip tests that can't be instantiated
            error_log("Failed to load test {$className}: " . $e->getMessage());
        }
    }

    /**
     * Get class name from PHP file
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return null;
        }

        // Extract namespace
        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        if (preg_match('/^class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
            return $namespace ? $namespace . '\\' . $className : $className;
        }

        return null;
    }

    /**
     * Register a test class
     */
    public function registerTest(string $className, array $testInfo = null): void
    {
        if (!$testInfo) {
            if (!class_exists($className)) {
                throw new \InvalidArgumentException("Class {$className} does not exist");
            }

            if (!is_subclass_of($className, AbstractTest::class)) {
                throw new \InvalidArgumentException("Class {$className} must extend AbstractTest");
            }

            $testInstance = new $className();
            $testInfo = $testInstance->getTestInfo();
        }

        $testName = $testInfo['name'];
        $category = $testInfo['category'];

        // Check if category is allowed
        if (!empty($this->config['allowed_categories']) &&
            !in_array($category, $this->config['allowed_categories'])) {
            return;
        }

        $this->tests[$testName] = [
            'class' => $className,
            'info' => $testInfo,
            'enabled' => $this->config['default_enabled']
        ];

        // Track categories
        if (!isset($this->categories[$category])) {
            $this->categories[$category] = [];
        }
        $this->categories[$category][] = $testName;
    }

    /**
     * Unregister a test
     */
    public function unregisterTest(string $testName): void
    {
        if (isset($this->tests[$testName])) {
            $category = $this->tests[$testName]['info']['category'];
            unset($this->tests[$testName]);

            // Remove from category
            if (isset($this->categories[$category])) {
                $this->categories[$category] = array_filter(
                    $this->categories[$category],
                    fn($name) => $name !== $testName
                );

                // Remove empty category
                if (empty($this->categories[$category])) {
                    unset($this->categories[$category]);
                }
            }
        }
    }

    /**
     * Get all registered tests
     */
    public function getAllTests(): array
    {
        return $this->tests;
    }

    /**
     * Get test by name
     */
    public function getTest(string $testName): ?array
    {
        return $this->tests[$testName] ?? null;
    }

    /**
     * Get tests by category
     */
    public function getTestsByCategory(string $category): array
    {
        $categoryTests = $this->categories[$category] ?? [];
        $tests = [];

        foreach ($categoryTests as $testName) {
            if (isset($this->tests[$testName])) {
                $tests[$testName] = $this->tests[$testName];
            }
        }

        return $tests;
    }

    /**
     * Get tests by tags
     */
    public function getTestsByTags(array $tags): array
    {
        $matchingTests = [];

        foreach ($this->tests as $testName => $testData) {
            $testTags = $testData['info']['tags'] ?? [];

            // Check if any of the requested tags match
            if (array_intersect($tags, $testTags)) {
                $matchingTests[$testName] = $testData;
            }
        }

        return $matchingTests;
    }

    /**
     * Get enabled tests
     */
    public function getEnabledTests(): array
    {
        return array_filter($this->tests, fn($test) => $test['enabled']);
    }

    /**
     * Get disabled tests
     */
    public function getDisabledTests(): array
    {
        return array_filter($this->tests, fn($test) => !$test['enabled']);
    }

    /**
     * Enable test
     */
    public function enableTest(string $testName): bool
    {
        if (isset($this->tests[$testName])) {
            $this->tests[$testName]['enabled'] = true;
            return true;
        }
        return false;
    }

    /**
     * Disable test
     */
    public function disableTest(string $testName): bool
    {
        if (isset($this->tests[$testName])) {
            $this->tests[$testName]['enabled'] = false;
            return true;
        }
        return false;
    }

    /**
     * Enable tests by category
     */
    public function enableCategory(string $category): int
    {
        $count = 0;
        $categoryTests = $this->categories[$category] ?? [];

        foreach ($categoryTests as $testName) {
            if ($this->enableTest($testName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Disable tests by category
     */
    public function disableCategory(string $category): int
    {
        $count = 0;
        $categoryTests = $this->categories[$category] ?? [];

        foreach ($categoryTests as $testName) {
            if ($this->disableTest($testName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Enable tests by tags
     */
    public function enableByTags(array $tags): int
    {
        $count = 0;
        $matchingTests = $this->getTestsByTags($tags);

        foreach ($matchingTests as $testName => $testData) {
            if ($this->enableTest($testName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Disable tests by tags
     */
    public function disableByTags(array $tags): int
    {
        $count = 0;
        $matchingTests = $this->getTestsByTags($tags);

        foreach ($matchingTests as $testName => $testData) {
            if ($this->disableTest($testName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create test instance
     */
    public function createTestInstance(string $testName, array $config = []): ?AbstractTest
    {
        $testData = $this->getTest($testName);
        if (!$testData) {
            return null;
        }

        $className = $testData['class'];
        if (!class_exists($className)) {
            return null;
        }

        try {
            return new $className($config);
        } catch (\Exception $e) {
            error_log("Failed to create test instance {$testName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all categories
     */
    public function getCategories(): array
    {
        return array_keys($this->categories);
    }

    /**
     * Get category statistics
     */
    public function getCategoryStats(): array
    {
        $stats = [];

        foreach ($this->categories as $category => $tests) {
            $enabled = 0;
            $disabled = 0;

            foreach ($tests as $testName) {
                if (isset($this->tests[$testName])) {
                    if ($this->tests[$testName]['enabled']) {
                        $enabled++;
                    } else {
                        $disabled++;
                    }
                }
            }

            $stats[$category] = [
                'total' => count($tests),
                'enabled' => $enabled,
                'disabled' => $disabled
            ];
        }

        return $stats;
    }

    /**
     * Get all tags used by tests
     */
    public function getAllTags(): array
    {
        $allTags = [];

        foreach ($this->tests as $testData) {
            $tags = $testData['info']['tags'] ?? [];
            $allTags = array_merge($allTags, $tags);
        }

        return array_unique($allTags);
    }

    /**
     * Search tests by name or description
     */
    public function searchTests(string $query): array
    {
        $query = strtolower($query);
        $results = [];

        foreach ($this->tests as $testName => $testData) {
            $name = strtolower($testData['info']['name']);
            $description = strtolower($testData['info']['description'] ?? '');

            if (str_contains($name, $query) || str_contains($description, $query)) {
                $results[$testName] = $testData;
            }
        }

        return $results;
    }

    /**
     * Get registry statistics
     */
    public function getStatistics(): array
    {
        $enabled = count($this->getEnabledTests());
        $disabled = count($this->getDisabledTests());

        return [
            'total_tests' => count($this->tests),
            'enabled_tests' => $enabled,
            'disabled_tests' => $disabled,
            'total_categories' => count($this->categories),
            'total_tags' => count($this->getAllTags()),
            'categories' => $this->getCategoryStats()
        ];
    }

    /**
     * Validate test dependencies
     */
    public function validateDependencies(): array
    {
        $issues = [];

        foreach ($this->tests as $testName => $testData) {
            $requirements = $testData['info']['metadata']['requires'] ?? [];
            $conflicts = $testData['info']['metadata']['conflicts'] ?? [];

            // Check requirements
            foreach ($requirements as $requirement) {
                if (!$this->checkRequirement($requirement)) {
                    $issues[] = [
                        'test' => $testName,
                        'type' => 'missing_requirement',
                        'requirement' => $requirement
                    ];
                }
            }

            // Check conflicts
            foreach ($conflicts as $conflict) {
                if ($this->hasTest($conflict) && $this->isTestEnabled($conflict)) {
                    $issues[] = [
                        'test' => $testName,
                        'type' => 'conflict',
                        'conflict_with' => $conflict
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Check if test exists
     */
    public function hasTest(string $testName): bool
    {
        return isset($this->tests[$testName]);
    }

    /**
     * Check if test is enabled
     */
    public function isTestEnabled(string $testName): bool
    {
        return $this->tests[$testName]['enabled'] ?? false;
    }

    /**
     * Check requirement
     */
    private function checkRequirement(string $requirement): bool
    {
        // Check PHP extensions
        if (str_starts_with($requirement, 'ext:')) {
            $extension = substr($requirement, 4);
            return extension_loaded($extension);
        }

        // Check PHP functions
        if (str_starts_with($requirement, 'func:')) {
            $function = substr($requirement, 5);
            return function_exists($function);
        }

        // Check PHP version
        if (str_starts_with($requirement, 'php:')) {
            $version = substr($requirement, 4);
            return version_compare(PHP_VERSION, $version, '>=');
        }

        // Check other tests
        if (str_starts_with($requirement, 'test:')) {
            $testName = substr($requirement, 5);
            return $this->hasTest($testName) && $this->isTestEnabled($testName);
        }

        return true;
    }

    /**
     * Organize tests by categories
     */
    private function organizeByCategories(): void
    {
        $this->categories = [];

        foreach ($this->tests as $testName => $testData) {
            $category = $testData['info']['category'];
            if (!isset($this->categories[$category])) {
                $this->categories[$category] = [];
            }
            $this->categories[$category][] = $testName;
        }
    }

    /**
     * Load registry from cache
     */
    private function loadFromCache(): bool
    {
        $cacheFile = $this->config['cache_file'];

        if (!file_exists($cacheFile)) {
            return false;
        }

        // Check cache age
        if (time() - filemtime($cacheFile) > $this->config['cache_ttl']) {
            return false;
        }

        $cached = file_get_contents($cacheFile);
        if (!$cached) {
            return false;
        }

        $data = unserialize($cached);
        if ($data === false) {
            return false;
        }

        $this->tests = $data['tests'] ?? [];
        $this->categories = $data['categories'] ?? [];

        return true;
    }

    /**
     * Save registry to cache
     */
    private function saveToCache(): void
    {
        $cacheFile = $this->config['cache_file'];
        $cacheDir = dirname($cacheFile);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $data = [
            'tests' => $this->tests,
            'categories' => $this->categories,
            'cached_at' => time()
        ];

        file_put_contents($cacheFile, serialize($data));
    }

    /**
     * Clear cache
     */
    public function clearCache(): bool
    {
        $cacheFile = $this->config['cache_file'];

        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }

        return true;
    }

    /**
     * Export registry configuration
     */
    public function exportConfig(): array
    {
        $config = [];

        foreach ($this->tests as $testName => $testData) {
            $config[$testName] = [
                'enabled' => $testData['enabled'],
                'config' => $testData['info']['config'] ?? []
            ];
        }

        return $config;
    }

    /**
     * Import registry configuration
     */
    public function importConfig(array $config): void
    {
        foreach ($config as $testName => $testConfig) {
            if (isset($this->tests[$testName])) {
                $this->tests[$testName]['enabled'] = $testConfig['enabled'] ?? true;

                if (isset($testConfig['config'])) {
                    $this->tests[$testName]['info']['config'] = array_merge(
                        $this->tests[$testName]['info']['config'] ?? [],
                        $testConfig['config']
                    );
                }
            }
        }
    }
}