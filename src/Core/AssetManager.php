<?php

namespace SecurityScanner\Core;

class AssetManager
{
    private static ?AssetManager $instance = null;
    private Config $config;
    private Logger $logger;
    private string $publicPath;
    private string $assetsPath;
    private string $buildPath;
    private array $manifest = [];

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::errors();
        $this->publicPath = __DIR__ . '/../../public';
        $this->assetsPath = $this->publicPath . '/assets';
        $this->buildPath = $this->publicPath . '/build';

        $this->loadManifest();
    }

    public static function getInstance(): AssetManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function css(string $file): string
    {
        $path = $this->getAssetPath($file, 'css');
        $url = $this->getAssetUrl($path);

        return "<link rel=\"stylesheet\" href=\"{$url}\">";
    }

    public function js(string $file): string
    {
        $path = $this->getAssetPath($file, 'js');
        $url = $this->getAssetUrl($path);

        return "<script src=\"{$url}\"></script>";
    }

    public function image(string $file, array $attributes = []): string
    {
        $path = $this->getAssetPath($file, 'images');
        $url = $this->getAssetUrl($path);

        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= " {$key}=\"" . htmlspecialchars($value) . "\"";
        }

        return "<img src=\"{$url}\"{$attrs}>";
    }

    public function url(string $file, string $type = 'css'): string
    {
        $path = $this->getAssetPath($file, $type);
        return $this->getAssetUrl($path);
    }

    private function getAssetPath(string $file, string $type): string
    {
        // Check if file exists in manifest (built assets)
        $manifestKey = "{$type}/{$file}";
        if (isset($this->manifest[$manifestKey])) {
            return $this->buildPath . '/' . $this->manifest[$manifestKey];
        }

        // Check if file exists in assets directory
        $assetPath = $this->assetsPath . "/{$type}/{$file}";
        if (file_exists($assetPath)) {
            return $assetPath;
        }

        // Return default path even if file doesn't exist
        return $assetPath;
    }

    private function getAssetUrl(string $path): string
    {
        // Convert absolute path to relative URL
        $relativePath = str_replace($this->publicPath, '', $path);

        // Add cache busting parameter
        $version = $this->getAssetVersion($path);

        return $relativePath . '?v=' . $version;
    }

    private function getAssetVersion(string $path): string
    {
        if (file_exists($path)) {
            return substr(md5_file($path), 0, 8);
        }

        return '1.0.0';
    }

    public function minifyCss(string $css): string
    {
        // Remove comments
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);

        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove unnecessary spaces around specific characters
        $css = str_replace([' {', '{ ', ' }', '} ', '; ', ' ;', ': ', ' :', ', ', ' ,'],
                          ['{', '{', '}', '}', ';', ';', ':', ':', ',', ','], $css);

        return trim($css);
    }

    public function minifyJs(string $js): string
    {
        // Remove single line comments
        $js = preg_replace('/\/\/.*$/m', '', $js);

        // Remove multi-line comments
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);

        // Remove extra whitespace
        $js = preg_replace('/\s+/', ' ', $js);

        // Remove spaces around operators and punctuation
        $js = preg_replace('/\s*([{}();,=+\-*\/])\s*/', '$1', $js);

        return trim($js);
    }

    public function buildAssets(): array
    {
        $built = [];

        try {
            // Create build directory if it doesn't exist
            if (!is_dir($this->buildPath)) {
                mkdir($this->buildPath, 0755, true);
            }

            // Build CSS files
            $cssFiles = glob($this->assetsPath . '/css/*.css');
            foreach ($cssFiles as $cssFile) {
                $built[] = $this->buildCssFile($cssFile);
            }

            // Build JS files
            $jsFiles = glob($this->assetsPath . '/js/*.js');
            foreach ($jsFiles as $jsFile) {
                $built[] = $this->buildJsFile($jsFile);
            }

            // Generate manifest
            $this->generateManifest($built);

            $this->logger->info("Assets built successfully", [
                'files_built' => count($built),
                'build_path' => $this->buildPath,
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Asset build failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $built;
    }

    private function buildCssFile(string $cssFile): array
    {
        $filename = basename($cssFile);
        $content = file_get_contents($cssFile);

        // Process CSS (minify, add vendor prefixes, etc.)
        $processedContent = $this->minifyCss($content);

        // Generate versioned filename
        $version = substr(md5($processedContent), 0, 8);
        $versionedFilename = str_replace('.css', ".{$version}.css", $filename);

        // Write to build directory
        $buildFile = $this->buildPath . '/' . $versionedFilename;
        file_put_contents($buildFile, $processedContent);

        return [
            'type' => 'css',
            'original' => $filename,
            'built' => $versionedFilename,
            'size_original' => filesize($cssFile),
            'size_built' => filesize($buildFile),
        ];
    }

    private function buildJsFile(string $jsFile): array
    {
        $filename = basename($jsFile);
        $content = file_get_contents($jsFile);

        // Process JS (minify, transpile, etc.)
        $processedContent = $this->minifyJs($content);

        // Generate versioned filename
        $version = substr(md5($processedContent), 0, 8);
        $versionedFilename = str_replace('.js', ".{$version}.js", $filename);

        // Write to build directory
        $buildFile = $this->buildPath . '/' . $versionedFilename;
        file_put_contents($buildFile, $processedContent);

        return [
            'type' => 'js',
            'original' => $filename,
            'built' => $versionedFilename,
            'size_original' => filesize($jsFile),
            'size_built' => filesize($buildFile),
        ];
    }

    private function generateManifest(array $built): void
    {
        $manifest = [];

        foreach ($built as $asset) {
            $key = $asset['type'] . '/' . $asset['original'];
            $manifest[$key] = $asset['built'];
        }

        $manifestFile = $this->buildPath . '/manifest.json';
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        $this->manifest = $manifest;
    }

    private function loadManifest(): void
    {
        $manifestFile = $this->buildPath . '/manifest.json';

        if (file_exists($manifestFile)) {
            $content = file_get_contents($manifestFile);
            $this->manifest = json_decode($content, true) ?? [];
        }
    }

    public function inlineCSS(string $file): string
    {
        $path = $this->getAssetPath($file, 'css');

        if (file_exists($path)) {
            $content = file_get_contents($path);
            return "<style>{$content}</style>";
        }

        return "<!-- CSS file not found: {$file} -->";
    }

    public function inlineJS(string $file): string
    {
        $path = $this->getAssetPath($file, 'js');

        if (file_exists($path)) {
            $content = file_get_contents($path);
            return "<script>{$content}</script>";
        }

        return "<!-- JS file not found: {$file} -->";
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function clearBuildCache(): bool
    {
        try {
            $files = glob($this->buildPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            $this->manifest = [];

            $this->logger->info("Asset build cache cleared");

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear asset build cache", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getAssetStats(): array
    {
        $stats = [
            'css_files' => 0,
            'js_files' => 0,
            'image_files' => 0,
            'total_size' => 0,
        ];

        // Count CSS files
        $cssFiles = glob($this->assetsPath . '/css/*.css');
        $stats['css_files'] = count($cssFiles);

        // Count JS files
        $jsFiles = glob($this->assetsPath . '/js/*.js');
        $stats['js_files'] = count($jsFiles);

        // Count image files
        $imageFiles = glob($this->assetsPath . '/images/*');
        $stats['image_files'] = count($imageFiles);

        // Calculate total size
        foreach (array_merge($cssFiles, $jsFiles, $imageFiles) as $file) {
            if (is_file($file)) {
                $stats['total_size'] += filesize($file);
            }
        }

        return $stats;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}