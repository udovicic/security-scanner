<?php

namespace SecurityScanner\Core;

class EnvLoader
{
    private string $envPath;
    private array $variables = [];

    public function __construct(string $envPath)
    {
        $this->envPath = $envPath;
    }

    public function load(): void
    {
        if (!file_exists($this->envPath)) {
            throw new \InvalidArgumentException("Environment file not found: {$this->envPath}");
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Parse key=value pairs
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remove quotes if present
            $value = $this->parseValue($value);

            // Set environment variable and store internally
            $this->variables[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->variables[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->variables[$key]);
    }

    public function all(): array
    {
        return $this->variables;
    }

    private function parseValue(string $value): string
    {
        // Handle quoted values
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Handle boolean values
        $lowerValue = strtolower($value);
        if (in_array($lowerValue, ['true', 'false'])) {
            return $lowerValue === 'true' ? '1' : '0';
        }

        // Handle null values
        if (strtolower($value) === 'null') {
            return '';
        }

        return $value;
    }

}