<?php

namespace SecurityScanner\Core;

class XssProtection
{
    private array $config;
    private Logger $logger;
    private static array $allowedTags = [];
    private static array $allowedAttributes = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_encoding' => 'UTF-8',
            'strict_mode' => true,
            'allow_data_attributes' => false,
            'allow_style_attributes' => false,
            'auto_escape_output' => true,
            'csp_enabled' => true,
            'csp_nonce_length' => 32
        ], $config);

        $this->logger = Logger::channel('xss_protection');
        $this->initializeDefaults();
    }

    public function encode(string $data, string $context = 'html'): string
    {
        switch ($context) {
            case 'html':
                return $this->encodeHtml($data);
            case 'attribute':
                return $this->encodeHtmlAttribute($data);
            case 'js':
                return $this->encodeJavaScript($data);
            case 'css':
                return $this->encodeCss($data);
            case 'url':
                return $this->encodeUrl($data);
            case 'json':
                return $this->encodeJson($data);
            default:
                return $this->encodeHtml($data);
        }
    }

    public function encodeHtml(string $data): string
    {
        return htmlspecialchars(
            $data,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            $this->config['default_encoding'],
            false
        );
    }

    public function encodeHtmlAttribute(string $data): string
    {
        if (empty($data)) {
            return '';
        }

        $encoded = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $ord = ord($char);

            // Encode dangerous characters
            if ($ord < 32 || $ord > 126) {
                $encoded .= '&#' . $ord . ';';
            } elseif (in_array($char, ['"', "'", '&', '<', '>'])) {
                $encoded .= '&#' . $ord . ';';
            } else {
                $encoded .= $char;
            }
        }

        return $encoded;
    }

    public function encodeJavaScript(string $data): string
    {
        $encoded = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $ord = ord($char);

            if ($ord < 32 || $ord > 126) {
                $encoded .= sprintf('\\u%04x', $ord);
            } elseif (in_array($char, ['\\', '"', "'", '/', '\n', '\r', '\t'])) {
                switch ($char) {
                    case '\\': $encoded .= '\\\\'; break;
                    case '"': $encoded .= '\\"'; break;
                    case "'": $encoded .= "\\'"; break;
                    case '/': $encoded .= '\\/'; break;
                    case "\n": $encoded .= '\\n'; break;
                    case "\r": $encoded .= '\\r'; break;
                    case "\t": $encoded .= '\\t'; break;
                }
            } else {
                $encoded .= $char;
            }
        }

        return $encoded;
    }

    public function encodeCss(string $data): string
    {
        $encoded = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $ord = ord($char);

            if (($ord >= 48 && $ord <= 57) ||  // 0-9
                ($ord >= 65 && $ord <= 90) ||  // A-Z
                ($ord >= 97 && $ord <= 122)) { // a-z
                $encoded .= $char;
            } else {
                $encoded .= sprintf('\\%X ', $ord);
            }
        }

        return trim($encoded);
    }

    public function encodeUrl(string $data): string
    {
        return rawurlencode($data);
    }

    public function encodeJson(string $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    }

    public function sanitizeHtml(string $html, array $options = []): string
    {
        $allowedTags = $options['allowed_tags'] ?? self::$allowedTags;
        $allowedAttributes = $options['allowed_attributes'] ?? self::$allowedAttributes;

        if (empty($allowedTags)) {
            return $this->encodeHtml($html);
        }

        if (class_exists('DOMDocument')) {
            return $this->sanitizeWithDom($html, $allowedTags, $allowedAttributes);
        }

        return $this->sanitizeWithRegex($html, $allowedTags, $allowedAttributes);
    }

    public function detectXss(string $input): array
    {
        $result = [
            'is_safe' => true,
            'threats' => [],
            'risk_level' => 'low'
        ];

        $patterns = [
            'script_tags' => '/<script[^>]*>.*?<\/script>/is',
            'javascript_protocol' => '/javascript:/i',
            'data_protocol' => '/data:.*base64/i',
            'event_handlers' => '/on\w+\s*=/i',
            'iframe_tags' => '/<iframe[^>]*>/i',
            'object_embed' => '/<(object|embed|applet)[^>]*>/i',
            'style_expressions' => '/expression\s*\(/i',
            'meta_refresh' => '/<meta[^>]*http-equiv[^>]*refresh/i',
            'form_tags' => '/<form[^>]*>/i',
            'link_tags' => '/<link[^>]*>/i',
            'base_tags' => '/<base[^>]*>/i'
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                $result['is_safe'] = false;
                $result['threats'][] = [
                    'type' => $type,
                    'match' => substr($matches[0], 0, 100),
                    'severity' => $this->getThreatSeverity($type)
                ];
            }
        }

        $result['risk_level'] = $this->calculateRiskLevel($result['threats']);

        if (!$result['is_safe']) {
            $this->logger->warning("XSS threat detected", [
                'input_hash' => hash('sha256', $input),
                'threats' => $result['threats'],
                'risk_level' => $result['risk_level']
            ]);
        }

        return $result;
    }

    public function generateCspNonce(): string
    {
        return base64_encode(random_bytes($this->config['csp_nonce_length']));
    }

    public function getCspHeaders(array $options = []): array
    {
        $nonce = $options['nonce'] ?? $this->generateCspNonce();
        $reportUri = $options['report_uri'] ?? '/security/csp-report';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' https:",
            "connect-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            "child-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests",
            "report-uri {$reportUri}"
        ];

        if (isset($options['additional_script_sources'])) {
            $directives[1] = str_replace("'strict-dynamic'", "'strict-dynamic' " . implode(' ', $options['additional_script_sources']), $directives[1]);
        }

        return [
            'Content-Security-Policy' => implode('; ', $directives),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ];
    }

    public function createSafeOutput(string $template, array $variables = []): string
    {
        $output = $template;

        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $encodedValue = $this->encodeHtml($value);
            $output = str_replace($placeholder, $encodedValue, $output);
        }

        $unsafePlaceholder = '/\{\{unsafe:([^}]+)\}\}/';
        $output = preg_replace_callback($unsafePlaceholder, function($matches) use ($variables) {
            $key = $matches[1];
            return $variables[$key] ?? '';
        }, $output);

        $contextPlaceholders = '/\{\{([^:}]+):([^}]+)\}\}/';
        $output = preg_replace_callback($contextPlaceholders, function($matches) use ($variables) {
            $context = $matches[1];
            $key = $matches[2];
            $value = $variables[$key] ?? '';
            return $this->encode($value, $context);
        }, $output);

        return $output;
    }

    public function validateAndSanitizeInput(string $input, array $options = []): array
    {
        $result = [
            'original' => $input,
            'sanitized' => '',
            'is_safe' => true,
            'modifications' => []
        ];

        $xssCheck = $this->detectXss($input);
        $result['is_safe'] = $xssCheck['is_safe'];

        if (!$xssCheck['is_safe']) {
            $result['modifications'][] = 'XSS threats detected and removed';
            $result['threats'] = $xssCheck['threats'];
        }

        if ($options['allow_html'] ?? false) {
            $result['sanitized'] = $this->sanitizeHtml($input, $options);
        } else {
            $result['sanitized'] = $this->encodeHtml($input);
        }

        if ($result['sanitized'] !== $input) {
            $result['modifications'][] = 'Content was encoded for safety';
        }

        return $result;
    }

    public static function setAllowedTags(array $tags): void
    {
        self::$allowedTags = array_map('strtolower', $tags);
    }

    public static function setAllowedAttributes(array $attributes): void
    {
        self::$allowedAttributes = array_map('strtolower', $attributes);
    }

    public static function escape(string $data, string $context = 'html'): string
    {
        $instance = new self();
        return $instance->encode($data, $context);
    }

    public function createOutputBuffer(): void
    {
        if ($this->config['auto_escape_output']) {
            ob_start([$this, 'outputHandler']);
        }
    }

    public function outputHandler(string $buffer): string
    {
        if (strpos($buffer, '<!DOCTYPE') === 0 || strpos($buffer, '<html') !== false) {
            return $this->processHtmlDocument($buffer);
        }

        return $buffer;
    }

    private function sanitizeWithDom(string $html, array $allowedTags, array $allowedAttributes): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);

        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $this->sanitizeDomNode($dom, $allowedTags, $allowedAttributes);

        $output = $dom->saveHTML();
        $output = str_replace('<?xml encoding="UTF-8">', '', $output);

        return trim($output);
    }

    private function sanitizeDomNode(\DOMNode $node, array $allowedTags, array $allowedAttributes): void
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tagName = strtolower($node->nodeName);

            if (!in_array($tagName, $allowedTags)) {
                $node->parentNode->removeChild($node);
                return;
            }

            if ($node->hasAttributes()) {
                $attributesToRemove = [];
                foreach ($node->attributes as $attribute) {
                    $attrName = strtolower($attribute->name);

                    if (!in_array($attrName, $allowedAttributes)) {
                        $attributesToRemove[] = $attribute->name;
                    } elseif ($this->isAttributeValueDangerous($attribute->value)) {
                        $attributesToRemove[] = $attribute->name;
                    }
                }

                foreach ($attributesToRemove as $attrName) {
                    $node->removeAttribute($attrName);
                }
            }
        }

        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }

            foreach ($children as $child) {
                $this->sanitizeDomNode($child, $allowedTags, $allowedAttributes);
            }
        }
    }

    private function sanitizeWithRegex(string $html, array $allowedTags, array $allowedAttributes): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        $html = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

        $html = preg_replace('/javascript:/i', '', $html);

        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        return strip_tags($html, $allowedTagsString);
    }

    private function isAttributeValueDangerous(string $value): bool
    {
        $dangerousPatterns = [
            '/javascript:/i',
            '/data:.*base64/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/expression\s*\(/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function getThreatSeverity(string $type): string
    {
        $highSeverity = ['script_tags', 'javascript_protocol', 'data_protocol', 'iframe_tags'];
        $mediumSeverity = ['event_handlers', 'object_embed', 'style_expressions'];

        if (in_array($type, $highSeverity)) {
            return 'high';
        } elseif (in_array($type, $mediumSeverity)) {
            return 'medium';
        }

        return 'low';
    }

    private function calculateRiskLevel(array $threats): string
    {
        if (empty($threats)) {
            return 'low';
        }

        $highCount = 0;
        $mediumCount = 0;

        foreach ($threats as $threat) {
            if ($threat['severity'] === 'high') {
                $highCount++;
            } elseif ($threat['severity'] === 'medium') {
                $mediumCount++;
            }
        }

        if ($highCount > 0) {
            return 'high';
        } elseif ($mediumCount > 1) {
            return 'high';
        } elseif ($mediumCount > 0) {
            return 'medium';
        }

        return 'low';
    }

    private function processHtmlDocument(string $html): string
    {
        if (strpos($html, '<script') !== false) {
            $html = preg_replace_callback('/<script([^>]*)>/i', function($matches) {
                $attributes = $matches[1];
                if (strpos($attributes, 'nonce=') === false) {
                    $nonce = $this->generateCspNonce();
                    return '<script' . $attributes . ' nonce="' . $nonce . '">';
                }
                return $matches[0];
            }, $html);
        }

        return $html;
    }

    private function initializeDefaults(): void
    {
        if (empty(self::$allowedTags)) {
            self::$allowedTags = [
                'p', 'br', 'strong', 'em', 'u', 'b', 'i',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'ul', 'ol', 'li', 'blockquote',
                'a', 'img', 'span', 'div'
            ];
        }

        if (empty(self::$allowedAttributes)) {
            self::$allowedAttributes = [
                'href', 'src', 'alt', 'title', 'class', 'id'
            ];
        }
    }
}