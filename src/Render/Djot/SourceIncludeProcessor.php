<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Glaze\Utility\Path;
use RuntimeException;

/**
 * Preprocesses Djot source to expand `<!--@include: path-->` directives before conversion.
 *
 * Supported directive formats:
 *   `<!--@include: ./partials/snippet.dj-->`            – include entire file
 *   `<!--@include: ./partials/snippet.dj{5,15}-->`      – include lines 5–15 (1-based, inclusive)
 *   `<!--@include: ./partials/snippet.dj{3,}-->`        – include from line 3 to end
 *   `<!--@include: ./partials/snippet.dj{,10}-->`       – include from start to line 10
 *   `<!--@include: ./partials/snippet.dj#basic-usage-->` – include from heading slug to next same-or-higher heading
 *
 * Included files are processed recursively (their own includes are expanded). Circular
 * references are detected via a visited-path set and cause a RuntimeException. All
 * resolved paths must remain within the configured content root for security.
 *
 * Example:
 *   $processor = new SourceIncludeProcessor();
 *   $expanded = $processor->process($source, '/content/docs/guide', '/content');
 */
class SourceIncludeProcessor
{
    /**
     * Pattern matching an include directive, capturing: path, optional anchor, optional start/end line numbers.
     */
    private const DIRECTIVE_PATTERN =
        '/<!--@include:\s*(?P<path>[^\s{#>]+)(?:#(?P<anchor>[^\s{}>]+))?(?:\{(?P<start>\d+)?,(?P<end>\d+)?\})?\s*-->/';

    /**
     * Expand all `<!--@include:-->` directives in the given Djot source string.
     *
     * Paths in directives are resolved relative to `$baseDir`. All resolved
     * absolute paths must be located inside `$contentRoot`; any path escaping
     * this boundary throws a RuntimeException.
     *
     * @param string $source Djot source that may contain include directives.
     * @param string $baseDir Absolute directory of the file being rendered; used to resolve relative paths.
     * @param string $contentRoot Absolute path to the content root used as the security boundary.
     * @return string Source with all include directives replaced by the referenced file contents.
     */
    public function process(string $source, string $baseDir, string $contentRoot): string
    {
        if (!str_contains($source, '<!--@include:')) {
            return $source;
        }

        return $this->expand($source, $baseDir, $contentRoot, []);
    }

    /**
     * Recursively expand include directives, tracking visited paths to detect cycles.
     *
     * The source is split into fenced-code and plain-text segments. Include directives
     * are only expanded in plain-text segments; fenced code blocks (`` ``` `` or `~~~`)
     * are passed through verbatim so that directive examples in documentation are not
     * accidentally processed.
     *
     * @param string $source Current source text to process.
     * @param string $baseDir Directory used to resolve relative paths in this source.
     * @param string $contentRoot Security boundary – resolved paths must be inside this directory.
     * @param array<string, true> $visited Set of already-included absolute file paths (cycle detection).
     * @return string Fully expanded source text.
     */
    protected function expand(string $source, string $baseDir, string $contentRoot, array $visited): string
    {
        $lines = explode("\n", $source);
        $total = count($lines);

        // Partition lines into [startIdx, endIdx, isFence] segments.
        $segments = [];
        $segStart = 0;
        $inFence = false;
        $fenceChar = '';
        $fenceLen = 0;

        for ($i = 0; $i < $total; $i++) {
            $line = $lines[$i];

            if (!$inFence) {
                if (preg_match('/^(`{3,}|~{3,})/', $line, $m)) {
                    if ($i > $segStart) {
                        $segments[] = [$segStart, $i - 1, false];
                    }

                    $inFence = true;
                    $fenceChar = $m[1][0];
                    $fenceLen = strlen($m[1]);
                    $segStart = $i;
                }
            } elseif (preg_match('/^' . preg_quote($fenceChar, '/') . '{' . $fenceLen . ',}\s*$/', $line)) {
                // A closing fence is a line consisting solely of >= $fenceLen of $fenceChar.
                $segments[] = [$segStart, $i, true];
                $inFence = false;
                $segStart = $i + 1;
            }
        }

        // Remaining lines after the last segment boundary.
        if ($segStart < $total) {
            $segments[] = [$segStart, $total - 1, $inFence];
        }

        $parts = [];
        foreach ($segments as [$start, $end, $isFence]) {
            $chunk = implode("\n", array_slice($lines, $start, $end - $start + 1));
            $parts[] = $isFence ? $chunk : $this->expandDirectives($chunk, $baseDir, $contentRoot, $visited);
        }

        return implode("\n", $parts);
    }

