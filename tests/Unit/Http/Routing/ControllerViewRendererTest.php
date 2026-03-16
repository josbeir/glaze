<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http\Routing;

use Glaze\Config\BuildConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Http\Routing\ControllerViewRenderer;
use Glaze\Http\Routing\MatchedRoute;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for ControllerViewRenderer template resolution and rendering.
 */
final class ControllerViewRendererTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure the default backend Vite options target the package's own Vite build (port 5184).
     */
    public function testDefaultBackendViteOptionsTargetPackageBuild(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $renderer = $this->makeRenderer($config);

        $vite = (new ReflectionProperty(ControllerViewRenderer::class, 'backendVite'))->getValue($renderer);

        $this->assertInstanceOf(TemplateViteOptions::class, $vite);
        $this->assertTrue($vite->buildEnabled);
        $this->assertTrue($vite->devEnabled);
        $this->assertStringEndsWith('/resources/backend/assets/dist/.vite/manifest.json', $vite->manifestPath);
        $this->assertSame('http://localhost:5174', $vite->devServerUrl);
        $this->assertSame('/_glaze/assets/dist/', $vite->assetBaseUrl);
        $this->assertSame('dev', $vite->mode);
    }

    /**
     * Ensure the backendVite property can be overridden via reflection for testing purposes.
     */
    public function testBackendViteOptionsCanBeOverriddenViaReflection(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $renderer = $this->makeRenderer($config);

        $customVite = new TemplateViteOptions(devServerUrl: 'http://127.0.0.1:9999');
        (new ReflectionProperty(ControllerViewRenderer::class, 'backendVite'))->setValue($renderer, $customVite);

        $vite = (new ReflectionProperty(ControllerViewRenderer::class, 'backendVite'))->getValue($renderer);

        $this->assertSame($customVite, $vite);
        $this->assertSame('http://127.0.0.1:9999', $vite->devServerUrl);
    }

    /**
     * Ensure hasTemplate returns false when no template file exists.
     */
    public function testHasTemplateReturnsFalseWhenNoTemplateExists(): void
    {
        $templateDir = $this->createTempDirectory();
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $renderer = $this->makeRenderer($config, $templateDir);

        $this->assertFalse($renderer->hasTemplate('inspector', 'nonexistent'));
    }

    /**
     * Ensure hasTemplate returns true when the template exists in the configured directory.
     */
    public function testHasTemplateReturnsTrueWhenTemplateExists(): void
    {
        $templateDir = $this->createTempDirectory();
        mkdir($templateDir . '/inspector', 0755, true);
        file_put_contents($templateDir . '/inspector/routes.sugar.php', '<p>routes</p>');

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $renderer = $this->makeRenderer($config, $templateDir);

        $this->assertTrue($renderer->hasTemplate('inspector', 'routes'));
    }

    /**
     * Ensure render returns a 200 HTML response with the template output.
     */
    public function testRenderRendersTemplate(): void
    {
        $templateDir = $this->createTempDirectory();
        mkdir($templateDir . '/test', 0755, true);
        file_put_contents($templateDir . '/test/hello.sugar.php', '<?= $greeting ?>');

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $renderer = $this->makeRenderer($config, $templateDir);

        $route = new MatchedRoute(
            controllerClass: self::class,
            actionMethod: 'hello',
            params: [],
            controllerName: 'test',
            actionName: 'hello',
        );

        $response = $renderer->render($route, ['greeting' => 'Hello from package!']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Hello from package!', (string)$response->getBody());
    }

    /**
     * Instantiate a renderer for the given config and optional template directory.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string|null $templateDirectory Optional override template directory.
     */
    protected function makeRenderer(BuildConfig $config, ?string $templateDirectory = null): ControllerViewRenderer
    {
        return new ControllerViewRenderer($config, $this->service(ResourcePathRewriter::class), $templateDirectory);
    }
}
