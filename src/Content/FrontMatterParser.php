<?php
declare(strict_types=1);

namespace Glaze\Content;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * Parses NEON frontmatter fenced by +++ or --- at the top of content files.
 */
final class FrontMatterParser
{
    /**
     * Parse frontmatter from raw content.
     *
     * When no frontmatter fence is present, metadata is empty and body
     * contains the original source.
     *
     * @param string $source Raw source content.
     */
    public function parse(string $source): FrontMatterParseResult
    {
        if (!preg_match('/\A(?<fence>\+\+\+|---)\R(?<frontmatter>.*?)\R\k<fence>(?:\R|\z)/s', $source, $matches)) {
            return new FrontMatterParseResult(metadata: [], body: $source);
        }

        $frontMatter = $matches['frontmatter'];
        $body = substr($source, strlen($matches[0]));

        try {
            $decoded = Neon::decode($frontMatter);
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('Invalid NEON frontmatter: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid NEON frontmatter: expected a key/value mapping.');
        }

        /** @var array<string, mixed> $decoded */
        return new FrontMatterParseResult(metadata: $decoded, body: $body);
    }
}
