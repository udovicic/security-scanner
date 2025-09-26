<?php

namespace SecurityScanner\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private array $namedRoutes = [];
    private string $baseUrl = '';
    private Logger $logger;

    public function __construct()
    {
        $this->logger = Logger::scheduler();
        $this->baseUrl = $this->detectBaseUrl();
    }

    /**
     * Register a GET route
     */
    public function get(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Register a route for any HTTP method
     */
    public function any(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('*', $path, $handler, $middleware);
    }

    /**
     * Register a route for multiple HTTP methods
     */
    public function match(array $methods, string $path, $handler, array $middleware = []): Route
    {
        $methodString = implode('|', $methods);
        return $this->addRoute($methodString, $path, $handler, $middleware);
    }

    /**
     * Add a route to the collection
     */
    private function addRoute(string $method, string $path, $handler, array $middleware = []): Route
    {
        $route = new Route($method, $path, $handler, $middleware);
        $this->routes[] = $route;
        return $route;
    }

    /**
     * Register global middleware
     */
    public function middleware(string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Create a route group with shared middleware
     */
    public function group(array $attributes, callable $callback): void
    {
        $originalMiddleware = $this->middleware;
        $prefix = $attributes['prefix'] ?? '';
        $middleware = $attributes['middleware'] ?? [];

        // Add group middleware
        $this->middleware = array_merge($this->middleware, $middleware);

        // Store original route count
        $originalRouteCount = count($this->routes);

        // Execute callback to register routes
        $callback($this);

        // Apply group attributes to new routes
        for ($i = $originalRouteCount; $i < count($this->routes); $i++) {
            $route = $this->routes[$i];

            // Apply prefix
            if ($prefix) {
                $route->prefix($prefix);
            }

            // Apply group middleware
            if (!empty($middleware)) {
                $route->middleware($middleware);
            }
        }

        // Restore original middleware
        $this->middleware = $originalMiddleware;
    }

    /**
     * Resolve the current request to a route
     */
    public function resolve(?string $path = null, ?string $method = null): ?RouteMatch
    {
        $path = $path ?? $this->getCurrentPath();
        $method = $method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $this->logger->debug('Resolving route', [
            'path' => $path,
            'method' => $method
        ]);

        foreach ($this->routes as $route) {
            $match = $this->matchRoute($route, $path, $method);

            if ($match) {
                $this->logger->info('Route matched', [
                    'path' => $path,
                    'method' => $method,
                    'route' => $route->getPath(),
                    'handler' => $this->getHandlerDescription($route->getHandler())
                ]);

                return $match;
            }
        }

        $this->logger->warning('No route matched', [
            'path' => $path,
            'method' => $method
        ]);

        return null;
    }

    /**
     * Match a route against the current path and method
     */
    private function matchRoute(Route $route, string $path, string $method): ?RouteMatch
    {
        // Check HTTP method
        if (!$this->methodMatches($route->getMethod(), $method)) {
            return null;
        }

        // Convert route pattern to regex
        $pattern = $this->routeToRegex($route->getPath());

        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        // Extract parameters
        $parameters = $this->extractParameters($route->getPath(), $matches);

        return new RouteMatch($route, $parameters);
    }

    /**
     * Check if route method matches request method
     */
    private function methodMatches(string $routeMethod, string $requestMethod): bool
    {
        if ($routeMethod === '*') {
            return true;
        }

        $methods = explode('|', $routeMethod);
        return in_array($requestMethod, $methods);
    }

    /**
     * Convert route pattern to regex
     */
    private function routeToRegex(string $route): string
    {
        // Escape forward slashes
        $route = str_replace('/', '\/', $route);

        // Convert parameter placeholders to regex
        // {id} -> (\d+)
        // {id:regex} -> (regex)
        // {slug} -> ([^\/]+)
        $route = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):([^}]+)\}/', '($2)', $route);
        $route = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '([^\/]+)', $route);

        // Handle optional parameters
        // {id?} -> (?:\/(\d+))?
        $route = preg_replace('/\/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/', '(?:\/([^\/]+))?', $route);

        return '/^' . $route . '$/';
    }

    /**
     * Extract parameters from matched route
     */
    private function extractParameters(string $routePattern, array $matches): array
    {
        $parameters = [];

        // Get parameter names from route pattern
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*?)(?::[^}]+)?\??}/', $routePattern, $paramNames);

        if (!empty($paramNames[1])) {
            // Skip the full match (index 0) and map parameter names to values
            for ($i = 1; $i < count($matches); $i++) {
                if (isset($paramNames[1][$i - 1]) && !empty($matches[$i])) {
                    $parameters[$paramNames[1][$i - 1]] = $matches[$i];
                }
            }
        }

        return $parameters;
    }

    /**
     * Get current request path
     */
    private function getCurrentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Remove base URL if present
        if ($this->baseUrl && strpos($path, $this->baseUrl) === 0) {
            $path = substr($path, strlen($this->baseUrl));
        }

        return $path ?: '/';
    }

    /**
     * Detect base URL
     */
    private function detectBaseUrl(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($requestUri, $scriptName) === 0) {
            return dirname($scriptName);
        }

        return '';
    }

    /**
     * Generate URL for named route
     */
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Named route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name];
        $path = $route->getPath();

        // Replace parameters in path
        foreach ($parameters as $key => $value) {
            $path = str_replace(['{' . $key . '}', '{' . $key . '?}'], $value, $path);
        }

        // Remove remaining optional parameters
        $path = preg_replace('/\/\{[^}]*?\?\}/', '', $path);

        return $this->baseUrl . $path;
    }

    /**
     * Register named route
     */
    public function name(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
        $route->name($name);
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get route statistics
     */
    public function getStats(): array
    {
        $methods = [];
        $patterns = [];

        foreach ($this->routes as $route) {
            $method = $route->getMethod();
            $methods[$method] = ($methods[$method] ?? 0) + 1;

            if (preg_match('/\{.*\}/', $route->getPath())) {
                $patterns['dynamic'] = ($patterns['dynamic'] ?? 0) + 1;
            } else {
                $patterns['static'] = ($patterns['static'] ?? 0) + 1;
            }
        }

        return [
            'total_routes' => count($this->routes),
            'methods' => $methods,
            'patterns' => $patterns,
            'middleware' => count($this->middleware),
            'named_routes' => count($this->namedRoutes)
        ];
    }

    /**
     * Get handler description for logging
     */
    private function getHandlerDescription($handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler)) {
            return implode('@', $handler);
        }

        if (is_callable($handler)) {
            return 'Closure';
        }

        return 'Unknown';
    }

    /**
     * Clear all routes (useful for testing)
     */
    public function clear(): void
    {
        $this->routes = [];
        $this->middleware = [];
        $this->namedRoutes = [];
    }
}