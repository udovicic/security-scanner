<?php

namespace SecurityScanner\Core;

class ViewHelper
{
    private static ?ViewHelper $instance = null;
    private AssetManager $assetManager;
    private Config $config;

    private function __construct()
    {
        $this->assetManager = AssetManager::getInstance();
        $this->config = Config::getInstance();
    }

    public static function getInstance(): ViewHelper
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Include CSS file
     */
    public function css(string $file): string
    {
        return $this->assetManager->css($file);
    }

    /**
     * Include JS file
     */
    public function js(string $file): string
    {
        return $this->assetManager->js($file);
    }

    /**
     * Get asset URL
     */
    public function asset(string $file, string $type = 'css'): string
    {
        return $this->assetManager->url($file, $type);
    }

    /**
     * Include image with attributes
     */
    public function image(string $file, array $attributes = []): string
    {
        return $this->assetManager->image($file, $attributes);
    }

    /**
     * Inline CSS for critical styles
     */
    public function inlineCSS(string $file): string
    {
        return $this->assetManager->inlineCSS($file);
    }

    /**
     * Inline JS for critical scripts
     */
    public function inlineJS(string $file): string
    {
        return $this->assetManager->inlineJS($file);
    }

    /**
     * Generate CSRF token meta tag
     */
    public function csrfMeta(): string
    {
        $token = $this->generateCsrfToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * Generate CSRF token hidden input
     */
    public function csrfField(): string
    {
        $token = $this->generateCsrfToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Render page title
     */
    public function title(string $pageTitle = '', string $separator = ' - '): string
    {
        $appName = $this->config->get('app.name', 'Security Scanner Tool');

        if (empty($pageTitle)) {
            return htmlspecialchars($appName);
        }

        return htmlspecialchars($pageTitle . $separator . $appName);
    }

    /**
     * Generate navigation menu
     */
    public function navigation(array $items, string $currentPath = ''): string
    {
        $html = '<nav class="nav">';

        foreach ($items as $item) {
            $isActive = $currentPath === $item['path'] ? ' active' : '';
            $html .= sprintf(
                '<a href="%s" class="nav-link%s">%s</a>',
                htmlspecialchars($item['path']),
                $isActive,
                htmlspecialchars($item['label'])
            );
        }

        $html .= '</nav>';
        return $html;
    }

    /**
     * Format date for display
     */
    public function formatDate($date, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        if (!$date instanceof \DateTime) {
            return 'Invalid date';
        }

        return $date->format($format);
    }

    /**
     * Format file size
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Render status badge
     */
    public function statusBadge(string $status): string
    {
        $statusClasses = [
            'success' => 'status-success',
            'passed' => 'status-success',
            'ok' => 'status-success',
            'warning' => 'status-warning',
            'pending' => 'status-warning',
            'error' => 'status-error',
            'failed' => 'status-error',
            'danger' => 'status-error',
            'info' => 'status-info',
            'running' => 'status-info',
        ];

        $class = $statusClasses[strtolower($status)] ?? 'status-info';
        return sprintf('<span class="status %s">%s</span>', $class, htmlspecialchars(ucfirst($status)));
    }

    /**
     * Render pagination
     */
    public function pagination(int $currentPage, int $totalPages, string $baseUrl): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav class="pagination">';
        $html .= '<ul class="pagination-list">';

        // Previous button
        if ($currentPage > 1) {
            $html .= sprintf('<li><a href="%s?page=%d" class="btn btn-secondary">Previous</a></li>',
                $baseUrl, $currentPage - 1);
        }

        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            $activeClass = $i === $currentPage ? ' btn-primary' : ' btn-secondary';
            $html .= sprintf('<li><a href="%s?page=%d" class="btn%s">%d</a></li>',
                $baseUrl, $i, $activeClass, $i);
        }

        // Next button
        if ($currentPage < $totalPages) {
            $html .= sprintf('<li><a href="%s?page=%d" class="btn btn-secondary">Next</a></li>',
                $baseUrl, $currentPage + 1);
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Render alert message
     */
    public function alert(string $message, string $type = 'info'): string
    {
        $validTypes = ['success', 'warning', 'error', 'info'];
        $type = in_array($type, $validTypes) ? $type : 'info';

        return sprintf('<div class="alert alert-%s">%s</div>',
            $type, htmlspecialchars($message));
    }

    /**
     * Escape HTML
     */
    public function e(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate CSRF token
     */
    private function generateCsrfToken(): string
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Check if current environment is production
     */
    public function isProduction(): bool
    {
        return $this->config->isProduction();
    }

    /**
     * Get configuration value
     */
    public function config(string $key, $default = null)
    {
        return $this->config->get($key, $default);
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}