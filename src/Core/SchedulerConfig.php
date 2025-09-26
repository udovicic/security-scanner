<?php

namespace SecurityScanner\Core;

class SchedulerConfig
{
    /**
     * Default scan frequencies in minutes
     */
    const SCAN_FREQUENCIES = [
        'immediate' => 0,       // Run immediately
        'hourly' => 60,         // Every hour
        'bi_hourly' => 120,     // Every 2 hours
        'quarter_daily' => 360, // Every 6 hours
        'daily' => 1440,        // Every day
        'weekly' => 10080,      // Every week
        'monthly' => 43200      // Every month (30 days)
    ];

    /**
     * Priority weights for scan scheduling
     */
    const PRIORITY_WEIGHTS = [
        'critical' => 1,        // Highest priority
        'high' => 2,
        'medium' => 3,
        'low' => 4,
        'maintenance' => 5      // Lowest priority
    ];

    /**
     * Website category configurations
     */
    const CATEGORY_CONFIGS = [
        'ecommerce' => [
            'default_frequency' => 'quarter_daily',
            'priority' => 'high',
            'timeout' => 300,
            'retry_attempts' => 3
        ],
        'government' => [
            'default_frequency' => 'daily',
            'priority' => 'critical',
            'timeout' => 600,
            'retry_attempts' => 3
        ],
        'healthcare' => [
            'default_frequency' => 'bi_hourly',
            'priority' => 'critical',
            'timeout' => 600,
            'retry_attempts' => 3
        ],
        'finance' => [
            'default_frequency' => 'hourly',
            'priority' => 'critical',
            'timeout' => 300,
            'retry_attempts' => 5
        ],
        'education' => [
            'default_frequency' => 'daily',
            'priority' => 'medium',
            'timeout' => 300,
            'retry_attempts' => 2
        ],
        'news' => [
            'default_frequency' => 'quarter_daily',
            'priority' => 'medium',
            'timeout' => 180,
            'retry_attempts' => 2
        ],
        'blog' => [
            'default_frequency' => 'weekly',
            'priority' => 'low',
            'timeout' => 120,
            'retry_attempts' => 1
        ],
        'portfolio' => [
            'default_frequency' => 'weekly',
            'priority' => 'low',
            'timeout' => 120,
            'retry_attempts' => 1
        ],
        'corporate' => [
            'default_frequency' => 'daily',
            'priority' => 'medium',
            'timeout' => 240,
            'retry_attempts' => 2
        ],
        'other' => [
            'default_frequency' => 'daily',
            'priority' => 'medium',
            'timeout' => 180,
            'retry_attempts' => 2
        ]
    ];

    /**
     * Time slot configurations for load balancing
     */
    const TIME_SLOTS = [
        'early_morning' => ['start' => '00:00', 'end' => '06:00', 'weight' => 1.0],
        'morning' => ['start' => '06:00', 'end' => '12:00', 'weight' => 0.7],
        'afternoon' => ['start' => '12:00', 'end' => '18:00', 'weight' => 0.5],
        'evening' => ['start' => '18:00', 'end' => '24:00', 'weight' => 0.8]
    ];

    private array $config;
    private Database $db;

    public function __construct(array $customConfig = [])
    {
        $this->db = Database::getInstance();
        $this->config = array_merge([
            'max_concurrent_scans' => 10,
            'scan_timeout_default' => 300,
            'retry_delay_minutes' => 15,
            'max_retries_per_day' => 5,
            'load_balancing_enabled' => true,
            'priority_scheduling_enabled' => true,
            'adaptive_frequency_enabled' => true,
            'resource_aware_scheduling' => true
        ], $customConfig);
    }

    /**
     * Get scan frequency in minutes for a website
     */
    public function getScanFrequency(array $website): int
    {
        // Check for explicit scan_frequency setting
        if (!empty($website['scan_frequency'])) {
            if (is_numeric($website['scan_frequency'])) {
                return (int) $website['scan_frequency'];
            }

            if (isset(self::SCAN_FREQUENCIES[$website['scan_frequency']])) {
                return self::SCAN_FREQUENCIES[$website['scan_frequency']];
            }
        }

        // Use category-based frequency
        $category = $website['category'] ?? 'other';
        $categoryConfig = self::CATEGORY_CONFIGS[$category] ?? self::CATEGORY_CONFIGS['other'];
        $frequencyKey = $categoryConfig['default_frequency'];

        return self::SCAN_FREQUENCIES[$frequencyKey];
    }

    /**
     * Get priority weight for a website
     */
    public function getPriorityWeight(array $website): int
    {
        // Check for explicit priority setting
        if (!empty($website['priority'])) {
            return self::PRIORITY_WEIGHTS[$website['priority']] ?? self::PRIORITY_WEIGHTS['medium'];
        }

        // Use category-based priority
        $category = $website['category'] ?? 'other';
        $categoryConfig = self::CATEGORY_CONFIGS[$category] ?? self::CATEGORY_CONFIGS['other'];
        $priority = $categoryConfig['priority'];

        return self::PRIORITY_WEIGHTS[$priority];
    }

    /**
     * Get timeout for a website scan
     */
    public function getScanTimeout(array $website): int
    {
        // Check for explicit timeout setting
        if (!empty($website['scan_timeout'])) {
            return (int) $website['scan_timeout'];
        }

        // Use category-based timeout
        $category = $website['category'] ?? 'other';
        $categoryConfig = self::CATEGORY_CONFIGS[$category] ?? self::CATEGORY_CONFIGS['other'];

        return $categoryConfig['timeout'];
    }

