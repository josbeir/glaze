<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Scaffold\ScaffoldOptions;
use Glaze\Scaffold\ScaffoldRegistry;
use Glaze\Scaffold\ScaffoldSchemaLoader;
use Glaze\Scaffold\TemplateRenderer;
use Glaze\Tests\Helper\FilesystemTestTrait;
use Glaze\Utility\Normalization;
use Nette\Neon\Neon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ProjectScaffoldService.
 */
final class ProjectScaffoldServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Resolve the bundled scaffolds directory.
     */
    private function scaffoldsDirectory(): string
    {
        return dirname(__DIR__, 3) . '/scaffolds';
    }

    /**
     * Build a service instance using the bundled scaffolds registry.
     */
    private function buildService(): ProjectScaffoldService
    {
        return new ProjectScaffoldService(
            new ScaffoldRegistry($this->scaffoldsDirectory(), new ScaffoldSchemaLoader()),
            new TemplateRenderer(),
        );
    }

    /**
     * Ensure the default preset layout does not include the s-vite directive.
     */
    public function testScaffoldDefaultPresetLayoutHasNoViteDirective(): void
    {
        $target = $this->createTempDirectory() . '/plain-site';
        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'plain-site',
            siteTitle: 'Plain Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: [],
        ));

        $layout = file_get_contents($target . '/templates/layout/page.sugar.php');
        $this->assertIsString($layout);
        $this->assertStringNotContainsString('<s-vite', $layout);
    }

    /**
     * Ensure scaffold with the default preset creates expected starter files.
     */
    public function testScaffoldDefaultPresetCreatesExpectedFiles(): void
    {
        $target = $this->createTempDirectory() . '/my-site';
        $service = $this->buildService();

        $created = $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'my-site',
            siteTitle: 'My Site',
            pageTemplate: 'landing',
            description: 'My description',
            baseUrl: 'https://example.com',
            basePath: '/blog',
            taxonomies: ['tags', 'categories'],
            preset: 'default',
            force: false,
        ));

        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
        $this->assertFileExists($target . '/.gitignore');
        $this->assertFileExists($target . '/.editorconfig');
        $this->assertFileExists($target . '/glaze.neon');

        $normalizedCreated = array_map(static fn(string $path): string => Normalization::path($path), $created);

        $this->assertContains(Normalization::path($target . '/glaze.neon'), $normalizedCreated);
        $this->assertNotContains(Normalization::path($target . '/vite.config.js'), $normalizedCreated);
        $this->assertNotContains(Normalization::path($target . '/package.json'), $normalizedCreated);
    }

    /**
     * Ensure the generated glaze.neon encodes user-supplied values correctly.
     */
    public function testScaffoldDefaultPresetGeneratesCorrectGlazeNeon(): void
    {
        $target = $this->createTempDirectory() . '/my-site';
        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'my-site',
            siteTitle: 'My Site',
            pageTemplate: 'landing',
            description: 'My description',
            baseUrl: 'https://example.com',
            basePath: '/blog',
            taxonomies: ['tags', 'categories'],
        ));

        $raw = file_get_contents($target . '/glaze.neon');
        $this->assertIsString($raw);

        $decoded = Neon::decode($raw);
        $this->assertIsArray($decoded);
        $this->assertSame('landing', $decoded['pageTemplate'] ?? null);
        $this->assertIsArray($decoded['site']);
        /** @var array<string, mixed> $siteConfig */
        $siteConfig = $decoded['site'];
        $this->assertSame('My Site', $siteConfig['title'] ?? null);
        $this->assertSame('My description', $siteConfig['description'] ?? null);
        $this->assertSame('https://example.com', $siteConfig['baseUrl'] ?? null);
        $this->assertSame('/blog', $siteConfig['basePath'] ?? null);
        $this->assertSame(['tags', 'categories'], $decoded['taxonomies'] ?? null);

        $this->assertStringContainsString('# --- Available options (uncomment and adjust as needed) ---', $raw);
        $this->assertStringContainsString('# contentTypes:', $raw);
        $this->assertStringContainsString('# djot:', $raw);
        $this->assertStringContainsString('# devServer:', $raw);
    }

    /**
     * Ensure the skeleton files are copied verbatim for the default preset.
     */
    public function testScaffoldCopiesSkeletonFilesVerbatim(): void
    {
        $target = $this->createTempDirectory() . '/my-site';
        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'my-site',
            siteTitle: 'My Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
        ));

        $scaffoldsDir = $this->scaffoldsDirectory();

        $this->assertSame(
            file_get_contents($scaffoldsDir . '/default/content/index.dj'),
            file_get_contents($target . '/content/index.dj'),
        );
        $this->assertSame(
            file_get_contents($scaffoldsDir . '/default/templates/page.sugar.php'),
            file_get_contents($target . '/templates/page.sugar.php'),
        );
        $this->assertSame(
            file_get_contents($scaffoldsDir . '/default/.gitignore'),
            file_get_contents($target . '/.gitignore'),
        );
    }

    /**
     * Ensure the vite preset creates vite.config.js and package.json with npm-ready content.
     */
    public function testScaffoldVitePresetCreatesViteFiles(): void
    {
        $target = $this->createTempDirectory() . '/vite-site';
        $service = $this->buildService();

        $created = $service->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'vite-site',
            siteTitle: 'Vite Site',
            pageTemplate: 'page',
            description: 'Vite-enabled description',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
            preset: 'vite',
            force: false,
        ));

        $this->assertFileExists($target . '/vite.config.js');
        $this->assertFileExists($target . '/package.json');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');

        $normalizedCreated = array_map(static fn(string $path): string => Normalization::path($path), $created);

        $this->assertContains(Normalization::path($target . '/vite.config.js'), $normalizedCreated);
        $this->assertContains(Normalization::path($target . '/package.json'), $normalizedCreated);

        $viteLayout = file_get_contents($target . '/templates/layout/page.sugar.php');
        $this->assertIsString($viteLayout);
        $this->assertStringContainsString("<s-vite src=\"['assets/css/site.css']\" />", $viteLayout);

        $viteConfig = file_get_contents($target . '/vite.config.js');
        $this->assertIsString($viteConfig);
        $this->assertStringContainsString('manifest: true', $viteConfig);
        $this->assertStringContainsString("input: 'assets/css/site.css'", $viteConfig);

        $package = file_get_contents($target . '/package.json');
        $this->assertIsString($package);
        $packageData = json_decode($package, true);
        $this->assertIsArray($packageData);
        $this->assertSame('vite-site', $packageData['name'] ?? null);
        $this->assertSame('Vite-enabled description', $packageData['description'] ?? null);
        $this->assertArrayHasKey('devDependencies', $packageData);
        $this->assertIsArray($packageData['devDependencies']);
        /** @var array<string, mixed> $devDeps */
        $devDeps = $packageData['devDependencies'];
        $this->assertSame('latest', $devDeps['vite'] ?? null);
    }

    /**
     * Ensure the vite preset injects build and devServer vite config into glaze.neon.
     */
    public function testScaffoldVitePresetInjectsViteConfigIntoGlazeNeon(): void
    {
        $target = $this->createTempDirectory() . '/vite-site';
        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'vite-site',
            siteTitle: 'Vite Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
            preset: 'vite',
        ));

        $raw = file_get_contents($target . '/glaze.neon');
        $this->assertIsString($raw);

        $decoded = Neon::decode($raw);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('build', $decoded);
        $this->assertArrayHasKey('devServer', $decoded);

        /** @var array{vite: array{enabled: bool, command: string, defaultEntry: string}} $buildConfig */
        $buildConfig = $decoded['build'];
        $this->assertArrayHasKey('vite', $buildConfig);
        $this->assertTrue($buildConfig['vite']['enabled']);
        $this->assertSame('npm run build', $buildConfig['vite']['command']);
        $this->assertSame('assets/css/site.css', $buildConfig['vite']['defaultEntry']);

        /** @var array{vite: array{enabled: bool, port: int, defaultEntry: string}} $devServer */
        $devServer = $decoded['devServer'];
        $this->assertArrayHasKey('vite', $devServer);
        $this->assertTrue($devServer['vite']['enabled']);
        $this->assertSame(5173, $devServer['vite']['port']);
        $this->assertSame('assets/css/site.css', $devServer['vite']['defaultEntry']);
    }

    /**
     * Ensure scaffold rejects non-empty target directory when force is false.
     */
    public function testScaffoldRejectsNonEmptyTargetWithoutForce(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not empty');

        $this->buildService()->scaffold(new ScaffoldOptions(
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
     * Ensure force allows scaffolding into a non-empty target directory.
     */
    public function testScaffoldAllowsForceForNonEmptyTarget(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $this->buildService()->scaffold(new ScaffoldOptions(
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
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
    }

    /**
     * Ensure presetNames returns the names of all available presets.
     */
    public function testPresetNamesReturnsAvailablePresets(): void
    {
        $service = $this->buildService();
        $names = $service->presetNames();

        $this->assertContains('default', $names);
        $this->assertContains('vite', $names);
    }

    /**
     * Ensure requesting an unknown preset throws a RuntimeException.
     */
    public function testScaffoldThrowsForUnknownPreset(): void
    {
        $target = $this->createTempDirectory() . '/new-site';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffold preset "nonexistent" not found');

        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'site',
            siteTitle: 'Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: [],
            preset: 'nonexistent',
        ));
    }

    /**
     * Ensure scaffold returns list of all created file absolute paths.
     */
    public function testScaffoldReturnsCreatedFilePaths(): void
    {
        $target = $this->createTempDirectory() . '/paths-site';
        $created = $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'site',
            siteTitle: 'Site',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: [],
        ));

        $this->assertNotEmpty($created);
        foreach ($created as $path) {
            $this->assertStringStartsWith($target, $path);
            $this->assertFileExists($path);
        }
    }

    /**
     * Ensure description and optional site fields are omitted from glaze.neon when blank.
     */
    public function testScaffoldOmitsBlankOptionalSiteFields(): void
    {
        $target = $this->createTempDirectory() . '/minimal';
        $this->buildService()->scaffold(new ScaffoldOptions(
            targetDirectory: $target,
            siteName: 'minimal',
            siteTitle: 'Minimal',
            pageTemplate: 'page',
            description: '',
            baseUrl: null,
            basePath: null,
            taxonomies: ['tags'],
        ));

        $raw = file_get_contents($target . '/glaze.neon');
        $this->assertIsString($raw);
        $decoded = Neon::decode($raw);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['site']);
        /** @var array<string, mixed> $siteConfig */
        $siteConfig = $decoded['site'];
        $this->assertArrayNotHasKey('description', $siteConfig);
        $this->assertArrayNotHasKey('baseUrl', $siteConfig);
        $this->assertArrayNotHasKey('basePath', $siteConfig);
    }
}
