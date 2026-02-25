<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Serve;

use Closure;
use Glaze\Serve\PhpServerConfig;
use Glaze\Serve\PhpServerService;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for PHP server service internals.
 */
final class PhpServerServiceTest extends TestCase
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

        $service = new PhpServerService();
        $config = new PhpServerConfig(
            host: '127.0.0.1',
            port: 8080,
            docRoot: $projectRoot,
            projectRoot: $projectRoot,
            staticMode: false,
        );

        $builtCommand = $this->callProtected($service, 'buildCommand', $config);

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

        $service = new PhpServerService();
        $config = new PhpServerConfig(
            host: '127.0.0.1',
            port: 8080,
            docRoot: $projectRoot,
            projectRoot: $projectRoot,
            staticMode: true,
        );

        $builtCommand = $this->callProtected($service, 'buildCommand', $config);

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
        $service = new PhpServerService();
        $config = new PhpServerConfig(
            host: '127.0.0.1',
            port: 8080,
            docRoot: $projectRoot,
            projectRoot: $projectRoot,
            staticMode: false,
        );

        $this->callProtected($service, 'buildCommand', $config);
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
            $service = new PhpServerService();
            $config = new PhpServerConfig(
                host: '127.0.0.1',
                port: 8080,
                docRoot: $projectRoot,
                projectRoot: $projectRoot,
                staticMode: false,
            );

            $builtCommand = $this->callProtected($service, 'buildCommand', $config);
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
     * Ensure shared start API rejects non-PHP-server configuration objects.
     */
    public function testStartThrowsForInvalidConfigurationObject(): void
    {
        $service = new PhpServerService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration type');

        $service->start(new stdClass(), sys_get_temp_dir());
    }

    /**
     * Ensure stop method is a no-op for compatibility with shared interface.
     */
    public function testStopIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $service = new PhpServerService();
        $service->stop(new stdClass());
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
            $service = new PhpServerService();
            $config = new PhpServerConfig(
                host: '127.0.0.1',
                port: 8080,
                docRoot: $projectRoot,
                projectRoot: $projectRoot,
                staticMode: false,
            );

            $builtCommand = $this->callProtected($service, 'buildCommand', $config);
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
