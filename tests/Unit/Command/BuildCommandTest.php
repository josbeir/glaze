<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Closure;
use Glaze\Build\SiteBuilder;
use Glaze\Command\BuildCommand;
use Glaze\Config\BuildConfigFactory;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Process\ViteBuildProcess;
use Glaze\Tests\Helper\ConsoleIoTestTrait;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for build command internals.
 */
final class BuildCommandTest extends TestCase
{
    use ConsoleIoTestTrait;
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure root-relative conversion handles both matching and non-matching roots.
     */
    public function testRelativeToRootHandlesMatchingAndNonMatchingRoots(): void
    {
        $command = $this->createCommand();

        $relative = $this->callProtected(
            $command,
            'relativeToRoot',
            '/tmp/project/public/index.html',
            '/tmp/project',
        );

        $unchanged = $this->callProtected(
            $command,
            'relativeToRoot',
            '/other/public/index.html',
            '/tmp/project',
        );

        $this->assertSame('public/index.html', $relative);
        $this->assertSame('/other/public/index.html', $unchanged);
    }

    /**
     * Ensure command reuses the injected site builder instance.
     */
    public function testConstructorUsesInjectedSiteBuilder(): void
    {
        $siteBuilder = $this->service(SiteBuilder::class);
        $command = new BuildCommand(
            siteBuilder: $siteBuilder,
            projectConfigurationReader: $this->service(ProjectConfigurationReader::class),
            viteBuildProcess: $this->service(ViteBuildProcess::class),
            buildConfigFactory: $this->service(BuildConfigFactory::class),
        );

        $siteBuilderProperty = new ReflectionProperty($command, 'siteBuilder');
        $resolvedSiteBuilder = $siteBuilderProperty->getValue($command);

        $this->assertSame($siteBuilder, $resolvedSiteBuilder);
    }

    /**
     * Ensure root option normalization trims string values and rejects invalid input.
     */
    public function testNormalizeRootOptionHandlesVariants(): void
    {
        $command = $this->createCommand();

        $trimmed = $this->callProtected($command, 'normalizeRootOption', ' /tmp/site ');
        $blank = $this->callProtected($command, 'normalizeRootOption', '   ');
        $invalid = $this->callProtected($command, 'normalizeRootOption', 123);

        $this->assertSame('/tmp/site', $trimmed);
        $this->assertNull($blank);
        $this->assertNull($invalid);
    }

    /**
     * Ensure Vite build configuration can be loaded from glaze.neon and overridden by CLI options.
     */
    public function testResolveViteBuildConfigurationFromConfigAndCliOverrides(): void
    {
        $command = $this->createCommand();

        $argsFromConfig = new Arguments([], [
            'vite' => false,
            'vite-command' => null,
        ], []);

        $resolvedFromConfig = $this->callProtected(
            $command,
            'resolveViteBuildConfiguration',
            $argsFromConfig,
            [
                'vite' => [
                    'enabled' => true,
                    'command' => 'npm run build:prod',
                ],
            ],
        );

        $this->assertIsArray($resolvedFromConfig);
        $this->assertTrue($resolvedFromConfig['enabled']);
        $this->assertSame('npm run build:prod', $resolvedFromConfig['command']);

        $argsFromCli = new Arguments([], [
            'vite' => true,
            'vite-command' => 'pnpm build',
        ], []);

        $resolvedFromCli = $this->callProtected(
            $command,
            'resolveViteBuildConfiguration',
            $argsFromCli,
            [
                'vite' => [
                    'enabled' => true,
                    'command' => 'npm run build:prod',
                ],
            ],
        );

        $this->assertIsArray($resolvedFromCli);
        $this->assertTrue($resolvedFromCli['enabled']);
        $this->assertSame('pnpm build', $resolvedFromCli['command']);
    }

    /**
     * Ensure build boolean options use CLI true values and fallback to configuration defaults.
     */
    public function testResolveBuildBooleanOptionFromCliAndConfiguration(): void
    {
        $command = $this->createCommand();

        $argsFromCli = new Arguments([], [
            'clean' => true,
        ], []);
        $cleanFromCli = $this->callProtected(
            $command,
            'resolveBuildBooleanOption',
            $argsFromCli,
            ['clean' => false],
            'clean',
        );
        $this->assertTrue($cleanFromCli);

        $argsFromConfig = new Arguments([], [
            'clean' => null,
        ], []);
        $cleanFromConfig = $this->callProtected(
            $command,
            'resolveBuildBooleanOption',
            $argsFromConfig,
            ['clean' => true],
            'clean',
        );
        $this->assertTrue($cleanFromConfig);

        $argsFromDefault = new Arguments([], [
            'clean' => null,
        ], []);
        $cleanFromDefault = $this->callProtected(
            $command,
            'resolveBuildBooleanOption',
            $argsFromDefault,
            [],
            'clean',
        );
        $this->assertFalse($cleanFromDefault);
    }

    /**
     * Ensure execute catches unexpected throwables and returns a clean command error.
     */
    public function testExecuteReturnsErrorWhenBuildThrowsThrowable(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?php throw new Exception("Template exploded"); ?>',
        );

        $command = $this->createCommand();

        $args = new Arguments([], [
            'root' => $projectRoot,
            'clean' => false,
            'drafts' => false,
            'vite' => false,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(BuildCommand::CODE_ERROR, $exitCode);
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
    protected function createCommand(): BuildCommand
    {
        /** @var \Glaze\Command\BuildCommand */
        return $this->service(BuildCommand::class);
    }
}
