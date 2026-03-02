<?php
declare(strict_types=1);

namespace Glaze\Support;

use DateInterval;
use DateTimeImmutable;
use Glaze\Utility\Hash;
use Psr\SimpleCache\CacheInterface;

/**
 * Persistent PSR-16 cache that stores serialized values on disk.
 *
 * This cache is designed for build-time workloads where values should survive
 * across process boundaries, such as syntax-highlighted HTML fragments.
 */
final class FileCache implements CacheInterface
{
    /**
     * Constructor.
     *
     * @param string $directory Absolute cache directory path.
     */
    public function __construct(protected string $directory)
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->readEntry($key);
        if ($entry === null) {
            return $default;
        }

        return $entry['value'];
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $path = $this->pathForKey($key);
        $this->ensureDirectoryExists();

        $entry = [
            'expiresAt' => $this->resolveExpiry($ttl),
            'value' => $value,
        ];

        return file_put_contents($path, serialize($entry), LOCK_EX) !== false;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $entries = scandir($this->directory);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $this->directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && !unlink($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Persist multiple key/value pairs.
     *
     * @param iterable<string, mixed> $values Key/value pairs to store.
     * @param \DateInterval|int|null $ttl Optional time-to-live.
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->delete((string)$key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    /**
     * Resolve on-disk path for a cache key.
     *
     * @param string $key Cache key.
     */
    protected function pathForKey(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . Hash::make($key) . '.cache.phpser';
    }

    /**
     * Read and validate a cache entry from disk.
     *
     * @param string $key Cache key.
     * @return array{expiresAt: int|null, value: mixed}|null
     */
    protected function readEntry(string $key): ?array
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $entry = $this->safeUnserialize($raw);
        if (!is_array($entry) || !array_key_exists('value', $entry) || !array_key_exists('expiresAt', $entry)) {
            return null;
        }

        if ($this->isExpired($entry['expiresAt'])) {
            $this->delete($key);

            return null;
        }

        return [
            'expiresAt' => is_int($entry['expiresAt']) ? $entry['expiresAt'] : null,
            'value' => $entry['value'],
        ];
    }

    /**
     * Ensure the target cache directory exists.
     */
    protected function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    /**
     * Resolve optional TTL to an absolute UNIX timestamp.
     *
     * @param \DateInterval|int|null $ttl Expiry value.
     */
    protected function resolveExpiry(int|DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        return (new DateTimeImmutable())->add($ttl)->getTimestamp();
    }

    /**
     * Safely unserialize cache payload and suppress E_NOTICE/E_WARNING via local error handler.
     *
     * @param string $payload Serialized cache payload.
     */
    protected function safeUnserialize(string $payload): mixed
    {
        set_error_handler(static fn() => true);

        try {
            return unserialize($payload, ['allowed_classes' => true]);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Determine whether an expiry timestamp is in the past.
     *
     * @param mixed $expiresAt Expiry timestamp value.
     */
    protected function isExpired(mixed $expiresAt): bool
    {
        return is_int($expiresAt) && $expiresAt < time();
    }
}
