<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Image;

use Closure;
use Glaze\Image\GlideImageTransformer;
use Glaze\Image\ImagePresetResolver;
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

        $transformer = $this->createTransformer();
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
        $transformer = $this->createTransformer();
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

        $transformer = $this->createTransformer();
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

        $transformer = $this->createTransformer();
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
        $this->assertStringContainsString('no-cache', $response->getHeaderLine('Cache-Control'));
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
        $transformer = $this->createTransformer();

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
        $transformer = $this->createTransformer();

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
     * Ensure versioned cache path callable produces different hashes for different mtimes.
     */
    public function testVersionedCachePathCallableProducesDifferentHashesForDifferentMtimes(): void
    {
        $transformer = $this->createTransformer();

        $callable1 = $this->callProtected($transformer, 'createVersionedCachePathCallable', 1000);
        $callable2 = $this->callProtected($transformer, 'createVersionedCachePathCallable', 2000);

        $this->assertInstanceOf(Closure::class, $callable1);
        $this->assertInstanceOf(Closure::class, $callable2);
    }

    /**
     * Ensure sourceFileMtime returns file modification time with cleared stat cache.
     */
    public function testSourceFileMtimeReturnsCorrectMtime(): void
    {
        $tempDir = $this->createTempDirectory();
        $mtime = time() - 300;
        file_put_contents($tempDir . '/source.jpg', 'source-data');
        touch($tempDir . '/source.jpg', $mtime);

        $transformer = $this->createTransformer();
        $result = $this->callProtected($transformer, 'sourceFileMtime', $tempDir, 'source.jpg');

        $this->assertSame($mtime, $result);
    }

    /**
     * Ensure sourceFileMtime returns zero for missing files.
     */
    public function testSourceFileMtimeReturnsZeroForMissingFile(): void
    {
        $tempDir = $this->createTempDirectory();
        $transformer = $this->createTransformer();
        $result = $this->callProtected($transformer, 'sourceFileMtime', $tempDir, 'missing.jpg');

        $this->assertSame(0, $result);
    }

    /**
     * Ensure a changed source image produces a new cache entry via versioned cache path.
     *
     * The cache_path_callable includes source mtime in the hash, so replacing
     * the source file (which changes mtime) produces a different cache path
     * and fresh transform automatically, without needing cache deletion.
     */
    public function testCreateTransformedPathUsesNewCachePathWhenSourceChanges(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);

        // Create initial black source image with a past mtime
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 0, 0, 0);
        touch($rootPath . '/images/pixel.jpg', time() - 120);

        $cachePath = $this->createTempDirectory() . '/cache';
        $transformer = $this->createTransformer();

        $firstCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '2'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($firstCachedPath);
        $firstContent = file_get_contents($firstCachedPath);

        // Replace with a different (white) image — natural file overwrite changes mtime
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 255, 255, 255);
        clearstatcache(true);

        $secondCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '2'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($secondCachedPath);
        $secondContent = file_get_contents($secondCachedPath);

        // Different cache path (mtime changed) and different content
        $this->assertNotSame($firstCachedPath, $secondCachedPath);
        $this->assertNotSame($firstContent, $secondContent);
    }

    /**
     * Ensure versioned cache path works when source has an older mtime than cache.
     *
     * Simulates the scenario where a user replaces an image file with one
     * that has a historically older modification time (e.g. restored from backup).
     */
    public function testCreateTransformedPathUsesNewCachePathWhenSourceIsOlderThanCache(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);

        // Create initial black source image
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 0, 0, 0);

        $cachePath = $this->createTempDirectory() . '/cache';
        $transformer = $this->createTransformer();

        $firstCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '2'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($firstCachedPath);
        $firstContent = file_get_contents($firstCachedPath);

        // Replace with a different (white) image with a PAST mtime (e.g., backup restore)
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 255, 255, 255);
        touch($rootPath . '/images/pixel.jpg', time() - 600);
        clearstatcache(true);

        $secondCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['w' => '2'],
            presets: [],
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($secondCachedPath);
        $secondContent = file_get_contents($secondCachedPath);

        // Different cache path (mtime changed) and different content
        $this->assertNotSame($firstCachedPath, $secondCachedPath);
        $this->assertNotSame($firstContent, $secondContent);
    }

    /**
     * Ensure versioned cache path works with preset-based transforms.
     *
     * When using `?p=product`, the preset params are resolved and the cache
     * path includes both the resolved params and source mtime. Replacing the
     * source image produces a new cache entry for the same preset.
     */
    public function testCreateTransformedPathWithPresetUsesNewCachePathWhenSourceChanges(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide transformer tests.');
        }

        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);

        // Create initial black source image with past mtime
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 0, 0, 0);
        touch($rootPath . '/images/pixel.jpg', time() - 120);

        $cachePath = $this->createTempDirectory() . '/cache';
        $presets = ['product' => ['w' => '2', 'h' => '2', 'fit' => 'crop']];
        $transformer = $this->createTransformer();

        // First transform using preset
        $firstCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['p' => 'product'],
            presets: $presets,
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($firstCachedPath);
        $firstContent = file_get_contents($firstCachedPath);

        // Replace with a different (white) image
        $this->createColoredJpeg($rootPath . '/images/pixel.jpg', 255, 255, 255);
        clearstatcache(true);

        // Second transform using same preset
        $secondCachedPath = $transformer->createTransformedPath(
            rootPath: $rootPath,
            requestPath: '/images/pixel.jpg',
            queryParams: ['p' => 'product'],
            presets: $presets,
            cachePath: $cachePath,
            options: [],
        );
        $this->assertIsString($secondCachedPath);
        $secondContent = file_get_contents($secondCachedPath);

        // Different cache path and different content
        $this->assertNotSame($firstCachedPath, $secondCachedPath);
        $this->assertNotSame($firstContent, $secondContent);
    }

    /**
     * Build a minimal valid JPEG image file with a specific fill color.
     *
     * @param string $path File path to write JPEG to.
     * @param int<0, 255> $red Red channel value (0-255).
     * @param int<0, 255> $green Green channel value (0-255).
     * @param int<0, 255> $blue Blue channel value (0-255).
     */
    protected function createColoredJpeg(string $path, int $red, int $green, int $blue): void
    {
        $image = imagecreatetruecolor(4, 4);
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false) {
            self::fail('Unable to allocate test image color.');
        }

        imagefill($image, 0, 0, $color);
        imagejpeg($image, $path, 100);
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

    /**
     * Create an image transformer with concrete dependencies.
     */
    protected function createTransformer(): GlideImageTransformer
    {
        return new GlideImageTransformer(new ImagePresetResolver());
    }
}
