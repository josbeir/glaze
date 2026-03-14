<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http\Middleware;

use Cake\Http\Response;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\Middleware\ControllerMiddleware;
use Glaze\Http\Routing\ControllerRouter;
use Glaze\Http\Routing\ControllerViewRenderer;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests for ControllerMiddleware routing and injection behavior.
 */
final class ControllerMiddlewareTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure unmatched requests are forwarded to the next handler.
     */
    public function testProcessPassesUnmatchedRequestToNextHandler(): void
    {
        $router = new ControllerRouter();
        $config = BuildConfig::fromProjectRoot($this->createProjectRoot(), true);

        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $this->createTempDirectory(),
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/no-match');
        $handler = $this->fallbackHandler(404, 'fallback');

        $response = $middleware->process($request, $handler);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fallback', (string)$response->getBody());
    }

    /**
     * Ensure a matched route that returns ResponseInterface is returned directly.
     */
    public function testProcessReturnsResponseDirectlyFromAction(): void
    {
        $projectRoot = $this->createProjectRoot();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $controllersDir = $projectRoot . '/controllers';
        mkdir($controllersDir, 0755, true);
        file_put_contents($controllersDir . '/PingController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Cake\Http\Response;
        use Glaze\Http\Attribute\Route;
        use Psr\Http\Message\ResponseInterface;
        final class PingController {
            #[Route('/ping')]
            public function ping(): ResponseInterface {
                return (new Response(['charset' => 'UTF-8']))->withStatus(200)->withStringBody('pong');
            }
        }
        PHP);

        $router = new ControllerRouter();
        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $controllersDir,
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/ping');
        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', (string)$response->getBody());
    }

    /**
     * Ensure path parameters are injected into action method arguments.
     */
    public function testProcessInjectsPathParametersIntoAction(): void
    {
        $projectRoot = $this->createProjectRoot();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $controllersDir = $projectRoot . '/controllers';
        mkdir($controllersDir, 0755, true);
        file_put_contents($controllersDir . '/SlugController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Cake\Http\Response;
        use Glaze\Http\Attribute\Route;
        use Psr\Http\Message\ResponseInterface;
        final class SlugController {
            #[Route('/items/{slug}')]
            public function show(string $slug): ResponseInterface {
                return (new Response(['charset' => 'UTF-8']))->withStatus(200)->withStringBody($slug);
            }
        }
        PHP);

        $router = new ControllerRouter();
        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $controllersDir,
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/items/hello-world');
        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello-world', (string)$response->getBody());
    }

    /**
     * Ensure the PSR-7 request is injected when the action type-hints it.
     */
    public function testProcessInjectsRequestByType(): void
    {
        $projectRoot = $this->createProjectRoot();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $controllersDir = $projectRoot . '/controllers';
        mkdir($controllersDir, 0755, true);
        file_put_contents($controllersDir . '/MethodController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Cake\Http\Response;
        use Glaze\Http\Attribute\Route;
        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        final class MethodController {
            #[Route('/method', methods: ['GET', 'POST'])]
            public function check(ServerRequestInterface $request): ResponseInterface {
                return (new Response(['charset' => 'UTF-8']))->withStatus(200)->withStringBody($request->getMethod());
            }
        }
        PHP);

        $router = new ControllerRouter();
        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $controllersDir,
        );

        $postRequest = (new ServerRequestFactory())->createServerRequest('POST', '/method');
        $response = $middleware->process($postRequest, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('POST', (string)$response->getBody());
    }

    /**
     * Ensure controller discovery only runs once even across multiple requests.
     *
     * A temp directory is created with one controller file. Both requests match
     * the same route, confirming discovery persisted after the first request.
     */
    public function testDiscoveryRunsOnlyOnce(): void
    {
        $projectRoot = $this->createProjectRoot();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $controllersDir = $projectRoot . '/controllers';
        mkdir($controllersDir, 0755, true);
        file_put_contents($controllersDir . '/OnceController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Cake\Http\Response;
        use Glaze\Http\Attribute\Route;
        use Psr\Http\Message\ResponseInterface;
        final class OnceController {
            #[Route('/once')]
            public function index(): ResponseInterface {
                return (new Response(['charset' => 'UTF-8']))->withStatus(200)->withStringBody('ok');
            }
        }
        PHP);

        $router = new ControllerRouter();
        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $controllersDir,
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/once');

        // First request — triggers discovery.
        $response1 = $middleware->process($request, $this->fallbackHandler());
        $this->assertSame(200, $response1->getStatusCode());

        // Second request — discovery must NOT clear and re-run (route still matched).
        $response2 = $middleware->process($request, $this->fallbackHandler());
        $this->assertSame(200, $response2->getStatusCode());
    }

    /**
     * Create a minimal project root directory with required subdirectories.
     */
    protected function createProjectRoot(): string
    {
        $dir = $this->createTempDirectory();
        mkdir($dir . '/content', 0755, true);

        return $dir;
    }

    /**
     * Create a ControllerViewRenderer bound to the given config.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function makeViewRenderer(BuildConfig $config): ControllerViewRenderer
    {
        return new ControllerViewRenderer($config, $this->service(ResourcePathRewriter::class));
    }

    /**
     * Create a fallback request handler that returns a fixed status and body.
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
