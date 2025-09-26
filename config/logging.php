<?php

return [
    'default' => $_ENV['LOG_CHANNEL'] ?? 'daily',

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily', 'error'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/application.log'),
            'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/application.log'),
            'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 14),
        ],

        'error' => [
            'driver' => 'daily',
            'path' => storage_path('logs/error.log'),
            'level' => 'error',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 30),
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'info',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 90),
        ],

        'access' => [
            'driver' => 'daily',
            'path' => storage_path('logs/access.log'),
            'level' => 'info',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 30),
        ],

        'scheduler' => [
            'driver' => 'daily',
            'path' => storage_path('logs/scheduler.log'),
            'level' => 'debug',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 14),
        ],

        'performance' => [
            'driver' => 'daily',
            'path' => storage_path('logs/performance.log'),
            'level' => 'info',
            'days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 7),
        ],
    ],

    'processors' => [
        'web' => [
            'add_request_id' => true,
            'add_user_id' => true,
            'add_ip_address' => true,
            'add_user_agent' => false,
        ],
        'cli' => [
            'add_process_id' => true,
            'add_memory_usage' => true,
        ],
    ],

    'emergency_email' => $_ENV['LOG_EMERGENCY_EMAIL'] ?? null,
    'max_file_size' => $_ENV['LOG_MAX_FILE_SIZE'] ?? '100M',
];