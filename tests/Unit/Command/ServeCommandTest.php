<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Closure;
use Glaze\Command\ServeCommand;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Tests\Helper\ConsoleIoTestTrait;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use Glaze\Utility\Path;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for serve command internals.
 */
final class ServeCommandTest extends TestCase
{
    use ConsoleIoTestTrait;
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure live environment variables are applied and restored correctly.
     */
    public function testApplyAndRestoreEnvironmentRoundTrip(): void
    {
        $command = $this->createCommand();

        $originalProjectRoot = getenv('GLAZE_PROJECT_ROOT');
        $originalIncludeDrafts = getenv('GLAZE_INCLUDE_DRAFTS');

        try {
            putenv('GLAZE_PROJECT_ROOT=__old_root__');
            putenv('GLAZE_INCLUDE_DRAFTS=0');

            $previous = $this->callProtected(
                $command,
                'applyEnvironment',
                [
                    'GLAZE_PROJECT_ROOT' => '__new_root__',
                    'GLAZE_INCLUDE_DRAFTS' => '1',
                ],
            );

            $this->assertIsArray($previous);
            /** @var array<string, string|null> $previous */

            $this->assertSame('__old_root__', $previous['GLAZE_PROJECT_ROOT']);
            $this->assertSame('0', $previous['GLAZE_INCLUDE_DRAFTS']);
            $this->assertSame('__new_root__', getenv('GLAZE_PROJECT_ROOT'));
            $this->assertSame('1', getenv('GLAZE_INCLUDE_DRAFTS'));

            $this->callProtected($command, 'restoreEnvironment', $previous);

            $this->assertSame('__old_root__', getenv('GLAZE_PROJECT_ROOT'));
            $this->assertSame('0', getenv('GLAZE_INCLUDE_DRAFTS'));
        } finally {
            $this->restoreVariable('GLAZE_PROJECT_ROOT', $originalProjectRoot);
            $this->restoreVariable('GLAZE_INCLUDE_DRAFTS', $originalIncludeDrafts);
        }
    }

    /**
     * Ensure helper methods normalize root options and port values as expected.
     */
    public function testNormalizeHelpers(): void
    {
        $command = $this->createCommand();

        $normalizedRoot = Path::optional(' /tmp/test/ ');
        $blankRoot = Path::optional('   ');
        $validPort = $this->callProtected($command, 'normalizePort', '8080');
        $invalidPort = $this->callProtected($command, 'normalizePort', '70000');

        $this->assertIsString($normalizedRoot);
        $this->assertSame('/tmp/test', str_replace('\\', '/', $normalizedRoot));
        $this->assertNull($blankRoot);
        $this->assertSame(8080, $validPort);
        $this->assertNull($invalidPort);
    }

    /**
     * Ensure router environment payload reflects includeDrafts and staticMode state.
     */
    public function testBuildRouterEnvironmentContainsExpectedValues(): void
    {
        $command = $this->createCommand();
        $viteEnabled = [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 5173,
            'command' => 'npm run dev -- --host {host} --port {port} --strictPort',
        ];
        $viteDisabled = [
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 5173,
            'command' => 'npm run dev -- --host {host} --port {port} --strictPort',
        ];

        $live = $this->callProtected($command, 'buildRouterEnvironment', '/tmp/project', true, $viteDisabled, false, false);
        $static = $this->callProtected($command, 'buildRouterEnvironment', '/tmp/project', false, $viteDisabled, true, false);
        $liveWithVite = $this->callProtected($command, 'buildRouterEnvironment', '/tmp/project', true, $viteEnabled, false, true);

        $this->assertIsArray($live);
        $this->assertIsArray($static);
        $this->assertIsArray($liveWithVite);
        /** @var array<string, string> $live */
        /** @var array<string, string> $static */
        /** @var array<string, string> $liveWithVite */
        $this->assertSame('/tmp/project', $live['GLAZE_PROJECT_ROOT']);
        $this->assertSame('0', $live['GLAZE_STATIC_MODE']);
        $this->assertSame('1', $live['GLAZE_INCLUDE_DRAFTS']);
        $this->assertSame('0', $live['GLAZE_DEBUG']);
        $this->assertSame('1', $static['GLAZE_STATIC_MODE']);
        $this->assertSame('0', $static['GLAZE_INCLUDE_DRAFTS']);
        $this->assertSame('0', $live['GLAZE_VITE_ENABLED']);
        $this->assertSame('', $live['GLAZE_VITE_URL']);
        $this->assertSame('1', $liveWithVite['GLAZE_DEBUG']);
        $this->assertSame('1', $liveWithVite['GLAZE_VITE_ENABLED']);
        $this->assertSame('http://127.0.0.1:5173', $liveWithVite['GLAZE_VITE_URL']);
    }

