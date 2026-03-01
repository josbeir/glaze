<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\ConsoleIo;
use Glaze\Command\BuildCommand;
use Glaze\Command\ServeCommand;
use Glaze\Support\Ansi;
use Glaze\Tests\Helper\TestGlazeCommandRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for shared Glaze command runner helpers.
 */
final class GlazeCommandRunnerTest extends TestCase
{
    /**
     * Ensure non-interactive header prints plain version and separator.
     */
    public function testRenderVersionHeaderPrintsPlainFallbackWhenNotInteractive(): void
    {
        $runner = $this->createRunner();
        $runner->setCachedVersionForTest('1.2.3');

        $ansi = $this->getMockBuilder(Ansi::class)
            ->onlyMethods(['isInteractive'])
            ->getMock();
        $ansi->expects($this->once())
            ->method('isInteractive')
            ->willReturn(false);

        $io = $this->getMockBuilder(ConsoleIo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['out', 'hr'])
            ->getMock();

        $io
            ->expects($this->once())
            ->method('out')
            ->with('Glaze version 1.2.3');

        $io
            ->expects($this->once())
            ->method('hr');

        $runner->renderVersionHeaderForTest($io, $ansi);
    }

    /**
     * Ensure interactive header delegates to animated gradient rendering.
     */
    public function testRenderVersionHeaderDelegatesToAnimatedHeaderWhenInteractive(): void
    {
        $runner = $this->createRunner();
        $runner->setCachedVersionForTest('3.0.0');

        $stream = fopen('php://memory', 'rw');
        $this->assertIsResource($stream);

        $ansi = $this->getMockBuilder(Ansi::class)
            ->setConstructorArgs([$stream])
            ->onlyMethods(['isInteractive'])
            ->getMock();
        $ansi->expects($this->once())
            ->method('isInteractive')
            ->willReturn(true);

        $io = $this->createStub(ConsoleIo::class);

        $runner->renderVersionHeaderForTest($io, $ansi);

        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertStringContainsString('3.0.0', (string)$output);
        $this->assertStringContainsString('Static site generator', (string)$output);
        fclose($stream);
    }

    /**
     * Ensure animated header outputs gradient logo lines and version tagline.
     */
    public function testRenderAnimatedHeaderOutputsLogoAndTagline(): void
    {
        $runner = $this->createRunner();

        $stream = fopen('php://memory', 'rw');
        $this->assertIsResource($stream);

        $ansi = new Ansi($stream);

        $runner->renderAnimatedHeaderForTest($ansi, '2.0.0');

        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertStringContainsString('2.0.0', (string)$output);
        $this->assertStringContainsString('Static site generator', (string)$output);
        $this->assertStringContainsString("\033[", (string)$output);
        fclose($stream);
    }

    /**
     * Ensure cached version value is returned without additional resolution.
     */
    public function testResolveAppVersionReturnsCachedValue(): void
    {
        $runner = $this->createRunner();
        $runner->setCachedVersionForTest('9.9.9');

        $resolved = $runner->resolveAppVersionForTest();

        $this->assertSame('9.9.9', $resolved);
    }

    /**
     * Ensure version resolution returns a non-empty string when cache is empty.
     */
    public function testResolveAppVersionReturnsNonEmptyValueWhenCacheMissing(): void
    {
        $runner = $this->createRunner();
        $runner->setCachedVersionForTest(null);

        $resolved = $runner->resolveAppVersionForTest();

        $this->assertNotSame('', trim($resolved));
    }

    /**
     * Ensure no-version-set placeholders are ignored during normalization.
     */
    public function testNormalizeVersionCandidateSkipsNoVersionSetPlaceholders(): void
    {
        $runner = $this->createRunner();

        $normalized = $runner->normalizeVersionCandidateForTest('1.0.0+no-version-set');

        $this->assertNull($normalized);
    }

    /**
     * Ensure valid version candidates are normalized and preserved.
     */
    public function testNormalizeVersionCandidateReturnsTrimmedVersion(): void
    {
        $runner = $this->createRunner();

        $normalized = $runner->normalizeVersionCandidateForTest('  v0.1.0  ');

        $this->assertSame('v0.1.0', $normalized);
    }

    /**
     * Ensure non-serve Glaze commands render headers unless quiet mode is set.
     */
    public function testShouldRenderVersionHeaderForRegularGlazeCommand(): void
    {
        $runner = $this->createRunner();
        $buildCommand = $this->createBuildCommand();

        $this->assertTrue($runner->shouldRenderVersionHeaderForTest($buildCommand, []));
        $this->assertFalse($runner->shouldRenderVersionHeaderForTest($buildCommand, ['--quiet']));
    }

    /**
     * Ensure serve command only renders the header when verbose mode is set.
     */
    public function testShouldRenderVersionHeaderForServeCommandVerboseOnly(): void
    {
        $runner = $this->createRunner();
        $serveCommand = $this->createServeCommand();

        $this->assertFalse($runner->shouldRenderVersionHeaderForTest($serveCommand, []));
        $this->assertTrue($runner->shouldRenderVersionHeaderForTest($serveCommand, ['--verbose']));
        $this->assertFalse($runner->shouldRenderVersionHeaderForTest($serveCommand, ['--verbose', '--quiet']));
    }

    /**
     * Create a concrete test runner.
     */
    protected function createRunner(): TestGlazeCommandRunner
    {
        return new TestGlazeCommandRunner();
    }

    /**
     * Create a build command with mocked dependencies.
     */
    protected function createBuildCommand(): BuildCommand
    {
        /** @var \Glaze\Command\BuildCommand $command */
        $command = (new ReflectionClass(BuildCommand::class))->newInstanceWithoutConstructor();

        return $command;
    }

    /**
     * Create a serve command with mocked dependencies.
     */
    protected function createServeCommand(): ServeCommand
    {
        /** @var \Glaze\Command\ServeCommand $command */
        $command = (new ReflectionClass(ServeCommand::class))->newInstanceWithoutConstructor();

        return $command;
    }
}
