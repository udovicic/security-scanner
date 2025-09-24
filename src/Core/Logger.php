<?php

namespace SecurityScanner\Core;

class Logger
{
    private static array $instances = [];
    private string $channel;
    private string $logPath;
    private string $level;
    private array $levelPriority = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4,
    ];

    private function __construct(string $channel, string $logPath, string $level = 'info')
    {
        $this->channel = $channel;
        $this->logPath = $logPath;
        $this->level = strtolower($level);

        // Create log directory if it doesn't exist
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public static function channel(string $channel): self
    {
        if (!isset(self::$instances[$channel])) {
            $config = Config::getInstance();

            $channelConfig = $config->get("logging.channels.{$channel}");
            if (!$channelConfig) {
                throw new \InvalidArgumentException("Unknown logging channel: {$channel}");
            }

            self::$instances[$channel] = new self(
                $channel,
                $channelConfig['path'],
                $channelConfig['level'] ?? 'info'
            );
        }

        return self::$instances[$channel];
    }

    public static function access(): self
    {
        return self::channel('access');
    }

    public static function errors(): self
    {
        return self::channel('error');
    }

    public static function scheduler(): self
    {
        return self::channel('scheduler');
    }

    public static function security(): self
    {
        return self::channel('security');
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // Check if this message should be logged based on configured level
        if ($this->levelPriority[$level] < $this->levelPriority[$this->level]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $processId = getmypid();
        $memoryUsage = memory_get_usage(true);
        $formattedMemory = $this->formatBytes($memoryUsage);

        // Format context data
        $contextString = '';
        if (!empty($context)) {
            $contextString = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        // Build log entry
        $logEntry = sprintf(
            "[%s] %s.%s: %s [PID:%d] [MEM:%s]%s\n",
            $timestamp,
            $this->channel,
            strtoupper($level),
            $message,
            $processId,
            $formattedMemory,
            $contextString
        );

        // Write to log file
        $this->writeToFile($logEntry);

        // Also log to PHP error log for critical errors
        if ($level === 'critical' || $level === 'error') {
            error_log(trim($logEntry));
        }
    }

    private function writeToFile(string $logEntry): void
    {
        // Use file locking to prevent race conditions in concurrent environments
        $result = file_put_contents($this->logPath, $logEntry, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log("Failed to write to log file: {$this->logPath}");
        }
    }

    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . $units[$unitIndex];
    }

    public function rotate(int $maxFiles = 5): void
    {
        if (!file_exists($this->logPath)) {
            return;
        }

        // Only rotate if file is larger than 10MB
        if (filesize($this->logPath) < 10 * 1024 * 1024) {
            return;
        }

        // Rotate existing log files
        for ($i = $maxFiles - 1; $i > 0; $i--) {
            $oldFile = $this->logPath . '.' . $i;
            $newFile = $this->logPath . '.' . ($i + 1);

            if (file_exists($oldFile)) {
                if ($i === $maxFiles - 1) {
                    unlink($oldFile); // Delete oldest file
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }

        // Move current log to .1
        rename($this->logPath, $this->logPath . '.1');

        // Create new empty log file
        touch($this->logPath);
        chmod($this->logPath, 0644);
    }

    public static function rotateAll(): void
    {
        foreach (self::$instances as $logger) {
            $logger->rotate();
        }
    }
}