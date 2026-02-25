<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Content\FrontMatterParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for NEON frontmatter parsing.
 */
final class FrontMatterParserTest extends TestCase
{
    /**
     * Ensure content without frontmatter remains unchanged.
     */
    public function testParseWithoutFrontMatterReturnsSourceAsBody(): void
    {
        $source = "# Title\n\nBody\n";

        $result = (new FrontMatterParser())->parse($source);

        $this->assertSame([], $result->metadata);
        $this->assertSame($source, $result->body);
    }

    /**
     * Ensure fenced NEON metadata is parsed and stripped from body.
     */
    public function testParseExtractsNeonFrontMatter(): void
    {
        $source = "+++\ntitle: Home\ndraft: false\ntags:\n  - glaze\n  - neon\n+++\n# Body\n";

        $result = (new FrontMatterParser())->parse($source);

        $this->assertSame('Home', $result->metadata['title']);
        $this->assertFalse($result->metadata['draft']);
        $this->assertSame(['glaze', 'neon'], $result->metadata['tags']);
        $this->assertSame("# Body\n", $result->body);
    }

    /**
     * Ensure --- fenced frontmatter is also accepted.
     */
    public function testParseExtractsNeonFrontMatterWithDashFence(): void
    {
        $source = "---\ntitle: Home\ndraft: false\n---\n# Body\n";

        $result = (new FrontMatterParser())->parse($source);

        $this->assertSame('Home', $result->metadata['title']);
        $this->assertFalse($result->metadata['draft']);
        $this->assertSame("# Body\n", $result->body);
    }

    /**
     * Ensure invalid NEON frontmatter fails fast with explicit error.
     */
    public function testParseThrowsForInvalidNeonFrontMatter(): void
    {
        $source = "+++\nfoo: [\n+++\n# Body\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid NEON frontmatter');

        (new FrontMatterParser())->parse($source);
    }

    /**
     * Ensure scalar frontmatter values are rejected as non-mapping metadata.
     */
    public function testParseThrowsWhenFrontMatterDecodesToScalar(): void
    {
        $source = "+++\ntrue\n+++\n# Body\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected a key/value mapping');

        (new FrontMatterParser())->parse($source);
    }
}
