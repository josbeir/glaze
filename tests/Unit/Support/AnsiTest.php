<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use Glaze\Support\Ansi;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ANSI terminal escape sequence helper.
 */
final class AnsiTest extends TestCase
{
    /**
     * Shared Ansi instance for each test.
     */
    protected Ansi $ansi;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ansi = new Ansi();
    }

    /**
     * Ensure write sends text to the configured output stream.
     */
    public function testWriteSendsTextToOutputStream(): void
    {
        $stream = fopen('php://memory', 'rw');
        $this->assertIsResource($stream);

        $ansi = new Ansi($stream);
        $ansi->write('hello');

        rewind($stream);
        $this->assertSame('hello', stream_get_contents($stream));
        fclose($stream);
    }

    /**
     * Ensure constructor defaults to STDOUT when no stream is given.
     */
    public function testConstructorDefaultsToStdout(): void
    {
        $ansi = new Ansi();

        // Should not throw; verify the instance is usable
        $this->assertInstanceOf(Ansi::class, $ansi);
    }

    /**
     * Ensure truecolor wraps text in 24-bit foreground escape codes.
     */
    public function testTruecolorProducesCorrectSequence(): void
    {
        $result = $this->ansi->truecolor('A', 255, 128, 0);

        $this->assertSame("\033[38;2;255;128;0mA\033[0m", $result);
    }

    /**
     * Ensure truecolor handles zero RGB values.
     */
    public function testTruecolorWithBlack(): void
    {
        $result = $this->ansi->truecolor('X', 0, 0, 0);

        $this->assertSame("\033[38;2;0;0;0mX\033[0m", $result);
    }

    /**
     * Ensure gradient with empty string returns empty string.
     */
    public function testGradientEmptyStringReturnsEmpty(): void
    {
        $result = $this->ansi->gradient('', [255, 0, 0], [0, 0, 255]);

        $this->assertSame('', $result);
    }

    /**
     * Ensure gradient with a single character uses the `from` color.
     */
    public function testGradientSingleCharacterUsesFromColor(): void
    {
        $result = $this->ansi->gradient('A', [255, 0, 0], [0, 0, 255]);

        $this->assertSame("\033[38;2;255;0;0mA\033[0m", $result);
    }

    /**
     * Ensure gradient interpolates across multiple characters.
     */
    public function testGradientInterpolatesAcrossCharacters(): void
    {
        $result = $this->ansi->gradient('AB', [0, 0, 0], [100, 100, 100]);

        // A should be (0,0,0), B should be (100,100,100)
        $this->assertStringContainsString("\033[38;2;0;0;0mA\033[0m", $result);
        $this->assertStringContainsString("\033[38;2;100;100;100mB\033[0m", $result);
    }

    /**
     * Ensure gradient preserves whitespace without applying color.
     */
    public function testGradientPreservesSpacesWithoutColor(): void
    {
        $result = $this->ansi->gradient('A B', [255, 0, 0], [0, 0, 255]);

        // Space should be literal space, not wrapped in escape codes
        $this->assertStringContainsString(' ', $result);
        // We should only have 2 truecolor sequences (for A and B), not 3
        $this->assertSame(2, substr_count($result, "\033[38;2;"));
    }

    /**
     * Ensure gradient preserves tabs without applying color.
     */
    public function testGradientPreservesTabsWithoutColor(): void
    {
        $result = $this->ansi->gradient("A\tB", [255, 0, 0], [0, 0, 255]);

        $this->assertStringContainsString("\t", $result);
        $this->assertSame(2, substr_count($result, "\033[38;2;"));
    }

    /**
     * Ensure bold wraps text correctly.
     */
    public function testBoldWrapsText(): void
    {
        $result = $this->ansi->bold('Hello');

        $this->assertSame("\033[1mHello\033[22m", $result);
    }

    /**
     * Ensure dim wraps text correctly.
     */
    public function testDimWrapsText(): void
    {
        $result = $this->ansi->dim('Hello');

        $this->assertSame("\033[2mHello\033[22m", $result);
    }

    /**
     * Ensure hideCursor returns the correct escape sequence.
     */
    public function testHideCursorSequence(): void
    {
        $this->assertSame("\033[?25l", $this->ansi->hideCursor());
    }

    /**
     * Ensure showCursor returns the correct escape sequence.
     */
    public function testShowCursorSequence(): void
    {
        $this->assertSame("\033[?25h", $this->ansi->showCursor());
    }

    /**
     * Ensure clearLine returns the correct escape sequence.
     */
    public function testClearLineSequence(): void
    {
        $this->assertSame("\033[2K\r", $this->ansi->clearLine());
    }

    /**
     * Ensure moveUp returns the correct escape sequence.
     */
    public function testMoveUpSequence(): void
    {
        $this->assertSame("\033[3A", $this->ansi->moveUp(3));
    }

    /**
     * Ensure moveUp defaults to one line.
     */
    public function testMoveUpDefaultsToOneLine(): void
    {
        $this->assertSame("\033[1A", $this->ansi->moveUp());
    }

    /**
     * Ensure moveDown returns the correct escape sequence.
     */
    public function testMoveDownSequence(): void
    {
        $this->assertSame("\033[2B", $this->ansi->moveDown(2));
    }

    /**
     * Ensure moveDown defaults to one line.
     */
    public function testMoveDownDefaultsToOneLine(): void
    {
        $this->assertSame("\033[1B", $this->ansi->moveDown());
    }

    /**
     * Ensure reset returns the ANSI reset sequence.
     */
    public function testResetSequence(): void
    {
        $this->assertSame("\033[0m", $this->ansi->reset());
    }

    /**
     * Ensure ESC constant holds the standard escape character.
     */
    public function testEscConstant(): void
    {
        $this->assertSame("\033", Ansi::ESC);
    }

    /**
     * Ensure isInteractive returns false when NO_COLOR is set.
     */
    public function testIsInteractiveReturnsFalseWhenNoColorSet(): void
    {
        $previous = getenv('NO_COLOR');
        putenv('NO_COLOR=1');

        try {
            $this->assertFalse($this->ansi->isInteractive());
        } finally {
            if ($previous === false) {
                putenv('NO_COLOR');
            } else {
                putenv('NO_COLOR=' . $previous);
            }
        }
    }

    /**
     * Ensure gradient handles multibyte UTF-8 characters correctly.
     */
    public function testGradientHandlesMultibyteCharacters(): void
    {
        $result = $this->ansi->gradient('日本', [255, 0, 0], [0, 0, 255]);

        // Both characters should get truecolor sequences
        $this->assertSame(2, substr_count($result, "\033[38;2;"));
        $this->assertStringContainsString('日', $result);
        $this->assertStringContainsString('本', $result);
    }

    /**
     * Ensure gradient with three characters interpolates the middle color.
     */
    public function testGradientThreeCharacterMiddleInterpolation(): void
    {
        $result = $this->ansi->gradient('ABC', [0, 0, 0], [200, 200, 200]);

        // Middle char B should be at ratio 0.5 → (100, 100, 100)
        $this->assertStringContainsString("\033[38;2;100;100;100mB\033[0m", $result);
    }
}
