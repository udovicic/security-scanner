<?php

namespace SecurityScanner\Core;

class Config
{
    private static ?Config $instance = null;
    private array $config = [];
    private EnvLoader $envLoader;

    private function __construct(string $basePath = null)
    {
        $basePath = $basePath ?? __DIR__ . '/../..';

        // Load .env file (only one per environment)
        $envFile = $basePath . '/.env';
        if (file_exists($envFile)) {
            $this->envLoader = new EnvLoader($envFile);
            $this->envLoader->load();
        } else {
            throw new \RuntimeException('.env file not found. Copy .env.example to .env and configure your environment.');
        }

        $this->loadConfigurations();
    }

    public static function getInstance(string $basePath = null): Config
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }

        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $keyPart) {
            if (!isset($value[$keyPart])) {
                return $default;
            }
            $value = $value[$keyPart];
        }

        return $value;
    }

    public function env(string $key, $default = null)
    {
        return $this->envLoader->get($key, $default);
    }

    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $keyPart) {
            if (!isset($config[$keyPart]) || !is_array($config[$keyPart])) {
                $config[$keyPart] = [];
            }
            $config = &$config[$keyPart];
        }

        $config = $value;
    }

    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $keyPart) {
            if (!isset($value[$keyPart])) {
                return false;
            }
            $value = $value[$keyPart];
        }

        return true;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function getEnvironment(): string
    {
        return $this->env('APP_ENV', 'development');
    }

    public function isDebug(): bool
    {
        return (bool) $this->env('APP_DEBUG', false);
    }

    public function isDevelopment(): bool
    {
        return $this->getEnvironment() === 'development';
    }

    public function isProduction(): bool
    {
        return $this->getEnvironment() === 'production';
    }

    public function isTesting(): bool
    {
        return $this->getEnvironment() === 'testing';
    }

    private function loadConfigurations(): void
    {
        $this->config = [
            'app' => [
                'name' => $this->env('APP_NAME', 'Security Scanner Tool'),
                'environment' => $this->env('APP_ENV', 'development'),
                'debug' => (bool) $this->env('APP_DEBUG', true),
                'timezone' => $this->env('APP_TIMEZONE', 'UTC'),
                'url' => $this->env('APP_URL', 'http://localhost'),
            ],

            'database' => [
                'default' => $this->env('DB_CONNECTION', 'mysql'),
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'host' => $this->env('DB_HOST', 'localhost'),
                        'port' => (int) $this->env('DB_PORT', 3306),
                        'database' => $this->env('DB_DATABASE', 'security_scanner'),
                        'username' => $this->env('DB_USERNAME', 'root'),
                        'password' => $this->env('DB_PASSWORD', ''),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'options' => array_filter([
                            \PDO::MYSQL_ATTR_SSL_CA => $this->env('DB_SSL_CA') ?: null,
                            \PDO::MYSQL_ATTR_SSL_CERT => $this->env('DB_SSL_CERT') ?: null,
                            \PDO::MYSQL_ATTR_SSL_KEY => $this->env('DB_SSL_KEY') ?: null,
                            \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $this->env('DB_SSL_VERIFY') ? true : null,
                        ], function($value) { return $value !== null; }),
                        'pool' => [
                            'min_connections' => 1,
                            'max_connections' => (int) $this->env('DB_MAX_CONNECTIONS', 10),
                            'connection_timeout' => 5,
                            'idle_timeout' => 300,
                        ],
                    ],
                    'mysql_read' => [
                        'driver' => 'mysql',
                        'host' => $this->env('DB_READ_HOST', $this->env('DB_HOST', 'localhost')),
                        'port' => (int) $this->env('DB_READ_PORT', $this->env('DB_PORT', 3306)),
                        'database' => $this->env('DB_DATABASE', 'security_scanner'),
                        'username' => $this->env('DB_READ_USERNAME', $this->env('DB_USERNAME', 'root')),
                        'password' => $this->env('DB_READ_PASSWORD', $this->env('DB_PASSWORD', '')),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'options' => [],
                    ],
                    'testing' => [
                        'driver' => 'mysql',
                        'host' => $this->env('DB_TEST_HOST', 'localhost'),
                        'port' => (int) $this->env('DB_TEST_PORT', 3306),
                        'database' => $this->env('DB_TEST_DATABASE', 'security_scanner_test'),
                        'username' => $this->env('DB_TEST_USERNAME', 'root'),
                        'password' => $this->env('DB_TEST_PASSWORD', ''),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'options' => [],
                    ],
                ],
                'migrations' => [
                    'table' => 'migrations',
                    'path' => __DIR__ . '/../../migrations',
                ],
            ],

            'security' => [
                'csrf_token_lifetime' => (int) $this->env('CSRF_TOKEN_LIFETIME', 3600),
                'session_lifetime' => (int) $this->env('SESSION_LIFETIME', 7200),
                'password_hash_algo' => PASSWORD_ARGON2ID,
                'password_hash_options' => [
                    'memory_cost' => 65536,
                    'time_cost' => 4,
                    'threads' => 3,
                ],
                'rate_limit' => [
                    'max_requests' => (int) $this->env('RATE_LIMIT_MAX_REQUESTS', 60),
                    'time_window' => (int) $this->env('RATE_LIMIT_TIME_WINDOW', 60),
                ],
            ],

            'logging' => [
                'channels' => [
                    'access' => [
                        'path' => $this->env('LOG_ACCESS_PATH', __DIR__ . '/../../logs/access.log'),
                        'level' => 'info',
                    ],
                    'error' => [
                        'path' => $this->env('LOG_ERROR_PATH', __DIR__ . '/../../logs/error.log'),
                        'level' => 'error',
                    ],
                    'scheduler' => [
                        'path' => $this->env('LOG_SCHEDULER_PATH', __DIR__ . '/../../logs/scheduler.log'),
                        'level' => 'info',
                    ],
                    'security' => [
                        'path' => $this->env('LOG_SECURITY_PATH', __DIR__ . '/../../logs/security.log'),
                        'level' => 'warning',
                    ],
                    'email_notifications' => [
                        'path' => $this->env('LOG_EMAIL_PATH', __DIR__ . '/../../logs/email.log'),
                        'level' => 'info',
                    ],
                    'sms_notifications' => [
                        'path' => $this->env('LOG_SMS_PATH', __DIR__ . '/../../logs/sms.log'),
                        'level' => 'info',
                    ],
                    'webhook_notifications' => [
                        'path' => $this->env('LOG_WEBHOOK_PATH', __DIR__ . '/../../logs/webhook.log'),
                        'level' => 'info',
                    ],
                    'notifications' => [
                        'path' => $this->env('LOG_NOTIFICATIONS_PATH', __DIR__ . '/../../logs/notifications.log'),
                        'level' => 'info',
                    ],
                    'templates' => [
                        'path' => $this->env('LOG_TEMPLATES_PATH', __DIR__ . '/../../logs/templates.log'),
                        'level' => 'info',
                    ],
                    'escalation' => [
                        'path' => $this->env('LOG_ESCALATION_PATH', __DIR__ . '/../../logs/escalation.log'),
                        'level' => 'info',
                    ],
                    'alert_escalation' => [
                        'path' => $this->env('LOG_ALERT_ESCALATION_PATH', __DIR__ . '/../../logs/alert_escalation.log'),
                        'level' => 'info',
                    ],
                ],
            ],

            'caching' => [
                'default_ttl' => (int) $this->env('CACHE_DEFAULT_TTL', 3600),
                'query_cache_ttl' => (int) $this->env('CACHE_QUERY_TTL', 600),
                'static_assets_ttl' => (int) $this->env('CACHE_STATIC_ASSETS_TTL', 86400),
            ],

            'scheduler' => [
                'lock_timeout' => (int) $this->env('SCHEDULER_LOCK_TIMEOUT', 300),
                'max_execution_time' => (int) $this->env('SCHEDULER_MAX_EXECUTION_TIME', 1800),
                'memory_limit' => $this->env('SCHEDULER_MEMORY_LIMIT', '512M'),
                'retry_attempts' => (int) $this->env('SCHEDULER_RETRY_ATTEMPTS', 3),
                'retry_delay' => (int) $this->env('SCHEDULER_RETRY_DELAY', 60),
            ],

            'tests' => [
                'timeout' => (int) $this->env('TEST_TIMEOUT', 30),
                'user_agent' => $this->env('TEST_USER_AGENT', 'Security Scanner Bot/1.0'),
                'max_redirects' => (int) $this->env('TEST_MAX_REDIRECTS', 5),
                'verify_ssl' => (bool) $this->env('TEST_VERIFY_SSL', true),
            ],

            'notifications' => [
                'email' => [
                    'enabled' => (bool) $this->env('EMAIL_ENABLED', false),
                    'from' => $this->env('EMAIL_FROM', 'noreply@security-scanner.local'),
                    'from_name' => $this->env('EMAIL_FROM_NAME', 'Security Scanner'),
                    'host' => $this->env('EMAIL_HOST'),
                    'port' => (int) $this->env('EMAIL_PORT', 587),
                    'username' => $this->env('EMAIL_USERNAME'),
                    'password' => $this->env('EMAIL_PASSWORD'),
                    'encryption' => $this->env('EMAIL_ENCRYPTION', 'tls'),
                ],
                'webhook' => [
                    'enabled' => (bool) $this->env('WEBHOOK_ENABLED', false),
                    'url' => $this->env('WEBHOOK_URL'),
                    'secret' => $this->env('WEBHOOK_SECRET'),
                    'timeout' => 10,
                ],
            ],
        ];
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}