<?php

// Security Scanner Tool Bootstrap File
// This file handles the initial setup and autoloading for the application

// Ensure PHP version compatibility
if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    throw new RuntimeException('Security Scanner Tool requires PHP 8.4 or higher. Current version: ' . PHP_VERSION);
}

// Define root path
define('ROOT_PATH', __DIR__);
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('LOG_PATH', ROOT_PATH . '/logs');
define('CACHE_PATH', ROOT_PATH . '/cache');

// Create necessary directories if they don't exist
$directories = [LOG_PATH, CACHE_PATH];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Load autoloader
require_once SRC_PATH . '/Core/Autoloader.php';

// Register autoloader
SecurityScanner\Core\Autoloader::register();

// Load environment configuration
try {
    $config = SecurityScanner\Core\Config::getInstance();
} catch (Exception $e) {
    error_log('Configuration error: ' . $e->getMessage());

    // In development, show error; in production, show generic error
    if (($_ENV['APP_DEBUG'] ?? false) && ($_ENV['APP_ENV'] ?? 'production') !== 'production') {
        throw $e;
    } else {
        http_response_code(500);
        echo 'Application configuration error. Please check server logs.';
        exit(1);
    }
}

// Set timezone
$timezone = $config->get('app.timezone', 'UTC');
if (!date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
    error_log("Invalid timezone '{$timezone}' specified, falling back to UTC");
}

// Set error reporting based on environment
if ($config->isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// Set memory limit for scheduler if running from CLI
if (php_sapi_name() === 'cli') {
    $memoryLimit = $config->get('scheduler.memory_limit', '512M');
    ini_set('memory_limit', $memoryLimit);

    $executionTime = $config->get('scheduler.max_execution_time', 1800);
    set_time_limit($executionTime);
}

// Register comprehensive error handler
SecurityScanner\Core\ErrorHandler::register($config);

// Initialize dependency injection container
$container = SecurityScanner\Core\Container::getInstance();
$container->registerCoreServices();

// Initialize provider manager (for future service providers)
$providerManager = new SecurityScanner\Core\ProviderManager($container);

// Store container and provider manager in config for global access
$container->instance('provider.manager', $providerManager);

// Return config instance for use by the application
return $config;