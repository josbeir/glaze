<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use DateInterval;
use Glaze\Support\FileCache;
use Glaze\Utility\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the persistent file-based PSR-16 cache implementation.
 */
final class FileCacheTest extends TestCase
{
    /**
     * Temporary cache directory used by the test case.
     */
    protected string $cacheDirectory;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = sys_get_temp_dir() . '/glaze-file-cache-test-' . uniqid('', true);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->cacheDirectory)) {
            return;
        }

        $entries = scandir($this->cacheDirectory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }

                if ($entry === '..') {
                    continue;
                }

                $path = $this->cacheDirectory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        rmdir($this->cacheDirectory);
    }

    /**
     * Ensure values can be written and read from the file cache.
     */
    public function testSetAndGetRoundTrip(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        $cache->set('alpha', ['a' => 1]);

        $this->assertSame(['a' => 1], $cache->get('alpha'));
        $this->assertNull($cache->get('missing'));
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    /**
     * Ensure stored values persist across cache object instances.
     */
    public function testValuesPersistAcrossInstances(): void
    {
        $writer = new FileCache($this->cacheDirectory);
        $writer->set('key', 'persisted');

        $reader = new FileCache($this->cacheDirectory);

        $this->assertSame('persisted', $reader->get('key'));
        $this->assertTrue($reader->has('key'));
    }

    /**
     * Ensure expired entries are treated as missing and removed lazily.
     */
    public function testExpiredEntriesAreNotReturned(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('soon-expired', 'value', -1);

        $this->assertFalse($cache->has('soon-expired'));
        $this->assertSame('fallback', $cache->get('soon-expired', 'fallback'));
    }

    /**
     * Ensure DateInterval TTL is supported.
     */
    public function testDateIntervalTtlIsSupported(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('interval', 'ok', new DateInterval('PT60S'));

        $this->assertTrue($cache->has('interval'));
        $this->assertSame('ok', $cache->get('interval'));
    }

    /**
     * Ensure clear removes all cache entries.
     */
    public function testClearRemovesAllEntries(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    /**
     * Ensure missing cache files are treated as successful no-op deletes.
     */
    public function testDeleteReturnsTrueForMissingFile(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        $this->assertTrue($cache->delete('missing'));
    }

    /**
     * Ensure clear succeeds for a non-existing cache directory.
     */
    public function testClearReturnsTrueWhenDirectoryDoesNotExist(): void
    {
        $cache = new FileCache($this->cacheDirectory . '-does-not-exist');

        $this->assertTrue($cache->clear());
    }

    /**
     * Ensure getMultiple returns defaults for misses and values for hits.
     */
    public function testGetMultipleReturnsValuesAndDefaults(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('known', 'value');

        $result = $cache->getMultiple(['known', 'missing'], 'fallback');

        $this->assertSame(['known' => 'value', 'missing' => 'fallback'], $result);
    }

    /**
     * Ensure deleteMultiple deletes all provided keys.
     */
    public function testDeleteMultipleDeletesProvidedKeys(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
        $this->assertTrue($cache->has('c'));
    }

    /**
     * Ensure corrupt cache payloads fail safely and return defaults.
     */
    public function testCorruptPayloadReturnsDefaultSafely(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        mkdir($this->cacheDirectory, 0755, true);

        file_put_contents(
            $this->cacheDirectory . DIRECTORY_SEPARATOR . Hash::make('bad') . '.cache.phpser',
            'not-a-serialized-payload',
        );

        $this->assertSame('fallback', $cache->get('bad', 'fallback'));
        $this->assertFalse($cache->has('bad'));
    }
}
