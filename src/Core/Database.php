<?php

namespace SecurityScanner\Core;

class Database
{
    private static ?Database $instance = null;
    private array $connections = [];
    private Config $config;
    private Logger $logger;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::errors();
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection(?string $connectionName = null): \PDO
    {
        $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');

        if (!isset($this->connections[$connectionName])) {
            $this->connections[$connectionName] = $this->createConnection($connectionName);
        }

        return $this->connections[$connectionName];
    }

    public function getReadConnection(): \PDO
    {
        return $this->getConnection('mysql_read');
    }

    public function getWriteConnection(): \PDO
    {
        return $this->getConnection('mysql');
    }

    public function getTestConnection(): \PDO
    {
        return $this->getConnection('testing');
    }

    private function createConnection(string $connectionName): \PDO
    {
        $connectionConfig = $this->config->get("database.connections.{$connectionName}");

        if (!$connectionConfig) {
            throw new \InvalidArgumentException("Database connection '{$connectionName}' not configured");
        }

        $dsn = $this->buildDsn($connectionConfig);
        $username = $connectionConfig['username'];
        $password = $connectionConfig['password'];
        $options = $this->prepareOptions($connectionConfig['options'] ?? []);

        try {
            $startTime = microtime(true);

            $pdo = new \PDO($dsn, $username, $password, $options);

            $connectionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info("Database connection established", [
                'connection' => $connectionName,
                'host' => $connectionConfig['host'],
                'database' => $connectionConfig['database'],
                'connection_time_ms' => $connectionTime,
            ]);

            // Set additional PDO attributes
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // Set charset for MySQL connections
            if ($connectionConfig['driver'] === 'mysql') {
                $charset = $connectionConfig['charset'] ?? 'utf8mb4';
                $pdo->exec("SET NAMES {$charset}");
            }

            return $pdo;

        } catch (\PDOException $e) {
            $this->logger->critical("Database connection failed", [
                'connection' => $connectionName,
                'host' => $connectionConfig['host'],
                'database' => $connectionConfig['database'],
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to connect to database '{$connectionName}': " . $e->getMessage(), 0, $e);
        }
    }

    private function buildDsn(array $config): string
    {
        $driver = $config['driver'];
        $host = $config['host'];
        $port = $config['port'];
        $database = $config['database'];
        $charset = $config['charset'] ?? 'utf8mb4';

        switch ($driver) {
            case 'mysql':
                return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

            case 'pgsql':
                return "pgsql:host={$host};port={$port};dbname={$database}";

            case 'sqlite':
                return "sqlite:{$database}";

            default:
                throw new \InvalidArgumentException("Unsupported database driver: {$driver}");
        }
    }

    private function prepareOptions(array $options): array
    {
        $defaultOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
        ];

        // Filter out null SSL options to avoid PDO errors
        $filteredOptions = [];
        foreach ($options as $key => $value) {
            // Skip SSL options that are null or empty
            if (in_array($key, [\PDO::MYSQL_ATTR_SSL_CA, \PDO::MYSQL_ATTR_SSL_CERT, \PDO::MYSQL_ATTR_SSL_KEY])
                && ($value === null || $value === '')) {
                continue;
            }

            $filteredOptions[$key] = $value;
        }

        return $defaultOptions + $filteredOptions;
    }

    public function testConnection(?string $connectionName = null): bool
    {
        try {
            $pdo = $this->getConnection($connectionName);
            $pdo->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            $this->logger->warning("Database connection test failed", [
                'connection' => $connectionName ?? 'default',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function closeConnection(?string $connectionName = null): void
    {
        $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');

        if (isset($this->connections[$connectionName])) {
            unset($this->connections[$connectionName]);

            $this->logger->info("Database connection closed", [
                'connection' => $connectionName,
            ]);
        }
    }

    public function closeAllConnections(): void
    {
        foreach (array_keys($this->connections) as $connectionName) {
            $this->closeConnection($connectionName);
        }
    }

    public function getConnectionInfo(?string $connectionName = null): array
    {
        $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');
        $config = $this->config->get("database.connections.{$connectionName}");

        if (!$config) {
            throw new \InvalidArgumentException("Database connection '{$connectionName}' not configured");
        }

        return [
            'connection_name' => $connectionName,
            'driver' => $config['driver'],
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'charset' => $config['charset'] ?? 'utf8mb4',
            'ssl_enabled' => !empty($config['options'][\PDO::MYSQL_ATTR_SSL_CA]),
        ];
    }

    public function query(string $sql, array $params = [], ?string $connectionName = null): \PDOStatement
    {
        $pdo = $this->getConnection($connectionName);

        $startTime = microtime(true);

        try {
            if (empty($params)) {
                $statement = $pdo->query($sql);
            } else {
                $statement = $pdo->prepare($sql);
                $statement->execute($params);
            }

            $queryTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($queryTime > 1000) { // Log slow queries (>1 second)
                $this->logger->warning("Slow database query detected", [
                    'query_time_ms' => $queryTime,
                    'sql' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                    'connection' => $connectionName ?? 'default',
                ]);
            }

            return $statement;

        } catch (\PDOException $e) {
            $this->logger->error("Database query failed", [
                'sql' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                'params' => $params,
                'connection' => $connectionName ?? 'default',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function beginTransaction(?string $connectionName = null): bool
    {
        $pdo = $this->getConnection($connectionName);

        $result = $pdo->beginTransaction();

        $this->logger->debug("Database transaction started", [
            'connection' => $connectionName ?? 'default',
        ]);

        return $result;
    }

    public function commit(?string $connectionName = null): bool
    {
        $pdo = $this->getConnection($connectionName);

        $result = $pdo->commit();

        $this->logger->debug("Database transaction committed", [
            'connection' => $connectionName ?? 'default',
        ]);

        return $result;
    }

    public function rollback(?string $connectionName = null): bool
    {
        $pdo = $this->getConnection($connectionName);

        $result = $pdo->rollback();

        $this->logger->info("Database transaction rolled back", [
            'connection' => $connectionName ?? 'default',
        ]);

        return $result;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}