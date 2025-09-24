<?php

namespace SecurityScanner\Core;

class ConnectionPool
{
    private static ?ConnectionPool $instance = null;
    private array $pools = [];
    private Config $config;
    private Logger $logger;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::errors();
    }

    public static function getInstance(): ConnectionPool
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection(?string $connectionName = null): \PDO
    {
        $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');

        if (!isset($this->pools[$connectionName])) {
            $this->initializePool($connectionName);
        }

        return $this->borrowConnection($connectionName);
    }

    public function releaseConnection(\PDO $pdo, ?string $connectionName = null): void
    {
        $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');

        if (!isset($this->pools[$connectionName])) {
            return;
        }

        $pool = &$this->pools[$connectionName];

        // Check if connection is still valid
        if ($this->isConnectionValid($pdo)) {
            $pool['available'][] = [
                'connection' => $pdo,
                'last_used' => time(),
            ];

            $this->logger->debug("Database connection returned to pool", [
                'connection_name' => $connectionName,
                'available_connections' => count($pool['available']),
                'active_connections' => $pool['active_count'],
            ]);
        } else {
            $this->logger->warning("Invalid database connection discarded from pool", [
                'connection_name' => $connectionName,
            ]);
        }

        $pool['active_count']--;
    }

    private function initializePool(string $connectionName): void
    {
        $connectionConfig = $this->config->get("database.connections.{$connectionName}");

        if (!$connectionConfig) {
            throw new \InvalidArgumentException("Database connection '{$connectionName}' not configured");
        }

        $poolConfig = $connectionConfig['pool'] ?? [];
        $minConnections = $poolConfig['min_connections'] ?? 1;
        $maxConnections = $poolConfig['max_connections'] ?? 10;

        $this->pools[$connectionName] = [
            'available' => [],
            'active_count' => 0,
            'min_connections' => $minConnections,
            'max_connections' => $maxConnections,
            'connection_timeout' => $poolConfig['connection_timeout'] ?? 5,
            'idle_timeout' => $poolConfig['idle_timeout'] ?? 300,
        ];

        // Create minimum number of connections
        for ($i = 0; $i < $minConnections; $i++) {
            $connection = $this->createNewConnection($connectionName);
            if ($connection) {
                $this->pools[$connectionName]['available'][] = [
                    'connection' => $connection,
                    'last_used' => time(),
                ];
            }
        }

        $this->logger->info("Database connection pool initialized", [
            'connection_name' => $connectionName,
            'min_connections' => $minConnections,
            'max_connections' => $maxConnections,
            'initial_connections' => count($this->pools[$connectionName]['available']),
        ]);
    }

    private function borrowConnection(string $connectionName): \PDO
    {
        $pool = &$this->pools[$connectionName];

        // Try to get an available connection
        if (!empty($pool['available'])) {
            $connectionData = array_pop($pool['available']);
            $connection = $connectionData['connection'];

            // Check if connection is still valid and not too old
            if ($this->isConnectionValid($connection) &&
                (time() - $connectionData['last_used']) < $pool['idle_timeout']) {

                $pool['active_count']++;

                $this->logger->debug("Database connection borrowed from pool", [
                    'connection_name' => $connectionName,
                    'available_connections' => count($pool['available']),
                    'active_connections' => $pool['active_count'],
                ]);

                return $connection;
            }
        }

        // Create new connection if we haven't reached the maximum
        if ($pool['active_count'] < $pool['max_connections']) {
            $connection = $this->createNewConnection($connectionName);
            if ($connection) {
                $pool['active_count']++;

                $this->logger->debug("New database connection created from pool", [
                    'connection_name' => $connectionName,
                    'active_connections' => $pool['active_count'],
                    'max_connections' => $pool['max_connections'],
                ]);

                return $connection;
            }
        }

        // If we reach here, we've exceeded the maximum connections
        $this->logger->warning("Database connection pool exhausted", [
            'connection_name' => $connectionName,
            'active_connections' => $pool['active_count'],
            'max_connections' => $pool['max_connections'],
        ]);

        throw new \RuntimeException("Database connection pool exhausted for '{$connectionName}'");
    }

    private function createNewConnection(string $connectionName): ?\PDO
    {
        try {
            $database = Database::getInstance();
            return $database->getConnection($connectionName);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create new database connection for pool", [
                'connection_name' => $connectionName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isConnectionValid(\PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function cleanupIdleConnections(): int
    {
        $cleanedCount = 0;

        foreach ($this->pools as $connectionName => &$pool) {
            $currentTime = time();
            $validConnections = [];

            foreach ($pool['available'] as $connectionData) {
                if (($currentTime - $connectionData['last_used']) < $pool['idle_timeout'] &&
                    $this->isConnectionValid($connectionData['connection'])) {
                    $validConnections[] = $connectionData;
                } else {
                    $cleanedCount++;
                }
            }

            $pool['available'] = $validConnections;
        }

        if ($cleanedCount > 0) {
            $this->logger->info("Cleaned up idle database connections", [
                'connections_cleaned' => $cleanedCount,
            ]);
        }

        return $cleanedCount;
    }

    public function getPoolStats(): array
    {
        $stats = [];

        foreach ($this->pools as $connectionName => $pool) {
            $stats[$connectionName] = [
                'available_connections' => count($pool['available']),
                'active_connections' => $pool['active_count'],
                'min_connections' => $pool['min_connections'],
                'max_connections' => $pool['max_connections'],
                'pool_utilization' => round(($pool['active_count'] / $pool['max_connections']) * 100, 2),
            ];
        }

        return $stats;
    }

    public function closeAllConnections(): void
    {
        foreach ($this->pools as $connectionName => &$pool) {
            $totalConnections = count($pool['available']) + $pool['active_count'];

            $pool['available'] = [];
            $pool['active_count'] = 0;

            $this->logger->info("All database connections closed for pool", [
                'connection_name' => $connectionName,
                'connections_closed' => $totalConnections,
            ]);
        }

        $this->pools = [];
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}