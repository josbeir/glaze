<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Process;

use Closure;
use Glaze\Process\PhpServerProcess;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for PHP server process internals.
 */
final class PhpServerProcessTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure live server command is shell-agnostic and does not rely on inline env assignments.
     */
    public function testBuildCommandForLiveModeUsesRouterWithoutInlineEnvironmentAssignments(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/bin', 0755, true);
        file_put_contents($projectRoot . '/bin/dev-router.php', '<?php');

        $process = new PhpServerProcess();
        $config = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'docRoot' => $projectRoot,
            'projectRoot' => $projectRoot,
            'staticMode' => false,
        ];

        $builtCommand = $this->callProtected($process, 'buildCommand', $config);

        $this->assertIsString($builtCommand);
        $this->assertStringStartsWith('php -S ', $builtCommand);
        $this->assertStringContainsString('dev-router.php', $builtCommand);
        $this->assertStringNotContainsString('GLAZE_PROJECT_ROOT=', $builtCommand);
        $this->assertStringNotContainsString('GLAZE_INCLUDE_DRAFTS=', $builtCommand);
    }

    /**
     * Ensure static mode command generation does not require router script.
     */
    public function testBuildCommandForStaticModeUsesDocumentRootOnly(): void
    {
        $projectRoot = $this->createTempDirectory();

        $process = new PhpServerProcess();
        $config = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'docRoot' => $projectRoot,
            'projectRoot' => $projectRoot,
            'staticMode' => true,
        ];

        $builtCommand = $this->callProtected($process, 'buildCommand', $config);

        $this->assertIsString($builtCommand);
        $this->assertStringStartsWith('php -S ', $builtCommand);
        $this->assertStringNotContainsString('dev-router.php', $builtCommand);
    }

    /**
     * Ensure live mode fails when router file is missing.
     */
    public function testBuildCommandThrowsWhenLiveRouterIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Live router script not found');

        $projectRoot = $this->createTempDirectory();
        $process = new PhpServerProcess();
        $config = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'docRoot' => $projectRoot,
            'projectRoot' => $projectRoot,
            'staticMode' => false,
        ];

        $this->callProtected($process, 'buildCommand', $config);
    }

    /**
     * Ensure live mode can resolve router from CLI root when project contains glaze.neon.
     */
    public function testBuildCommandUsesCliRouterFallbackWhenProjectHasConfig(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $cliRoot = $this->createTempDirectory();
        mkdir($cliRoot . '/bin', 0755, true);
        file_put_contents($cliRoot . '/bin/dev-router.php', '<?php');

        $originalCliRoot = getenv('GLAZE_CLI_ROOT');
        putenv('GLAZE_CLI_ROOT=' . $cliRoot);

        try {
            $process = new PhpServerProcess();
            $config = [
                'host' => '127.0.0.1',
                'port' => 8080,
                'docRoot' => $projectRoot,
                'projectRoot' => $projectRoot,
                'staticMode' => false,
            ];

            $builtCommand = $this->callProtected($process, 'buildCommand', $config);
        } finally {
            $this->restoreVariable('GLAZE_CLI_ROOT', $originalCliRoot);
        }

        $this->assertIsString($builtCommand);
        $this->assertStringContainsString(
            str_replace('\\', '/', $cliRoot . '/bin/dev-router.php'),
            str_replace('\\', '/', $builtCommand),
        );
    }

    /**
     * Ensure shared start API rejects invalid configuration arrays.
     */
    public function testStartThrowsForInvalidConfigurationArray(): void
    {
        $process = new PhpServerProcess();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration for');

        $process->start([], sys_get_temp_dir());
    }

    /**
     * Ensure a custom output callback receives server output when streamOutput is enabled.
     *
     * Uses a non-routable address to force an immediate server failure so the
     * process terminates quickly, while verifying the callback is invoked with
     * the server's stderr output.
     */
    public function testStartInvokesCustomOutputCallbackForStreamOutput(): void
    {
        $projectRoot = $this->createTempDirectory();
        $process = new PhpServerProcess();

        $config = [
            'host' => '192.0.2.1',
            'port' => 8099,
            'docRoot' => $projectRoot,
            'projectRoot' => $projectRoot,
            'staticMode' => true,
            'streamOutput' => true,
        ];

        $received = [];
        $process->start($config, $projectRoot, function (string $type, string $buffer) use (&$received): void {
            $received[] = [$type, $buffer];
        });

        $this->assertNotEmpty($received);
    }

    /**
     * Ensure stop method is a no-op for compatibility with shared interface.
     */
    public function testStopIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $process = new PhpServerProcess();
        $process->stop(new stdClass());
    }

    /**
     * Ensure required Glaze environment variables are forwarded to child process.
     */
    public function testForwardedEnvironmentVariablesIncludesGlazeKeys(): void
    {
        $process = new PhpServerProcess();

        $originalProjectRoot = getenv('GLAZE_PROJECT_ROOT');
        $originalIncludeDrafts = getenv('GLAZE_INCLUDE_DRAFTS');
        $originalViteEnabled = getenv('GLAZE_VITE_ENABLED');
        $originalViteUrl = getenv('GLAZE_VITE_URL');
        $originalCliRoot = getenv('GLAZE_CLI_ROOT');

        try {
            putenv('GLAZE_PROJECT_ROOT=/tmp/glaze-project');
            putenv('GLAZE_INCLUDE_DRAFTS=1');
            putenv('GLAZE_VITE_ENABLED=1');
            putenv('GLAZE_VITE_URL=http://127.0.0.1:5174');
            putenv('GLAZE_CLI_ROOT=/tmp/glaze-cli');

            $environment = $this->callProtected($process, 'forwardedEnvironmentVariables');
        } finally {
            $this->restoreVariable('GLAZE_PROJECT_ROOT', $originalProjectRoot);
            $this->restoreVariable('GLAZE_INCLUDE_DRAFTS', $originalIncludeDrafts);
            $this->restoreVariable('GLAZE_VITE_ENABLED', $originalViteEnabled);
            $this->restoreVariable('GLAZE_VITE_URL', $originalViteUrl);
            $this->restoreVariable('GLAZE_CLI_ROOT', $originalCliRoot);
        }

        $this->assertIsArray($environment);
        $this->assertSame('/tmp/glaze-project', $environment['GLAZE_PROJECT_ROOT'] ?? null);
        $this->assertSame('1', $environment['GLAZE_INCLUDE_DRAFTS'] ?? null);
        $this->assertSame('1', $environment['GLAZE_VITE_ENABLED'] ?? null);
        $this->assertSame('http://127.0.0.1:5174', $environment['GLAZE_VITE_URL'] ?? null);
        $this->assertSame('/tmp/glaze-cli', $environment['GLAZE_CLI_ROOT'] ?? null);
    }

    /**
     * Ensure package router fallback can be resolved when project has configuration.
     */
    public function testBuildCommandUsesPackageRouterFallbackWhenProjectHasConfig(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $originalCliRoot = getenv('GLAZE_CLI_ROOT');
        putenv('GLAZE_CLI_ROOT');

        try {
            $process = new PhpServerProcess();
            $config = [
                'host' => '127.0.0.1',
                'port' => 8080,
                'docRoot' => $projectRoot,
                'projectRoot' => $projectRoot,
                'staticMode' => false,
            ];

            $builtCommand = $this->callProtected($process, 'buildCommand', $config);
        } finally {
            $this->restoreVariable('GLAZE_CLI_ROOT', $originalCliRoot);
        }

        $this->assertIsString($builtCommand);
        $this->assertStringContainsString('bin/dev-router.php', str_replace('\\', '/', $builtCommand));
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
}
