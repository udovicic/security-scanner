<?php

namespace SecurityScanner\Core;

/**
 * Simple array-based cache implementation for testing
 */
class ArrayCache
{
    private array $cache = [];
    private array $tags = [];

    public function get(string $key, $default = null)
    {
        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $this->cache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->cache[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->tags = [];
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        $item = $this->cache[$key];
        if (time() > $item['expires_at']) {
            unset($this->cache[$key]);
            return false;
        }

        return true;
    }

    public function tag(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }
            $this->tags[$tag][] = $key;
        }
    }

    public function invalidateTag(string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            return;
        }

        foreach ($this->tags[$tag] as $key) {
            unset($this->cache[$key]);
        }

        unset($this->tags[$tag]);
    }
}