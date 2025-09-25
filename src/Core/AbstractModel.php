<?php

namespace SecurityScanner\Core;

abstract class AbstractModel
{
    protected Database $db;
    protected Logger $logger;
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
    protected bool $timestamps = true;
    protected array $attributes = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler(); // Use scheduler channel for database operations

        if (empty($this->table)) {
            // Generate table name from class name
            $className = (new \ReflectionClass($this))->getShortName();
            $this->table = strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($className)));
            $this->table = trim($this->table, '_') . 's';
        }
    }

    /**
     * Find record by primary key
     */
    public function find($id): ?array
    {
        if ($id === null) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$id]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ? $this->processResult($result) : null;
        } catch (\Exception $e) {
            $this->logger->error('Database find error', [
                'table' => $this->table,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Find record by primary key or throw exception
     */
    public function findOrFail($id): array
    {
        $result = $this->find($id);
        if (!$result) {
            throw new \Exception("Record not found in {$this->table} with {$this->primaryKey} = {$id}");
        }
        return $result;
    }

    /**
     * Find record by column value
     */
    public function findBy(string $column, $value): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$column} = ? LIMIT 1";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$value]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ? $this->processResult($result) : null;
        } catch (\Exception $e) {
            $this->logger->error('Database findBy error', [
                'table' => $this->table,
                'column' => $column,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Get all records
     */
    public function all(): array
    {
        $sql = "SELECT * FROM {$this->table}";

        try {
            $stmt = $this->db->getConnection()->query($sql);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map([$this, 'processResult'], $results);
        } catch (\Exception $e) {
            $this->logger->error('Database all error', [
                'table' => $this->table,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Get records with conditions
     */
    public function where(array $conditions): array
    {
        $whereClause = '';
        $values = [];

        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $column => $value) {
                if (is_array($value)) {
                    $placeholders = str_repeat('?,', count($value) - 1) . '?';
                    $whereParts[] = "{$column} IN ({$placeholders})";
                    $values = array_merge($values, $value);
                } else {
                    $whereParts[] = "{$column} = ?";
                    $values[] = $value;
                }
            }
            $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
        }

        $sql = "SELECT * FROM {$this->table} {$whereClause}";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute($values);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map([$this, 'processResult'], $results);
        } catch (\Exception $e) {
            $this->logger->error('Database where error', [
                'table' => $this->table,
                'conditions' => $conditions,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Create new record
     */
    public function create(array $data): array
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';

        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute(array_values($data));

            $id = $this->db->getConnection()->lastInsertId();

            $this->logger->info('Record created', [
                'table' => $this->table,
                'id' => $id,
                'data' => $this->hideFields($data)
            ]);

            return $this->findOrFail($id);
        } catch (\Exception $e) {
            $this->logger->error('Database create error', [
                'table' => $this->table,
                'data' => $this->hideFields($data),
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Update record by primary key
     */
    public function update($id, array $data): array
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "{$column} = ?";
        }

        $sql = "UPDATE {$this->table} SET " . implode(',', $setParts) . " WHERE {$this->primaryKey} = ?";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $values = array_merge(array_values($data), [$id]);
            $stmt->execute($values);

            if ($stmt->rowCount() === 0) {
                throw new \Exception("No record found to update with {$this->primaryKey} = {$id}");
            }

            $this->logger->info('Record updated', [
                'table' => $this->table,
                'id' => $id,
                'data' => $this->hideFields($data)
            ]);

            return $this->findOrFail($id);
        } catch (\Exception $e) {
            $this->logger->error('Database update error', [
                'table' => $this->table,
                'id' => $id,
                'data' => $this->hideFields($data),
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database update failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete record by primary key
     */
    public function delete($id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$id]);

            $deleted = $stmt->rowCount() > 0;

            if ($deleted) {
                $this->logger->info('Record deleted', [
                    'table' => $this->table,
                    'id' => $id
                ]);
            }

            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('Database delete error', [
                'table' => $this->table,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database delete failed: ' . $e->getMessage());
        }
    }

    /**
     * Count records
     */
    public function count(array $conditions = []): int
    {
        $whereClause = '';
        $values = [];

        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $column => $value) {
                $whereParts[] = "{$column} = ?";
                $values[] = $value;
            }
            $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} {$whereClause}";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute($values);
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            $this->logger->error('Database count error', [
                'table' => $this->table,
                'conditions' => $conditions,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if record exists
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    /**
     * Get paginated results
     */
    public function paginate(int $page = 1, int $perPage = 20, array $conditions = []): array
    {
        $offset = ($page - 1) * $perPage;

        $whereClause = '';
        $values = [];

        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $column => $value) {
                $whereParts[] = "{$column} = ?";
                $values[] = $value;
            }
            $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
        }

        // Get total count
        $total = $this->count($conditions);

        // Get records for current page
        $sql = "SELECT * FROM {$this->table} {$whereClause} LIMIT {$perPage} OFFSET {$offset}";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute($values);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'data' => array_map([$this, 'processResult'], $results),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
                'has_more' => $page * $perPage < $total
            ];
        } catch (\Exception $e) {
            $this->logger->error('Database paginate error', [
                'table' => $this->table,
                'page' => $page,
                'per_page' => $perPage,
                'conditions' => $conditions,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute raw SQL query
     */
    protected function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->error('Database query error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Filter data to only include fillable fields
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Hide sensitive fields from data
     */
    protected function hideFields(array $data): array
    {
        if (empty($this->hidden)) {
            return $data;
        }

        return array_diff_key($data, array_flip($this->hidden));
    }

    /**
     * Cast field values to appropriate types
     */
    protected function castAttributes(array $data): array
    {
        foreach ($this->casts as $field => $type) {
            if (!isset($data[$field])) {
                continue;
            }

            switch ($type) {
                case 'int':
                case 'integer':
                    $data[$field] = (int) $data[$field];
                    break;
                case 'float':
                case 'double':
                    $data[$field] = (float) $data[$field];
                    break;
                case 'bool':
                case 'boolean':
                    $data[$field] = (bool) $data[$field];
                    break;
                case 'array':
                case 'json':
                    $data[$field] = is_string($data[$field]) ?
                        json_decode($data[$field], true) : $data[$field];
                    break;
                case 'datetime':
                    $data[$field] = new \DateTime($data[$field]);
                    break;
            }
        }

        return $data;
    }

    /**
     * Process query result
     */
    protected function processResult(array $result): array
    {
        $result = $this->castAttributes($result);
        return $this->hideFields($result);
    }

    /**
     * Begin database transaction
     */
    protected function beginTransaction(): bool
    {
        return $this->db->getConnection()->beginTransaction();
    }

    /**
     * Commit database transaction
     */
    protected function commit(): bool
    {
        return $this->db->getConnection()->commit();
    }

    /**
     * Rollback database transaction
     */
    protected function rollback(): bool
    {
        return $this->db->getConnection()->rollBack();
    }
}