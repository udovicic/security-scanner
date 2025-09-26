<?php

namespace SecurityScanner\Core;

class Route
{
    private string $method;
    private string $path;
    private $handler;
    private array $middleware = [];
    private ?string $name = null;
    private string $prefix = '';

    public function __construct(string $method, string $path, $handler, array $middleware = [])
    {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        $this->middleware = $middleware;
    }

    /**
     * Get the HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the route path
     */
    public function getPath(): string
    {
        return $this->prefix . $this->path;
    }

    /**
     * Get the original path (without prefix)
     */
    public function getOriginalPath(): string
    {
        return $this->path;
    }

    /**
     * Get the route handler
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Add middleware to the route
     */
    public function middleware(array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Add prefix to route path
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = rtrim($prefix, '/');
        return $this;
    }

    /**
     * Get route prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Check if route has specific middleware
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->middleware);
    }

    /**
     * Get route parameters from path pattern
     */
    public function getParameterNames(): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*?)(?::[^}]+)?\??}/', $this->getPath(), $matches);
        return $matches[1] ?? [];
    }

    /**
     * Check if route has parameters
     */
    public function hasParameters(): bool
    {
        return !empty($this->getParameterNames());
    }

    /**
     * Check if parameter is optional
     */
    public function isParameterOptional(string $parameter): bool
    {
        return preg_match('/\{' . preg_quote($parameter) . '\?\}/', $this->getPath()) === 1;
    }

    /**
     * Get route constraints
     */
    public function getParameterConstraints(): array
    {
        $constraints = [];
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*?):([^}]+)\}/', $this->getPath(), $matches);

        if (!empty($matches[1])) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $constraints[$matches[1][$i]] = $matches[2][$i];
            }
        }

        return $constraints;
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->getPath(),
            'original_path' => $this->path,
            'handler' => $this->getHandlerDescription(),
            'middleware' => $this->middleware,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'parameters' => $this->getParameterNames(),
            'constraints' => $this->getParameterConstraints(),
            'has_parameters' => $this->hasParameters()
        ];
    }

    /**
     * Get string representation of handler
     */
    private function getHandlerDescription(): string
    {
        if (is_string($this->handler)) {
            return $this->handler;
        }

        if (is_array($this->handler)) {
            return implode('@', $this->handler);
        }

        if (is_callable($this->handler)) {
            return 'Closure';
        }

        return 'Unknown';
    }
}