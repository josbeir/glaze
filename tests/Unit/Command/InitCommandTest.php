<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Closure;
use Glaze\Command\InitCommand;
use Glaze\Process\NpmInstallProcess;
use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Scaffold\ScaffoldOptions;
use Glaze\Scaffold\ScaffoldRegistry;
use Glaze\Scaffold\ScaffoldSchemaLoader;
use Glaze\Scaffold\TemplateRenderer;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Unit tests for init command internals.
 */
final class InitCommandTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure taxonomy parsing handles empty values and deduplication.
     */
    public function testParseTaxonomiesNormalizesInput(): void
    {
        $command = $this->createCommand();

        $parsed = $this->callProtected($command, 'parseTaxonomies', ' Tags, categories, tags, , ');
        $fallback = $this->callProtected($command, 'parseTaxonomies', ' , ');

        $this->assertSame(['tags', 'categories'], $parsed);
        $this->assertSame(['tags', 'categories'], $fallback);
    }

    /**
     * Ensure path helpers and title defaults resolve expected values.
     */
    public function testPathAndTitleHelpers(): void
    {
        $command = $this->createCommand();

        $title = $this->callProtected($command, 'defaultTitle', 'my-site');
        $absoluteUnix = $this->callProtected($command, 'isAbsolutePath', '/tmp/new-site');
        $absoluteWindows = $this->callProtected($command, 'isAbsolutePath', 'C:\\tmp\\new-site');
        $notAbsolute = $this->callProtected($command, 'isAbsolutePath', 'tmp/new-site');
        $emptyPath = $this->callProtected($command, 'isAbsolutePath', '');

        $this->assertSame('My Site', $title);
        $this->assertTrue($absoluteUnix);
        $this->assertTrue($absoluteWindows);
        $this->assertFalse($notAbsolute);
        $this->assertFalse($emptyPath);
    }

    /**
     * Ensure constructor keeps the injected scaffold service instance.
     */
    public function testConstructorUsesInjectedScaffoldService(): void
    {
        $scaffoldsDir = dirname(__DIR__, 3) . '/scaffolds';
        $scaffoldService = new ProjectScaffoldService(
            new ScaffoldRegistry($scaffoldsDir, new ScaffoldSchemaLoader()),
            new TemplateRenderer(),
        );
        $command = new InitCommand($scaffoldService, new NpmInstallProcess());

        $scaffoldServiceProperty = new ReflectionProperty($command, 'scaffoldService');
        $resolvedScaffoldService = $scaffoldServiceProperty->getValue($command);

        $this->assertSame($scaffoldService, $resolvedScaffoldService);
    }

    /**
     * Ensure constructor keeps the injected npm install process instance.
     */
    public function testConstructorUsesInjectedNpmInstallProcess(): void
    {
        $scaffoldsDir = dirname(__DIR__, 3) . '/scaffolds';
        $scaffoldService = new ProjectScaffoldService(
            new ScaffoldRegistry($scaffoldsDir, new ScaffoldSchemaLoader()),
            new TemplateRenderer(),
        );
        $npmInstallProcess = new NpmInstallProcess();
        $command = new InitCommand($scaffoldService, $npmInstallProcess);

        $npmInstallProcessProperty = new ReflectionProperty($command, 'npmInstallProcess');
        $resolvedNpmInstallProcess = $npmInstallProcessProperty->getValue($command);

        $this->assertSame($npmInstallProcess, $resolvedNpmInstallProcess);
    }

    /**
     * Ensure normalize string helper handles empty and non-string values.
     */
    public function testNormalizeStringReturnsNullForInvalidValues(): void
    {
        $command = $this->createCommand();

        $empty = $this->callProtected($command, 'normalizeString', '   ');
        $nonString = $this->callProtected($command, 'normalizeString', ['site']);
        $value = $this->callProtected($command, 'normalizeString', '  my-site  ');

        $this->assertNull($empty);
        $this->assertNull($nonString);
        $this->assertSame('my-site', $value);
    }

    /**
     * Ensure base path normalization handles empty and relative values.
     */
    public function testNormalizeBasePathNormalizesInput(): void
    {
        $command = $this->createCommand();

        $empty = $this->callProtected($command, 'normalizeBasePath', null);
        $root = $this->callProtected($command, 'normalizeBasePath', '/');
        $relative = $this->callProtected($command, 'normalizeBasePath', 'docs');
        $trimmed = $this->callProtected($command, 'normalizeBasePath', '/blog/');

        $this->assertNull($empty);
        $this->assertNull($root);
        $this->assertSame('/docs', $relative);
        $this->assertSame('/blog', $trimmed);
    }

    /**
     * Ensure template normalization falls back to default template.
     */
    public function testNormalizeTemplateNameNormalizesInput(): void
    {
        $command = $this->createCommand();

        $defaultTemplate = $this->callProtected($command, 'normalizeTemplateName', null);
        $emptyTemplate = $this->callProtected($command, 'normalizeTemplateName', '  ');
        $customTemplate = $this->callProtected($command, 'normalizeTemplateName', ' landing ');

        $this->assertSame('page', $defaultTemplate);
        $this->assertSame('page', $emptyTemplate);
        $this->assertSame('landing', $customTemplate);
    }

    /**
     * Ensure non-interactive options resolve relative paths against current directory.
     */
    public function testResolveScaffoldOptionsNormalizesRelativeDirectory(): void
    {
        $command = $this->createCommand();
        $arguments = new Arguments(
            ['relative-site'],
            ['yes' => true, 'base-path' => 'docs'],
            ['directory'],
        );
        $io = new ConsoleIo();

        $options = $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);

        $this->assertInstanceOf(ScaffoldOptions::class, $options);
        $this->assertSame(getcwd() . DIRECTORY_SEPARATOR . 'relative-site', $options->targetDirectory);
        $this->assertSame('relative-site', $options->siteName);
        $this->assertSame('page', $options->pageTemplate);
        $this->assertSame('/docs', $options->basePath);
        $this->assertSame('default', $options->preset);
    }

    /**
     * Ensure preset resolution supports non-interactive and interactive flows.
     */
    public function testResolvePresetHandlesInteractiveAndNonInteractiveModes(): void
    {
        $command = $this->createCommand();

        $nonInteractiveArgs = new Arguments([], ['preset' => 'vite'], []);
        $preset = $this->callProtected($command, 'resolvePreset', $nonInteractiveArgs, new ConsoleIo(), true);
        $this->assertSame('vite', $preset);

        $defaultArgs = new Arguments([], ['preset' => null], []);
        $defaultPreset = $this->callProtected($command, 'resolvePreset', $defaultArgs, new ConsoleIo(), true);
        $this->assertSame('default', $defaultPreset);
    }

    /**
     * Ensure an absolute path passed as --preset is returned unchanged.
     */
    public function testResolvePresetReturnsAbsolutePathUnchanged(): void
    {
        $command = $this->createCommand();
        $args = new Arguments([], ['preset' => '/home/user/my-preset'], []);

        $preset = $this->callProtected($command, 'resolvePreset', $args, new ConsoleIo(), true);

        $this->assertSame('/home/user/my-preset', $preset);
    }

    /**
     * Ensure a relative path passed as --preset is resolved against the current working directory.
     */
    public function testResolvePresetResolvesRelativePath(): void
    {
        $command = $this->createCommand();
        $args = new Arguments([], ['preset' => './my-preset'], []);

        $preset = $this->callProtected($command, 'resolvePreset', $args, new ConsoleIo(), true);

        $this->assertIsString($preset);
        $this->assertStringStartsWith(getcwd() ?: '.', $preset);
        $this->assertStringEndsWith('my-preset', $preset);
    }

    /**
     * Ensure isPresetPath detects path separators correctly.
     */
    public function testIsPresetPathDetectsPathSeparators(): void
    {
        $command = $this->createCommand();

        $this->assertTrue($this->callProtected($command, 'isPresetPath', '/absolute/path'));
        $this->assertTrue($this->callProtected($command, 'isPresetPath', './relative'));
        $this->assertTrue($this->callProtected($command, 'isPresetPath', '../parent'));
        $this->assertFalse($this->callProtected($command, 'isPresetPath', 'default'));
        $this->assertFalse($this->callProtected($command, 'isPresetPath', 'vite'));
    }

    /**
     * Ensure interactive preset selection returns askChoice result when multiple presets exist.
     */
    public function testResolvePresetUsesAskChoiceWhenMultiplePresetsExist(): void
    {
        $command = $this->createCommand();
        $args = new Arguments([], ['preset' => null], []);
        $io = $this->createMock(ConsoleIo::class);
        $io->expects($this->once())
            ->method('askChoice')
            ->willReturn('vite');

        $preset = $this->callProtected($command, 'resolvePreset', $args, $io, false);

        $this->assertSame('vite', $preset);
    }

    /**
     * Ensure interactive preset resolution skips askChoice when only default is available.
     */
    public function testResolvePresetSkipsAskChoiceWhenOnlyDefaultExists(): void
    {
        $scaffoldsRoot = $this->createTempDirectory();
        $defaultDir = $scaffoldsRoot . '/default';
        mkdir($defaultDir, 0755, true);
        file_put_contents($defaultDir . '/scaffold.neon', "name: default\nfiles:\n");

        $command = new InitCommand(
            new ProjectScaffoldService(
                new ScaffoldRegistry($scaffoldsRoot, new ScaffoldSchemaLoader()),
                new TemplateRenderer(),
            ),
            new NpmInstallProcess(),
        );

        $args = new Arguments([], ['preset' => null], []);
        $io = $this->createMock(ConsoleIo::class);
        $io->expects($this->never())
            ->method('askChoice');

        $preset = $this->callProtected($command, 'resolvePreset', $args, $io, false);

        $this->assertSame('default', $preset);
    }

    /**
     * Ensure non-interactive options reject missing directory input.
     */
    public function testResolveScaffoldOptionsRejectsMissingDirectory(): void
    {
        $command = $this->createCommand();
        $arguments = new Arguments([], ['yes' => true], ['directory']);
        $io = new ConsoleIo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project directory is required.');

        $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);
    }

    /**
     * Ensure non-interactive mode rejects empty site name derived from root directory.
     */
    public function testResolveScaffoldOptionsRejectsMissingDerivedSiteName(): void
    {
        $command = $this->createCommand();
        $arguments = new Arguments(['/'], ['yes' => true], ['directory']);
        $io = new ConsoleIo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Site name is required.');

        $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);
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

    /**
     * Create a command instance with concrete dependencies.
     */
    protected function createCommand(): InitCommand
    {
        /** @var \Glaze\Command\InitCommand */
        return $this->service(InitCommand::class);
    }
}
