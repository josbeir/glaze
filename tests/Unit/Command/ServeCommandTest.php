<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Closure;
use Glaze\Command\ServeCommand;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for serve command internals.
 */
final class ServeCommandTest extends TestCase
{
    use FilesystemTestTrait;

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
     * Ensure static mode command generation does not require router script.
     */
    public function testBuildServerCommandForStaticModeUsesDocumentRootOnly(): void
    {
        $projectRoot = $this->createTempDirectory();
        $command = new ServeCommand();

        $builtCommand = $this->callProtected(
            $command,
            'buildServerCommand',
            '127.0.0.1',
            8080,
            $projectRoot,
            $projectRoot,
            true,
            false,
        );

        $this->assertIsString($builtCommand);
        $this->assertStringStartsWith('php -S ', $builtCommand);
        $this->assertStringNotContainsString('dev-router.php', $builtCommand);
    }

    /**
     * Ensure live mode fails when router file is missing.
     */
    public function testBuildServerCommandThrowsWhenLiveRouterIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Live router script not found');

        $projectRoot = $this->createTempDirectory();
        $command = new ServeCommand();

        $this->callProtected(
            $command,
            'buildServerCommand',
            '127.0.0.1',
            8080,
            $projectRoot,
            $projectRoot,
            false,
            false,
        );
    }

    /**
     * Ensure live mode can resolve router from CLI root when project contains glaze.neon.
     */
    public function testBuildServerCommandUsesCliRouterFallbackWhenProjectHasConfig(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $cliRoot = $this->createTempDirectory();
        mkdir($cliRoot . '/bin', 0755, true);
        file_put_contents($cliRoot . '/bin/dev-router.php', '<?php');

        $originalCliRoot = getenv('GLAZE_CLI_ROOT');
        putenv('GLAZE_CLI_ROOT=' . $cliRoot);

        try {
            $command = new ServeCommand();
            $builtCommand = $this->callProtected(
                $command,
                'buildServerCommand',
                '127.0.0.1',
                8080,
                $projectRoot,
                $projectRoot,
                false,
                false,
            );
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
     * Ensure helper methods normalize root options and port values as expected.
     */
    public function testNormalizeHelpers(): void
    {
        $command = new ServeCommand();

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
        $command = new ServeCommand();

        $enabled = $this->callProtected($command, 'buildLiveEnvironment', '/tmp/project', true);
        $disabled = $this->callProtected($command, 'buildLiveEnvironment', '/tmp/project', false);

        $this->assertIsArray($enabled);
        $this->assertIsArray($disabled);
        $this->assertSame('/tmp/project', $enabled['GLAZE_PROJECT_ROOT']);
        $this->assertSame('1', $enabled['GLAZE_INCLUDE_DRAFTS']);
        $this->assertSame('0', $disabled['GLAZE_INCLUDE_DRAFTS']);
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
