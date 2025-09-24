<?php

namespace SecurityScanner\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private Config $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::access();

        // Load default routes
        $this->loadDefaultRoutes();
    }

    public function get(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    public function any(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        foreach ($methods as $method) {
            $this->addRoute($method, $pattern, $handler, $middleware);
        }
    }

    private function addRoute(string $method, string $pattern, callable|string $handler, array $middleware): void
    {
        $this->routes[$method][] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware,
            'regex' => $this->patternToRegex($pattern),
        ];
    }

    public function dispatch(Request $request, Response $response): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Apply global middleware first
        foreach ($this->middleware as $middlewareClass) {
            $middleware = new $middlewareClass();
            $result = $middleware->handle($request, $response);

            if ($result instanceof Response) {
                return $result;
            }
        }

        // Find matching route
        $route = $this->findRoute($method, $path);

        if (!$route) {
            return $response->notFound('Page not found');
        }

        // Apply route-specific middleware
        foreach ($route['middleware'] as $middlewareClass) {
            $middleware = new $middlewareClass();
            $result = $middleware->handle($request, $response);

            if ($result instanceof Response) {
                return $result;
            }
        }

        // Extract parameters from URL
        $params = $this->extractParameters($route, $path);
        $request->setParameters($params);

        try {
            // Call the handler
            if (is_callable($route['handler'])) {
                $result = call_user_func($route['handler'], $request, $response);
            } else {
                $result = $this->callControllerAction($route['handler'], $request, $response);
            }

            // If handler returns a Response object, use it
            if ($result instanceof Response) {
                return $result;
            }

            // Otherwise, return the original response (handler should have modified it)
            return $response;

        } catch (\Throwable $e) {
            $this->logger->error("Route handler error", [
                'route' => $route['pattern'],
                'handler' => is_string($route['handler']) ? $route['handler'] : 'closure',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($this->config->isDebug()) {
                throw $e;
            }

            return $response->error(500, 'Internal server error');
        }
    }

    private function findRoute(string $method, string $path): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['regex'], $path)) {
                return $route;
            }
        }

        return null;
    }

    private function extractParameters(array $route, string $path): array
    {
        $params = [];

        if (preg_match($route['regex'], $path, $matches)) {
            // Remove the full match
            array_shift($matches);

            // Extract named parameters
            preg_match_all('/\{(\w+)\}/', $route['pattern'], $paramNames);

            foreach ($paramNames[1] as $index => $paramName) {
                if (isset($matches[$index])) {
                    $params[$paramName] = $matches[$index];
                }
            }
        }

        return $params;
    }

    private function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except {}
        $pattern = preg_quote($pattern, '/');

        // Convert {param} to regex groups
        $pattern = preg_replace('/\\\{(\w+)\\\}/', '([^/]+)', $pattern);

        // Make sure we match the whole path
        return '/^' . $pattern . '$/';
    }

    private function callControllerAction(string $handler, Request $request, Response $response)
    {
        // Parse controller@action format
        if (strpos($handler, '@') !== false) {
            [$controllerName, $action] = explode('@', $handler, 2);
        } else {
            $controllerName = $handler;
            $action = 'index';
        }

        // Build full controller class name
        $controllerClass = "SecurityScanner\\Controllers\\{$controllerName}";

        if (!class_exists($controllerClass)) {
            throw new \RuntimeException("Controller {$controllerClass} not found");
        }

        $controller = new $controllerClass($request, $response);

        if (!method_exists($controller, $action)) {
            throw new \RuntimeException("Action {$action} not found in controller {$controllerClass}");
        }

        return $controller->$action();
    }

    public function addMiddleware(string $middlewareClass): void
    {
        $this->middleware[] = $middlewareClass;
    }

    private function loadDefaultRoutes(): void
    {
        // Home page
        $this->get('/', function(Request $request, Response $response) {
            return $response->html($this->renderWelcomePage());
        });

        // Health check endpoint
        $this->get('/health', function(Request $request, Response $response) {
            $health = [
                'status' => 'ok',
                'timestamp' => date('c'),
                'version' => '1.0.0',
                'environment' => $this->config->getEnvironment(),
            ];

            // Test database connection
            try {
                $database = Database::getInstance();
                $database->testConnection();
                $health['database'] = 'connected';
            } catch (\Exception $e) {
                $health['database'] = 'disconnected';
                $health['status'] = 'degraded';
            }

            return $response->json($health);
        });

        // API test endpoint
        $this->get('/api/test', function(Request $request, Response $response) {
            return $response->json([
                'message' => 'Security Scanner API is working',
                'timestamp' => date('c'),
                'method' => $request->getMethod(),
                'ip' => $request->getClientIp(),
            ]);
        });

        // Dashboard (placeholder)
        $this->get('/dashboard', 'DashboardController@index');

        // Websites management
        $this->get('/websites', 'WebsiteController@index');
        $this->get('/websites/create', 'WebsiteController@create');
        $this->post('/websites', 'WebsiteController@store');
        $this->get('/websites/{id}', 'WebsiteController@show');
        $this->get('/websites/{id}/edit', 'WebsiteController@edit');
        $this->put('/websites/{id}', 'WebsiteController@update');
        $this->delete('/websites/{id}', 'WebsiteController@destroy');

        // Test results
        $this->get('/results', 'ResultController@index');
        $this->get('/results/{id}', 'ResultController@show');

        // API endpoints
        $this->get('/api/websites', 'Api\\WebsiteController@index');
        $this->post('/api/websites', 'Api\\WebsiteController@store');
        $this->get('/api/websites/{id}', 'Api\\WebsiteController@show');
        $this->put('/api/websites/{id}', 'Api\\WebsiteController@update');
        $this->delete('/api/websites/{id}', 'Api\\WebsiteController@destroy');
    }

    private function renderWelcomePage(): string
    {
        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>Security Scanner Tool</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 50px 20px;
            text-align: center;
        }
        .logo {
            font-size: 48px;
            font-weight: 300;
            margin-bottom: 20px;
        }
        .subtitle {
            font-size: 20px;
            opacity: 0.9;
            margin-bottom: 40px;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin: 50px 0;
        }
        .feature {
            background: rgba(255,255,255,0.1);
            padding: 30px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        .feature h3 {
            font-size: 24px;
            margin-bottom: 15px;
        }
        .feature p {
            opacity: 0.9;
            line-height: 1.6;
        }
        .actions {
            margin-top: 50px;
        }
        .btn {
            display: inline-block;
            padding: 15px 30px;
            margin: 10px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: 2px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
        }
        .btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }
        .status {
            margin-top: 40px;
            padding: 20px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            font-family: monospace;
        }
        .status-item {
            margin: 10px 0;
        }
        .status-ok { color: #4caf50; }
        .status-error { color: #f44336; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='logo'>🛡️ Security Scanner</div>
        <div class='subtitle'>Monitor your websites' security and performance</div>

        <div class='features'>
            <div class='feature'>
                <h3>🔍 Website Monitoring</h3>
                <p>Continuously scan your websites for security vulnerabilities, SSL issues, and performance problems.</p>
            </div>
            <div class='feature'>
                <h3>📊 Detailed Reports</h3>
                <p>Get comprehensive reports with actionable insights and recommendations for each test.</p>
            </div>
            <div class='feature'>
                <h3>🔔 Smart Alerts</h3>
                <p>Receive notifications when issues are detected, with customizable alert preferences.</p>
            </div>
        </div>

        <div class='actions'>
            <a href='/dashboard' class='btn'>📊 Dashboard</a>
            <a href='/websites' class='btn'>🌐 Websites</a>
            <a href='/health' class='btn'>❤️ Health Check</a>
        </div>

        <div class='status'>
            <div class='status-item'>
                <strong>Status:</strong> <span class='status-ok'>✓ System Operational</span>
            </div>
            <div class='status-item'>
                <strong>Environment:</strong> {$this->config->getEnvironment()}
            </div>
            <div class='status-item'>
                <strong>Version:</strong> 1.0.0
            </div>
        </div>
    </div>
</body>
</html>";
    }
}