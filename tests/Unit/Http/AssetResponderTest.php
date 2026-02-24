<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\Response;
use Glaze\Http\AssetResponder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for static asset response generation.
 */
final class AssetResponderTest extends TestCase
{
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
     * Create a temporary directory for isolated test execution.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }
}
