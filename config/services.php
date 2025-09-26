<?php

return [
    'website_service' => [
        'validation' => [
            'url_accessibility_check' => filter_var($_ENV['WEBSITE_SERVICE_URL_CHECK'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'duplicate_url_prevention' => filter_var($_ENV['WEBSITE_SERVICE_DUPLICATE_CHECK'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ],
        'defaults' => [
            'scan_frequency' => $_ENV['WEBSITE_SERVICE_DEFAULT_FREQUENCY'] ?? 'daily',
            'timeout' => (int)($_ENV['WEBSITE_SERVICE_DEFAULT_TIMEOUT'] ?? 30),
        ],
    ],

    'notification_service' => [
        'email' => [
            'smtp_host' => $_ENV['MAIL_HOST'] ?? 'localhost',
            'smtp_port' => (int)($_ENV['MAIL_PORT'] ?? 587),
            'smtp_username' => $_ENV['MAIL_USERNAME'] ?? '',
            'smtp_password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'smtp_encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
            'from_email' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@security-scanner.local',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Security Scanner',
        ],
        'webhook' => [
            'timeout' => (int)($_ENV['WEBHOOK_TIMEOUT'] ?? 30),
            'max_retries' => (int)($_ENV['WEBHOOK_MAX_RETRIES'] ?? 3),
        ],
        'rate_limiting' => [
            'enabled' => filter_var($_ENV['NOTIFICATION_RATE_LIMIT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'per_hour' => (int)($_ENV['NOTIFICATION_RATE_LIMIT_PER_HOUR'] ?? 100),
        ],
    ],

    'metrics_service' => [
        'cache_ttl' => (int)($_ENV['METRICS_CACHE_TTL'] ?? 300), // 5 minutes
        'retention_days' => (int)($_ENV['METRICS_RETENTION_DAYS'] ?? 365),
        'aggregation_intervals' => ['hourly', 'daily', 'weekly', 'monthly'],
    ],

    'archive_service' => [
        'retention_days' => [
            'scan_results' => (int)($_ENV['ARCHIVE_SCAN_RESULTS_RETENTION'] ?? 365),
            'test_executions' => (int)($_ENV['ARCHIVE_TEST_EXECUTIONS_RETENTION'] ?? 365),
            'scheduler_log' => (int)($_ENV['ARCHIVE_SCHEDULER_LOG_RETENTION'] ?? 90),
            'notification_log' => (int)($_ENV['ARCHIVE_NOTIFICATION_LOG_RETENTION'] ?? 90),
        ],
        'compression_enabled' => filter_var($_ENV['ARCHIVE_COMPRESSION_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'encryption_enabled' => filter_var($_ENV['ARCHIVE_ENCRYPTION_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],

    'queue_service' => [
        'max_workers' => (int)($_ENV['QUEUE_MAX_WORKERS'] ?? 5),
        'worker_memory_limit' => $_ENV['QUEUE_WORKER_MEMORY_LIMIT'] ?? '256M',
        'job_timeout' => (int)($_ENV['QUEUE_JOB_TIMEOUT'] ?? 300),
        'max_retries' => (int)($_ENV['QUEUE_MAX_RETRIES'] ?? 3),
        'cleanup_completed_jobs_after' => (int)($_ENV['QUEUE_CLEANUP_AFTER'] ?? 86400), // 24 hours
    ],

    'backup_service' => [
        'compression_enabled' => filter_var($_ENV['BACKUP_COMPRESSION_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'encryption_enabled' => filter_var($_ENV['BACKUP_ENCRYPTION_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'retention_days' => (int)($_ENV['BACKUP_RETENTION_DAYS'] ?? 30),
        'verify_backups' => filter_var($_ENV['BACKUP_VERIFY_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],

    'scheduler_service' => [
        'max_concurrent_executions' => (int)($_ENV['SCHEDULER_MAX_CONCURRENT'] ?? 5),
        'max_execution_time' => (int)($_ENV['SCHEDULER_MAX_EXECUTION_TIME'] ?? 3600), // 1 hour
        'memory_limit' => $_ENV['SCHEDULER_MEMORY_LIMIT'] ?? '512M',
        'batch_size' => (int)($_ENV['SCHEDULER_BATCH_SIZE'] ?? 10),
        'health_check_interval' => (int)($_ENV['SCHEDULER_HEALTH_CHECK_INTERVAL'] ?? 300), // 5 minutes
    ],

    'resource_monitor_service' => [
        'monitoring_interval' => (int)($_ENV['RESOURCE_MONITOR_INTERVAL'] ?? 30),
        'enable_auto_throttling' => filter_var($_ENV['RESOURCE_MONITOR_AUTO_THROTTLE'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'enable_alerts' => filter_var($_ENV['RESOURCE_MONITOR_ALERTS'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'thresholds' => [
            'cpu_usage' => [
                'warning' => (int)($_ENV['RESOURCE_THRESHOLD_CPU_WARNING'] ?? 70),
                'critical' => (int)($_ENV['RESOURCE_THRESHOLD_CPU_CRITICAL'] ?? 85),
                'throttle' => (int)($_ENV['RESOURCE_THRESHOLD_CPU_THROTTLE'] ?? 90),
            ],
            'memory_usage' => [
                'warning' => (int)($_ENV['RESOURCE_THRESHOLD_MEMORY_WARNING'] ?? 75),
                'critical' => (int)($_ENV['RESOURCE_THRESHOLD_MEMORY_CRITICAL'] ?? 90),
                'throttle' => (int)($_ENV['RESOURCE_THRESHOLD_MEMORY_THROTTLE'] ?? 95),
            ],
        ],
    ],
];