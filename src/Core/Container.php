<?php

namespace SecurityScanner\Core;

class Container
{
    private static ?Container $instance = null;
    private array $services = [];
    private array $instances = [];
    private array $singletons = [];
    private array $aliases = [];
    private array $tags = [];
    private Logger $logger;
    private ?LazyLoader $lazyLoader = null;
    private bool $lazyLoadingEnabled = false;

    private function __construct()
    {
        $this->logger = Logger::access();
    }

    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, $concrete = null, bool $singleton = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->services[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton,
            'resolved' => false
        ];

        if ($singleton) {
            $this->singletons[] = $abstract;
        }

        $this->logger->debug('Service bound to container', [
            'abstract' => $abstract,
            'concrete' => is_string($concrete) ? $concrete : get_class($concrete),
            'singleton' => $singleton
        ]);
    }

    /**
     * Bind a singleton service
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Bind an existing instance
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->services[$abstract] = [
            'concrete' => get_class($instance),
            'singleton' => true,
            'resolved' => true
        ];

        $this->logger->debug('Instance bound to container', [
            'abstract' => $abstract,
            'class' => get_class($instance)
        ]);
    }

    /**
     * Create an alias for a service
     */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Tag services for group resolution
     */
    public function tag(array $services, string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }

        foreach ($services as $service) {
            $this->tags[$tag][] = $service;
        }
    }

    /**
     * Resolve a service from the container
     */
    public function get(string $abstract)
    {
        // Check for alias
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        // Return existing instance if singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve the service
        $instance = $this->resolve($abstract);

        // Store instance if singleton
        if (isset($this->services[$abstract]) && $this->services[$abstract]['singleton']) {
            $this->instances[$abstract] = $instance;
            $this->services[$abstract]['resolved'] = true;
        }

        return $instance;
    }

    /**
     * Make a new instance (always creates new, ignores singletons)
     */
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters, false);
    }

    /**
     * Check if service is bound
     */
    public function has(string $abstract): bool
    {
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        return isset($this->services[$abstract]) ||
               isset($this->instances[$abstract]) ||
               class_exists($abstract);
    }

    /**
     * Get all services tagged with a tag
     */
    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        $services = [];
        foreach ($this->tags[$tag] as $service) {
            $services[] = $this->get($service);
        }

        return $services;
    }

    /**
     * Forget a service binding
     */
    public function forget(string $abstract): void
    {
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        unset($this->services[$abstract]);
        unset($this->instances[$abstract]);

        $key = array_search($abstract, $this->singletons);
        if ($key !== false) {
            unset($this->singletons[$key]);
        }
    }

    /**
     * Flush all services
     */
    public function flush(): void
    {
        $this->services = [];
        $this->instances = [];
        $this->singletons = [];
        $this->aliases = [];
        $this->tags = [];
    }

    /**
     * Get container statistics
     */
    public function getStats(): array
    {
        return [
            'total_services' => count($this->services),
            'resolved_instances' => count($this->instances),
            'singletons' => count($this->singletons),
            'aliases' => count($this->aliases),
            'tags' => count($this->tags)
        ];
    }

    /**
     * Resolve service from container
     */
    private function resolve(string $abstract, array $parameters = [], bool $respectSingleton = true)
    {
        try {
            // Check if we have a bound service
            if (isset($this->services[$abstract])) {
                $concrete = $this->services[$abstract]['concrete'];

                // If singleton and already resolved, return instance
                if ($respectSingleton &&
                    $this->services[$abstract]['singleton'] &&
                    isset($this->instances[$abstract])) {
                    return $this->instances[$abstract];
                }

                // If concrete is a closure
                if ($concrete instanceof \Closure) {
                    return $concrete($this, $parameters);
                }

                // If concrete is a string, resolve it
                if (is_string($concrete)) {
                    return $this->build($concrete, $parameters);
                }

                // If concrete is an object, return it
                if (is_object($concrete)) {
                    return $concrete;
                }
            }

            // Try to build the class directly
            return $this->build($abstract, $parameters);

        } catch (\Exception $e) {
            $this->logger->error('Container resolution failed', [
                'abstract' => $abstract,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new ContainerException(
                "Unable to resolve service '{$abstract}': " . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Build a class instance with dependency injection
     */
    private function build(string $className, array $parameters = [])
    {
        if (!class_exists($className)) {
            throw new ContainerException("Class '{$className}' does not exist");
        }

        $reflection = new \ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class '{$className}' is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        // If no constructor, just create instance
        if ($constructor === null) {
            return new $className();
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $parameters
        );

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     */
    private function resolveDependencies(array $parameters, array $providedParams = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            // Use provided parameter if available
            if (array_key_exists($name, $providedParams)) {
                $dependencies[] = $providedParams[$name];
                continue;
            }

            // Try to resolve type-hinted dependency
            $type = $parameter->getType();

            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                $dependencies[] = $this->get($typeName);
                continue;
            }

            // Use default value if available
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // Check if parameter is nullable
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            throw new ContainerException(
                "Unable to resolve parameter '{$name}' for class " .
                $parameter->getDeclaringClass()->getName()
            );
        }

        return $dependencies;
    }

    /**
     * Enable lazy loading for dependencies
     */
    public function enableLazyLoading(): void
    {
        $this->lazyLoadingEnabled = true;
        $this->lazyLoader = LazyLoader::getInstance();

        $this->logger->info('Lazy loading enabled for container');
    }

    /**
     * Register a lazy-loaded service
     */
    public function lazy(string $abstract, callable $factory, array $dependencies = [], bool $singleton = true): void
    {
        if (!$this->lazyLoadingEnabled) {
            $this->enableLazyLoading();
        }

        $this->lazyLoader->registerWithDependencies($abstract, $factory, $dependencies, $singleton);

        // Also register in container for compatibility
        $this->bind($abstract, function() use ($abstract) {
            return $this->lazyLoader->get($abstract);
        }, $singleton);

        $this->logger->debug('Lazy service registered', [
            'abstract' => $abstract,
            'dependencies' => $dependencies,
            'singleton' => $singleton
        ]);
    }

    /**
     * Preload lazy dependencies
     */
    public function preload(array $keys = []): void
    {
        if ($this->lazyLoader) {
            $this->lazyLoader->preload($keys);
        }
    }

    /**
     * Get lazy loading statistics
     */
    public function getLazyStats(): array
    {
        if (!$this->lazyLoader) {
            return [
                'enabled' => false,
                'stats' => []
            ];
        }

        return [
            'enabled' => true,
            'stats' => $this->lazyLoader->getLoadStats()
        ];
    }

    /**
     * Register core services
     */
    public function registerCoreServices(): void
    {
        // Enable lazy loading first
        $this->enableLazyLoading();

        // Register core lazy-loaded singletons
        $this->lazy(Config::class, function() {
            return Config::getInstance();
        });

        $this->lazy(Database::class, function() {
            return Database::getInstance();
        }, [Config::class]);

        $this->lazy(QueryOptimizer::class, function() {
            return new QueryOptimizer(
                $this->get(Database::class),
                $this->get(Config::class)->get('query_optimizer', [])
            );
        }, [Database::class, Config::class]);

        $this->lazy(AssetLazyLoader::class, function() {
            return AssetLazyLoader::getInstance();
        });

        $this->lazy('logger.access', function() {
            return Logger::access();
        });

        $this->lazy('logger.error', function() {
            return Logger::errors();
        });

        $this->lazy('logger.security', function() {
            return Logger::security();
        });

        $this->lazy('logger.scheduler', function() {
            return Logger::scheduler();
        });

        // Create aliases
        $this->alias('config', Config::class);
        $this->alias('db', Database::class);
        $this->alias('database', Database::class);
        $this->alias('query_optimizer', QueryOptimizer::class);
        $this->alias('asset_loader', AssetLazyLoader::class);

        // Tag loggers
        $this->tag([
            'logger.access',
            'logger.error',
            'logger.security',
            'logger.scheduler'
        ], 'loggers');

        $this->logger->info('Core services registered in container with lazy loading');
    }

    /**
     * Call a method with dependency injection
     */
    public function call(callable $callback, array $parameters = [])
    {
        if (is_array($callback)) {
            [$class, $method] = $callback;

            if (is_string($class)) {
                $class = $this->get($class);
            }

            $reflection = new \ReflectionMethod($class, $method);
            $dependencies = $this->resolveDependencies(
                $reflection->getParameters(),
                $parameters
            );

            return $reflection->invokeArgs($class, $dependencies);
        } else {
            $reflection = new \ReflectionFunction($callback);
            $dependencies = $this->resolveDependencies(
                $reflection->getParameters(),
                $parameters
            );

            return $reflection->invokeArgs($dependencies);
        }
    }

    /**
     * Get all bound services
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Get all instances
     */
    public function getInstances(): array
    {
        return $this->instances;
    }

    /**
     * Magic method to resolve services
     */
    public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}