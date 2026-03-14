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
use ReflectionProperty;

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
     * After the first request triggers discovery, the route table is inspected
     * via reflection before and after a second request to confirm the entries
     * were not duplicated or cleared.
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

        // Capture route table size immediately after discovery.
        $routesProp = new ReflectionProperty($router, 'routes');
        /** @var array<mixed> $routesAfterFirst */
        $routesAfterFirst = $routesProp->getValue($router);
        $routeCountAfterFirstRequest = count($routesAfterFirst);
        $this->assertGreaterThan(0, $routeCountAfterFirstRequest, 'Routes must be registered after first request.');

        // Second request — discovery must NOT re-run; the route table must be unchanged.
        $response2 = $middleware->process($request, $this->fallbackHandler());
        $this->assertSame(200, $response2->getStatusCode());
        /** @var array<mixed> $routesAfterSecond */
        $routesAfterSecond = $routesProp->getValue($router);
        $this->assertCount(
            $routeCountAfterFirstRequest,
            $routesAfterSecond,
            'Route count must not change after the second request; discoverOnce must not have re-run.',
        );
    }

    /**
     * Ensure the original (basePath-prefixed) request is forwarded unchanged when no route matches.
     *
     * Downstream handlers such as DevPageRequestHandler rely on the full original
     * path (including any basePath prefix) for canonical redirects and Location headers.
     */
    public function testProcessForwardsOriginalRequestPathOnMiss(): void
    {
        $projectRoot = $this->createProjectRoot();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /app\n");
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $router = new ControllerRouter();
        $middleware = new ControllerMiddleware(
            $router,
            $this->makeViewRenderer($config),
            $this->container(),
            $config,
            $this->createTempDirectory(),
        );

        $capturedPath = null;
        $capturingHandler = new class ($capturedPath) implements RequestHandlerInterface {
            public function __construct(public ?string &$path)
            {
            }

            /**
             * @inheritDoc
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->path = $request->getUri()->getPath();

                return (new Response(['charset' => 'UTF-8']))->withStatus(404)->withStringBody('miss');
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/app/about/');
        $middleware->process($request, $capturingHandler);

        $this->assertSame('/app/about/', $capturedPath, 'The original basePath-prefixed path must be forwarded unchanged.');
    }

    /**
     * Ensure path parameters are coerced to declared scalar types (int, float, bool).
     */
    public function testProcessCoercesScalarPathParams(): void
    {
        $projectRoot = $this->createProjectRoot();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $controllersDir = $projectRoot . '/controllers';
        mkdir($controllersDir, 0755, true);
        file_put_contents($controllersDir . '/TypedController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Cake\Http\Response;
        use Glaze\Http\Attribute\Route;
        use Psr\Http\Message\ResponseInterface;
        final class TypedController {
            #[Route('/items/{id}/{score}/{active}')]
            public function show(int $id, float $score, bool $active): ResponseInterface {
                return (new Response(['charset' => 'UTF-8']))
                    ->withStatus(200)
                    ->withStringBody(json_encode(['id' => $id, 'score' => $score, 'active' => $active]));
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

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/items/42/3.14/true');
        $response = $middleware->process($request, $this->fallbackHandler());

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string)$response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(42, $decoded['id']);
        $this->assertEqualsWithDelta(3.14, $decoded['score'], PHP_FLOAT_EPSILON);
        $this->assertTrue($decoded['active']);
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
