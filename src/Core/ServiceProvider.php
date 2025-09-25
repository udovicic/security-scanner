<?php

namespace SecurityScanner\Core;

abstract class ServiceProvider
{
    protected Container $container;
    protected Config $config;
    protected Logger $logger;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->config = Config::getInstance();
        $this->logger = Logger::access();
    }

    /**
     * Register services in the container
     */
    abstract public function register(): void;

    /**
     * Boot services after all providers have been registered
     */
    public function boot(): void
    {
        // Override in child classes if needed
    }

    /**
     * Get services provided by this provider
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Check if provider is deferred
     */
    public function isDeferred(): bool
    {
        return false;
    }

    /**
     * Get provider name
     */
    public function getName(): string
    {
        return static::class;
    }

    /**
     * Bind a service as singleton
     */
    protected function singleton(string $abstract, $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    /**
     * Bind a service
     */
    protected function bind(string $abstract, $concrete = null, bool $singleton = false): void
    {
        $this->container->bind($abstract, $concrete, $singleton);
    }

    /**
     * Bind an instance
     */
    protected function instance(string $abstract, object $instance): void
    {
        $this->container->instance($abstract, $instance);
    }

    /**
     * Create alias
     */
    protected function alias(string $alias, string $abstract): void
    {
        $this->container->alias($alias, $abstract);
    }

    /**
     * Tag services
     */
    protected function tag(array $services, string $tag): void
    {
        $this->container->tag($services, $tag);
    }

    /**
     * Log provider activity
     */
    protected function log(string $message, array $context = []): void
    {
        $this->logger->info($message, array_merge([
            'provider' => $this->getName()
        ], $context));
    }
}