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

    'enable_read_replicas' => filter_var($_ENV['DB_READ_REPLICAS_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),

    'read_replicas' => [
        'mysql_read_1' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_READ_HOST_1'] ?? '127.0.0.1',
            'port' => $_ENV['DB_READ_PORT_1'] ?? '3306',
            'database' => $_ENV['DB_READ_DATABASE_1'] ?? 'security_scanner',
            'username' => $_ENV['DB_READ_USERNAME_1'] ?? 'readonly',
            'password' => $_ENV['DB_READ_PASSWORD_1'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => $_ENV['DB_READ_SSL_CA_1'] ?? null,
                PDO::MYSQL_ATTR_SSL_CERT => $_ENV['DB_READ_SSL_CERT_1'] ?? null,
                PDO::MYSQL_ATTR_SSL_KEY => $_ENV['DB_READ_SSL_KEY_1'] ?? null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => filter_var($_ENV['DB_READ_SSL_VERIFY_1'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        ],

        'mysql_read_2' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_READ_HOST_2'] ?? '127.0.0.1',
            'port' => $_ENV['DB_READ_PORT_2'] ?? '3306',
            'database' => $_ENV['DB_READ_DATABASE_2'] ?? 'security_scanner',
            'username' => $_ENV['DB_READ_USERNAME_2'] ?? 'readonly',
            'password' => $_ENV['DB_READ_PASSWORD_2'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => $_ENV['DB_READ_SSL_CA_2'] ?? null,
                PDO::MYSQL_ATTR_SSL_CERT => $_ENV['DB_READ_SSL_CERT_2'] ?? null,
                PDO::MYSQL_ATTR_SSL_KEY => $_ENV['DB_READ_SSL_KEY_2'] ?? null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => filter_var($_ENV['DB_READ_SSL_VERIFY_2'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        ],
    ],
];