    /**
     * Expand include directives within a single plain-text (non-fence) chunk.
     *
     * @param string $source Plain-text source chunk (no fenced code blocks).
     * @param string $baseDir Directory used to resolve relative paths.
     * @param string $contentRoot Security boundary.
     * @param array<string, true> $visited Visited paths for cycle detection.
     * @return string Chunk with all include directives replaced.
     */
    protected function expandDirectives(string $source, string $baseDir, string $contentRoot, array $visited): string
    {
        if (!str_contains($source, '<!--@include:')) {
            return $source;
        }

        return (string)preg_replace_callback(
            self::DIRECTIVE_PATTERN,
            function (array $matches) use ($baseDir, $contentRoot, $visited): string {
                $rawPath = $matches['path'];
                $anchor = isset($matches['anchor']) && $matches['anchor'] !== '' ? $matches['anchor'] : null;
                $start = ctype_digit($matches['start'] ?? '') ? (int)$matches['start'] : null;
                $end = ctype_digit($matches['end'] ?? '') ? (int)$matches['end'] : null;

                $absPath = $this->resolvePath($rawPath, $baseDir, $contentRoot);

                return $this->processFile($absPath, $anchor, $start, $end, $contentRoot, $visited);
            },
            $source,
        );
    }

    /**
     * Resolve a raw path from a directive to an absolute, validated filesystem path.
     *
     * The path is resolved relative to `$baseDir`. The result must be a real file that
     * lies inside `$contentRoot`; otherwise a RuntimeException is thrown.
     *
     * @param string $rawPath The path string from the directive.
     * @param string $baseDir Directory used as the resolution base.
     * @param string $contentRoot Security boundary.
     * @return string Absolute, real path to the target file.
     * @throws \RuntimeException When the path escapes the content root or does not exist.
     */
    protected function resolvePath(string $rawPath, string $baseDir, string $contentRoot): string
    {
        $candidate = Path::resolve($baseDir, $rawPath);
        $real = realpath($candidate);

        if ($real === false) {
            throw new RuntimeException(sprintf(
                'Include target "%s" does not exist (resolved to "%s").',
                $rawPath,
                $candidate,
            ));
        }

        $absPath = Path::normalize($real);
        $normalizedRoot = Path::normalize((string)realpath($contentRoot));

        if (!str_starts_with($absPath, $normalizedRoot . '/') && $absPath !== $normalizedRoot) {
            throw new RuntimeException(sprintf(
                'Include target "%s" resolves outside the content root "%s".',
                $rawPath,
                $contentRoot,
            ));
        }

        return $absPath;
    }

