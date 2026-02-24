<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Closure;
use Glaze\Build\ContentAssetPublisher;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for content asset publisher edge cases.
 */
final class ContentAssetPublisherTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure publish returns an empty list when content directory does not exist.
     */
    public function testPublishReturnsEmptyArrayWhenContentDirectoryIsMissing(): void
    {
        $publisher = new ContentAssetPublisher();

        $result = $publisher->publish(
            $this->createTempDirectory() . '/missing-content',
            $this->createTempDirectory() . '/public',
        );

        $this->assertSame([], $result);
    }

    /**
     * Ensure copy throws when destination directory cannot be created.
     */
    public function testCopyFileThrowsWhenDestinationDirectoryCannotBeCreated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create asset output directory');

        $temp = $this->createTempDirectory();
        $source = $temp . '/source.jpg';
        file_put_contents($source, 'bytes');

        $publisher = new ContentAssetPublisher();
        set_error_handler(static fn(): bool => true);
        try {
            $this->callProtected($publisher, 'copyFile', $source, '/dev/null/subdir/image.jpg');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Ensure copy throws when source file cannot be copied.
     */
    public function testCopyFileThrowsWhenCopyFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to copy asset');

        $temp = $this->createTempDirectory();
        $destination = $temp . '/public/image.jpg';

        $publisher = new ContentAssetPublisher();
        set_error_handler(static fn(): bool => true);
        try {
            $this->callProtected($publisher, 'copyFile', $temp . '/missing.jpg', $destination);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Invoke protected method on publisher using scope-bound closure.
     *
     * @param object $object Publisher instance.
     * @param string $method Protected method name.
     * @param mixed ...$arguments Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $invoker = Closure::bind(
            function (string $method, mixed ...$arguments): mixed {
                return $this->{$method}(...$arguments);
            },
            $object,
            $object::class,
        );

        return $invoker($method, ...$arguments);
    }
}
