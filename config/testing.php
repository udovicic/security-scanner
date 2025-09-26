<?php

return [
    'default_timeout' => (int)($_ENV['TEST_DEFAULT_TIMEOUT'] ?? 30),
    'max_timeout' => (int)($_ENV['TEST_MAX_TIMEOUT'] ?? 300),
    'default_retries' => (int)($_ENV['TEST_DEFAULT_RETRIES'] ?? 3),
    'max_retries' => (int)($_ENV['TEST_MAX_RETRIES'] ?? 5),

    'parallel_execution' => [
        'enabled' => filter_var($_ENV['TEST_PARALLEL_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'max_workers' => (int)($_ENV['TEST_MAX_WORKERS'] ?? 5),
        'memory_limit' => $_ENV['TEST_WORKER_MEMORY_LIMIT'] ?? '256M',
    ],

    'test_types' => [
        'security' => [
            'enabled' => filter_var($_ENV['TEST_SECURITY_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'priority' => 'high',
            'default_timeout' => 60,
        ],
        'performance' => [
            'enabled' => filter_var($_ENV['TEST_PERFORMANCE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'priority' => 'normal',
            'default_timeout' => 30,
        ],
        'availability' => [
            'enabled' => filter_var($_ENV['TEST_AVAILABILITY_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'priority' => 'high',
            'default_timeout' => 15,
        ],
        'custom' => [
            'enabled' => filter_var($_ENV['TEST_CUSTOM_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'priority' => 'low',
            'default_timeout' => 30,
        ],
    ],

    'result_storage' => [
        'detailed_logs' => filter_var($_ENV['TEST_DETAILED_LOGS'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'retention_days' => (int)($_ENV['TEST_RESULT_RETENTION'] ?? 365),
        'compression' => filter_var($_ENV['TEST_RESULT_COMPRESSION'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],

    'plugins' => [
        'auto_discovery' => filter_var($_ENV['TEST_PLUGIN_AUTO_DISCOVERY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'plugin_directories' => [
            base_path('src/Tests/SecurityTests'),
            base_path('src/Tests/PerformanceTests'),
            base_path('src/Tests/CustomTests'),
        ],
    ],

    'notifications' => [
        'on_failure' => filter_var($_ENV['TEST_NOTIFY_ON_FAILURE'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'on_recovery' => filter_var($_ENV['TEST_NOTIFY_ON_RECOVERY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'failure_threshold' => (int)($_ENV['TEST_FAILURE_THRESHOLD'] ?? 3),
    ],
];