<?php

namespace SecurityScanner\Core;

class Database
{
    private static ?Database $instance = null;
    private array $connections = [];
    private Config $config;
    private Logger $logger;
    private SqlSecurityValidator $sqlValidator;
    private bool $enableSqlValidation = true;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::errors();
        $this->sqlValidator = new SqlSecurityValidator();
        $this->enableSqlValidation = $this->config->get('database.enable_sql_validation', true);
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
        if ($this->enableSqlValidation) {
            $validation = $this->sqlValidator->validateQuery($sql, $params);

            if (!$validation['is_safe']) {
                $this->logger->error("Unsafe SQL query blocked", [
                    'query_hash' => hash('sha256', $sql),
                    'issues' => $validation['issues'],
                    'risk_level' => $validation['risk_level'],
                    'connection' => $connectionName ?? 'default'
                ]);

                throw new \SecurityException('SQL query failed security validation: ' . implode(', ', $validation['issues']));
            }

            if ($validation['risk_level'] === 'medium') {
                $this->logger->warning("Medium risk SQL query executed", [
                    'query_hash' => hash('sha256', $sql),
                    'issues' => $validation['issues'],
                    'recommendations' => $validation['recommendations'],
                    'connection' => $connectionName ?? 'default'
                ]);
            }
        }

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

