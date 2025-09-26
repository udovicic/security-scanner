<?php

return [
    'default' => $_ENV['DB_CONNECTION'] ?? 'mysql',

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'security_scanner',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => $_ENV['DB_SSL_CA'] ?? null,
                PDO::MYSQL_ATTR_SSL_CERT => $_ENV['DB_SSL_CERT'] ?? null,
                PDO::MYSQL_ATTR_SSL_KEY => $_ENV['DB_SSL_KEY'] ?? null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => filter_var($_ENV['DB_SSL_VERIFY'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $_ENV['DB_DATABASE'] ?? database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'pool' => [
        'enabled' => filter_var($_ENV['DB_POOL_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'min_connections' => (int)($_ENV['DB_POOL_MIN'] ?? 2),
        'max_connections' => (int)($_ENV['DB_POOL_MAX'] ?? 10),
        'timeout' => (int)($_ENV['DB_POOL_TIMEOUT'] ?? 30),
    ],

    'migrations' => [
        'table' => 'migrations',
        'path' => base_path('migrations'),
    ],

    'backup' => [
        'enabled' => filter_var($_ENV['DB_BACKUP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'path' => storage_path('backups'),
        'compression' => filter_var($_ENV['DB_BACKUP_COMPRESSION'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'encryption' => filter_var($_ENV['DB_BACKUP_ENCRYPTION'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'retention_days' => (int)($_ENV['DB_BACKUP_RETENTION'] ?? 30),
    ],
];