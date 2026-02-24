<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\Response;
use Glaze\Http\AssetResponder;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for static asset response generation.
 */
final class AssetResponderTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure existing files return HTTP responses.
     */
    public function testCreateFileResponseReturnsResponseForExistingFile(): void
    {
        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/images', 0755, true);
        file_put_contents($rootPath . '/images/photo.jpg', 'jpg-bytes');

        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/images/photo.jpg',
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertSame('jpg-bytes', (string)$response->getBody());
    }

    /**
     * Ensure empty request paths are ignored.
     */
    public function testCreateFileResponseReturnsNullForEmptyRequestPath(): void
    {
        $rootPath = $this->createTempDirectory();
        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/',
        );

        $this->assertNotInstanceOf(Response::class, $response);
    }

    /**
     * Ensure djot source files are blocked by default.
     */
    public function testCreateFileResponseBlocksDjotFilesByDefault(): void
    {
        $rootPath = $this->createTempDirectory();
        file_put_contents($rootPath . '/index.dj', '# source');

        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/index.dj',
        );

        $this->assertNotInstanceOf(Response::class, $response);
    }

    /**
     * Ensure djot files can be served when explicitly allowed.
     */
    public function testCreateFileResponseAllowsDjotFilesWhenEnabled(): void
    {
        $rootPath = $this->createTempDirectory();
        file_put_contents($rootPath . '/index.dj', '# source');

        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/index.dj',
            allowDjot: true,
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Ensure path traversal attempts are rejected.
     */
    public function testCreateFileResponseRejectsPathTraversal(): void
    {
        $rootPath = $this->createTempDirectory();
        mkdir($rootPath . '/safe', 0755, true);
        file_put_contents($rootPath . '/safe/file.txt', 'safe');

        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/../safe/file.txt',
        );

        $this->assertNotInstanceOf(Response::class, $response);
    }

    /**
     * Ensure invalid asset root paths return no response.
     */
    public function testCreateFileResponseReturnsNullForInvalidRootPath(): void
    {
        $response = (new AssetResponder())->createFileResponse(
            rootPath: $this->createTempDirectory() . '/missing',
            requestPath: '/image.jpg',
        );

        $this->assertNotInstanceOf(Response::class, $response);
    }

    /**
     * Ensure symlink traversal outside root is rejected by root-prefix guard.
     */
    public function testCreateFileResponseRejectsSymlinkTraversalOutsideRoot(): void
    {
        $rootPath = $this->createTempDirectory();
        $outsidePath = $this->createTempDirectory();
        file_put_contents($outsidePath . '/external.txt', 'external');

        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlink support is required for this test.');
        }

        symlink($outsidePath . '/external.txt', $rootPath . '/link.txt');

        $response = (new AssetResponder())->createFileResponse(
            rootPath: $rootPath,
            requestPath: '/link.txt',
        );

        $this->assertNotInstanceOf(Response::class, $response);
    }
}
