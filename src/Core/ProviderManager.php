<?php

namespace SecurityScanner\Core;

class ProviderManager
{
    private Container $container;
    private array $providers = [];
    private array $deferredProviders = [];
    private array $loadedProviders = [];
    private Logger $logger;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->logger = Logger::access();
    }

    /**
     * Register a service provider
     */
    public function register(string $providerClass): void
    {
        if (in_array($providerClass, $this->loadedProviders)) {
            return;
        }

        $provider = new $providerClass($this->container);

        if (!$provider instanceof ServiceProvider) {
            throw new ContainerException(
                "Provider must extend ServiceProvider class",
                $providerClass
            );
        }

        $this->providers[$providerClass] = $provider;

        if ($provider->isDeferred()) {
            $this->registerDeferredProvider($provider);
        } else {
            $this->registerProvider($provider);
        }

        $this->loadedProviders[] = $providerClass;
    }

    /**
     * Register multiple providers
     */
    public function registerProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * Boot all registered providers
     */
    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isDeferred()) {
                $provider->boot();
            }
        }

        $this->logger->info('Service providers booted', [
            'total_providers' => count($this->providers),
            'deferred_providers' => count($this->deferredProviders)
        ]);
    }

    /**
     * Get a provider instance
     */
    public function getProvider(string $providerClass): ?ServiceProvider
    {
        return $this->providers[$providerClass] ?? null;
    }

    /**
     * Check if provider is registered
     */
    public function hasProvider(string $providerClass): bool
    {
        return isset($this->providers[$providerClass]);
    }

    /**
     * Get all registered providers
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get loaded providers
     */
    public function getLoadedProviders(): array
    {
        return $this->loadedProviders;
    }

    /**
     * Register a provider immediately
     */
    private function registerProvider(ServiceProvider $provider): void
    {
        try {
            $startTime = microtime(true);

            $provider->register();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->debug('Service provider registered', [
                'provider' => $provider->getName(),
                'execution_time' => $executionTime . 'ms',
                'services' => $provider->provides()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Service provider registration failed', [
                'provider' => $provider->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new ContainerException(
                "Failed to register provider {$provider->getName()}: " . $e->getMessage(),
                $provider->getName(),
                [],
                0,
                $e
            );
        }
    }

    /**
     * Register a deferred provider
     */
    private function registerDeferredProvider(ServiceProvider $provider): void
    {
        foreach ($provider->provides() as $service) {
            $this->deferredProviders[$service] = $provider;
        }

        $this->logger->debug('Deferred service provider registered', [
            'provider' => $provider->getName(),
            'services' => $provider->provides()
        ]);
    }

    /**
     * Load a deferred provider when needed
     */
    public function loadDeferred(string $service): void
    {
        if (isset($this->deferredProviders[$service])) {
            $provider = $this->deferredProviders[$service];

            $this->logger->debug('Loading deferred provider', [
                'provider' => $provider->getName(),
                'service' => $service
            ]);

            $this->registerProvider($provider);
            $provider->boot();

            // Remove from deferred list
            foreach ($provider->provides() as $providedService) {
                unset($this->deferredProviders[$providedService]);
            }
        }
    }

    /**
     * Get provider statistics
     */
    public function getStats(): array
    {
        return [
            'total_providers' => count($this->providers),
            'loaded_providers' => count($this->loadedProviders),
            'deferred_providers' => count($this->deferredProviders),
            'provider_names' => array_keys($this->providers)
        ];
    }
}