    /**
     * Get retry attempts for a website
     */
    public function getRetryAttempts(array $website): int
    {
        // Check for explicit retry setting
        if (!empty($website['max_retries'])) {
            return (int) $website['max_retries'];
        }

        // Use category-based retries
        $category = $website['category'] ?? 'other';
        $categoryConfig = self::CATEGORY_CONFIGS[$category] ?? self::CATEGORY_CONFIGS['other'];

        return $categoryConfig['retry_attempts'];
    }

    /**
     * Calculate next scan time for a website
     */
    public function calculateNextScanTime(array $website, bool $scanSuccessful = true, int $retryCount = 0): string
    {
        $frequencyMinutes = $this->getScanFrequency($website);

        if (!$scanSuccessful && $retryCount > 0) {
            // Use exponential backoff for retries
            $retryDelayMinutes = $this->config['retry_delay_minutes'] * pow(2, min($retryCount - 1, 4));
            $frequencyMinutes = min($retryDelayMinutes, $frequencyMinutes);
        }

        // Apply load balancing if enabled
        if ($this->config['load_balancing_enabled']) {
            $frequencyMinutes = $this->applyLoadBalancing($frequencyMinutes, $website);
        }

        // Apply adaptive frequency if enabled
        if ($this->config['adaptive_frequency_enabled']) {
            $frequencyMinutes = $this->applyAdaptiveFrequency($frequencyMinutes, $website);
        }

        return date('Y-m-d H:i:s', time() + ($frequencyMinutes * 60));
    }

    /**
     * Apply load balancing to spread scans across time slots
     */
    private function applyLoadBalancing(int $frequencyMinutes, array $website): int
    {
        $currentHour = (int) date('H');
        $currentSlot = $this->getCurrentTimeSlot($currentHour);
        $slotWeight = self::TIME_SLOTS[$currentSlot]['weight'];

        // Adjust frequency based on current load
        $adjustment = (1.0 - $slotWeight) * 0.2; // Max 20% adjustment
        return (int) ($frequencyMinutes * (1 + $adjustment));
    }

    /**
     * Apply adaptive frequency based on website reliability
     */
    private function applyAdaptiveFrequency(int $frequencyMinutes, array $website): int
    {
        try {
            // Get recent scan history
            $sql = "SELECT
                        COUNT(*) as total_scans,
                        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_scans,
                        AVG(execution_time) as avg_execution_time
                    FROM scan_results
                    WHERE website_id = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

            $stmt = $this->db->query($sql, [$website['id']]);
            $history = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($history && $history['total_scans'] >= 5) {
                $successRate = $history['successful_scans'] / $history['total_scans'];

                // Increase frequency for unreliable websites
                if ($successRate < 0.8) {
                    $frequencyMinutes = (int) ($frequencyMinutes * 0.75); // Scan 25% more often
                } elseif ($successRate >= 0.95) {
                    $frequencyMinutes = (int) ($frequencyMinutes * 1.25); // Scan 25% less often
                }
            }

        } catch (\Exception $e) {
            // Log error but don't fail
            Logger::scheduler()->warning('Failed to apply adaptive frequency', [
                'website_id' => $website['id'],
                'error' => $e->getMessage()
            ]);
        }

        return $frequencyMinutes;
    }

    /**
     * Get current time slot
     */
    private function getCurrentTimeSlot(int $hour): string
    {
        if ($hour >= 0 && $hour < 6) return 'early_morning';
        if ($hour >= 6 && $hour < 12) return 'morning';
        if ($hour >= 12 && $hour < 18) return 'afternoon';
        return 'evening';
    }

    /**
     * Get websites ordered by priority and next scan time
     */
    public function getWebsitesPrioritized(int $limit = 100): array
    {
        $sql = "SELECT w.*,
                       COALESCE(w.priority, 'medium') as effective_priority,
                       COALESCE(w.category, 'other') as effective_category,
                       COALESCE(w.scan_frequency, 'daily') as effective_frequency,
                       sr.failed_attempts,
                       sr.last_error
                FROM websites w
                LEFT JOIN (
                    SELECT website_id,
                           COUNT(*) as failed_attempts,
                           MAX(error_message) as last_error
                    FROM scan_results
                    WHERE success = 0
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    GROUP BY website_id
                ) sr ON w.id = sr.website_id
                WHERE w.active = 1
                AND (w.next_scan_at IS NULL OR w.next_scan_at <= NOW())
                AND w.id NOT IN (
                    SELECT DISTINCT website_id
                    FROM scan_results
                    WHERE status = 'running'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                )
                ORDER BY
                    CASE w.priority
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                        ELSE 3
                    END,
                    COALESCE(w.next_scan_at, '1970-01-01'),
                    w.created_at
                LIMIT ?";

        $stmt = $this->db->query($sql, [$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get configuration value
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Get all scan frequency options
     */
    public static function getFrequencyOptions(): array
    {
        return self::SCAN_FREQUENCIES;
    }

    /**
     * Get all priority options
     */
    public static function getPriorityOptions(): array
    {
        return array_keys(self::PRIORITY_WEIGHTS);
    }

    /**
     * Get all category options
     */
    public static function getCategoryOptions(): array
    {
        return array_keys(self::CATEGORY_CONFIGS);
    }
}