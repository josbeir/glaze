<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\MiddlewareQueue;
use Cake\Http\Response;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Closure;
use Glaze\Config\BuildConfig;
use Glaze\Http\AssetResponder;
use Glaze\Http\Middleware\AbstractAssetMiddleware;
use Glaze\Http\Middleware\AbstractGlideAssetMiddleware;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;
use Glaze\Http\Middleware\StaticAssetMiddleware;
use Glaze\Image\GlideImageTransformer;
use Glaze\Image\ImageTransformerInterface;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests for static asset middleware behavior.
 */
final class AssetMiddlewareTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure public asset middleware serves files that only exist in public output.
     */
    public function testPublicAssetMiddlewareServesPublicOnlyFiles(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/assets', 0755, true);
        mkdir($projectRoot . '/content', 0755, true);
        file_put_contents($projectRoot . '/public/assets/app.css', 'body{}');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config, $this->service(AssetResponder::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/assets/app.css');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
        $this->assertSame('body{}', (string)$response->getBody());
    }

    /**
     * Ensure public asset middleware skips files that also exist in content directory.
     */
    public function testPublicAssetMiddlewareSkipsFilesExistingInContentDir(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/images', 0755, true);
        mkdir($projectRoot . '/content/images', 0755, true);
        file_put_contents($projectRoot . '/public/images/logo.jpg', 'stale-jpg-bytes');
        file_put_contents($projectRoot . '/content/images/logo.jpg', 'fresh-jpg-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config, $this->service(AssetResponder::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/images/logo.jpg');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure public asset middleware skips files that also exist in static directory.
     */
    public function testPublicAssetMiddlewareSkipsFilesExistingInStaticDir(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        mkdir($projectRoot . '/static', 0755, true);
        file_put_contents($projectRoot . '/public/manual.pdf', 'stale-pdf');
        file_put_contents($projectRoot . '/static/manual.pdf', 'fresh-pdf');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config, $this->service(AssetResponder::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/manual.pdf');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure public asset middleware resolves basePath-prefixed requests for public-only files.
     */
    public function testPublicAssetMiddlewareResolvesBasePathPrefixedAssetResponse(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/assets', 0755, true);
        mkdir($projectRoot . '/content', 0755, true);
        file_put_contents($projectRoot . '/public/assets/app.js', 'var x=1;');
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /glaze\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config, $this->service(AssetResponder::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/glaze/assets/app.js');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('javascript', $response->getHeaderLine('Content-Type'));
        $this->assertSame('var x=1;', (string)$response->getBody());
    }

    /**
     * Ensure static asset middleware returns matching files from static directory.
     */
    public function testStaticAssetMiddlewareReturnsAssetResponse(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/static', 0755, true);
        file_put_contents($projectRoot . '/static/image.png', 'png-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new StaticAssetMiddleware($config, $this->service(AssetResponder::class), $this->service(GlideImageTransformer::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/image.png');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('png-bytes', (string)$response->getBody());
    }

    /**
     * Ensure static asset middleware resolves basePath-prefixed requests.
     */
    public function testStaticAssetMiddlewareResolvesBasePathPrefixedAssetResponse(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/static', 0755, true);
        file_put_contents($projectRoot . '/static/image.png', 'png-bytes');
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /glaze\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new StaticAssetMiddleware($config, $this->service(AssetResponder::class), $this->service(GlideImageTransformer::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/glaze/image.png');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('png-bytes', (string)$response->getBody());
    }

    /**
     * Ensure static asset middleware applies Glide transforms to static-folder images.
     */
    public function testStaticAssetMiddlewareUsesImageTransformerForImageRequests(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/static/images', 0755, true);
        file_put_contents($projectRoot . '/static/images/logo.jpg', 'jpg-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $transformer = new class implements ImageTransformerInterface {
            public function createResponse(
                string $rootPath,
                string $requestPath,
                array $queryParams,
                array $presets,
                string $cachePath,
                array $options = [],
            ): ?ResponseInterface {
                if (($queryParams['w'] ?? null) !== '200') {
                    return null;
                }

                return (new Response(['charset' => 'UTF-8']))
                    ->withStatus(206)
                    ->withHeader('Content-Type', 'image/jpeg')
                    ->withStringBody('transformed-static-jpg');
            }
        };

        $middleware = new StaticAssetMiddleware($config, $this->service(AssetResponder::class), $transformer);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/images/logo.jpg')
            ->withQueryParams(['w' => '200']);

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('transformed-static-jpg', (string)$response->getBody());
    }

    /**
     * Ensure content asset middleware falls back when no matching asset exists.
     */
    public function testContentAssetMiddlewareFallsBackWhenMissingAsset(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new ContentAssetMiddleware($config, $this->service(AssetResponder::class), $this->service(GlideImageTransformer::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing.jpg');

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure public asset middleware skips source-directory files even with query params.
     */
    public function testPublicAssetMiddlewareSkipsSourceFileWithQueryParams(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/images', 0755, true);
        mkdir($projectRoot . '/content/images', 0755, true);
        file_put_contents($projectRoot . '/public/images/logo.jpg', 'stale-bytes');
        file_put_contents($projectRoot . '/content/images/logo.jpg', 'fresh-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new PublicAssetMiddleware($config, $this->service(AssetResponder::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/images/logo.jpg')
            ->withQueryParams(['h' => '500']);

        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure Glide-aware middleware helper methods normalize expected values.
     */
    public function testGlideAssetMiddlewareHelperMethods(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new class ($config, $this->service(AssetResponder::class), $this->service(GlideImageTransformer::class)) extends AbstractGlideAssetMiddleware {
            protected function assetRootPath(): string
            {
                return $this->config->outputPath();
            }
        };

        $this->assertTrue($this->callProtected($middleware, 'isImagePath', '/images/photo.jpg'));
        $this->assertFalse($this->callProtected($middleware, 'isImagePath', '/images/readme.txt'));
        $this->assertFalse($this->callProtected($middleware, 'isImagePath', '/images/photo'));
        $this->assertSame(
            ['h' => '500'],
            $this->callProtected($middleware, 'normalizeQueryParams', ['h' => '500', 0 => 'ignored']),
        );
    }

    /**
     * Ensure ContentAssetMiddleware serves a fresh transform when the source image is replaced.
     *
     * This simulates the real dev-mode scenario: make a request with a Glide
     * param, replace the source image, make the same request again. The second
     * response must reflect the new image, not the stale cached transform.
     */
    public function testContentMiddlewareServesUpdatedImageAfterSourceReplacement(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);

        // Create initial black source image with a past mtime
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        imagejpeg($image, $projectRoot . '/content/images/photo.jpg', 100);
        touch($projectRoot . '/content/images/photo.jpg', time() - 120);

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new ContentAssetMiddleware(
            $config,
            $this->service(AssetResponder::class),
            $this->service(GlideImageTransformer::class),
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/images/photo.jpg')
            ->withQueryParams(['w' => '2']);

        // First request — transforms the black image
        $firstResponse = $middleware->process($request, $this->fallbackHandler());
        $this->assertSame(200, $firstResponse->getStatusCode());
        $firstBody = (string)$firstResponse->getBody();

        // Replace source with a white image (natural overwrite, no touch)
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagejpeg($image, $projectRoot . '/content/images/photo.jpg', 100);
        clearstatcache(true);

        // Second request — must serve the white image, not stale black cache
        $secondResponse = $middleware->process($request, $this->fallbackHandler());
        $this->assertSame(200, $secondResponse->getStatusCode());
        $secondBody = (string)$secondResponse->getBody();

        $this->assertNotSame($firstBody, $secondBody, 'Middleware must serve fresh transform after source replacement');
    }

    /**
     * Ensure abstract middleware can skip handling through shouldHandleRequest hook.
     */
    public function testAbstractAssetMiddlewareCanSkipRequestHandling(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public/images', 0755, true);
        file_put_contents($projectRoot . '/public/images/logo.jpg', 'jpg-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $middleware = new class ($config, $this->service(AssetResponder::class)) extends AbstractAssetMiddleware {
            protected function shouldHandleRequest(ServerRequestInterface $request): bool
            {
                return false;
            }

            protected function assetRootPath(): string
            {
                return $this->config->outputPath();
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/images/logo.jpg');
        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure abstract middleware can override response creation through hook method.
     */
    public function testAbstractAssetMiddlewareCanOverrideAssetResponseCreation(): void
    {
        $projectRoot = $this->createTempDirectory();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $middleware = new class ($config, $this->service(AssetResponder::class)) extends AbstractAssetMiddleware {
            protected function createAssetResponse(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(['charset' => 'UTF-8']))
                    ->withStatus(202)
                    ->withStringBody('custom');
            }

            protected function assetRootPath(): string
            {
                return $this->config->outputPath();
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/images/logo.jpg');
        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('custom', (string)$response->getBody());
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

    /**
     * Invoke a protected method on an object using scope-bound closure.
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