    /**
     * Load a file, apply any line-range or anchor extraction, then recursively expand its includes.
     *
     * @param string $absPath Absolute path to the file to include.
     * @param string|null $anchor Optional heading anchor slug to locate a section within the file.
     * @param int|null $start Optional 1-based first line to include (after anchor region is resolved, if any).
     * @param int|null $end Optional 1-based last line to include.
     * @param string $contentRoot Security boundary forwarded to nested expansions.
     * @param array<string, true> $visited Visited paths for cycle detection.
     * @return string The extracted (and recursively expanded) content.
     * @throws \RuntimeException On unreadable files or circular includes.
     */
    protected function processFile(
        string $absPath,
        ?string $anchor,
        ?int $start,
        ?int $end,
        string $contentRoot,
        array $visited,
    ): string {
        if (array_key_exists($absPath, $visited)) {
            throw new RuntimeException(sprintf(
                'Circular include detected: "%s" is already being processed.',
                $absPath,
            ));
        }

        $raw = file_get_contents($absPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Cannot read include file "%s".', $absPath));
        }

        $normalizedRaw = str_replace(["\r\n", "\r"], "\n", $raw);
        $normalizedRaw = rtrim($normalizedRaw, "\n");

        $lines = $normalizedRaw === '' ? [] : explode("\n", $normalizedRaw);

        if ($anchor !== null) {
            $content = $this->extractAnchorRegion($lines, $anchor, $end);
        } else {
            $content = $this->extractLineRange($lines, $start, $end);
        }

        $newVisited = $visited + [$absPath => true];
        $newBaseDir = Path::normalize(dirname($absPath));

        if (!str_contains($content, '<!--@include:')) {
            return $content;
        }

        return $this->expand($content, $newBaseDir, $contentRoot, $newVisited);
    }

    /**
     * Extract lines between `$start` and `$end` (both 1-based, inclusive).
     *
     * A null `$start` defaults to the first line; a null `$end` defaults to the last.
     *
     * @param array<int, string> $lines All lines of the file.
     * @param int|null $start 1-based start line, or null for beginning.
     * @param int|null $end 1-based end line, or null for the last line.
     * @return string The extracted content with a trailing newline.
     */
    protected function extractLineRange(array $lines, ?int $start, ?int $end): string
    {
        $from = $start !== null ? $start - 1 : 0;
        $to = $end !== null ? $end - 1 : count($lines) - 1;

        $slice = array_slice($lines, $from, $to - $from + 1);

        return implode("\n", $slice);
    }

    /**
     * Find the heading matching `$anchor` and extract that section of the document.
     *
     * The section begins at the matched heading line and continues until a heading at
     * the same or higher level (fewer `#` chars) is found, or end of file. An optional
     * `$lineLimit` caps the number of extracted lines.
     *
     * @param array<int, string> $lines All lines of the file.
     * @param string $anchor Slug to match against normalised heading text.
     * @param int|null $lineLimit Maximum number of lines to include from the matched section.
     * @return string The extracted section, or an empty string if the anchor is not found.
     */
    protected function extractAnchorRegion(array $lines, string $anchor, ?int $lineLimit): string
    {
        $startIdx = null;
        $headingLevel = 0;

        foreach ($lines as $idx => $line) {
            if (!preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                continue;
            }

            $slug = $this->normalizeHeadingSlug($m[2]);
            if ($slug !== $anchor) {
                continue;
            }

            $startIdx = $idx;
            $headingLevel = strlen($m[1]);
            break;
        }

        if ($startIdx === null) {
            return '';
        }

        $lineCount = count($lines);
        $endIdx = $lineCount - 1;
        for ($i = $startIdx + 1; $i < $lineCount; $i++) {
            if (!preg_match('/^(#{1,6})\s+/', $lines[$i], $m)) {
                continue;
            }

            if (strlen($m[1]) <= $headingLevel) {
                $endIdx = $i - 1;
                break;
            }
        }

        $slice = array_slice($lines, $startIdx, $endIdx - $startIdx + 1);

        if ($lineLimit !== null) {
            $slice = array_slice($slice, 0, $lineLimit);
        }

        return implode("\n", $slice);
    }

    /**
     * Convert a heading text string into a URL-friendly slug for anchor matching.
     *
     * Follows the same algorithm as `HeadingIdTracker::normalizeId()`:
     * strip `#` characters, trim whitespace, then collapse whitespace sequences to `-`.
     *
     * @param string $text Raw heading text (may include inline markup).
     * @return string Normalised slug string.
     */
    protected function normalizeHeadingSlug(string $text): string
    {
        $slug = str_replace('#', '', $text);
        $slug = trim($slug);

        return preg_replace('/[\s]+/', '-', $slug) ?? $slug;
    }
}
