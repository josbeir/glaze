<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\Response;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests for static asset middleware behavior.
 */
final class AssetMiddlewareTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure public asset middleware returns matching files from output directory.
     */
    public function testPublicAssetMiddlewareReturnsAssetResponse(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/images', 0755, true);
        file_put_contents($projectRoot . '/public/images/logo.jpg', 'jpg-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/images/logo.jpg');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertSame('jpg-bytes', (string)$response->getBody());
    }

    /**
     * Ensure content asset middleware falls back when no matching asset exists.
     */
    public function testContentAssetMiddlewareFallsBackWhenMissingAsset(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new ContentAssetMiddleware($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing.jpg');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Create a fallback request handler for middleware passthrough tests.
     */
    protected function fallbackHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            /**
             * @inheritDoc
             */
            public function handle(ServerRequestInterface $request): Response
            {
                return (new Response(['charset' => 'UTF-8']))
                    ->withStatus(404)
                    ->withStringBody('fallback');
            }
        };
    }
}
