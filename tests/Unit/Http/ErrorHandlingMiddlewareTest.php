<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\MiddlewareQueue;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Http\Middleware\ErrorHandlingMiddleware;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Tests for error handling middleware behavior.
 */
final class ErrorHandlingMiddlewareTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure middleware returns debug details for thrown exceptions.
     */
    public function testProcessReturnsDebugResponseForExceptions(): void
    {
        $middleware = new ErrorHandlingMiddleware(true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $response = $middleware->process($request, $this->throwingHandler('Execution stopped by dd().'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Execution stopped by dd().', (string)$response->getBody());
    }

    /**
     * Ensure middleware hides exception details when debug mode is disabled.
     */
    public function testProcessReturnsGenericResponseWhenDebugDisabled(): void
    {
        $middleware = new ErrorHandlingMiddleware(false);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $response = $middleware->process($request, $this->throwingHandler('sensitive-details'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('500 Internal Server Error', (string)$response->getBody());
        $this->assertStringNotContainsString('sensitive-details', (string)$response->getBody());
    }

    /**
     * Ensure middleware catches dd exceptions triggered by template rendering.
     */
    public function testMiddlewareCatchesDdExceptionFromTemplateRendering(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/template-dd');
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $queue = new MiddlewareQueue();
        $queue->add(new ErrorHandlingMiddleware(true));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new Runner())->run($queue, $request, new DevPageRequestHandler($config));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Execution stopped by dd().', (string)$response->getBody());
    }

    /**
     * Build request handler that always throws.
     *
     * @param string $message Exception message.
     */
    protected function throwingHandler(string $message): RequestHandlerInterface
    {
        return new class ($message) implements RequestHandlerInterface {
            /**
             * Constructor.
             *
             * @param string $message Exception message.
             */
            public function __construct(protected string $message)
            {
            }

            /**
             * @inheritDoc
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
