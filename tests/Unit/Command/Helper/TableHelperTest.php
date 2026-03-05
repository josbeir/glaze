<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Closure;
use Glaze\Command\Helper\TableHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TableHelper ASCII table rendering.
 */
final class TableHelperTest extends TestCase
{
    /**
     * output() renders a separator, styled header, and data rows.
     */
    public function testOutputRendersHeaderAndDataRows(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io);

        $table->output([
            ['Name', 'Value'],
            ['foo', 'bar'],
            ['hello', 'world'],
        ]);

        $out = $read();
        $this->assertStringContainsString('Name', $out);
        $this->assertStringContainsString('Value', $out);
        $this->assertStringContainsString('foo', $out);
        $this->assertStringContainsString('bar', $out);
        $this->assertStringContainsString('hello', $out);
        $this->assertStringContainsString('world', $out);
        $this->assertStringContainsString('+', $out);
        $this->assertStringContainsString('|', $out);
    }

    /**
     * output() returns immediately without output when given an empty array.
     */
    public function testOutputWithEmptyInputProducesNoOutput(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io);

        $table->output([]);

        $this->assertSame('', $read());
    }

    /**
     * output() renders only the header row when no data rows follow it.
     */
    public function testOutputWithHeaderOnlyRendersSeparatorAndHeader(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io);

        $table->output([['Col A', 'Col B']]);

        $out = $read();
        $this->assertStringContainsString('Col A', $out);
        $this->assertStringContainsString('Col B', $out);
        $this->assertStringContainsString('+', $out);
    }

    /**
     * output() skips header treatment when headers config is false.
     */
    public function testOutputWithHeadersFalseRendersAllRowsAsData(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io, ['headers' => false]);

        $table->output([
            ['row1a', 'row1b'],
            ['row2a', 'row2b'],
        ]);

        $out = $read();
        $this->assertStringContainsString('row1a', $out);
        $this->assertStringContainsString('row2a', $out);
    }

    /**
     * output() emits a row separator between every data row when rowSeparator is true.
     */
    public function testOutputWithRowSeparatorEmitsSeparatorBetweenRows(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io, ['rowSeparator' => true]);

        $table->output([
            ['A', 'B'],
            ['x', 'y'],
            ['p', 'q'],
        ]);

        $out = $read();
        // header separator + 2 row separators + closing = at least 4 separator lines
        $separatorCount = substr_count($out, '+---');
        $this->assertGreaterThanOrEqual(4, $separatorCount);
    }

    /**
     * output() pads columns to the width of the widest cell in each column.
     */
    public function testOutputPadsColumnsToWidestCell(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io);

        $table->output([
            ['Short', 'A Very Long Header'],
            ['x', 'y'],
        ]);

        $out = $read();
        // The separator for 'A Very Long Header' column must be at least 18 dashes wide
        $this->assertMatchesRegularExpression('/\+[-]{20,}/', $out);
    }

    /**
     * cellWidth() returns the visible width, ignoring style tags.
     */
    public function testCellWidthStripsStyleTagsFromMeasurement(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        // 'hello' wrapped in <info> tags: visible width should be 5
        $width = $this->callProtected($table, 'cellWidth', '<info>hello</info>');
        $this->assertSame(5, $width);
    }

    /**
     * cellWidth() returns 0 for empty strings.
     */
    public function testCellWidthReturnsZeroForEmptyString(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        $width = $this->callProtected($table, 'cellWidth', '');
        $this->assertSame(0, $width);
    }

    /**
     * calculateWidths() returns the max cell width for each column position.
     */
    public function testCalculateWidthsReturnsMaxWidthPerColumn(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        /** @var array<int, int> $widths */
        $widths = $this->callProtected($table, 'calculateWidths', [
            ['AB', 'C'],
            ['ABCDE', 'XY'],
        ]);

        $this->assertSame(5, $widths[0]);
        $this->assertSame(2, $widths[1]);
    }

    /**
     * output() correctly handles style tags in data cells without breaking alignment.
     */
    public function testOutputHandlesStyleTagsInCellsWithoutBreakingAlignment(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io);

        $table->output([
            ['URL', 'Type'],
            ['/about/', '<info>page</info>'],
            ['/blog/post/', '<info>blog</info>'],
        ]);

        $out = $read();
        // Both type cells should be present
        $this->assertStringContainsString('page', $out);
        $this->assertStringContainsString('blog', $out);
        // Table should not be malformed — all lines should start with |+
        $lines = array_filter(explode(PHP_EOL, $out));
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^[|+]/', $line);
        }
    }

    /**
     * stripStyles() removes known style tags and returns plain visible text.
     */
    public function testStripStylesRemovesKnownTags(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        $stripped = $this->callProtected($table, 'stripStyles', '<info>hello</info> <comment>world</comment>');
        $this->assertSame('hello world', $stripped);
    }

    /**
     * stripStyles() returns unchanged text when no style tags are present.
     */
    public function testStripStylesReturnsTextUnchangedWhenNoTags(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        $text = 'plain text';
        $stripped = $this->callProtected($table, 'stripStyles', $text);
        $this->assertSame($text, $stripped);
    }

    /**
     * truncateCell() returns the original string when it fits within maxWidth.
     */
    public function testTruncateCellReturnsOriginalWhenFits(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        $result = $this->callProtected($table, 'truncateCell', 'hello', 10);
        $this->assertSame('hello', $result);
    }

    /**
     * truncateCell() truncates and appends ellipsis when cell exceeds maxWidth.
     */
    public function testTruncateCellTruncatesAndAppendsEllipsis(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        /** @var string $result */
        $result = $this->callProtected($table, 'truncateCell', 'hello world', 7);
        $this->assertStringEndsWith('…', $result);
        $this->assertSame(7, mb_strwidth($result));
    }

    /**
     * truncateCell() returns text unchanged when maxWidth is 0 (disabled).
     */
    public function testTruncateCellNoopWhenMaxWidthIsZero(): void
    {
        [$io] = $this->captureIo();
        $table = new TableHelper($io);

        $long = str_repeat('a', 200);
        $result = $this->callProtected($table, 'truncateCell', $long, 0);
        $this->assertSame($long, $result);
    }

    /**
     * output() truncates data cells but leaves header cells intact when maxWidth is set.
     */
    public function testOutputTruncatesDataCellsButNotHeaders(): void
    {
        [$io, $read] = $this->captureIo();
        $table = new TableHelper($io, ['maxWidth' => 10]);

        $table->output([
            ['URL Path'],
            ['/a-very-long-url-path-that-exceeds-ten-characters/'],
        ]);

        $out = $read();
        $this->assertStringContainsString('URL Path', $out);
        $this->assertStringContainsString('…', $out);
        $this->assertStringNotContainsString('/a-very-long-url-path-that-exceeds-ten-characters/', $out);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a capturing ConsoleIo and return it along with a reader closure.
     *
     * @return array{0: \Cake\Console\ConsoleIo, 1: \Closure(): string}
     */
    private function captureIo(): array
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'glaze-table-test-');
        $io = new ConsoleIo(new ConsoleOutput($path));
        $read = static function () use ($path): string {
            return (string)file_get_contents($path);
        };

        return [$io, $read];
    }

    /**
     * Invoke a protected method via closure binding.
     *
     * @param object $object Target object.
     * @param string $method Method name.
     * @param mixed ...$arguments Method arguments.
     */
    private function callProtected(object $object, string $method, mixed ...$arguments): mixed
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
