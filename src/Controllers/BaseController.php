<?php

namespace SecurityScanner\Controllers;

use SecurityScanner\Core\{Request, Response, Validator, InputSanitizer, ProgressiveEnhancement};

abstract class BaseController
{
    protected Request $request;
    protected Response $response;
    protected Validator $validator;
    protected InputSanitizer $sanitizer;
    protected ProgressiveEnhancement $pe;
    protected array $config;
    protected array $errors = [];
    protected array $flash = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_format' => 'html',
            'enable_csrf' => true,
            'enable_validation' => true,
            'enable_sanitization' => true,
            'enable_audit_logging' => true,
            'pagination_limit' => 20,
            'max_pagination_limit' => 100
        ], $config);

        $this->initializeComponents();
    }

    /**
     * Initialize controller components
     */
    protected function initializeComponents(): void
    {
        $this->request = Request::createFromGlobals();
        $this->response = new Response();
        $this->validator = new Validator();
        $this->sanitizer = new InputSanitizer();
        $this->pe = new ProgressiveEnhancement();

        // Load flash messages from session
        $this->loadFlashMessages();
    }

    /**
     * Handle incoming request
     */
    public function handleRequest(string $action, array $params = []): Response
    {
        try {
            // Sanitize input if enabled
            if ($this->config['enable_sanitization']) {
                $this->sanitizeInput();
            }

            // CSRF protection for state-changing operations
            if ($this->config['enable_csrf'] && $this->isStateChangingRequest()) {
                $this->validateCsrfToken();
            }

            // Determine response format
            $format = $this->determineResponseFormat();

            // Execute the action
            $result = $this->executeAction($action, $params);

            // Format response based on content negotiation
            return $this->formatResponse($result, $format);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Execute controller action
     */
    protected function executeAction(string $action, array $params): mixed
    {
        $methodName = $action . 'Action';

        if (!method_exists($this, $methodName)) {
            throw new \BadMethodCallException("Action '{$action}' not found");
        }

        return $this->$methodName($params);
    }

    /**
     * Validate request data
     */
    protected function validate(array $data, array $rules): bool
    {
        if (!$this->config['enable_validation']) {
            return true;
        }

        $result = $this->validator->validate($data, $rules);

        if (!$result->isValid()) {
            $this->errors = $result->getErrors();
            return false;
        }

        return true;
    }

    /**
     * Sanitize input data
     */
    protected function sanitizeInput(): void
    {
        // Sanitize POST data
        $postData = $this->request->all();
        if (!empty($postData)) {
            $sanitized = $this->sanitizer->sanitize($postData);
            // Update request with sanitized data
            foreach ($sanitized as $key => $value) {
                $_POST[$key] = $value;
            }
        }

        // Sanitize GET data
        $getData = $this->request->query();
        if (!empty($getData)) {
            $sanitized = $this->sanitizer->sanitize($getData);
            foreach ($sanitized as $key => $value) {
                $_GET[$key] = $value;
            }
        }
    }

    /**
     * Validate CSRF token
     */
    protected function validateCsrfToken(): void
    {
        $token = $this->request->post('csrf_token');
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
            throw new \SecurityException('CSRF token validation failed');
        }
    }

    /**
     * Check if request is state-changing
     */
    protected function isStateChangingRequest(): bool
    {
        return in_array($this->request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * Determine response format
     */
    protected function determineResponseFormat(): string
    {
        // Check Accept header
        $acceptHeader = $this->request->getHeader('Accept');
        if ($acceptHeader && str_contains($acceptHeader, 'application/json')) {
            return 'json';
        }

        // Check X-Requested-With header (AJAX)
        if ($this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
            return 'json';
        }

        // Check format parameter
        $format = $this->request->input('format');
        if ($format && in_array($format, ['json', 'html', 'xml'])) {
            return $format;
        }

        return $this->config['default_format'];
    }

    /**
     * Format response based on content type
     */
    protected function formatResponse(mixed $data, string $format): Response
    {
        switch ($format) {
            case 'json':
                return $this->jsonResponse($data);

            case 'xml':
                return $this->xmlResponse($data);

            case 'html':
            default:
                return $this->htmlResponse($data);
        }
    }

    /**
     * Create JSON response
     */
    protected function jsonResponse(mixed $data, int $status = 200): Response
    {
        if ($data instanceof Response) {
            return $data;
        }

        $responseData = [
            'status' => $status < 400 ? 'success' : 'error',
            'data' => $data,
            'timestamp' => date('c'),
            'errors' => $this->errors,
            'flash' => $this->flash
        ];

        return Response::json($responseData, $status)
            ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Create HTML response
     */
    protected function htmlResponse(mixed $data): Response
    {
        if ($data instanceof Response) {
            return $data;
        }

        if (is_string($data)) {
            return Response::html($data);
        }

        // Render view with progressive enhancement
        return $this->renderView($data);
    }

    /**
     * Create XML response
     */
    protected function xmlResponse(mixed $data): Response
    {
        if ($data instanceof Response) {
            return $data;
        }

        $xml = $this->arrayToXml($data, 'response');
        return Response::create($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Render view with progressive enhancement
     */
    protected function renderView(array $data): Response
    {
        $viewData = array_merge($data, [
            'errors' => $this->errors,
            'flash' => $this->flash,
            'csrf_token' => $this->generateCsrfToken()
        ]);

        $html = $this->pe->renderBaseStructure($viewData);
        return Response::html($html);
    }

    /**
     * Handle pagination
     */
    protected function paginate(array $data, int $page = 1, ?int $limit = null): array
    {
        $limit = $limit ?? $this->config['pagination_limit'];
        $limit = min($limit, $this->config['max_pagination_limit']);

        $offset = ($page - 1) * $limit;
        $total = count($data);
        $totalPages = ceil($total / $limit);

        $paginatedData = array_slice($data, $offset, $limit);

        return [
            'data' => $paginatedData,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'items_per_page' => $limit,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
                'next_page' => $page < $totalPages ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null
            ]
        ];
    }

    /**
     * Add flash message
     */
    protected function addFlash(string $type, string $message): void
    {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }

        $_SESSION['flash'][] = [
            'type' => $type,
            'message' => $message,
            'timestamp' => time()
        ];
    }

    /**
     * Load flash messages from session
     */
    protected function loadFlashMessages(): void
    {
        if (isset($_SESSION['flash'])) {
            $this->flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
        }
    }

    /**
     * Generate CSRF token
     */
    protected function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Redirect to URL
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Redirect back with flash message
     */
    protected function redirectBack(string $message = '', string $type = 'info'): Response
    {
        if ($message) {
            $this->addFlash($type, $message);
        }

        $referer = $this->request->getHeader('Referer');
        $url = $referer ?: '/';

        return $this->redirect($url);
    }

    /**
     * Handle exceptions
     */
    protected function handleException(\Exception $e): Response
    {
        $status = $e instanceof \SecurityException ? 403 : 500;

        $this->logError($e);

        $format = $this->determineResponseFormat();

        if ($format === 'json') {
            return $this->jsonResponse([
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ], $status);
        }

        return $this->htmlResponse([
            'title' => 'Error',
            'main' => '<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>'
        ]);
    }

    /**
     * Log error
     */
    protected function logError(\Exception $e): void
    {
        error_log(sprintf(
            "[%s] %s: %s in %s:%d",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Convert array to XML
     */
    protected function arrayToXml(array $data, string $rootElement = 'root'): string
    {
        $xml = new \SimpleXMLElement("<{$rootElement}/>");

        $this->addArrayToXml($data, $xml);

        return $xml->asXML();
    }

    /**
     * Add array data to XML element
     */
    private function addArrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->addArrayToXml($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }

    /**
     * Audit log action
     */
    protected function auditLog(string $action, array $data = []): void
    {
        if (!$this->config['enable_audit_logging']) {
            return;
        }

        $logData = [
            'timestamp' => date('c'),
            'controller' => get_class($this),
            'action' => $action,
            'user_ip' => $this->request->getClientIp(),
            'user_agent' => $this->request->getHeader('User-Agent'),
            'request_method' => $this->request->getMethod(),
            'request_uri' => $this->request->getUri(),
            'data' => $data
        ];

        error_log('AUDIT: ' . json_encode($logData));
    }

    /**
     * Get current user (override in subclasses)
     */
    protected function getCurrentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Check if user is authenticated
     */
    protected function isAuthenticated(): bool
    {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Require authentication
     */
    protected function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            throw new \SecurityException('Authentication required');
        }
    }

    /**
     * Get request object
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Set request object
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * Get response object
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}

/**
 * Security exception class
 */
class SecurityException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}