    public function fetchRow(string $sql, array $params = [], ?string $connectionName = null): ?array
    {
        $statement = $this->query($sql, $params, $connectionName);
        $result = $statement->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = [], ?string $connectionName = null): array
    {
        $statement = $this->query($sql, $params, $connectionName);
        return $statement->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], ?string $connectionName = null): mixed
    {
        $statement = $this->query($sql, $params, $connectionName);
        return $statement->fetchColumn();
    }

    public function execute(string $sql, array $params = [], ?string $connectionName = null): bool
    {
        $statement = $this->query($sql, $params, $connectionName);
        return $statement !== false;
    }

    public function insert(string $table, array $data, ?string $connectionName = null): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";

        $this->execute($sql, array_values($data), $connectionName);

        return (int) $this->getConnection($connectionName)->lastInsertId();
    }

    public function update(string $table, array $data, array $where, ?string $connectionName = null): bool
    {
        $setParts = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);

        return $this->execute($sql, $params, $connectionName);
    }

    public function delete(string $table, array $where, ?string $connectionName = null): bool
    {
        $whereParts = [];
        $params = [];

        foreach ($where as $column => $value) {
            $whereParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereParts);

        return $this->execute($sql, $params, $connectionName);
    }

    public function enableSqlValidation(bool $enable = true): void
    {
        $this->enableSqlValidation = $enable;
    }

    public function validateQuery(string $sql, array $params = []): array
    {
        return $this->sqlValidator->validateQuery($sql, $params);
    }

    public function getQueryAnalytics(int $hours = 1): array
    {
        return [
            'total_queries' => 0,
            'avg_execution_time' => 0,
            'slow_queries' => 0,
            'failed_queries' => 0,
            'security_blocks' => 0
        ];
    }

    public function verifyConnectionSecurity(?string $connectionName = null): array
    {
        try {
            $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');
            $pdo = $this->getConnection($connectionName);
            $connectionConfig = $this->config->get("database.connections.{$connectionName}");

            $result = [
                'connection_name' => $connectionName,
                'is_secure' => true,
                'issues' => [],
                'recommendations' => [],
                'encryption_status' => 'unknown',
                'ssl_cipher' => null,
                'ssl_version' => null
            ];

            if ($connectionConfig['driver'] === 'mysql') {
                $this->verifyMysqlSecurity($pdo, $connectionConfig, $result);
            } elseif ($connectionConfig['driver'] === 'pgsql') {
                $this->verifyPostgresSecurity($pdo, $connectionConfig, $result);
            }

            $this->checkConnectionConfiguration($connectionConfig, $result);

            $this->logger->info("Database security verification completed", [
                'connection' => $connectionName,
                'is_secure' => $result['is_secure'],
                'issues_count' => count($result['issues'])
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Database security verification failed", [
                'connection' => $connectionName ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return [
                'connection_name' => $connectionName,
                'is_secure' => false,
                'issues' => ['Failed to verify connection security: ' . $e->getMessage()],
                'recommendations' => ['Check database connection and configuration'],
                'encryption_status' => 'unknown'
            ];
        }
    }

    public function enforceEncryption(?string $connectionName = null): bool
    {
        try {
            $connectionName = $connectionName ?? $this->config->get('database.default', 'mysql');
            $verification = $this->verifyConnectionSecurity($connectionName);

            if (!$verification['is_secure']) {
                $this->logger->critical("Insecure database connection detected", [
                    'connection' => $connectionName,
                    'issues' => $verification['issues']
                ]);

                throw new \SecurityException('Database connection security requirements not met: ' . implode(', ', $verification['issues']));
            }

            if ($verification['encryption_status'] !== 'enabled') {
                $this->logger->warning("Database connection encryption not verified", [
                    'connection' => $connectionName,
                    'encryption_status' => $verification['encryption_status']
                ]);

                if ($this->config->get('database.require_encryption', false)) {
                    throw new \SecurityException('Database encryption is required but not verified');
                }
            }

            return true;

        } catch (\SecurityException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to enforce database encryption", [
                'connection' => $connectionName ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function testAllConnectionsSecurity(): array
    {
        $results = [];
        $connections = $this->config->get('database.connections', []);

        foreach (array_keys($connections) as $connectionName) {
            try {
                $results[$connectionName] = $this->verifyConnectionSecurity($connectionName);
            } catch (\Exception $e) {
                $results[$connectionName] = [
                    'connection_name' => $connectionName,
                    'is_secure' => false,
                    'issues' => ['Connection test failed: ' . $e->getMessage()],
                    'recommendations' => ['Check connection configuration'],
                    'encryption_status' => 'unknown'
                ];
            }
        }

        return $results;
    }

    private function verifyMysqlSecurity(\PDO $pdo, array $config, array &$result): void
    {
        try {
            // Check SSL status
            $sslStatus = $pdo->query("SHOW STATUS LIKE 'Ssl_%'")->fetchAll(\PDO::FETCH_KEY_PAIR);

            if (isset($sslStatus['Ssl_cipher']) && !empty($sslStatus['Ssl_cipher'])) {
                $result['encryption_status'] = 'enabled';
                $result['ssl_cipher'] = $sslStatus['Ssl_cipher'];
                $result['ssl_version'] = $sslStatus['Ssl_version'] ?? 'unknown';

                // Check cipher strength
                if ($this->isWeakCipher($sslStatus['Ssl_cipher'])) {
                    $result['is_secure'] = false;
                    $result['issues'][] = 'Weak SSL cipher in use: ' . $sslStatus['Ssl_cipher'];
                    $result['recommendations'][] = 'Configure stronger SSL ciphers';
                }
            } else {
                $result['encryption_status'] = 'disabled';

                if ($this->config->get('database.require_encryption', false)) {
                    $result['is_secure'] = false;
                    $result['issues'][] = 'SSL encryption not enabled';
                    $result['recommendations'][] = 'Enable SSL encryption for database connections';
                }
            }

            // Check MySQL version
            $version = $pdo->query("SELECT VERSION()")->fetchColumn();
            if ($this->isVulnerableMysqlVersion($version)) {
                $result['is_secure'] = false;
                $result['issues'][] = 'Potentially vulnerable MySQL version: ' . $version;
                $result['recommendations'][] = 'Upgrade to a supported MySQL version';
            }

            // Check user privileges
            $this->checkMysqlUserPrivileges($pdo, $config, $result);

            // Check secure configurations
            $this->checkMysqlSecureConfiguration($pdo, $result);

        } catch (\Exception $e) {
            $result['is_secure'] = false;
            $result['issues'][] = 'Failed to verify MySQL security: ' . $e->getMessage();
        }
    }

    private function verifyPostgresSecurity(\PDO $pdo, array $config, array &$result): void
    {
        try {
            // Check SSL status for PostgreSQL
            $sslStatus = $pdo->query("SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()")->fetch();

            if ($sslStatus && $sslStatus['ssl'] === 't') {
                $result['encryption_status'] = 'enabled';
                $result['ssl_version'] = $sslStatus['version'] ?? 'unknown';
            } else {
                $result['encryption_status'] = 'disabled';

                if ($this->config->get('database.require_encryption', false)) {
                    $result['is_secure'] = false;
                    $result['issues'][] = 'SSL encryption not enabled';
                    $result['recommendations'][] = 'Enable SSL encryption for PostgreSQL connections';
                }
            }

            // Check PostgreSQL version
            $version = $pdo->query("SELECT version()")->fetchColumn();
            if ($this->isVulnerablePostgresVersion($version)) {
                $result['is_secure'] = false;
                $result['issues'][] = 'Potentially vulnerable PostgreSQL version';
                $result['recommendations'][] = 'Upgrade to a supported PostgreSQL version';
            }

        } catch (\Exception $e) {
            $result['is_secure'] = false;
            $result['issues'][] = 'Failed to verify PostgreSQL security: ' . $e->getMessage();
        }
    }

    private function checkConnectionConfiguration(array $config, array &$result): void
    {
        // Check for SSL configuration in connection config
        if (!empty($config['options'][\PDO::MYSQL_ATTR_SSL_CA])) {
            $result['ssl_ca_configured'] = true;
        } else {
            $result['recommendations'][] = 'Consider configuring SSL CA certificate for enhanced security';
        }

        // Check for secure connection options
        if (isset($config['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]) &&
            $config['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === false) {
            $result['is_secure'] = false;
            $result['issues'][] = 'SSL server certificate verification is disabled';
            $result['recommendations'][] = 'Enable SSL server certificate verification';
        }

        // Check password in configuration (should not be in plain text in production)
        if (isset($config['password']) && !empty($config['password'])) {
            $result['recommendations'][] = 'Consider using environment variables for database passwords';
        }
    }

    private function checkMysqlUserPrivileges(\PDO $pdo, array $config, array &$result): void
    {
        try {
            $grants = $pdo->query("SHOW GRANTS")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($grants as $grant) {
                if (strpos($grant, 'ALL PRIVILEGES') !== false && strpos($grant, '*.*') !== false) {
                    $result['is_secure'] = false;
                    $result['issues'][] = 'Database user has excessive privileges (ALL on *.*)';
                    $result['recommendations'][] = 'Limit database user privileges to only required databases and operations';
                    break;
                }
            }
        } catch (\Exception $e) {
            // User might not have permission to show grants, which is actually good for security
        }
    }

    private function checkMysqlSecureConfiguration(\PDO $pdo, array &$result): void
    {
        try {
            $variables = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('local_infile', 'secure_file_priv', 'general_log')")->fetchAll(\PDO::FETCH_KEY_PAIR);

            if (isset($variables['local_infile']) && $variables['local_infile'] === 'ON') {
                $result['issues'][] = 'local_infile is enabled (security risk)';
                $result['recommendations'][] = 'Disable local_infile to prevent local file inclusion attacks';
            }

            if (isset($variables['general_log']) && $variables['general_log'] === 'ON') {
                $result['recommendations'][] = 'General query log is enabled - ensure log files are secured';
            }

        } catch (\Exception $e) {
            // User might not have permission to show variables
        }
    }

    private function isWeakCipher(string $cipher): bool
    {
        $weakCiphers = [
            'DES-CBC-SHA',
            'DES-CBC3-SHA',
            'RC4-MD5',
            'RC4-SHA',
            'NULL-MD5',
            'NULL-SHA'
        ];

        return in_array($cipher, $weakCiphers);
    }

    private function isVulnerableMysqlVersion(string $version): bool
    {
        // Extract version number
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            $major = (int)$matches[1];
            $minor = (int)$matches[2];
            $patch = (int)$matches[3];

            // Check for known vulnerable versions (simplified)
            if ($major < 5 || ($major === 5 && $minor < 7)) {
                return true;
            }

            if ($major === 5 && $minor === 7 && $patch < 44) {
                return true;
            }

            if ($major === 8 && $minor === 0 && $patch < 34) {
                return true;
            }
        }

        return false;
    }

    private function isVulnerablePostgresVersion(string $version): bool
    {
        // This is a simplified check - in production you'd want more comprehensive version checking
        if (preg_match('/PostgreSQL (\d+)\.(\d+)/', $version, $matches)) {
            $major = (int)$matches[1];
            $minor = (int)$matches[2];

            // Check for very old versions
            if ($major < 10) {
                return true;
            }
        }

        return false;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}