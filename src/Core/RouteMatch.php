<?php

namespace SecurityScanner\Core;

class RouteMatch
{
    private Route $route;
    private array $parameters;

    public function __construct(Route $route, array $parameters = [])
    {
        $this->route = $route;
        $this->parameters = $parameters;
    }

    /**
     * Get the matched route
     */
    public function getRoute(): Route
    {
        return $this->route;
    }

    /**
     * Get route parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get specific parameter value
     */
    public function getParameter(string $name, $default = null)
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Check if parameter exists
     */
    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    /**
     * Get route handler
     */
    public function getHandler()
    {
        return $this->route->getHandler();
    }

    /**
     * Get route method
     */
    public function getMethod(): string
    {
        return $this->route->getMethod();
    }

    /**
     * Get route path
     */
    public function getPath(): string
    {
        return $this->route->getPath();
    }

    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->route->getMiddleware();
    }

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->route->getName();
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route->toArray(),
            'parameters' => $this->parameters
        ];
    }
}