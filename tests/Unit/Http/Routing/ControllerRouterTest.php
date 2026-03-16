<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http\Routing;

use Cake\Http\ServerRequestFactory;
use Glaze\Http\Routing\ControllerRouter;
use Glaze\Http\Routing\MatchedRoute;
use Glaze\Tests\Fixture\Http\AdminController;
use Glaze\Tests\Fixture\Http\ArticleController;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the attribute-based controller router.
 */
final class ControllerRouterTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Returns a router that has discovered both fixture controller files.
     */
    protected function makeRouter(): ControllerRouter
    {
        $router = new ControllerRouter();
        $router->discover(dirname(__DIR__, 3) . '/Fixture/Http');

        return $router;
    }

    /**
     * Ensure a simple prefixed route is matched.
     */
    public function testMatchReturnsPrefixedRoute(): void
    {
        $router = $this->makeRouter();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/dashboard');

        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame(AdminController::class, $match->controllerClass);
        $this->assertSame('dashboard', $match->actionMethod);
        $this->assertSame('admin', $match->controllerName);
        $this->assertSame('dashboard', $match->actionName);
        $this->assertSame([], $match->params);
    }

    /**
     * Ensure path parameters are extracted and mapped by name.
     */
    public function testMatchExtractsPathParameters(): void
    {
        $router = $this->makeRouter();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/articles/my-article');

        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame('my-article', $match->params['slug']);
    }

    /**
     * Ensure matching works for routes without a prefix.
     */
    public function testMatchesUnprefixedRoute(): void
    {
        $router = $this->makeRouter();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/articles');

        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame(ArticleController::class, $match->controllerClass);
        $this->assertSame('index', $match->actionMethod);
        $this->assertSame('article', $match->controllerName);
    }

    /**
     * Ensure incorrect HTTP method returns null.
     */
    public function testMatchReturnsNullForWrongMethod(): void
    {
        $router = $this->makeRouter();
        // /admin/dashboard only accepts GET
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/admin/dashboard');

        $this->assertNotInstanceOf(MatchedRoute::class, $router->match($request));
    }

    /**
     * Ensure no match is returned for an unknown path.
     */
    public function testMatchReturnsNullForUnknownPath(): void
    {
        $router = $this->makeRouter();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/no/such/path');

        $this->assertNotInstanceOf(MatchedRoute::class, $router->match($request));
    }

    /**
     * Ensure multi-method attribute registration works (GET and POST on same route).
     */
    public function testMatchAcceptsAllDeclaredHttpMethods(): void
    {
        $router = $this->makeRouter();

        $getRequest = (new ServerRequestFactory())->createServerRequest('GET', '/admin/articles/slug-a');
        $postRequest = (new ServerRequestFactory())->createServerRequest('POST', '/admin/articles/slug-a');

        $this->assertInstanceOf(MatchedRoute::class, $router->match($getRequest));
        $this->assertInstanceOf(MatchedRoute::class, $router->match($postRequest));
    }

    /**
     * Ensure methods passed as a plain string are normalised to uppercase.
     */
    public function testMatchMethodsNormalisedToUppercase(): void
    {
        $router = $this->makeRouter();
        // /admin/articles/{id}/delete accepts 'POST' (declared as 'POST' string)
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/articles/42/delete');

        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame('delete', $match->actionName);
    }

    /**
     * Ensure discover() silently does nothing for non-existent directories.
     */
    public function testDiscoverIgnoresMissingDirectory(): void
    {
        $router = new ControllerRouter();
        $router->discover('/tmp/glaze-non-existent-controllers-dir');

        $this->assertSame([], $router->routes());
    }

    /**
     * Ensure discover() registers routes found in a freshly-created temp dir.
     */
    public function testDiscoverFromTempDirectory(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/HelloController.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Glaze\Tests\Dynamic;
        use Glaze\Http\Attribute\Route;
        final class HelloController {
            #[Route('/hello')]
            public function index(): array { return []; }
        }
        PHP);

        $router = new ControllerRouter();
        $router->discover($dir);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/hello');
        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame('hello', $match->controllerName);
        $this->assertSame('index', $match->actionName);
    }

    /**
     * Ensure the controller name derived from a 'FooController' class is 'foo'.
     */
    public function testControllerNameStripsSuffix(): void
    {
        $router = $this->makeRouter();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/articles/hello');

        $match = $router->match($request);

        $this->assertInstanceOf(MatchedRoute::class, $match);
        $this->assertSame('article', $match->controllerName);
    }
}
