<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Image;

use Closure;
use Glaze\Image\GlideImageTransformer;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Tests for Glide-based image transformation responses.
 */
final class GlideImageTransformerTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure transformer returns null when there are no manipulations to apply.
     */
    public function testCreateResponseReturnsNullWithoutManipulations(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);
        $this->createJpegImage($rootPath . '/images/pixel.jpg');

        $transformer = new GlideImageTransformer();
        $response = $transformer->createResponse(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: [],
            presets: [],
            cachePath: $this->createTempDirectory() . '/cache',
            options: [],
        );

        $this->assertNotInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * Ensure transformer rejects unsafe source paths.
     */
    public function testCreateResponseReturnsNullForUnsafePath(): void
    {
        $transformer = new GlideImageTransformer();
        $response = $transformer->createResponse(
            rootPath: $this->createTempDirectory(),
            requestPath: '/../secret.png',
            queryParams: ['w' => '10'],
            presets: [],
            cachePath: $this->createTempDirectory() . '/cache',
            options: [],
        );

        $this->assertNotInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * Ensure transformer returns null when Glide fails (for example missing source file).
     */
    public function testCreateResponseReturnsNullWhenSourceImageIsMissing(): void
    {
        $rootPath = $this->createTempDirectory();

        $transformer = new GlideImageTransformer();
        $response = $transformer->createResponse(
            rootPath: $rootPath,
            requestPath: '/images/missing.jpg',
            queryParams: ['w' => '10'],
            presets: [],
            cachePath: $this->createTempDirectory() . '/cache',
            options: [],
        );

        $this->assertNotInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * Ensure transformer creates image response and cache entry for valid input.
     */
    public function testCreateResponseReturnsTransformedImageResponse(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);
        $this->createJpegImage($rootPath . '/images/pixel.jpg');

        $cachePath = $this->createTempDirectory() . '/cache';

        $transformer = new GlideImageTransformer();
        $response = $transformer->createResponse(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '1', 'h' => '1'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', $response->getHeaderLine('Cache-Control'));
        $this->assertGreaterThan(0, strlen((string)$response->getBody()));
        $this->assertDirectoryExists($cachePath);
    }

    /**
     * Ensure transformer returns null when cached image cannot be read.
     */
    public function testCreateResponseReturnsNullWhenCachedFileIsUnreadable(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);
        $this->createJpegImage($rootPath . '/images/pixel.jpg');

        $cachePath = $this->createTempDirectory() . '/cache';
        $transformer = new GlideImageTransformer();

        $firstResponse = $transformer->createResponse(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '1', 'h' => '1'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );
        $this->assertInstanceOf(ResponseInterface::class, $firstResponse);

        $cachedFile = $this->firstFileInDirectory($cachePath);
        if (!is_string($cachedFile)) {
            $this->markTestSkipped('Unable to locate generated Glide cache file.');
        }

        chmod($cachedFile, 0000);

        try {
            set_error_handler(static fn(): bool => true);
            $response = $transformer->createResponse(
                rootPath: $rootPath,
                requestPath: '/images/pixel.jpg',
                queryParams: ['w' => '1', 'h' => '1'],
                presets: [],
                cachePath: $cachePath,
                options: [],
            );
        } finally {
            restore_error_handler();
            chmod($cachedFile, 0644);
        }

        $this->assertNotInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * Ensure protected helper methods handle normalization and path safety branches.
     */
    public function testProtectedHelpersHandlePathNormalizationAndValidation(): void
    {
        $transformer = new GlideImageTransformer();

        $relative = $this->callProtected($transformer, 'toRelativeRequestPath', '/images/pixel.png');
        $emptyRelative = $this->callProtected($transformer, 'toRelativeRequestPath', '/');
        $unsafe = $this->callProtected($transformer, 'isUnsafeRelativePath', '../images/pixel.png');
        $safe = $this->callProtected($transformer, 'isUnsafeRelativePath', 'images/pixel.png');

        $cacheRoot = $this->createTempDirectory();
        $this->callProtected($transformer, 'ensureDirectory', $cacheRoot . '/nested/cache');
        $this->callProtected($transformer, 'ensureDirectory', $cacheRoot . '/nested/cache');

        $resolvedMissing = $this->callProtected($transformer, 'resolveCachedPath', $cacheRoot . '/nested/cache', 'missing.png');
        $resolvedMissingRoot = $this->callProtected($transformer, 'resolveCachedPath', $cacheRoot . '/missing-root', 'image.png');

        $outsideDirectory = $cacheRoot . '/outside';
        mkdir($outsideDirectory, 0755, true);
        $outsideFile = $outsideDirectory . '/outside.jpg';
        file_put_contents($outsideFile, 'outside-bytes');
        $resolvedOutsideRoot = $this->callProtected(
            $transformer,
            'resolveCachedPath',
            $cacheRoot . '/nested/cache',
            '../../outside/outside.jpg',
        );
        $normalizedGdDriver = $this->callProtected($transformer, 'normalizeDriverOption', ' GD ');
        $normalizedImagickDriver = $this->callProtected($transformer, 'normalizeDriverOption', 'imagick');
        $normalizedInvalidDriver = $this->callProtected($transformer, 'normalizeDriverOption', 'invalid');
        $serverConfigWithDriver = $this->callProtected(
            $transformer,
            'buildServerConfiguration',
            '/tmp/source',
            '/tmp/cache',
            ['driver' => 'imagick'],
        );
        $serverConfigWithoutDriver = $this->callProtected(
            $transformer,
            'buildServerConfiguration',
            '/tmp/source',
            '/tmp/cache',
            [],
        );

        $this->assertSame('images/pixel.png', $relative);
        $this->assertNull($emptyRelative);
        $this->assertTrue($unsafe);
        $this->assertFalse($safe);
        $this->assertDirectoryExists($cacheRoot . '/nested/cache');
        $this->assertNull($resolvedMissing);
        $this->assertNull($resolvedMissingRoot);
        $this->assertNull($resolvedOutsideRoot);
        $this->assertSame('gd', $normalizedGdDriver);
        $this->assertSame('imagick', $normalizedImagickDriver);
        $this->assertNull($normalizedInvalidDriver);
        $this->assertIsArray($serverConfigWithDriver);
        $this->assertIsArray($serverConfigWithoutDriver);
        /** @var array<string, mixed> $typedServerConfigWithDriver */
        $typedServerConfigWithDriver = $serverConfigWithDriver;
        /** @var array<string, mixed> $typedServerConfigWithoutDriver */
        $typedServerConfigWithoutDriver = $serverConfigWithoutDriver;
        $this->assertSame('imagick', $typedServerConfigWithDriver['driver'] ?? null);
        $this->assertSame('gd', $typedServerConfigWithoutDriver['driver'] ?? null);
    }

    /**
     * Build a minimal valid JPEG image file.
     */
    protected function createJpegImage(string $path): void
    {
        $image = imagecreatetruecolor(2, 2);
        $color = imagecolorallocate($image, 0, 0, 0);
        if ($color === false) {
            self::fail('Unable to allocate test image color.');
        }

        imagefilledrectangle($image, 0, 0, 1, 1, $color);
        imagejpeg($image, $path, 90);
    }

    /**
     * Locate first file entry under a directory.
     *
     * @param string $directory Directory to inspect.
     */
    protected function firstFileInDirectory(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isFile()) {
                return $entry->getPathname();
            }
        }

        return null;
    }

    /**
     * Invoke a protected method using scope-bound closure.
     *
     * @param object $object Object to invoke method on.
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