    /**
     * Ensure Vite configuration can be loaded from glaze.neon and overridden by CLI options.
     */
    public function testResolveViteConfigurationFromConfigAndCliOverrides(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "devServer:\n  vite:\n    enabled: true\n    host: 0.0.0.0\n    port: 5175\n    command: 'npm run dev -- --host {host} --port {port}'\n",
        );

        (new ProjectConfigurationReader())->read($projectRoot);

        $command = $this->createCommand();

        $argsFromConfig = new Arguments([], [
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $resolvedFromConfig = $this->callProtected(
            $command,
            'resolveViteConfiguration',
            $argsFromConfig,
        );

        $this->assertIsArray($resolvedFromConfig);
        $this->assertTrue($resolvedFromConfig['enabled']);
        $this->assertSame('0.0.0.0', $resolvedFromConfig['host']);
        $this->assertSame(5175, $resolvedFromConfig['port']);
        $this->assertSame('npm run dev -- --host {host} --port {port}', $resolvedFromConfig['command']);

        $argsFromCli = new Arguments([], [
            'vite' => true,
            'vite-host' => '127.0.0.1',
            'vite-port' => '5176',
            'vite-command' => 'pnpm dev --host {host} --port {port}',
        ], []);

        $resolvedFromCli = $this->callProtected(
            $command,
            'resolveViteConfiguration',
            $argsFromCli,
        );

        $this->assertIsArray($resolvedFromCli);
        $this->assertTrue($resolvedFromCli['enabled']);
        $this->assertSame('127.0.0.1', $resolvedFromCli['host']);
        $this->assertSame(5176, $resolvedFromCli['port']);
        $this->assertSame('pnpm dev --host {host} --port {port}', $resolvedFromCli['command']);
    }

    /**
     * Ensure Vite is silently disabled when static mode is active, even if enabled in config.
     */
    public function testResolveViteConfigurationDisabledInStaticMode(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "devServer:\n  vite:\n    enabled: true\n    host: 0.0.0.0\n    port: 5175\n",
        );

        (new ProjectConfigurationReader())->read($projectRoot);

        $command = $this->createCommand();

        $args = new Arguments([], [
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $resolved = $this->callProtected($command, 'resolveViteConfiguration', $args, true);

        $this->assertIsArray($resolved);
        $this->assertFalse($resolved['enabled']);
    }

    /**
     * Ensure PHP server configuration can be loaded from glaze.neon and overridden by CLI options.
     */
    public function testResolvePhpServerConfigurationFromConfigAndCliOverrides(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "devServer:\n  php:\n    host: 0.0.0.0\n    port: 9080\n",
        );

        (new ProjectConfigurationReader())->read($projectRoot);

        $command = $this->createCommand();

        $argsFromConfig = new Arguments([], [
            'host' => null,
            'port' => null,
        ], []);

        $resolvedFromConfig = $this->callProtected(
            $command,
            'resolvePhpServerConfiguration',
            $argsFromConfig,
            $projectRoot,
        );

        $this->assertIsArray($resolvedFromConfig);
        $this->assertSame('0.0.0.0', $resolvedFromConfig['host']);
        $this->assertSame(9080, $resolvedFromConfig['port']);

        $argsFromCli = new Arguments([], [
            'host' => '127.0.0.1',
            'port' => '9090',
        ], []);

        $resolvedFromCli = $this->callProtected(
            $command,
            'resolvePhpServerConfiguration',
            $argsFromCli,
            $projectRoot,
        );

        $this->assertIsArray($resolvedFromCli);
        $this->assertSame('127.0.0.1', $resolvedFromCli['host']);
        $this->assertSame(9090, $resolvedFromCli['port']);
    }

    /**
     * Ensure invalid explicit Vite port values are rejected.
     */
    public function testResolveViteConfigurationRejectsInvalidCliPort(): void
    {
        $command = $this->createCommand();

        $args = new Arguments([], [
            'vite' => false,
            'vite-host' => null,
            'vite-port' => '70000',
            'vite-command' => null,
        ], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Vite port');

        $this->callProtected($command, 'resolveViteConfiguration', $args);
    }

    /**
     * Ensure invalid explicit PHP server port values are rejected.
     */
    public function testResolvePhpServerConfigurationRejectsInvalidCliPort(): void
    {
        $projectRoot = $this->createTempDirectory();

        (new ProjectConfigurationReader())->read($projectRoot);

        $command = $this->createCommand();

        $args = new Arguments([], [
            'host' => null,
            'port' => '70000',
        ], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid port');

        $this->callProtected(
            $command,
            'resolvePhpServerConfiguration',
            $args,
            $projectRoot,
        );
    }

    /**
     * Ensure execute returns error when root directory does not exist.
     */
    public function testExecuteReturnsErrorWhenProjectRootIsMissing(): void
    {
        $command = $this->createCommand();
        $args = new Arguments([], [
            'root' => $this->createTempDirectory() . '/missing-root',
            'host' => null,
            'port' => null,
            'static' => false,
            'build' => false,
            'drafts' => false,
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(1, $exitCode);
    }

    /**
     * Ensure execute rejects using Vite in static mode.
     */
    public function testExecuteRejectsViteWhenStaticModeEnabled(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);

        $command = $this->createCommand();
        $args = new Arguments([], [
            'root' => $projectRoot,
            'host' => null,
            'port' => null,
            'static' => true,
            'build' => false,
            'drafts' => false,
            'vite' => true,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(1, $exitCode);
    }

    /**
     * Ensure execute rejects --build without --static.
     */
    public function testExecuteRejectsBuildWithoutStaticMode(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $command = $this->createCommand();
        $args = new Arguments([], [
            'root' => $projectRoot,
            'host' => null,
            'port' => null,
            'static' => false,
            'build' => true,
            'drafts' => false,
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(1, $exitCode);
    }

    /**
     * Ensure execute returns error when static doc root is missing.
     */
    public function testExecuteReturnsErrorWhenStaticDocRootIsMissing(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $command = $this->createCommand();
        $args = new Arguments([], [
            'root' => $projectRoot,
            'host' => null,
            'port' => null,
            'static' => true,
            'build' => false,
            'drafts' => false,
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(1, $exitCode);
    }

    /**
     * Ensure execute returns error for invalid explicit port value.
     */
    public function testExecuteReturnsErrorWhenPortIsInvalid(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);

        $command = $this->createCommand();
        $args = new Arguments([], [
            'root' => $projectRoot,
            'host' => null,
            'port' => '70000',
            'static' => true,
            'build' => false,
            'drafts' => false,
            'vite' => false,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => null,
        ], []);

        $exitCode = $command->execute($args, $this->createConsoleIo());

        $this->assertSame(1, $exitCode);
    }

    /**
     * Ensure execute surfaces Vite startup failures and restores environment.
     */
    public function testExecuteReturnsErrorWhenViteStartFails(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $command = $this->createCommand();

        $originalProjectRoot = getenv('GLAZE_PROJECT_ROOT');
        $originalIncludeDrafts = getenv('GLAZE_INCLUDE_DRAFTS');
        $originalViteEnabled = getenv('GLAZE_VITE_ENABLED');
        $originalViteUrl = getenv('GLAZE_VITE_URL');

        $args = new Arguments([], [
            'root' => $projectRoot,
            'host' => null,
            'port' => null,
            'static' => false,
            'build' => false,
            'drafts' => false,
            'vite' => true,
            'vite-host' => null,
            'vite-port' => null,
            'vite-command' => 'php -r "fwrite(STDERR, \"boom\"); exit(1);"',
        ], []);

        try {
            $exitCode = $command->execute($args, $this->createConsoleIo());
        } finally {
            $this->assertSame($originalProjectRoot, getenv('GLAZE_PROJECT_ROOT'));
            $this->assertSame($originalIncludeDrafts, getenv('GLAZE_INCLUDE_DRAFTS'));
            $this->assertSame($originalViteEnabled, getenv('GLAZE_VITE_ENABLED'));
            $this->assertSame($originalViteUrl, getenv('GLAZE_VITE_URL'));
        }

        $this->assertSame(1, $exitCode);
    }

    /**
     * Restore environment variable to prior state.
     *
     * @param string $name Environment variable name.
     * @param string|false $value Previous value returned by getenv().
     */
    protected function restoreVariable(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
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
    protected function createCommand(): ServeCommand
    {
        /** @var \Glaze\Command\ServeCommand */
        return $this->service(ServeCommand::class);
    }
}
