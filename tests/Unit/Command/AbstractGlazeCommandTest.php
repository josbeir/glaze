<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\ConsoleIo;
use Glaze\Tests\Helper\TestAbstractGlazeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests for shared abstract command helpers.
 */
final class AbstractGlazeCommandTest extends TestCase
{
    /**
     * Ensure header rendering sets success style and prints version output.
     */
    public function testRenderVersionHeaderPrintsVersionAndSeparator(): void
    {
        $command = $this->createCommand();
        $command->setCachedVersionForTest('1.2.3');

        $io = $this->getMockBuilder(ConsoleIo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStyle', 'setStyle', 'out', 'hr'])
            ->getMock();

        $io
            ->expects($this->once())
            ->method('getStyle')
            ->with('success')
            ->willReturn(['color' => 'green']);

        $io
            ->expects($this->once())
            ->method('setStyle')
            ->with('success', ['color' => 'green', 'bold' => true]);

        $io
            ->expects($this->once())
            ->method('out')
            ->with('<info>Glaze</info> version <success>1.2.3</success>');

        $io
            ->expects($this->once())
            ->method('hr');

        $command->renderVersionHeaderForTest($io);
    }

    /**
     * Ensure cached version value is returned without additional resolution.
     */
    public function testResolveAppVersionReturnsCachedValue(): void
    {
        $command = $this->createCommand();
        $command->setCachedVersionForTest('9.9.9');

        $resolved = $command->resolveAppVersionForTest();

        $this->assertSame('9.9.9', $resolved);
    }

    /**
     * Ensure version resolution returns a non-empty string when cache is empty.
     */
    public function testResolveAppVersionReturnsNonEmptyValueWhenCacheMissing(): void
    {
        $command = $this->createCommand();
        $command->setCachedVersionForTest(null);

        $resolved = $command->resolveAppVersionForTest();

        $this->assertNotSame('', trim($resolved));
    }

    /**
     * Create concrete test command for protected helper access.
     */
    protected function createCommand(): TestAbstractGlazeCommand
    {
        return new TestAbstractGlazeCommand();
    }
}
