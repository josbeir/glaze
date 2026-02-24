<?php
declare(strict_types=1);

namespace Glaze\Content;

/**
 * Parsed frontmatter metadata and remaining content body.
 */
final class FrontMatterParseResult
{
    /**
     * Constructor.
     *
     * @param array<string, mixed> $metadata Parsed frontmatter metadata.
     * @param string $body Content body without frontmatter.
     */
    public function __construct(
        public readonly array $metadata,
        public readonly string $body,
    ) {
    }
}
