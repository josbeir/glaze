<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\DjotConverter;
use Djot\Extension\HeadingPermalinksExtension;
use Glaze\Render\Djot\TocExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TocExtension heading collection and [[toc]] directive handling.
 */
final class TocExtensionTest extends TestCase
{
    /**
     * Ensure the extension collects one TocEntry per heading in document order.
     */
    public function testRegisterCollectsHeadingEntries(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $converter->convert("# Introduction\n\n## Installation\n\n### Requirements\n");

        $entries = $extension->getEntries();

        $this->assertCount(3, $entries);

        $this->assertSame(1, $entries[0]->level);
        $this->assertSame('Introduction', $entries[0]->id);
        $this->assertSame('Introduction', $entries[0]->text);

        $this->assertSame(2, $entries[1]->level);
        $this->assertSame('Installation', $entries[1]->id);
        $this->assertSame('Installation', $entries[1]->text);

        $this->assertSame(3, $entries[2]->level);
        $this->assertSame('Requirements', $entries[2]->id);
        $this->assertSame('Requirements', $entries[2]->text);
    }

    /**
     * Ensure the extension sets an id attribute on heading elements it processes.
     */
    public function testRegisterSetsIdAttributeOnHeadingElements(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("## Getting Started\n");

        $this->assertStringContainsString('id="Getting-Started"', $html);
    }

    /**
     * Ensure duplicate heading slugs are de-duplicated by the tracker.
     */
    public function testRegisterDeduplicatesDuplicateHeadingIds(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $converter->convert("## Setup\n\n## Setup\n");

        $entries = $extension->getEntries();

        $this->assertCount(2, $entries);
        $this->assertNotSame($entries[0]->id, $entries[1]->id);
        $this->assertSame('Setup', $entries[0]->id);
        $this->assertStringStartsWith('Setup', $entries[1]->id);
    }

    /**
     * Ensure a standalone [[toc]] paragraph is replaced with the sentinel string.
     */
    public function testRegisterReplacesTocDirectiveWithSentinel(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("[[toc]]\n");

        $this->assertStringContainsString(TocExtension::TOC_SENTINEL, $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    /**
     * Ensure [[toc]] inline within other text is NOT treated as a directive.
     */
    public function testRegisterIgnoresTocDirectiveInMixedParagraph(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("See [[toc]] for details.\n");

        $this->assertStringNotContainsString(TocExtension::TOC_SENTINEL, $html);
        $this->assertStringContainsString('<p>', $html);
    }

    /**
     * Ensure injectToc() replaces the sentinel with rendered TOC nav markup.
     */
    public function testInjectTocReplacessentinelWithNavMarkup(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("[[toc]]\n\n## Setup\n\n## Usage\n");
        $injected = $extension->injectToc($html);

        $this->assertStringNotContainsString(TocExtension::TOC_SENTINEL, $injected);
        $this->assertStringContainsString('<nav class="toc">', $injected);
        $this->assertStringContainsString('<ul>', $injected);
        $this->assertStringContainsString('href="#Setup"', $injected);
        $this->assertStringContainsString('href="#Usage"', $injected);
        // TOC appears before the heading content since [[toc]] precedes the headings.
        $this->assertLessThan(strpos($injected, 'id="Setup"'), strpos($injected, '<nav class="toc">'));
    }

    /**
     * Ensure injectToc() returns the original HTML unchanged when no sentinel is present.
     */
    public function testInjectTocIsNoopWhenSentinelAbsent(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("## Setup\n");
        $injected = $extension->injectToc($html);

        $this->assertSame($html, $injected);
    }

    /**
     * Ensure injectToc() emits an empty string when the document has no headings.
     */
    public function testInjectTocProducesEmptyStringWhenNoHeadingsPresent(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("[[toc]]\n\nJust some text.\n");
        $injected = $extension->injectToc($html);

        $this->assertStringNotContainsString(TocExtension::TOC_SENTINEL, $injected);
        $this->assertStringNotContainsString('<nav', $injected);
    }

    /**
     * Ensure the extension coexists with HeadingPermalinksExtension without corrupting
     * heading text labels (tracker caches plain text before anchors are appended).
     */
    public function testRegisterPreservesHeadingTextWhenCombinedWithHeadingPermalinksExtension(): void
    {
        $extension = new TocExtension();
        $anchors = new HeadingPermalinksExtension(symbol: '#', position: 'after');
        $converter = new DjotConverter();
        // Register TocExtension first — tracker caching should protect text regardless.
        $converter->addExtension($extension);
        $converter->addExtension($anchors);

        $converter->convert("## Installation Guide\n");

        $this->assertSame('Installation Guide', $extension->getEntries()[0]->text);
    }

    /**
     * Ensure a document without headings produces an empty entries list.
     */
    public function testRegisterProducesNoEntriesForDocumentWithoutHeadings(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $converter->convert("Just a paragraph.\n");

        $this->assertSame([], $extension->getEntries());
    }

    /**
     * Ensure special HTML characters in heading text are escaped in TOC markup.
     */
    public function testInjectTocEscapesSpecialCharactersInHeadingText(): void
    {
        $extension = new TocExtension();
        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert("[[toc]]\n\n## Using <script> & \"quotes\"\n");
        $injected = $extension->injectToc($html);

        $this->assertStringNotContainsString('<script>', $injected);
        $this->assertStringContainsString('&lt;script&gt;', $injected);
        $this->assertStringContainsString('&amp;', $injected);
    }
}
