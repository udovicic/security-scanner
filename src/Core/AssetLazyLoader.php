<?php

namespace SecurityScanner\Core;

class AssetLazyLoader
{
    private static ?AssetLazyLoader $instance = null;
    private array $assets = [];
    private array $loadedAssets = [];
    private array $priorities = [];
    private Config $config;
    private Logger $logger;
    private string $baseUrl;
    private bool $minifyEnabled;
    private string $cacheDir;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance('asset_loading');
        $this->baseUrl = $this->config->get('app.url', '');
        $this->minifyEnabled = $this->config->get('assets.minify_enabled', true);
        $this->cacheDir = $this->config->get('assets.cache_dir', storage_path('cache/assets'));
    }

    public static function getInstance(): AssetLazyLoader
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function registerAsset(string $key, string $type, string $path, int $priority = 100, array $attributes = []): void
    {
        $this->assets[$key] = [
            'type' => $type,
            'path' => $path,
            'priority' => $priority,
            'attributes' => $attributes,
            'loaded' => false,
            'size' => $this->getAssetSize($path),
            'modified' => $this->getAssetModifiedTime($path)
        ];

        $this->priorities[$priority][] = $key;

        $this->logger->debug("Registered asset for lazy loading", [
            'key' => $key,
            'type' => $type,
            'path' => $path,
            'priority' => $priority
        ]);
    }

    public function registerCss(string $key, string $path, int $priority = 100, array $attributes = []): void
    {
        $this->registerAsset($key, 'css', $path, $priority, $attributes);
    }

    public function registerJs(string $key, string $path, int $priority = 100, array $attributes = []): void
    {
        $this->registerAsset($key, 'js', $path, $priority, $attributes);
    }

    public function registerImage(string $key, string $path, int $priority = 100, array $attributes = []): void
    {
        $this->registerAsset($key, 'image', $path, $priority, $attributes);
    }

    public function loadAsset(string $key): ?string
    {
        if (!isset($this->assets[$key])) {
            $this->logger->warning("Attempted to load unregistered asset", ['key' => $key]);
            return null;
        }

        if ($this->assets[$key]['loaded']) {
            return $this->generateAssetTag($key);
        }

        $startTime = microtime(true);
        $asset = $this->assets[$key];

        try {
            $processedPath = $this->processAsset($asset);
            $tag = $this->generateAssetTag($key, $processedPath);

            $this->assets[$key]['loaded'] = true;
            $this->loadedAssets[$key] = $tag;

            $loadTime = (microtime(true) - $startTime) * 1000;

            $this->logger->info("Asset loaded", [
                'key' => $key,
                'type' => $asset['type'],
                'load_time_ms' => round($loadTime, 2),
                'size_bytes' => $asset['size']
            ]);

            return $tag;

        } catch (\Exception $e) {
            $this->logger->error("Failed to load asset", [
                'key' => $key,
                'path' => $asset['path'],
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    public function loadAssetsByPriority(int $maxPriority = 100): array
    {
        $loadedTags = [];
        ksort($this->priorities);

        foreach ($this->priorities as $priority => $keys) {
            if ($priority > $maxPriority) {
                break;
            }

            foreach ($keys as $key) {
                if (!$this->assets[$key]['loaded']) {
                    $tag = $this->loadAsset($key);
                    if ($tag) {
                        $loadedTags[] = $tag;
                    }
                }
            }
        }

        return $loadedTags;
    }

    public function loadCriticalAssets(): array
    {
        return $this->loadAssetsByPriority(50);
    }

    public function generatePreloadTags(): array
    {
        $preloadTags = [];

        foreach ($this->assets as $key => $asset) {
            if ($asset['priority'] <= 50) { // Critical assets
                $preloadTags[] = $this->generatePreloadTag($asset);
            }
        }

        return $preloadTags;
    }

    public function getAssetUrl(string $key): ?string
    {
        if (!isset($this->assets[$key])) {
            return null;
        }

        $asset = $this->assets[$key];
        $processedPath = $this->processAsset($asset);

        return $this->baseUrl . '/' . ltrim($processedPath, '/');
    }

    public function getDeferredLoadingScript(): string
    {
        $deferredAssets = [];

        foreach ($this->assets as $key => $asset) {
            if ($asset['priority'] > 50 && !$asset['loaded']) {
                $deferredAssets[$key] = [
                    'type' => $asset['type'],
                    'url' => $this->getAssetUrl($key),
                    'attributes' => $asset['attributes']
                ];
            }
        }

        if (empty($deferredAssets)) {
            return '';
        }

        $jsAssets = json_encode($deferredAssets);

        return <<<JS
<script>
(function() {
    var deferredAssets = {$jsAssets};

    function loadDeferredAssets() {
        Object.keys(deferredAssets).forEach(function(key) {
            var asset = deferredAssets[key];

            if (asset.type === 'css') {
                loadCss(asset.url, asset.attributes);
            } else if (asset.type === 'js') {
                loadJs(asset.url, asset.attributes);
            }
        });
    }

    function loadCss(url, attributes) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;

        Object.keys(attributes || {}).forEach(function(attr) {
            link.setAttribute(attr, attributes[attr]);
        });

        document.head.appendChild(link);
    }

    function loadJs(url, attributes) {
        var script = document.createElement('script');
        script.src = url;

        Object.keys(attributes || {}).forEach(function(attr) {
            script.setAttribute(attr, attributes[attr]);
        });

        document.head.appendChild(script);
    }

    // Load deferred assets when page is loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadDeferredAssets);
    } else {
        loadDeferredAssets();
    }
})();
</script>
JS;
    }

    public function getLoadStats(): array
    {
        $totalAssets = count($this->assets);
        $loadedAssets = count(array_filter($this->assets, fn($asset) => $asset['loaded']));
        $totalSize = array_sum(array_column($this->assets, 'size'));
        $loadedSize = array_sum(array_column(array_filter($this->assets, fn($asset) => $asset['loaded']), 'size'));

        return [
            'total_assets' => $totalAssets,
            'loaded_assets' => $loadedAssets,
            'load_percentage' => $totalAssets > 0 ? round(($loadedAssets / $totalAssets) * 100, 2) : 0,
            'total_size_bytes' => $totalSize,
            'loaded_size_bytes' => $loadedSize,
            'size_percentage' => $totalSize > 0 ? round(($loadedSize / $totalSize) * 100, 2) : 0,
            'critical_assets' => count(array_filter($this->assets, fn($asset) => $asset['priority'] <= 50)),
            'deferred_assets' => count(array_filter($this->assets, fn($asset) => $asset['priority'] > 50))
        ];
    }

    private function processAsset(array $asset): string
    {
        $path = $asset['path'];

        if (!$this->minifyEnabled || !in_array($asset['type'], ['css', 'js'])) {
            return $path;
        }

        $minifiedPath = $this->getMinifiedPath($path, $asset['type']);

        if (file_exists(public_path($minifiedPath))) {
            return $minifiedPath;
        }

        return $this->minifyAsset($path, $asset['type']) ?? $path;
    }

    private function minifyAsset(string $path, string $type): ?string
    {
        $fullPath = public_path($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        $minifiedContent = $this->minifyContent($content, $type);
        $minifiedPath = $this->getMinifiedPath($path, $type);
        $minifiedFullPath = public_path($minifiedPath);

        // Ensure cache directory exists
        $cacheDir = dirname($minifiedFullPath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (file_put_contents($minifiedFullPath, $minifiedContent) !== false) {
            return $minifiedPath;
        }

        return null;
    }

    private function minifyContent(string $content, string $type): string
    {
        switch ($type) {
            case 'css':
                return $this->minifyCss($content);
            case 'js':
                return $this->minifyJs($content);
            default:
                return $content;
        }
    }

    private function minifyCss(string $css): string
    {
        // Basic CSS minification
        $css = preg_replace('/\/\*.*?\*\//s', '', $css); // Remove comments
        $css = preg_replace('/\s+/', ' ', $css); // Collapse whitespace
        $css = str_replace(['; ', ' {', '{ ', ' }', '} ', ': ', ' :'], [';', '{', '{', '}', '}', ':', ':'], $css);
        return trim($css);
    }

    private function minifyJs(string $js): string
    {
        // Basic JavaScript minification
        $js = preg_replace('/\/\*.*?\*\//s', '', $js); // Remove block comments
        $js = preg_replace('/\/\/.*$/m', '', $js); // Remove line comments
        $js = preg_replace('/\s+/', ' ', $js); // Collapse whitespace
        return trim($js);
    }

    private function getMinifiedPath(string $path, string $type): string
    {
        $pathInfo = pathinfo($path);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'];

        return "{$directory}/{$filename}.min.{$extension}";
    }

    private function generateAssetTag(string $key, string $path = null): string
    {
        $asset = $this->assets[$key];
        $assetPath = $path ?? $asset['path'];
        $url = $this->baseUrl . '/' . ltrim($assetPath, '/');

        switch ($asset['type']) {
            case 'css':
                return $this->generateCssTag($url, $asset['attributes']);
            case 'js':
                return $this->generateJsTag($url, $asset['attributes']);
            case 'image':
                return $this->generateImageTag($url, $asset['attributes']);
            default:
                return '';
        }
    }

    private function generateCssTag(string $url, array $attributes): string
    {
        $attrs = $this->buildAttributes(array_merge(['rel' => 'stylesheet', 'href' => $url], $attributes));
        return "<link{$attrs}>";
    }

    private function generateJsTag(string $url, array $attributes): string
    {
        $attrs = $this->buildAttributes(array_merge(['src' => $url], $attributes));
        return "<script{$attrs}></script>";
    }

    private function generateImageTag(string $url, array $attributes): string
    {
        $attrs = $this->buildAttributes(array_merge(['src' => $url], $attributes));
        return "<img{$attrs}>";
    }

    private function generatePreloadTag(array $asset): string
    {
        $url = $this->baseUrl . '/' . ltrim($asset['path'], '/');
        $as = $asset['type'] === 'css' ? 'style' : ($asset['type'] === 'js' ? 'script' : 'image');

        return "<link rel=\"preload\" href=\"{$url}\" as=\"{$as}\">";
    }

    private function buildAttributes(array $attributes): string
    {
        $attrs = [];
        foreach ($attributes as $key => $value) {
            $attrs[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        return $attrs ? ' ' . implode(' ', $attrs) : '';
    }

    private function getAssetSize(string $path): int
    {
        $fullPath = public_path($path);
        return file_exists($fullPath) ? filesize($fullPath) : 0;
    }

    private function getAssetModifiedTime(string $path): int
    {
        $fullPath = public_path($path);
        return file_exists($fullPath) ? filemtime($fullPath) : 0;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}