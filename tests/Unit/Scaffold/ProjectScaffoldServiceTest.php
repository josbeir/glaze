<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Closure;
use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Scaffold\ScaffoldOptions;
use Glaze\Tests\Helper\FilesystemTestTrait;
use Nette\Neon\Neon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for project scaffold service.
 */
final class ProjectScaffoldServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure scaffold copies expected starter directories and files.
     */
    public function testScaffoldCreatesProjectFiles(): void
    {
        $target = $this->createTempDirectory() . '/my-site';
        $service = new ProjectScaffoldService();

        $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'my-site',
            siteTitle: 'My Site',
            pageTemplate: 'landing',
            description: 'My description',
            baseUrl: 'https://example.com',
            basePath: '/blog',
            taxonomies: ['tags', 'categories'],
            force: false,
        ));

        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
        $this->assertFileExists($target . '/.gitignore');
        $this->assertFileExists($target . '/.editorconfig');
        $this->assertFileExists($target . '/glaze.neon');

        $this->assertSame(
            file_get_contents(__DIR__ . '/../../../skeleton/content/index.dj'),
            file_get_contents($target . '/content/index.dj'),
        );
        $this->assertSame(
            file_get_contents(__DIR__ . '/../../../skeleton/templates/page.sugar.php'),
            file_get_contents($target . '/templates/page.sugar.php'),
        );
        $this->assertSame(
            file_get_contents(__DIR__ . '/../../../skeleton/.gitignore'),
            file_get_contents($target . '/.gitignore'),
        );

        $config = file_get_contents($target . '/glaze.neon');
        $this->assertIsString($config);
        $decoded = Neon::decode($config);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('site', $decoded);
        $this->assertArrayHasKey('taxonomies', $decoded);
        $this->assertIsArray($decoded['site']);
        $this->assertIsArray($decoded['taxonomies']);
        $this->assertSame('landing', $decoded['pageTemplate'] ?? null);
        $this->assertSame('My Site', $decoded['site']['title'] ?? null);
        $this->assertSame('/blog', $decoded['site']['basePath'] ?? null);
        $this->assertSame(['tags', 'categories'], $decoded['taxonomies']);
        $this->assertStringContainsString('# --- Available options (uncomment and adjust as needed) ---', $config);
        $this->assertStringContainsString('# images:', $config);
        $this->assertStringContainsString('#   driver: gd', $config);
        $this->assertStringContainsString('#   metaDefaults:', $config);
        $this->assertStringContainsString('# contentTypes:', $config);
        $this->assertStringContainsString('#       template: blog', $config);
        $this->assertStringContainsString('# djot:', $config);
        $this->assertStringContainsString('#   codeHighlighting:', $config);
        $this->assertStringContainsString('#   headerAnchors:', $config);
        $this->assertStringContainsString('# devServer:', $config);
        $this->assertStringContainsString('#   php:', $config);
        $this->assertStringContainsString('#     host: 127.0.0.1', $config);
        $this->assertStringContainsString('#     port: 8080', $config);
        $this->assertStringContainsString('#   vite:', $config);
        $this->assertStringContainsString('#     command: "npm run dev -- --host {host} --port {port} --strictPort"', $config);
    }

    /**
     * Ensure non-empty target directory fails unless force is enabled.
     */
    public function testScaffoldRejectsNonEmptyTargetWithoutForce(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $service = new ProjectScaffoldService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not empty');

        $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'site',
            siteTitle: 'Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
            force: false,
        ));
    }

    /**
     * Ensure force allows writing into existing non-empty target directory.
     */
    public function testScaffoldAllowsForceForNonEmptyTarget(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $service = new ProjectScaffoldService();
        $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'site',
            siteTitle: 'Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
            force: true,
        ));

        $this->assertFileExists($target . '/keep.txt');
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
    }

    /**
     * Ensure skeleton source path resolves to existing package skeleton directory.
     */
    public function testSkeletonSourcePathResolvesExistingDirectory(): void
    {
        $service = new ProjectScaffoldService();
        $path = $this->callProtected($service, 'skeletonSourcePath');

        $this->assertIsString($path);
        $this->assertDirectoryExists($path);
    }

    /**
     * Ensure Vite-enabled scaffolding writes vite.config.js and enables Vite settings in glaze.neon.
     */
    public function testScaffoldCreatesViteConfigurationWhenEnabled(): void
    {
        $target = $this->createTempDirectory() . '/vite-site';
        $service = new ProjectScaffoldService();

        $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'vite-site',
            siteTitle: 'Vite Site',
            pageTemplate: 'page',
            description: 'Vite-enabled site description',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags', 'categories'],
            enableVite: true,
            force: false,
        ));

        $this->assertFileExists($target . '/vite.config.js');
        $this->assertFileExists($target . '/package.json');

        $viteConfig = file_get_contents($target . '/vite.config.js');
        $this->assertIsString($viteConfig);
        $this->assertStringContainsString('manifest: true', $viteConfig);
        $this->assertStringContainsString("input: 'assets/css/site.css'", $viteConfig);

        $package = file_get_contents($target . '/package.json');
        $this->assertIsString($package);
        $packageData = json_decode($package, true);
        $this->assertIsArray($packageData);
        $this->assertSame('vite-site', $packageData['name'] ?? null);
        $this->assertSame('Vite-enabled site description', $packageData['description'] ?? null);
        $this->assertArrayHasKey('devDependencies', $packageData);
        $this->assertIsArray($packageData['devDependencies']);
        /** @var array<string, mixed> $devDependencies */
        $devDependencies = $packageData['devDependencies'];
        $this->assertSame('latest', $devDependencies['vite'] ?? null);

        $config = file_get_contents($target . '/glaze.neon');
        $this->assertIsString($config);
        $decoded = Neon::decode($config);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('build', $decoded);
        $this->assertArrayHasKey('devServer', $decoded);
        $this->assertIsArray($decoded['build']);
        $this->assertIsArray($decoded['devServer']);
        /** @var array<string, mixed> $buildConfig */
        $buildConfig = $decoded['build'];
        /** @var array<string, mixed> $devServerConfig */
        $devServerConfig = $decoded['devServer'];

        $this->assertArrayHasKey('vite', $buildConfig);
        $this->assertArrayHasKey('vite', $devServerConfig);
        $this->assertIsArray($buildConfig['vite']);
        $this->assertIsArray($devServerConfig['vite']);
        /** @var array<string, mixed> $buildViteConfig */
        $buildViteConfig = $buildConfig['vite'];
        /** @var array<string, mixed> $devServerViteConfig */
        $devServerViteConfig = $devServerConfig['vite'];

        $this->assertTrue($buildViteConfig['enabled'] ?? null);
        $this->assertSame('npm run build', $buildViteConfig['command'] ?? null);
        $this->assertSame('assets/css/site.css', $buildViteConfig['defaultEntry'] ?? null);
        $this->assertTrue($devServerViteConfig['enabled'] ?? null);
        $this->assertSame(5173, $devServerViteConfig['port'] ?? null);
        $this->assertSame('assets/css/site.css', $devServerViteConfig['defaultEntry'] ?? null);
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
