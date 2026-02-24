<?php
declare(strict_types=1);

namespace Glaze\Content;

use Cake\Utility\Text;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Discovers Djot content files and maps them to static routes.
 */
final class ContentDiscoveryService
{
    protected FrontMatterParser $frontMatterParser;

    /**
     * Constructor.
     *
     * @param \Glaze\Content\FrontMatterParser|null $frontMatterParser Frontmatter parser service.
     */
    public function __construct(?FrontMatterParser $frontMatterParser = null)
    {
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
    }

    /**
     * Discover all Djot pages in a content directory.
     *
     * @param string $contentPath Absolute content directory path.
     * @return array<\Glaze\Content\ContentPage>
     */
    public function discover(string $contentPath): array
    {
        if (!is_dir($contentPath)) {
            return [];
        }

        $pages = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($contentPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'dj') {
                continue;
            }

            $sourcePath = $file->getPathname();
            $relativePath = $this->relativePath($contentPath, $sourcePath);
            $parsed = $this->frontMatterParser->parse($this->readFile($sourcePath));
            $meta = $this->normalizeMetadata($parsed->metadata);
            $slug = $this->resolveSlug($relativePath, $meta);
            $title = $this->resolveTitle($slug, $meta);
            $pages[] = new ContentPage(
                sourcePath: $sourcePath,
                relativePath: $relativePath,
                slug: $slug,
                urlPath: $this->toUrlPath($slug),
                outputRelativePath: $this->toOutputRelativePath($slug),
                title: $title,
                source: $parsed->body,
                draft: $this->resolveDraft($meta),
                meta: $meta,
            );
        }

        usort(
            $pages,
            static fn(ContentPage $left, ContentPage $right): int => strcmp($left->relativePath, $right->relativePath),
        );

        return $pages;
    }

    /**
     * Build a content-relative file path.
     *
     * @param string $contentPath Absolute content root path.
     * @param string $sourcePath Absolute file path.
     */
    protected function relativePath(string $contentPath, string $sourcePath): string
    {
        $normalizedContentPath = rtrim(str_replace('\\', '/', $contentPath), '/');
        $normalizedSourcePath = str_replace('\\', '/', $sourcePath);

        return ltrim(substr($normalizedSourcePath, strlen($normalizedContentPath)), '/');
    }

    /**
     * Convert a relative file path to a normalized slug.
     *
     * @param string $relativePath Relative source path.
     */
    protected function toSlug(string $relativePath): string
    {
        $withoutExtension = preg_replace('/\.dj$/', '', $relativePath) ?? $relativePath;
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $withoutExtension)),
            static fn(string $segment): bool => $segment !== '',
        );

        if (count($segments) > 1 && strtolower(end($segments)) === 'index') {
            array_pop($segments);
        }

        $slugSegments = [];

        foreach ($segments as $segment) {
            $slugged = strtolower(Text::slug($segment));
            $slugSegments[] = $slugged !== '' ? $slugged : 'page';
        }

        return implode('/', $slugSegments);
    }

    /**
     * Resolve final slug with frontmatter override support.
     *
     * @param string $relativePath Relative source path.
     * @param array<string, mixed> $meta Normalized metadata.
     */
    protected function resolveSlug(string $relativePath, array $meta): string
    {
        $slug = $meta['slug'] ?? null;
        if (is_string($slug) && trim($slug) !== '') {
            return $this->slugifyPath($slug);
        }

        return $this->toSlug($relativePath);
    }

    /**
     * Resolve final page title with frontmatter override support.
     *
     * @param string $slug Page slug.
     * @param array<string, mixed> $meta Normalized metadata.
     */
    protected function resolveTitle(string $slug, array $meta): string
    {
        $title = $meta['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        return $this->toTitle($slug);
    }

    /**
     * Resolve draft state from metadata.
     *
     * @param array<string, mixed> $meta Normalized metadata.
     */
    protected function resolveDraft(array $meta): bool
    {
        return (bool)($meta['draft'] ?? false);
    }

    /**
     * Normalize metadata keys and accepted scalar/array values.
     *
     * @param array<string, mixed> $metadata Raw metadata.
     * @return array<string, mixed>
     */
    protected function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = $this->normalizeMetaValue($value);
        }

        return $normalized;
    }

    /**
     * Normalize supported metadata value types.
     *
     * @param mixed $value Metadata value.
     */
    protected function normalizeMetaValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                static fn(mixed $item): bool => is_scalar($item) || $item === null,
            ));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Normalize an arbitrary slug-like string into route-safe path segments.
     *
     * @param string $slug Raw slug.
     */
    protected function slugifyPath(string $slug): string
    {
        $segments = array_filter(
            explode('/', trim(str_replace('\\', '/', $slug), '/')),
            static fn(string $segment): bool => $segment !== '',
        );
        $slugSegments = [];

        foreach ($segments as $segment) {
            $slugged = strtolower(Text::slug($segment));
            $slugSegments[] = $slugged !== '' ? $slugged : 'page';
        }

        if ($slugSegments === []) {
            return 'index';
        }

        return implode('/', $slugSegments);
    }

    /**
     * Convert slug to final URL path.
     *
     * @param string $slug Page slug.
     */
    protected function toUrlPath(string $slug): string
    {
        if ($slug === 'index') {
            return '/';
        }

        return '/' . trim($slug, '/') . '/';
    }

    /**
     * Convert slug to output file path.
     *
     * @param string $slug Page slug.
     */
    protected function toOutputRelativePath(string $slug): string
    {
        if ($slug === 'index') {
            return 'index.html';
        }

        return trim($slug, '/') . '/index.html';
    }

    /**
     * Convert slug to a fallback title.
     *
     * @param string $slug Page slug.
     */
    protected function toTitle(string $slug): string
    {
        if ($slug === 'index') {
            return 'Home';
        }

        $segments = explode('/', $slug);
        $last = end($segments);

        return ucfirst(str_replace('-', ' ', $last));
    }

    /**
     * Read source content from disk.
     *
     * @param string $sourcePath Source file path.
     */
    protected function readFile(string $sourcePath): string
    {
        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read content file "%s".', $sourcePath));
        }

        return $content;
    }
}
