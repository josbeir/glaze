<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Closure;
use Glaze\Command\ServeCommand;
use Glaze\Serve\PhpServerConfig;
use Glaze\Serve\ViteServeConfig;
use Glaze\Tests\Helper\ConsoleIoTestTrait;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
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

        $normalizedRoot = $this->callProtected($command, 'normalizeRootOption', ' /tmp/test/ ');
        $blankRoot = $this->callProtected($command, 'normalizeRootOption', '   ');
        $validPort = $this->callProtected($command, 'normalizePort', '8080');
        $invalidPort = $this->callProtected($command, 'normalizePort', '70000');

        $this->assertIsString($normalizedRoot);
        $this->assertSame('/tmp/test', str_replace('\\', '/', $normalizedRoot));
        $this->assertNull($blankRoot);
        $this->assertSame(8080, $validPort);
        $this->assertNull($invalidPort);
    }

    /**
     * Ensure live environment payload reflects includeDrafts state.
     */
    public function testBuildLiveEnvironmentContainsExpectedValues(): void
    {
        $command = $this->createCommand();
        $viteEnabled = new ViteServeConfig(true, '127.0.0.1', 5173, 'npm run dev -- --host {host} --port {port} --strictPort');
        $viteDisabled = new ViteServeConfig(false, '127.0.0.1', 5173, 'npm run dev -- --host {host} --port {port} --strictPort');

        $enabled = $this->callProtected($command, 'buildLiveEnvironment', '/tmp/project', true, $viteDisabled);
        $disabled = $this->callProtected($command, 'buildLiveEnvironment', '/tmp/project', false, $viteDisabled);
        $enabledWithVite = $this->callProtected($command, 'buildLiveEnvironment', '/tmp/project', true, $viteEnabled);

        $this->assertIsArray($enabled);
        $this->assertIsArray($disabled);
        $this->assertIsArray($enabledWithVite);
        /** @var array<string, string> $enabled */
        /** @var array<string, string> $disabled */
        /** @var array<string, string> $enabledWithVite */
        $this->assertSame('/tmp/project', $enabled['GLAZE_PROJECT_ROOT']);
        $this->assertSame('1', $enabled['GLAZE_INCLUDE_DRAFTS']);
        $this->assertSame('0', $disabled['GLAZE_INCLUDE_DRAFTS']);
        $this->assertSame('0', $disabled['GLAZE_VITE_ENABLED']);
        $this->assertSame('', $disabled['GLAZE_VITE_URL']);
        $this->assertSame('1', $enabledWithVite['GLAZE_VITE_ENABLED']);
        $this->assertSame('http://127.0.0.1:5173', $enabledWithVite['GLAZE_VITE_URL']);
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
            $projectRoot,
        );

        $this->assertInstanceOf(ViteServeConfig::class, $resolvedFromConfig);
        $this->assertTrue($resolvedFromConfig->enabled);
        $this->assertSame('0.0.0.0', $resolvedFromConfig->host);
        $this->assertSame(5175, $resolvedFromConfig->port);

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
            $projectRoot,
        );

        $this->assertInstanceOf(ViteServeConfig::class, $resolvedFromCli);
        $this->assertTrue($resolvedFromCli->enabled);
        $this->assertSame('127.0.0.1', $resolvedFromCli->host);
        $this->assertSame(5176, $resolvedFromCli->port);
        $this->assertSame('pnpm dev --host {host} --port {port}', $resolvedFromCli->command);
        $this->assertSame('pnpm dev --host 127.0.0.1 --port 5176', $resolvedFromCli->commandLine());
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
            $projectRoot,
            false,
        );

        $this->assertInstanceOf(PhpServerConfig::class, $resolvedFromConfig);
        $this->assertSame('0.0.0.0', $resolvedFromConfig->host);
        $this->assertSame(9080, $resolvedFromConfig->port);

        $argsFromCli = new Arguments([], [
            'host' => '127.0.0.1',
            'port' => '9090',
        ], []);

        $resolvedFromCli = $this->callProtected(
            $command,
            'resolvePhpServerConfiguration',
            $argsFromCli,
            $projectRoot,
            $projectRoot,
            false,
        );

        $this->assertInstanceOf(PhpServerConfig::class, $resolvedFromCli);
        $this->assertSame('127.0.0.1', $resolvedFromCli->host);
        $this->assertSame(9090, $resolvedFromCli->port);
    }

    /**
     * Ensure project configuration reads reflect file changes for the same project root.
     */
    public function testReadProjectConfigurationRefreshesWhenFileChanges(): void
    {
        $projectRoot = $this->createTempDirectory();
        $configurationFile = $projectRoot . '/glaze.neon';
        file_put_contents($configurationFile, "devServer:\n  php:\n    port: 8081\n");

        $command = $this->createCommand();

        $first = $this->callProtected($command, 'readProjectConfiguration', $projectRoot);
        $this->assertIsArray($first);
        /** @var array<string, mixed> $first */
        $this->assertArrayHasKey('devServer', $first);
        $this->assertIsArray($first['devServer']);
        /** @var array<string, mixed> $firstDevServer */
        $firstDevServer = $first['devServer'];
        $this->assertArrayHasKey('php', $firstDevServer);
        $this->assertIsArray($firstDevServer['php']);
        /** @var array<string, mixed> $firstPhp */
        $firstPhp = $firstDevServer['php'];
        $this->assertArrayHasKey('port', $firstPhp);
        $this->assertSame(8081, $firstPhp['port']);

        file_put_contents($configurationFile, "devServer:\n  php:\n    port: 18081\n");

        $second = $this->callProtected($command, 'readProjectConfiguration', $projectRoot);
        $this->assertIsArray($second);
        /** @var array<string, mixed> $second */
        $this->assertArrayHasKey('devServer', $second);
        $this->assertIsArray($second['devServer']);
        /** @var array<string, mixed> $secondDevServer */
        $secondDevServer = $second['devServer'];
        $this->assertArrayHasKey('php', $secondDevServer);
        $this->assertIsArray($secondDevServer['php']);
        /** @var array<string, mixed> $secondPhp */
        $secondPhp = $secondDevServer['php'];
        $this->assertArrayHasKey('port', $secondPhp);
        $this->assertSame(18081, $secondPhp['port']);
    }

    /**
     * Ensure non-array devServer root configuration is normalized to an empty map.
     */
    public function testReadDevServerConfigurationReturnsEmptyArrayWhenInvalid(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "devServer: true\n");

        $command = $this->createCommand();
        $result = $this->callProtected($command, 'readDevServerConfiguration', $projectRoot);

        $this->assertSame([], $result);
    }

    /**
     * Ensure devServer map keeps only string keys.
     */
    public function testReadDevServerConfigurationFiltersNonStringKeys(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "devServer:\n  php:\n    port: 8082\n  1: ignored\n");

        $command = $this->createCommand();
        $result = $this->callProtected($command, 'readDevServerConfiguration', $projectRoot);

        $this->assertIsArray($result);
        /** @var array<string, mixed> $result */
        $this->assertArrayHasKey('php', $result);
        $this->assertArrayNotHasKey(1, $result);
    }

    /**
     * Ensure invalid explicit Vite port values are rejected.
     */
    public function testResolveViteConfigurationRejectsInvalidCliPort(): void
    {
        $projectRoot = $this->createTempDirectory();
        $command = $this->createCommand();

        $args = new Arguments([], [
            'vite' => false,
            'vite-host' => null,
            'vite-port' => '70000',
            'vite-command' => null,
        ], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Vite port');

        $this->callProtected($command, 'resolveViteConfiguration', $args, $projectRoot);
    }

    /**
     * Ensure invalid explicit PHP server port values are rejected.
     */
    public function testResolvePhpServerConfigurationRejectsInvalidCliPort(): void
    {
        $projectRoot = $this->createTempDirectory();
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
            $projectRoot,
            false,
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
