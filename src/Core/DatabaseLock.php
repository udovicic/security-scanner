<?php

namespace SecurityScanner\Core;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;

class DatabaseLock
{
    private Database $db;
    private Logger $logger;
    private array $activeLocks = [];
    private int $defaultTimeout;
    private int $cleanupInterval;

    public function __construct(int $defaultTimeout = 300, int $cleanupInterval = 3600)
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::scheduler();
        $this->defaultTimeout = $defaultTimeout; // 5 minutes default
        $this->cleanupInterval = $cleanupInterval; // 1 hour cleanup interval
    }

    /**
     * Acquire a named lock with timeout
     */
    public function acquire(string $lockName, int $timeout = null, array $metadata = []): bool
    {
        $timeout = $timeout ?? $this->defaultTimeout;
        $lockId = $this->generateLockId();

        try {
            $this->cleanupStaleLocks();

            // Check if lock already exists and is active
            $existingLock = $this->getLockInfo($lockName);

            if ($existingLock && !$this->isLockExpired($existingLock)) {
                $this->logger->debug('Lock acquisition failed - lock already exists', [
                    'lock_name' => $lockName,
                    'existing_lock_id' => $existingLock['lock_id'],
                    'existing_owner' => $existingLock['owner'],
                    'expires_at' => $existingLock['expires_at']
                ]);
                return false;
            }

            // If lock exists but is expired, remove it
            if ($existingLock && $this->isLockExpired($existingLock)) {
                $this->forceRelease($lockName);
            }

            // Create new lock
            $expiresAt = date('Y-m-d H:i:s', time() + $timeout);
            $owner = $this->getOwnerIdentifier();

            $lockData = [
                'lock_id' => $lockId,
                'lock_name' => $lockName,
                'owner' => $owner,
                'acquired_at' => date('Y-m-d H:i:s'),
                'expires_at' => $expiresAt,
                'timeout_seconds' => $timeout,
                'metadata' => json_encode($metadata),
                'heartbeat_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO database_locks (lock_id, lock_name, owner, acquired_at, expires_at, timeout_seconds, metadata, heartbeat_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [
                $lockData['lock_id'],
                $lockData['lock_name'],
                $lockData['owner'],
                $lockData['acquired_at'],
                $lockData['expires_at'],
                $lockData['timeout_seconds'],
                $lockData['metadata'],
                $lockData['heartbeat_at'],
                $lockData['created_at'],
                $lockData['updated_at']
            ];
            $this->db->query($sql, $params);

            // Track locally
            $this->activeLocks[$lockName] = [
                'lock_id' => $lockId,
                'acquired_at' => time(),
                'expires_at' => time() + $timeout,
                'owner' => $owner
            ];

            $this->logger->info('Lock acquired successfully', [
                'lock_name' => $lockName,
                'lock_id' => $lockId,
                'owner' => $owner,
                'timeout' => $timeout,
                'expires_at' => $expiresAt
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to acquire lock', [
                'lock_name' => $lockName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Release a named lock
     */
    public function release(string $lockName): bool
    {
        try {
            $lockInfo = $this->getLockInfo($lockName);

            if (!$lockInfo) {
                $this->logger->debug('Lock release failed - lock not found', [
                    'lock_name' => $lockName
                ]);
                return false;
            }

            // Verify ownership
            $currentOwner = $this->getOwnerIdentifier();
            if ($lockInfo['owner'] !== $currentOwner) {
                $this->logger->warning('Lock release failed - not owner', [
                    'lock_name' => $lockName,
                    'current_owner' => $currentOwner,
                    'lock_owner' => $lockInfo['owner']
                ]);
                return false;
            }

            // Delete the lock
            $sql = "DELETE FROM database_locks WHERE lock_name = ? AND owner = ?";
            $stmt = $this->db->query($sql, [$lockName, $currentOwner]);
            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                // Remove from local tracking
                unset($this->activeLocks[$lockName]);

                $this->logger->info('Lock released successfully', [
                    'lock_name' => $lockName,
                    'lock_id' => $lockInfo['lock_id'],
                    'owner' => $currentOwner,
                    'held_duration' => time() - strtotime($lockInfo['acquired_at'])
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->error('Failed to release lock', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Force release a lock (admin function)
     */
    public function forceRelease(string $lockName): bool
    {
        try {
            $lockInfo = $this->getLockInfo($lockName);

            if (!$lockInfo) {
                return false;
            }

            $sql = "DELETE FROM database_locks WHERE lock_name = ?";
            $stmt = $this->db->query($sql, [$lockName]);
            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                unset($this->activeLocks[$lockName]);

                $this->logger->warning('Lock force released', [
                    'lock_name' => $lockName,
                    'lock_id' => $lockInfo['lock_id'],
                    'original_owner' => $lockInfo['owner'],
                    'forced_by' => $this->getOwnerIdentifier()
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->error('Failed to force release lock', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if a lock is currently held
     */
    public function isLocked(string $lockName): bool
    {
        try {
            $lockInfo = $this->getLockInfo($lockName);
            return $lockInfo && !$this->isLockExpired($lockInfo);
        } catch (\Exception $e) {
            $this->logger->error('Failed to check lock status', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get information about a lock
     */
    public function getLockInfo(string $lockName): ?array
    {
        try {
            $sql = "SELECT * FROM database_locks WHERE lock_name = ? ORDER BY acquired_at DESC LIMIT 1";
            $stmt = $this->db->query($sql, [$lockName]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get lock info', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extend the timeout of an existing lock
     */
    public function extend(string $lockName, int $additionalSeconds): bool
    {
        try {
            $lockInfo = $this->getLockInfo($lockName);

            if (!$lockInfo) {
                return false;
            }

            // Verify ownership
            $currentOwner = $this->getOwnerIdentifier();
            if ($lockInfo['owner'] !== $currentOwner) {
                return false;
            }

            // Check if lock is not expired
            if ($this->isLockExpired($lockInfo)) {
                return false;
            }

            $newExpiresAt = date('Y-m-d H:i:s', strtotime($lockInfo['expires_at']) + $additionalSeconds);

            $sql = "UPDATE database_locks SET expires_at = ?, timeout_seconds = ?, updated_at = ? WHERE lock_name = ? AND owner = ?";
            $stmt = $this->db->query($sql, [
                $newExpiresAt,
                $lockInfo['timeout_seconds'] + $additionalSeconds,
                date('Y-m-d H:i:s'),
                $lockName,
                $currentOwner
            ]);
            $updated = $stmt->rowCount();

            if ($updated > 0) {
                // Update local tracking
                if (isset($this->activeLocks[$lockName])) {
                    $this->activeLocks[$lockName]['expires_at'] += $additionalSeconds;
                }

                $this->logger->info('Lock extended successfully', [
                    'lock_name' => $lockName,
                    'additional_seconds' => $additionalSeconds,
                    'new_expires_at' => $newExpiresAt
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->error('Failed to extend lock', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send heartbeat to keep lock alive
     */
    public function heartbeat(string $lockName): bool
    {
        try {
            $lockInfo = $this->getLockInfo($lockName);

            if (!$lockInfo) {
                return false;
            }

            // Verify ownership
            $currentOwner = $this->getOwnerIdentifier();
            if ($lockInfo['owner'] !== $currentOwner) {
                return false;
            }

            // Check if lock is not expired
            if ($this->isLockExpired($lockInfo)) {
                return false;
            }

            $sql = "UPDATE database_locks SET heartbeat_at = ?, updated_at = ? WHERE lock_name = ? AND owner = ?";
            $stmt = $this->db->query($sql, [
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                $lockName,
                $currentOwner
            ]);

            return $stmt->rowCount() > 0;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send heartbeat', [
                'lock_name' => $lockName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all currently active locks
     */
    public function getActiveLocks(): array
    {
        try {
            $sql = "SELECT * FROM database_locks WHERE expires_at > NOW() ORDER BY acquired_at DESC";
            $stmt = $this->db->query($sql);
            $locks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Filter out expired locks based on heartbeat
            return array_filter($locks, function($lock) {
                return !$this->isLockExpired($lock);
            });

        } catch (\Exception $e) {
            $this->logger->error('Failed to get active locks', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Execute a callback with an exclusive lock
     */
    public function withLock(string $lockName, callable $callback, int $timeout = null, array $metadata = []): mixed
    {
        $acquired = $this->acquire($lockName, $timeout, $metadata);

        if (!$acquired) {
            throw new \RuntimeException("Could not acquire lock: {$lockName}");
        }

        try {
            return $callback();
        } finally {
            $this->release($lockName);
        }
    }

    /**
     * Try to execute a callback with a lock, return null if lock cannot be acquired
     */
    public function tryWithLock(string $lockName, callable $callback, int $timeout = null, array $metadata = []): mixed
    {
        $acquired = $this->acquire($lockName, $timeout, $metadata);

        if (!$acquired) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release($lockName);
        }
    }

    /**
     * Wait for a lock to become available and then acquire it
     */
    public function waitAndAcquire(string $lockName, int $timeout = null, int $waitTimeout = 60, array $metadata = []): bool
    {
        $waitStart = time();
        $timeout = $timeout ?? $this->defaultTimeout;

        while ((time() - $waitStart) < $waitTimeout) {
            if ($this->acquire($lockName, $timeout, $metadata)) {
                return true;
            }

            // Wait before retrying
            sleep(1);
        }

        $this->logger->warning('Wait and acquire timeout', [
            'lock_name' => $lockName,
            'wait_timeout' => $waitTimeout,
            'elapsed' => time() - $waitStart
        ]);

        return false;
    }

    /**
     * Clean up stale locks
     */
    public function cleanupStaleLocks(): int
    {
        try {
            // Only run cleanup periodically to avoid performance impact
            $lastCleanup = $this->getLastCleanupTime();
            if ($lastCleanup && (time() - $lastCleanup) < $this->cleanupInterval) {
                return 0;
            }

            // Delete expired locks
            $sql = "DELETE FROM database_locks WHERE expires_at < NOW() OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)";
            $stmt = $this->db->query($sql, [$this->defaultTimeout * 2]); // Heartbeat timeout is 2x lock timeout
            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                $this->logger->info('Cleaned up stale locks', ['count' => $deleted]);
            }

            // Record cleanup time
            $this->setLastCleanupTime(time());

            return $deleted;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup stale locks', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get lock statistics
     */
    public function getStatistics(): array
    {
        try {
            $stats = [
                'total_active_locks' => 0,
                'locks_by_owner' => [],
                'average_lock_duration' => 0,
                'longest_held_lock' => null,
                'total_locks_today' => 0
            ];

            // Active locks
            $activeLocks = $this->getActiveLocks();
            $stats['total_active_locks'] = count($activeLocks);

            // Group by owner
            foreach ($activeLocks as $lock) {
                $owner = $lock['owner'];
                if (!isset($stats['locks_by_owner'][$owner])) {
                    $stats['locks_by_owner'][$owner] = 0;
                }
                $stats['locks_by_owner'][$owner]++;

                // Find longest held lock
                $heldDuration = time() - strtotime($lock['acquired_at']);
                if (!$stats['longest_held_lock'] || $heldDuration > $stats['longest_held_lock']['duration']) {
                    $stats['longest_held_lock'] = [
                        'lock_name' => $lock['lock_name'],
                        'owner' => $lock['owner'],
                        'duration' => $heldDuration,
                        'acquired_at' => $lock['acquired_at']
                    ];
                }
            }

            // Today's locks
            $sql = "SELECT COUNT(*) FROM database_locks WHERE DATE(acquired_at) = CURDATE()";
            $stmt = $this->db->query($sql);
            $stats['total_locks_today'] = $stmt->fetchColumn();

            return $stats;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get lock statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Check if a lock is expired
     */
    private function isLockExpired(array $lockInfo): bool
    {
        $now = time();
        $expiresAt = strtotime($lockInfo['expires_at']);
        $heartbeatAt = strtotime($lockInfo['heartbeat_at'] ?? $lockInfo['acquired_at']);

        // Lock is expired if either:
        // 1. Past expiration time
        // 2. No heartbeat for 2x the timeout period
        $heartbeatTimeout = ($lockInfo['timeout_seconds'] ?? $this->defaultTimeout) * 2;

        return $expiresAt <= $now || ($now - $heartbeatAt) > $heartbeatTimeout;
    }

    /**
     * Generate unique lock ID
     */
    private function generateLockId(): string
    {
        return uniqid('lock_', true) . '_' . getmypid();
    }

    /**
     * Get owner identifier for current process
     */
    private function getOwnerIdentifier(): string
    {
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid();
        $user = get_current_user() ?: 'unknown';

        return "{$hostname}:{$user}:{$pid}";
    }

    /**
     * Get last cleanup time from cache/temp file
     */
    private function getLastCleanupTime(): ?int
    {
        $cacheFile = sys_get_temp_dir() . '/db_lock_cleanup_time';
        if (file_exists($cacheFile)) {
            $time = file_get_contents($cacheFile);
            return is_numeric($time) ? (int)$time : null;
        }
        return null;
    }

    /**
     * Set last cleanup time
     */
    private function setLastCleanupTime(int $time): void
    {
        $cacheFile = sys_get_temp_dir() . '/db_lock_cleanup_time';
        file_put_contents($cacheFile, $time);
    }

    /**
     * Release all locks held by current process (cleanup on shutdown)
     */
    public function releaseAllOwnedLocks(): int
    {
        try {
            $currentOwner = $this->getOwnerIdentifier();

            $sql = "DELETE FROM database_locks WHERE owner = ?";
            $stmt = $this->db->query($sql, [$currentOwner]);
            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                $this->logger->info('Released all owned locks on shutdown', [
                    'count' => $deleted,
                    'owner' => $currentOwner
                ]);
            }

            $this->activeLocks = [];

            return $deleted;

        } catch (\Exception $e) {
            $this->logger->error('Failed to release all owned locks', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Destructor - cleanup locks
     */
    public function __destruct()
    {
        $this->releaseAllOwnedLocks();
    }
}