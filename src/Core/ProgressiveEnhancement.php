<?php

namespace SecurityScanner\Core;

class ProgressiveEnhancement
{
    private array $config;
    private array $assets = [];
    private array $noScriptContent = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_fallbacks' => true,
            'css_critical_inline' => true,
            'defer_non_critical_css' => true,
            'javascript_optional' => true,
            'form_validation_fallback' => true,
            'navigation_fallback' => true,
            'asset_optimization' => true
        ], $config);
    }

    /**
     * Generate base HTML structure that works without JavaScript
     */
    public function renderBaseStructure(array $content): string
    {
        $html = $this->renderDocumentHead($content);
        $html .= $this->renderBodyStart();
        $html .= $this->renderNavigationFallback($content['navigation'] ?? []);
        $html .= $this->renderMainContent($content['main'] ?? '');
        $html .= $this->renderFooter($content['footer'] ?? '');
        $html .= $this->renderEnhancementScripts();
        $html .= $this->renderBodyEnd();

        return $html;
    }

    /**
     * Render document head with progressive enhancement
     */
    private function renderDocumentHead(array $content): string
    {
        $title = htmlspecialchars($content['title'] ?? 'Security Scanner Tool');
        $description = htmlspecialchars($content['description'] ?? 'Web Security Analysis Tool');

        $head = "<!DOCTYPE html>\n";
        $head .= "<html lang=\"en\" class=\"no-js\">\n";
        $head .= "<head>\n";
        $head .= "    <meta charset=\"UTF-8\">\n";
        $head .= "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        $head .= "    <title>{$title}</title>\n";
        $head .= "    <meta name=\"description\" content=\"{$description}\">\n";

        // Progressive enhancement detection
        $head .= "    <script>\n";
        $head .= "        document.documentElement.className = document.documentElement.className.replace('no-js', 'js');\n";
        $head .= "    </script>\n";

        // Critical CSS inline
        if ($this->config['css_critical_inline']) {
            $head .= "    <style>\n";
            $head .= $this->getCriticalCSS();
            $head .= "    </style>\n";
        }

        // Non-critical CSS with fallback
        if ($this->config['defer_non_critical_css']) {
            $head .= "    <link rel=\"preload\" href=\"/assets/css/styles.css\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\">\n";
            $head .= "    <noscript><link rel=\"stylesheet\" href=\"/assets/css/styles.css\"></noscript>\n";
        } else {
            $head .= "    <link rel=\"stylesheet\" href=\"/assets/css/styles.css\">\n";
        }

        $head .= "</head>\n";

        return $head;
    }

    /**
     * Get critical CSS that should be inlined
     */
    private function getCriticalCSS(): string
    {
        return "
        /* Critical base styles */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #fff;
        }

        /* Skip links for accessibility */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 6px;
            background: #000;
            color: #fff;
            padding: 8px;
            text-decoration: none;
            z-index: 1000;
        }

        .skip-link:focus {
            top: 6px;
        }

        /* Basic layout */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Navigation fallback */
        .nav-fallback {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem 0;
        }

        .nav-fallback ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .nav-fallback a {
            text-decoration: none;
            color: #007bff;
            padding: 0.5rem;
            border-radius: 4px;
        }

        .nav-fallback a:hover,
        .nav-fallback a:focus {
            background: #e9ecef;
            outline: 2px solid #007bff;
        }

        /* Form fallbacks */
        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input, textarea, select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 1rem;
        }

        input:focus, textarea:focus, select:focus {
            outline: 2px solid #007bff;
            border-color: #007bff;
        }

        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }

        button:hover, button:focus {
            background: #0056b3;
            outline: 2px solid #007bff;
        }

        /* Progressive enhancement states */
        .js .no-js-only { display: none; }
        .no-js .js-only { display: none; }

        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Error states */
        .error {
            color: #dc3545;
            border-color: #dc3545;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 0.75rem;
            border-radius: 4px;
            margin: 0.5rem 0;
        }

        /* Success states */
        .success {
            color: #155724;
            background: #d4edda;
            border-color: #c3e6cb;
            padding: 0.75rem;
            border-radius: 4px;
            margin: 0.5rem 0;
        }
        ";
    }

    /**
     * Render body start with accessibility features
     */
    private function renderBodyStart(): string
    {
        return "<body>\n" .
               "    <a href=\"#main-content\" class=\"skip-link\">Skip to main content</a>\n";
    }

    /**
     * Render navigation with fallback
     */
    private function renderNavigationFallback(array $navigation): string
    {
        if (empty($navigation)) {
            return '';
        }

        $nav = "    <nav class=\"nav-fallback\" role=\"navigation\" aria-label=\"Main navigation\">\n";
        $nav .= "        <div class=\"container\">\n";
        $nav .= "            <ul>\n";

        foreach ($navigation as $item) {
            $href = htmlspecialchars($item['href'] ?? '#');
            $text = htmlspecialchars($item['text'] ?? 'Link');
            $nav .= "                <li><a href=\"{$href}\">{$text}</a></li>\n";
        }

        $nav .= "            </ul>\n";
        $nav .= "        </div>\n";
        $nav .= "    </nav>\n";

        return $nav;
    }

    /**
     * Render main content area
     */
    private function renderMainContent(string $content): string
    {
        return "    <main id=\"main-content\" class=\"container\" role=\"main\">\n" .
               "        {$content}\n" .
               "    </main>\n";
    }

    /**
     * Render footer
     */
    private function renderFooter(string $content): string
    {
        if (empty($content)) {
            $content = "<p>&copy; " . date('Y') . " Security Scanner Tool. Built with progressive enhancement.</p>";
        }

        return "    <footer class=\"container\" role=\"contentinfo\">\n" .
               "        {$content}\n" .
               "    </footer>\n";
    }

    /**
     * Render enhancement scripts
     */
    private function renderEnhancementScripts(): string
    {
        if (!$this->config['javascript_optional']) {
            return '';
        }

        $scripts = "    <!-- Progressive Enhancement Scripts -->\n";
        $scripts .= "    <script>\n";
        $scripts .= "        // Feature detection and progressive enhancement\n";
        $scripts .= "        (function() {\n";
        $scripts .= "            'use strict';\n";
        $scripts .= "            \n";
        $scripts .= "            // Check for modern browser features\n";
        $scripts .= "            var hasModernFeatures = (\n";
        $scripts .= "                'querySelector' in document &&\n";
        $scripts .= "                'addEventListener' in window &&\n";
        $scripts .= "                'classList' in document.createElement('div')\n";
        $scripts .= "            );\n";
        $scripts .= "            \n";
        $scripts .= "            if (!hasModernFeatures) {\n";
        $scripts .= "                // Graceful degradation for older browsers\n";
        $scripts .= "                document.documentElement.className = document.documentElement.className.replace('js', 'no-js');\n";
        $scripts .= "                return;\n";
        $scripts .= "            }\n";
        $scripts .= "            \n";
        $scripts .= "            // Load enhancement modules\n";
        $scripts .= "            var enhancementsToLoad = [];\n";
        $scripts .= "            \n";
        $scripts .= "            // Form enhancements\n";
        $scripts .= "            if (document.querySelector('form')) {\n";
        $scripts .= "                enhancementsToLoad.push('/assets/js/form-enhancements.js');\n";
        $scripts .= "            }\n";
        $scripts .= "            \n";
        $scripts .= "            // Navigation enhancements\n";
        $scripts .= "            if (document.querySelector('nav')) {\n";
        $scripts .= "                enhancementsToLoad.push('/assets/js/navigation-enhancements.js');\n";
        $scripts .= "            }\n";
        $scripts .= "            \n";
        $scripts .= "            // Load scripts asynchronously\n";
        $scripts .= "            enhancementsToLoad.forEach(function(src) {\n";
        $scripts .= "                var script = document.createElement('script');\n";
        $scripts .= "                script.src = src;\n";
        $scripts .= "                script.async = true;\n";
        $scripts .= "                document.head.appendChild(script);\n";
        $scripts .= "            });\n";
        $scripts .= "        })();\n";
        $scripts .= "    </script>\n";

        return $scripts;
    }

    /**
     * Render body end
     */
    private function renderBodyEnd(): string
    {
        return "</body>\n</html>";
    }

    /**
     * Create form with progressive enhancement
     */
    public function renderForm(array $form): string
    {
        $action = htmlspecialchars($form['action'] ?? '');
        $method = strtoupper($form['method'] ?? 'POST');
        $id = htmlspecialchars($form['id'] ?? 'form-' . uniqid());

        $html = "<form action=\"{$action}\" method=\"{$method}\" id=\"{$id}\" class=\"progressive-form\">\n";

        // Add CSRF token if available
        if (isset($form['csrf_token'])) {
            $token = htmlspecialchars($form['csrf_token']);
            $html .= "    <input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">\n";
        }

        // Form fields
        foreach ($form['fields'] ?? [] as $field) {
            $html .= $this->renderFormField($field);
        }

        // Submit button
        $submitText = htmlspecialchars($form['submit_text'] ?? 'Submit');
        $html .= "    <div class=\"form-group\">\n";
        $html .= "        <button type=\"submit\">{$submitText}</button>\n";
        $html .= "    </div>\n";

        // No-script fallback message
        $html .= "    <noscript>\n";
        $html .= "        <div class=\"no-js-message\">\n";
        $html .= "            <p>This form works without JavaScript. All validation will be performed on the server.</p>\n";
        $html .= "        </div>\n";
        $html .= "    </noscript>\n";

        $html .= "</form>\n";

        return $html;
    }

    /**
     * Render individual form field
     */
    private function renderFormField(array $field): string
    {
        $type = $field['type'] ?? 'text';
        $name = htmlspecialchars($field['name'] ?? '');
        $label = htmlspecialchars($field['label'] ?? '');
        $required = $field['required'] ?? false;
        $value = htmlspecialchars($field['value'] ?? '');

        $html = "    <div class=\"form-group\">\n";
        $html .= "        <label for=\"{$name}\">{$label}" . ($required ? ' *' : '') . "</label>\n";

        switch ($type) {
            case 'textarea':
                $rows = $field['rows'] ?? 4;
                $html .= "        <textarea id=\"{$name}\" name=\"{$name}\" rows=\"{$rows}\"" . ($required ? ' required' : '') . ">{$value}</textarea>\n";
                break;

            case 'select':
                $html .= "        <select id=\"{$name}\" name=\"{$name}\"" . ($required ? ' required' : '') . ">\n";
                foreach ($field['options'] ?? [] as $optValue => $optLabel) {
                    $optValue = htmlspecialchars($optValue);
                    $optLabel = htmlspecialchars($optLabel);
                    $selected = $value === $optValue ? ' selected' : '';
                    $html .= "            <option value=\"{$optValue}\"{$selected}>{$optLabel}</option>\n";
                }
                $html .= "        </select>\n";
                break;

            default:
                $html .= "        <input type=\"{$type}\" id=\"{$name}\" name=\"{$name}\" value=\"{$value}\"" . ($required ? ' required' : '') . ">\n";
                break;
        }

        // Server-side validation errors
        if (isset($field['error'])) {
            $error = htmlspecialchars($field['error']);
            $html .= "        <div class=\"error-message\">{$error}</div>\n";
        }

        $html .= "    </div>\n";

        return $html;
    }

    /**
     * Create data table with progressive enhancement
     */
    public function renderDataTable(array $table): string
    {
        $caption = htmlspecialchars($table['caption'] ?? '');
        $headers = $table['headers'] ?? [];
        $rows = $table['rows'] ?? [];

        $html = "<div class=\"table-wrapper\">\n";
        $html .= "    <table class=\"data-table\">\n";

        if ($caption) {
            $html .= "        <caption>{$caption}</caption>\n";
        }

        // Headers
        if (!empty($headers)) {
            $html .= "        <thead>\n";
            $html .= "            <tr>\n";
            foreach ($headers as $header) {
                $headerText = htmlspecialchars($header['text'] ?? '');
                $sortable = $header['sortable'] ?? false;
                $sortUrl = htmlspecialchars($header['sort_url'] ?? '');

                if ($sortable && $sortUrl) {
                    $html .= "                <th><a href=\"{$sortUrl}\">{$headerText}</a></th>\n";
                } else {
                    $html .= "                <th>{$headerText}</th>\n";
                }
            }
            $html .= "            </tr>\n";
            $html .= "        </thead>\n";
        }

        // Body
        $html .= "        <tbody>\n";
        foreach ($rows as $row) {
            $html .= "            <tr>\n";
            foreach ($row as $cell) {
                $cellText = htmlspecialchars($cell ?? '');
                $html .= "                <td>{$cellText}</td>\n";
            }
            $html .= "            </tr>\n";
        }
        $html .= "        </tbody>\n";

        $html .= "    </table>\n";
        $html .= "</div>\n";

        return $html;
    }

    /**
     * Create pagination with progressive enhancement
     */
    public function renderPagination(array $pagination): string
    {
        $currentPage = $pagination['current'] ?? 1;
        $totalPages = $pagination['total'] ?? 1;
        $baseUrl = $pagination['base_url'] ?? '';

        if ($totalPages <= 1) {
            return '';
        }

        $html = "<nav class=\"pagination\" aria-label=\"Pagination\">\n";
        $html .= "    <ul>\n";

        // Previous page
        if ($currentPage > 1) {
            $prevUrl = $baseUrl . '?page=' . ($currentPage - 1);
            $html .= "        <li><a href=\"{$prevUrl}\" rel=\"prev\">Previous</a></li>\n";
        } else {
            $html .= "        <li><span class=\"disabled\">Previous</span></li>\n";
        }

        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $currentPage) {
                $html .= "        <li><span class=\"current\" aria-current=\"page\">{$i}</span></li>\n";
            } else {
                $pageUrl = $baseUrl . '?page=' . $i;
                $html .= "        <li><a href=\"{$pageUrl}\">{$i}</a></li>\n";
            }
        }

        // Next page
        if ($currentPage < $totalPages) {
            $nextUrl = $baseUrl . '?page=' . ($currentPage + 1);
            $html .= "        <li><a href=\"{$nextUrl}\" rel=\"next\">Next</a></li>\n";
        } else {
            $html .= "        <li><span class=\"disabled\">Next</span></li>\n";
        }

        $html .= "    </ul>\n";
        $html .= "</nav>\n";

        return $html;
    }

    /**
     * Add noscript content
     */
    public function addNoScriptContent(string $content): void
    {
        $this->noScriptContent[] = $content;
    }

    /**
     * Render all noscript content
     */
    public function renderNoScriptContent(): string
    {
        if (empty($this->noScriptContent)) {
            return '';
        }

        $html = "<noscript>\n";
        foreach ($this->noScriptContent as $content) {
            $html .= "    {$content}\n";
        }
        $html .= "</noscript>\n";

        return $html;
    }

    /**
     * Create middleware for progressive enhancement
     */
    public function middleware(): \Closure
    {
        return function(Request $request, \Closure $next) {
            $response = $next($request);

            // Add progressive enhancement headers
            $response->setHeader('X-Progressive-Enhancement', 'enabled');

            // Check if JavaScript is disabled
            if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest' &&
                !$request->input('js_enabled')) {
                // Handle as no-JS request
                $response->setHeader('X-Enhancement-Level', 'fallback');
            } else {
                $response->setHeader('X-Enhancement-Level', 'enhanced');
            }

            return $response;
        };
    }
}