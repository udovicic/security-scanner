<?php

namespace SecurityScanner\Tests;

class PluginManager
{
    private TestRegistry $registry;
    private array $config;
    private array $plugins = [];
    private array $hooks = [];

    public function __construct(TestRegistry $registry, array $config = [])
    {
        $this->registry = $registry;
        $this->config = array_merge([
            'plugin_directory' => __DIR__ . '/Plugins',
            'auto_load_plugins' => true,
            'enable_hooks' => true,
            'plugin_cache' => true,
            'sandbox_plugins' => true
        ], $config);

        if ($this->config['auto_load_plugins']) {
            $this->loadPlugins();
        }
    }

    /**
     * Load all plugins from plugin directory
     */
    public function loadPlugins(): void
    {
        $pluginDir = $this->config['plugin_directory'];

        if (!is_dir($pluginDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->loadPlugin($file->getPathname());
            }
        }
    }

    /**
     * Load individual plugin
     */
    public function loadPlugin(string $pluginFile): bool
    {
        try {
            require_once $pluginFile;

            $className = $this->getPluginClassName($pluginFile);
            if (!$className || !class_exists($className)) {
                return false;
            }

            if (!$this->isValidPlugin($className)) {
                return false;
            }

            $plugin = new $className();
            $pluginInfo = $plugin->getPluginInfo();

            $this->plugins[$pluginInfo['name']] = [
                'instance' => $plugin,
                'info' => $pluginInfo,
                'file' => $pluginFile,
                'enabled' => true,
                'loaded_at' => new \DateTime()
            ];

            // Register plugin tests with registry
            if (method_exists($plugin, 'registerTests')) {
                $plugin->registerTests($this->registry);
            }

            // Register plugin hooks
            if (method_exists($plugin, 'registerHooks')) {
                $hooks = $plugin->registerHooks();
                $this->registerHooks($hooks, $pluginInfo['name']);
            }

            return true;

        } catch (\Exception $e) {
            error_log("Failed to load plugin {$pluginFile}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get plugin class name from file
     */
    private function getPluginClassName(string $file): ?string
    {
        $content = file_get_contents($file);
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
     * Check if class is a valid plugin
     */
    private function isValidPlugin(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $reflection = new \ReflectionClass($className);

        // Check if it implements required methods
        $requiredMethods = ['getPluginInfo'];
        foreach ($requiredMethods as $method) {
            if (!$reflection->hasMethod($method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register hooks from plugin
     */
    private function registerHooks(array $hooks, string $pluginName): void
    {
        if (!$this->config['enable_hooks']) {
            return;
        }

        foreach ($hooks as $hookName => $callback) {
            if (!isset($this->hooks[$hookName])) {
                $this->hooks[$hookName] = [];
            }

            $this->hooks[$hookName][] = [
                'plugin' => $pluginName,
                'callback' => $callback,
                'registered_at' => new \DateTime()
            ];
        }
    }

    /**
     * Execute hook
     */
    public function executeHook(string $hookName, array $data = []): array
    {
        if (!isset($this->hooks[$hookName]) || !$this->config['enable_hooks']) {
            return $data;
        }

        foreach ($this->hooks[$hookName] as $hook) {
            if (!$this->isPluginEnabled($hook['plugin'])) {
                continue;
            }

            try {
                if (is_callable($hook['callback'])) {
                    $data = call_user_func($hook['callback'], $data);
                }
            } catch (\Exception $e) {
                error_log("Hook execution failed for {$hookName} in plugin {$hook['plugin']}: " . $e->getMessage());
            }
        }

        return $data;
    }

    /**
     * Enable plugin
     */
    public function enablePlugin(string $pluginName): bool
    {
        if (isset($this->plugins[$pluginName])) {
            $this->plugins[$pluginName]['enabled'] = true;

            // Re-register tests if plugin has them
            $plugin = $this->plugins[$pluginName]['instance'];
            if (method_exists($plugin, 'registerTests')) {
                $plugin->registerTests($this->registry);
            }

            return true;
        }

        return false;
    }

    /**
     * Disable plugin
     */
    public function disablePlugin(string $pluginName): bool
    {
        if (isset($this->plugins[$pluginName])) {
            $this->plugins[$pluginName]['enabled'] = false;

            // Unregister tests if plugin has them
            $plugin = $this->plugins[$pluginName]['instance'];
            if (method_exists($plugin, 'unregisterTests')) {
                $plugin->unregisterTests($this->registry);
            }

            return true;
        }

        return false;
    }

    /**
     * Check if plugin is enabled
     */
    public function isPluginEnabled(string $pluginName): bool
    {
        return $this->plugins[$pluginName]['enabled'] ?? false;
    }

    /**
     * Get all loaded plugins
     */
    public function getLoadedPlugins(): array
    {
        return array_map(function($plugin) {
            return [
                'info' => $plugin['info'],
                'enabled' => $plugin['enabled'],
                'loaded_at' => $plugin['loaded_at']->format('Y-m-d H:i:s')
            ];
        }, $this->plugins);
    }

    /**
     * Get plugin info
     */
    public function getPluginInfo(string $pluginName): ?array
    {
        return $this->plugins[$pluginName]['info'] ?? null;
    }

    /**
     * Unload plugin
     */
    public function unloadPlugin(string $pluginName): bool
    {
        if (!isset($this->plugins[$pluginName])) {
            return false;
        }

        // Disable first
        $this->disablePlugin($pluginName);

        // Remove hooks
        foreach ($this->hooks as $hookName => &$hooks) {
            $hooks = array_filter($hooks, fn($hook) => $hook['plugin'] !== $pluginName);
        }

        // Remove plugin
        unset($this->plugins[$pluginName]);

        return true;
    }

    /**
     * Reload plugin
     */
    public function reloadPlugin(string $pluginName): bool
    {
        if (!isset($this->plugins[$pluginName])) {
            return false;
        }

        $pluginFile = $this->plugins[$pluginName]['file'];
        $this->unloadPlugin($pluginName);
        return $this->loadPlugin($pluginFile);
    }

    /**
     * Get available hooks
     */
    public function getAvailableHooks(): array
    {
        return array_keys($this->hooks);
    }

    /**
     * Get hooks for specific event
     */
    public function getHooksForEvent(string $hookName): array
    {
        return $this->hooks[$hookName] ?? [];
    }

    /**
     * Validate plugin configuration
     */
    public function validatePlugin(string $pluginName): array
    {
        $issues = [];

        if (!isset($this->plugins[$pluginName])) {
            $issues[] = 'Plugin not loaded';
            return $issues;
        }

        $plugin = $this->plugins[$pluginName];
        $info = $plugin['info'];

        // Check required fields
        $requiredFields = ['name', 'version', 'description'];
        foreach ($requiredFields as $field) {
            if (!isset($info[$field]) || empty($info[$field])) {
                $issues[] = "Missing required field: {$field}";
            }
        }

        // Check dependencies
        if (isset($info['dependencies'])) {
            foreach ($info['dependencies'] as $dependency) {
                if (!$this->isDependencyMet($dependency)) {
                    $issues[] = "Unmet dependency: {$dependency}";
                }
            }
        }

        // Check conflicts
        if (isset($info['conflicts'])) {
            foreach ($info['conflicts'] as $conflict) {
                if ($this->isPluginEnabled($conflict)) {
                    $issues[] = "Conflicts with enabled plugin: {$conflict}";
                }
            }
        }

        return $issues;
    }

    /**
     * Check if dependency is met
     */
    private function isDependencyMet(string $dependency): bool
    {
        // Check for PHP extensions
        if (str_starts_with($dependency, 'ext:')) {
            $extension = substr($dependency, 4);
            return extension_loaded($extension);
        }

        // Check for other plugins
        if (str_starts_with($dependency, 'plugin:')) {
            $pluginName = substr($dependency, 7);
            return $this->isPluginEnabled($pluginName);
        }

        // Check for PHP version
        if (str_starts_with($dependency, 'php:')) {
            $version = substr($dependency, 4);
            return version_compare(PHP_VERSION, $version, '>=');
        }

        return true;
    }

    /**
     * Get plugin statistics
     */
    public function getPluginStatistics(): array
    {
        $total = count($this->plugins);
        $enabled = array_sum(array_map(fn($p) => $p['enabled'] ? 1 : 0, $this->plugins));
        $disabled = $total - $enabled;

        return [
            'total_plugins' => $total,
            'enabled_plugins' => $enabled,
            'disabled_plugins' => $disabled,
            'total_hooks' => count($this->hooks),
            'active_hooks' => array_sum(array_map('count', $this->hooks))
        ];
    }

    /**
     * Create plugin template
     */
    public function createPluginTemplate(string $pluginName, string $directory): bool
    {
        $template = $this->getPluginTemplate($pluginName);
        $fileName = $directory . '/' . $pluginName . 'Plugin.php';

        if (file_exists($fileName)) {
            return false; // File already exists
        }

        return file_put_contents($fileName, $template) !== false;
    }

    /**
     * Get plugin template code
     */
    private function getPluginTemplate(string $pluginName): string
    {
        $className = $pluginName . 'Plugin';

        return "<?php

namespace SecurityScanner\\Tests\\Plugins;

use SecurityScanner\\Tests\\{TestRegistry, AbstractTest, TestResult};

class {$className}
{
    public function getPluginInfo(): array
    {
        return [
            'name' => '{$pluginName}',
            'version' => '1.0.0',
            'description' => 'Description of {$pluginName} plugin',
            'author' => 'Your Name',
            'dependencies' => [],
            'conflicts' => []
        ];
    }

    public function registerTests(TestRegistry \$registry): void
    {
        // Register your test classes here
        // \$registry->registerTest(YourTestClass::class);
    }

    public function registerHooks(): array
    {
        return [
            'before_test_execution' => [\$this, 'beforeTestExecution'],
            'after_test_execution' => [\$this, 'afterTestExecution']
        ];
    }

    public function beforeTestExecution(array \$data): array
    {
        // Hook logic here
        return \$data;
    }

    public function afterTestExecution(array \$data): array
    {
        // Hook logic here
        return \$data;
    }
}";
    }
}