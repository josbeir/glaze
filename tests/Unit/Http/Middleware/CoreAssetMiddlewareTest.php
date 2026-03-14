<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http\Middleware;

use Cake\Http\Response;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Http\AssetResponder;
use Glaze\Http\Middleware\CoreAssetMiddleware;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionProperty;

/**
 * Tests for CoreAssetMiddleware asset serving and basePath handling.
 */
final class CoreAssetMiddlewareTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure a request for an existing core asset returns 200.
     */
    public function testProcessServesExistingAsset(): void
    {
        $assetsDir = $this->createTempDirectory();
        mkdir($assetsDir . '/css', 0755, true);
        file_put_contents($assetsDir . '/css/dev.css', 'body { color: red; }');

        $middleware = $this->makeMiddleware($assetsDir);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/_glaze/assets/css/dev.css');

        $response = $middleware->process($request, $this->fallbackHandler(404));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('body { color: red; }', (string)$response->getBody());
    }

    /**
     * Ensure a request for a missing asset falls through to the next handler.
     */
    public function testProcessPassesThroughWhenAssetNotFound(): void
    {
        $assetsDir = $this->createTempDirectory();
        $middleware = $this->makeMiddleware($assetsDir);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/_glaze/assets/css/missing.css');

        $response = $middleware->process($request, $this->fallbackHandler(404, 'not found'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not found', (string)$response->getBody());
    }

    /**
     * Ensure non-/_glaze/ requests are forwarded to the next handler.
     */
    public function testProcessPassesThroughUnrelatedRequest(): void
    {
        $assetsDir = $this->createTempDirectory();
        $middleware = $this->makeMiddleware($assetsDir);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/some/other/path');

        $response = $middleware->process($request, $this->fallbackHandler(404, 'other'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('other', (string)$response->getBody());
    }

    /**
     * Ensure requests with a project basePath prefix are correctly resolved.
     *
     * When a project configures basePath (e.g. "/myapp"), Sugar templates emit
     * asset hrefs prefixed with it (e.g. /myapp/_glaze/assets/css/dev.css).
     * The middleware must strip the basePath and serve the file.
     */
    public function testProcessStripsBasePathBeforeMatching(): void
    {
        $assetsDir = $this->createTempDirectory();
        mkdir($assetsDir . '/css', 0755, true);
        file_put_contents($assetsDir . '/css/dev.css', '.glaze {}');

        $middleware = $this->makeMiddleware($assetsDir, '/myapp');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/myapp/_glaze/assets/css/dev.css');

        $response = $middleware->process($request, $this->fallbackHandler(404));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('.glaze {}', (string)$response->getBody());
    }

    /**
     * Ensure that with a basePath set, a bare /_glaze/assets/ request also still works.
     */
    public function testProcessServesAssetWithBasePathSetButNoPrefix(): void
    {
        $assetsDir = $this->createTempDirectory();
        mkdir($assetsDir . '/css', 0755, true);
        file_put_contents($assetsDir . '/css/dev.css', '.glaze {}');

        $middleware = $this->makeMiddleware($assetsDir, '/myapp');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/_glaze/assets/css/dev.css');

        $response = $middleware->process($request, $this->fallbackHandler(404));

        // Without the basePath prefix the path does not start with /myapp so stripBasePath
        // returns it unchanged, then the /_glaze/assets/ prefix still matches.
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Build a CoreAssetMiddleware with the given assets directory root overridden via reflection.
     *
     * @param string $assetsDir Temporary assets directory acting as resources/assets/.
     * @param string|null $basePath Optional site basePath (simulates glaze.neon site.basePath).
     */
    protected function makeMiddleware(string $assetsDir, ?string $basePath = null): CoreAssetMiddleware
    {
        $projectRoot = $this->createTempDirectory();
        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(basePath: $basePath),
        );

        $middleware = new CoreAssetMiddleware($config, new AssetResponder());

        $reflection = new ReflectionProperty(CoreAssetMiddleware::class, 'assetsRootPath');
        $reflection->setValue($middleware, $assetsDir);

        return $middleware;
    }

    /**
     * Build a simple fallback handler that returns the given status and body.
     *
     * @param int $status HTTP status code.
     * @param string $body Response body.
     */
    protected function fallbackHandler(int $status = 404, string $body = 'fallback'): RequestHandlerInterface
    {
        return new class ($status, $body) implements RequestHandlerInterface {
            /**
             * Constructor.
             *
             * @param int $status HTTP status code.
             * @param string $body Response body.
             */
            public function __construct(private int $status, private string $body)
            {
            }

            /**
             * @inheritDoc
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(['charset' => 'UTF-8']))
                    ->withStatus($this->status)
                    ->withStringBody($this->body);
            }
        };
    }
}
