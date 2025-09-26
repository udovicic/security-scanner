<?php

return [
    'name' => 'Security Scanner Tool',
    'version' => '1.0.0',
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',

    'key' => $_ENV['APP_KEY'] ?? '',
    'cipher' => 'AES-256-CBC',

    'providers' => [
        // Core service providers will be registered here
        SecurityScanner\Core\Container::class,
        SecurityScanner\Core\Database::class,
        SecurityScanner\Core\Logger::class,
    ],

    'aliases' => [
        'DB' => SecurityScanner\Core\Database::class,
        'Logger' => SecurityScanner\Core\Logger::class,
        'Config' => SecurityScanner\Core\Config::class,
    ],
];