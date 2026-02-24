<?php
declare(strict_types=1);

namespace Glaze\Content;

use Cake\Chronos\Chronos;
use Cake\Utility\Inflector;
use Cake\Utility\Text;
use DateTimeInterface;
use Glaze\Utility\Normalization;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

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
     * @param array<string> $taxonomies Enabled taxonomy keys.
     * @param array<string, array{paths: array<string>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @return array<\Glaze\Content\ContentPage>
     */
    public function discover(
        string $contentPath,
        array $taxonomies = ['tags'],
        array $contentTypes = [],
    ): array {
        if (!is_dir($contentPath)) {
            return [];
        }

        $normalizedTaxonomies = $this->normalizeTaxonomyKeys($taxonomies);

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
            $type = $this->resolveContentType($relativePath, $meta, $contentTypes);
            $meta = $this->mergeContentTypeDefaults($meta, $type, $contentTypes);
            [$meta, $taxonomyValues] = $this->extractTaxonomies($meta, $normalizedTaxonomies);
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
                taxonomies: $taxonomyValues,
                type: $type,
            );
        }

        usort(
            $pages,
            static fn(ContentPage $left, ContentPage $right): int => strcmp($left->relativePath, $right->relativePath),
        );

        return $pages;
    }

    /**
     * Resolve content type by explicit metadata override or path prefix matching.
     *
     * @param string $relativePath Relative source file path.
     * @param array<string, mixed> $meta Normalized page metadata.
     * @param array<string, array{paths: array<string>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     */
    protected function resolveContentType(string $relativePath, array $meta, array $contentTypes): ?string
    {
        if ($contentTypes === []) {
            return null;
        }

        $explicitType = Normalization::optionalString($meta['type'] ?? null);
        if ($explicitType !== null) {
            $normalizedExplicitType = strtolower($explicitType);
            if (!isset($contentTypes[$normalizedExplicitType])) {
                throw new RuntimeException(sprintf(
                    'Invalid frontmatter "type" value "%s" in "%s": unknown content type.',
                    $explicitType,
                    $relativePath,
                ));
            }

            return $normalizedExplicitType;
        }

        $normalizedRelativePath = trim(str_replace('\\', '/', $relativePath), '/');

        foreach ($contentTypes as $typeName => $typeConfiguration) {
            foreach ($typeConfiguration['paths'] as $prefix) {
                if ($prefix === '') {
                    continue;
                }

                if ($normalizedRelativePath === $prefix || str_starts_with($normalizedRelativePath, $prefix . '/')) {
                    return $typeName;
                }
            }
        }

        return null;
    }

    /**
     * Merge resolved content type metadata defaults with page metadata.
     *
     * @param array<string, mixed> $meta Normalized page metadata.
     * @param string|null $type Resolved content type.
     * @param array<string, array{paths: array<string>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @return array<string, mixed>
     */
    protected function mergeContentTypeDefaults(array $meta, ?string $type, array $contentTypes): array
    {
        if ($type === null) {
            return $meta;
        }

        $typeDefaults = $contentTypes[$type]['defaults'] ?? [];
        $normalizedDefaults = $this->normalizeMetadata($typeDefaults);
        $merged = array_replace($normalizedDefaults, $meta);
        $merged['type'] = $type;

        return $merged;
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

            if ($normalizedKey === 'meta' && is_array($value)) {
                $normalized[$normalizedKey] = $this->normalizeMetaMap($value);
                continue;
            }

            $normalized[$normalizedKey] = $this->normalizeMetaEntry($normalizedKey, $value);
        }

        return $normalized;
    }

    /**
     * Normalize a metadata entry while applying key-specific conversions.
     *
     * @param string $key Normalized metadata key.
     * @param mixed $value Metadata value.
     */
    protected function normalizeMetaEntry(string $key, mixed $value): mixed
    {
        $normalized = $this->normalizeMetaValue($value);
        if ($key !== 'date') {
            return $normalized;
        }

        return $this->normalizeDateMetaValue($normalized, $key);
    }

    /**
     * Normalize configured taxonomy key names.
     *
     * @param array<string> $taxonomies Configured taxonomy keys.
     * @return array<string>
     */
    protected function normalizeTaxonomyKeys(array $taxonomies): array
    {
        $normalized = [];

        foreach ($taxonomies as $taxonomy) {
            $key = strtolower(trim($taxonomy));
            if ($key === '') {
                continue;
            }

            $normalized[] = $key;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Extract configured taxonomies from metadata map.
     *
     * @param array<string, mixed> $meta Normalized metadata values.
     * @param array<string> $taxonomyKeys Taxonomy keys to extract.
     * @return array{array<string, mixed>, array<string, array<string>>}
     */
    protected function extractTaxonomies(array $meta, array $taxonomyKeys): array
    {
        $taxonomies = [];

        foreach ($taxonomyKeys as $taxonomyKey) {
            $raw = $meta[$taxonomyKey] ?? null;
            $taxonomies[$taxonomyKey] = $this->normalizeTaxonomyTerms($raw);
            unset($meta[$taxonomyKey]);
        }

        return [$meta, $taxonomies];
    }

    /**
     * Normalize taxonomy terms to distinct lowercased strings.
     *
     * @param mixed $raw Taxonomy frontmatter value.
     * @return array<string>
     */
    protected function normalizeTaxonomyTerms(mixed $raw): array
    {
        $terms = [];

        if (is_string($raw) && trim($raw) !== '') {
            $terms[] = strtolower(trim($raw));
        }

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_string($value)) {
                    continue;
                }

                if (trim($value) === '') {
                    continue;
                }

                $terms[] = strtolower(trim($value));
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Normalize supported metadata value types.
     *
     * @param mixed $value Metadata value.
     */
    protected function normalizeMetaValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Chronos::instance($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $normalizedItems = [];
            foreach ($value as $item) {
                $normalizedItem = $this->normalizeMetaValue($item);
                if (
                    !is_scalar($normalizedItem)
                    && $normalizedItem !== null
                    && !$normalizedItem instanceof DateTimeInterface
                ) {
                    continue;
                }

                $normalizedItems[] = $normalizedItem;
            }

            return $normalizedItems;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Normalize map-like metadata values while preserving keys.
     *
     * @param array<mixed> $value Raw metadata map.
     * @return array<string, mixed>
     */
    protected function normalizeMetaMap(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            $normalizedItem = $this->normalizeMetaEntry(strtolower($normalizedKey), $item);
            if (is_array($normalizedItem)) {
                continue;
            }

            if (
                !is_scalar($normalizedItem)
                && $normalizedItem !== null
                && !$normalizedItem instanceof DateTimeInterface
            ) {
                continue;
            }

            $normalized[$normalizedKey] = $normalizedItem;
        }

        return $normalized;
    }

    /**
     * Convert frontmatter date values into Chronos instances when possible.
     *
     * @param mixed $value Metadata date value.
     */
    protected function normalizeDateMetaValue(mixed $value, string $key): Chronos
    {
        if ($value instanceof DateTimeInterface) {
            return Chronos::instance($value);
        }

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Invalid frontmatter "%s" value: expected a valid date/datetime parseable by Chronos.',
                $key,
            ));
        }

        try {
            return Chronos::parse($value);
        } catch (Throwable) {
            throw new RuntimeException(sprintf(
                'Invalid frontmatter "%s" value: expected a valid date/datetime parseable by Chronos.',
                $key,
            ));
        }
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

        $humanized = Inflector::humanize(str_replace('-', '_', (string)$last));

        return ucfirst(strtolower($humanized));
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
