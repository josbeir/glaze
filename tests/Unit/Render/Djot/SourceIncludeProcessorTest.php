<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Glaze\Render\Djot\SourceIncludeProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SourceIncludeProcessor Djot source preprocessing.
 *
 * Fixtures live in {@see tests/fixtures/source-include/}.
 */
final class SourceIncludeProcessorTest extends TestCase
{
    private string $fixtureDir;

    private SourceIncludeProcessor $processor;

    /**
     * Set up fixture path and processor instance before each test.
     */
    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__, 3) . '/fixtures/source-include';
        $this->processor = new SourceIncludeProcessor();
    }

    /**
     * Ensure source without any include directives is returned unchanged.
     */
    public function testProcessReturnSourceUnchangedWhenNoDirectives(): void
    {
        $source = "# Hello\n\nJust a paragraph.\n";

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame($source, $result);
    }

    /**
     * Ensure an include directive is replaced with the full content of the target file.
     */
    public function testProcessExpandsWholeFileInclude(): void
    {
        $source = '<!--@include: ./partial.dj-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringContainsString('Line one', $result);
        $this->assertStringContainsString('Line six', $result);
        $this->assertStringNotContainsString('<!--@include:', $result);
    }

    /**
     * Ensure a {start,end} line range extracts only the specified lines (1-based, inclusive).
     */
    public function testProcessExtractsSpecifiedLineRange(): void
    {
        $source = '<!--@include: ./partial.dj{3,5}-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame("Line three\nLine four\nLine five", $result);
    }

    /**
     * Ensure a {start,} range includes from the given line to the end of the file.
     */
    public function testProcessExtractsFromLineToEndOfFile(): void
    {
        $source = '<!--@include: ./partial.dj{5,}-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringNotContainsString('Line one', $result);
        $this->assertStringNotContainsString('Line four', $result);
        $this->assertStringContainsString('Line five', $result);
        $this->assertStringContainsString('Line six', $result);
    }

    /**
     * Ensure a {,end} range includes from the start of the file up to the given line.
     */
    public function testProcessExtractsFromStartToLine(): void
    {
        $source = '<!--@include: ./partial.dj{,3}-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame("Line one\nLine two\nLine three", $result);
    }

    /**
     * Ensure an anchor directive extracts the matching section up to the next sibling heading.
     */
    public function testProcessExtractsAnchorSection(): void
    {
        $source = '<!--@include: ./sectioned.dj#Basic-Usage-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringContainsString('## Basic Usage', $result);
        $this->assertStringContainsString('Usage content here.', $result);
        $this->assertStringNotContainsString('# Introduction', $result);
        $this->assertStringNotContainsString('## Advanced', $result);
    }

    /**
     * Ensure an anchor that matches the first heading extracts that section and all its subsections.
     */
    public function testProcessExtractsFirstAnchorSection(): void
    {
        $source = '<!--@include: ./sectioned.dj#Introduction-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringContainsString('# Introduction', $result);
        $this->assertStringContainsString('Intro paragraph.', $result);
        // Level-2 subsections belong to the level-1 Introduction section
        $this->assertStringContainsString('## Basic Usage', $result);
    }

    /**
     * Ensure that when an anchor is not found in the target file, the expansion produces an empty string.
     */
    public function testProcessReturnsEmptyStringForUnknownAnchor(): void
    {
        $source = 'Before<!--@include: ./sectioned.dj#nonexistent-->After';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame('BeforeAfter', $result);
    }

    /**
     * Ensure nested includes (file A including file B) are fully expanded.
     */
    public function testProcessExpandsRecursiveIncludes(): void
    {
        $source = '<!--@include: ./recursive-a.dj-->';

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringContainsString('A content', $result);
        $this->assertStringContainsString('B content', $result);
        $this->assertStringNotContainsString('<!--@include:', $result);
    }

    /**
     * Ensure surrounding text is preserved when an include directive appears inline.
     */
    public function testProcessPreservesSurroundingTextAroundDirective(): void
    {
        $source = "Before\n<!--@include: ./partial.dj{1,1}-->\nAfter\n";

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame("Before\nLine one\nAfter\n", $result);
    }

    /**
     * Ensure a RuntimeException is thrown when the include target file does not exist.
     */
    public function testProcessThrowsOnMissingFile(): void
    {
        $source = '<!--@include: ./does-not-exist.dj-->';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);
    }

    /**
     * Ensure a RuntimeException is thrown when the resolved path escapes the content root.
     */
    public function testProcessThrowsOnPathOutsideContentRoot(): void
    {
        $source = '<!--@include: ../outside-content-root.dj-->';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the content root');

        $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);
    }

    /**
     * Ensure a RuntimeException is thrown when a circular include chain is detected.
     */
    public function testProcessThrowsOnCircularInclude(): void
    {
        $source = '<!--@include: ./cyclic-a.dj-->';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular include detected');

        $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);
    }

    /**
     * Ensure the same file can be included multiple times in one document without triggering cycle detection.
     */
    public function testProcessAllowsSameFileIncludedTwiceInDifferentPositions(): void
    {
        $source = "<!--@include: ./partial.dj{1,1}-->\n<!--@include: ./partial.dj{1,1}-->";

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame("Line one\nLine one", $result);
    }

    /**
     * Ensure include directives inside fenced code blocks are NOT expanded.
     */
    public function testProcessSkipsDirectivesInsideFencedCodeBlock(): void
    {
        $source = "```djot\n<!--@include: ./partial.dj-->\n```\n";

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertSame($source, $result);
    }

    /**
     * Ensure directives outside fences are expanded while those inside are left untouched.
     */
    public function testProcessExpandsDirectivesOutsideFencesButNotInside(): void
    {
        $source = "<!--@include: ./partial.dj{1,1}-->\n\n```djot\n<!--@include: ./partial.dj-->\n```\n";

        $result = $this->processor->process($source, $this->fixtureDir, $this->fixtureDir);

        $this->assertStringContainsString('Line one', $result);
        $this->assertStringContainsString("```djot\n<!--@include: ./partial.dj-->\n```", $result);
    }
}
