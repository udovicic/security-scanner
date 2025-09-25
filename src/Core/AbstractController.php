<?php

namespace SecurityScanner\Core;

abstract class AbstractController
{
    protected Config $config;
    protected Logger $logger;
    protected Request $request;
    protected Response $response;
    protected Container $container;
    protected array $middleware = [];

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->config = $this->container->get(Config::class);
        $this->logger = Logger::access();
        $this->request = new Request();
        $this->response = new Response();
    }

    /**
     * Execute controller action with middleware
     */
    public function execute(string $action, array $params = []): Response
    {
        try {
            // Run before middleware
            foreach ($this->middleware as $middleware) {
                if (method_exists($this, $middleware)) {
                    $result = $this->$middleware($this->request);
                    if ($result instanceof Response) {
                        return $result;
                    }
                }
            }

            // Execute the action
            if (!method_exists($this, $action)) {
                return $this->notFound("Action {$action} not found");
            }

            $result = $this->$action($params);

            if ($result instanceof Response) {
                return $result;
            }

            // If result is array, return as JSON
            if (is_array($result)) {
                return $this->json($result);
            }

            // If result is string, return as HTML
            if (is_string($result)) {
                return $this->html($result);
            }

            return $this->response;

        } catch (\Exception $e) {
            $this->logger->error('Controller error', [
                'controller' => get_class($this),
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error('Internal server error', 500);
        }
    }

    /**
     * Return JSON response
     */
    protected function json(array $data, int $status = 200): Response
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json')
            ->setBody(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Return HTML response
     */
    protected function html(string $content, int $status = 200): Response
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($content);
    }

    /**
     * Return success response
     */
    protected function success(string $message, array $data = []): Response
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * Return error response
     */
    protected function error(string $message, int $status = 400, array $errors = []): Response
    {
        $responseData = [
            'success' => false,
            'message' => $message
        ];

        if (!empty($errors)) {
            $responseData['errors'] = $errors;
        }

        return $this->json($responseData, $status);
    }

    /**
     * Return not found response
     */
    protected function notFound(string $message = 'Not found'): Response
    {
        return $this->error($message, 404);
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorized(string $message = 'Unauthorized'): Response
    {
        return $this->error($message, 401);
    }

    /**
     * Return forbidden response
     */
    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return $this->error($message, 403);
    }

    /**
     * Redirect to URL
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Location', $url);
    }

    /**
     * Get request input
     */
    protected function input(string $key, $default = null)
    {
        return $this->request->input($key, $default);
    }

    /**
     * Get all request input
     */
    protected function all(): array
    {
        return $this->request->all();
    }

    /**
     * Validate request input
     */
    protected function validate(array $rules): array
    {
        $data = $this->all();
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;

            if (is_string($rule)) {
                $rule = explode('|', $rule);
            }

            foreach ($rule as $r) {
                [$ruleName, $ruleParam] = explode(':', $r . ':');

                switch ($ruleName) {
                    case 'required':
                        if (empty($value)) {
                            $errors[$field][] = "The {$field} field is required";
                        }
                        break;
                    case 'email':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "The {$field} must be a valid email address";
                        }
                        break;
                    case 'url':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field][] = "The {$field} must be a valid URL";
                        }
                        break;
                    case 'min':
                        if (!empty($value) && strlen($value) < (int)$ruleParam) {
                            $errors[$field][] = "The {$field} must be at least {$ruleParam} characters";
                        }
                        break;
                    case 'max':
                        if (!empty($value) && strlen($value) > (int)$ruleParam) {
                            $errors[$field][] = "The {$field} must not exceed {$ruleParam} characters";
                        }
                        break;
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $data;
    }

    /**
     * Check if request is AJAX
     */
    protected function isAjax(): bool
    {
        return $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if request expects JSON
     */
    protected function expectsJson(): bool
    {
        $accept = $this->request->getHeader('Accept', '');
        return strpos($accept, 'application/json') !== false || $this->isAjax();
    }

    /**
     * Generate CSRF token
     */
    protected function generateCsrfToken(): string
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Verify CSRF token
     */
    protected function verifyCsrfToken(): bool
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $token = $this->input('_token') ?? $this->request->getHeader('X-CSRF-Token');
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * CSRF protection middleware
     */
    protected function csrfProtection(Request $request): ?Response
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            if (!$this->verifyCsrfToken()) {
                return $this->forbidden('CSRF token mismatch');
            }
        }
        return null;
    }

    /**
     * Rate limiting middleware
     */
    protected function rateLimit(Request $request): ?Response
    {
        $key = 'rate_limit:' . $request->getClientIp();
        $limit = $this->config->get('security.rate_limit', 60);
        $window = $this->config->get('security.rate_window', 60);

        // Simple in-memory rate limiting (in production, use Redis/Memcached)
        static $requests = [];
        $now = time();

        if (!isset($requests[$key])) {
            $requests[$key] = [];
        }

        // Clean old requests
        $requests[$key] = array_filter($requests[$key], function($timestamp) use ($now, $window) {
            return $now - $timestamp < $window;
        });

        if (count($requests[$key]) >= $limit) {
            return $this->error('Too many requests', 429);
        }

        $requests[$key][] = $now;
        return null;
    }
}