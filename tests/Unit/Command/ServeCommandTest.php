<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Closure;
use Glaze\Command\ServeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for serve command internals.
 */
final class ServeCommandTest extends TestCase
{
    /**
     * Ensure live server command is shell-agnostic and does not rely on inline env assignments.
     */
    public function testBuildServerCommandForLiveModeUsesRouterWithoutInlineEnvironmentAssignments(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/bin', 0755, true);
        file_put_contents($projectRoot . '/bin/dev-router.php', '<?php');

        $command = new ServeCommand();

        $builtCommand = $this->callProtected(
            $command,
            'buildServerCommand',
            '127.0.0.1',
            8080,
            $projectRoot,
            $projectRoot,
            false,
            true,
        );

        $this->assertIsString($builtCommand);

        $this->assertStringStartsWith('php -S ', $builtCommand);
        $this->assertStringContainsString('dev-router.php', $builtCommand);
        $this->assertStringNotContainsString('GLAZE_PROJECT_ROOT=', $builtCommand);
        $this->assertStringNotContainsString('GLAZE_INCLUDE_DRAFTS=', $builtCommand);
    }

    /**
     * Ensure live environment variables are applied and restored correctly.
     */
    public function testApplyAndRestoreEnvironmentRoundTrip(): void
    {
        $command = new ServeCommand();

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
     * Create temporary directory for isolated tests.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
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
