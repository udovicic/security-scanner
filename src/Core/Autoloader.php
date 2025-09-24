<?php

namespace SecurityScanner\Core;

class Autoloader
{
    private static bool $registered = false;
    private static array $namespaces = [];
    private static array $classMap = [];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;

        // Register default namespace
        self::addNamespace('SecurityScanner', __DIR__ . '/..');
    }

    public static function addNamespace(string $namespace, string $directory): void
    {
        $namespace = trim($namespace, '\\') . '\\';
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!isset(self::$namespaces[$namespace])) {
            self::$namespaces[$namespace] = [];
        }

        self::$namespaces[$namespace][] = $directory;
    }

    public static function addClassMap(string $className, string $filePath): void
    {
        self::$classMap[$className] = $filePath;
    }

    public static function loadClass(string $className): bool
    {
        // Check class map first
        if (isset(self::$classMap[$className])) {
            require_once self::$classMap[$className];
            return true;
        }

        // Try to load via PSR-4 namespace mapping
        foreach (self::$namespaces as $namespace => $directories) {
            if (strpos($className, $namespace) === 0) {
                $relativeClass = substr($className, strlen($namespace));

                foreach ($directories as $directory) {
                    $filePath = $directory . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

                    if (self::loadFile($filePath)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function loadFile(string $filePath): bool
    {
        if (file_exists($filePath)) {
            require_once $filePath;
            return true;
        }

        return false;
    }

    public static function getNamespaces(): array
    {
        return self::$namespaces;
    }

    public static function getClassMap(): array
    {
        return self::$classMap;
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    public static function unregister(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'loadClass']);
            self::$registered = false;
        }
    }
}