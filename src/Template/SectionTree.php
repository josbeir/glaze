<?php
declare(strict_types=1);

namespace Glaze\Template;

use Cake\Utility\Inflector;
use Glaze\Content\ContentPage;
use Glaze\Utility\Normalization;

/**
 * Builds nested section trees from discovered content pages.
 */
final class SectionTree
{
    /**
     * Build root section from a page list.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages in deterministic order.
     */
    public static function build(array $pages): Section
    {
        /** @var array<string, array{index: \Glaze\Content\ContentPage|null, pages: array<\Glaze\Content\ContentPage>, children: array<string, string>}> $nodes */
        $nodes = [
            '' => self::newNode(),
        ];

        foreach ($pages as $page) {
            $relativePath = Normalization::pathKey($page->relativePath);
            $sectionPath = self::resolveSectionPath($page, $relativePath);

            self::ensurePathNodes($nodes, $sectionPath);
            $nodes[$sectionPath] ??= self::newNode();

            $nodes[$sectionPath]['pages'][] = $page;

            if (self::isIndexPath($relativePath)) {
                $nodes[$sectionPath]['index'] = $page;
            }
        }

        foreach (array_keys($nodes) as $path) {
            if ($path === '') {
                continue;
            }

            $parentPath = self::parentPath($path);
            $childKey = self::pathKey($path);
            $nodes[$parentPath] ??= self::newNode();
            $nodes[$parentPath]['children'][$childKey] = $path;
        }

        return self::buildSection($nodes, '');
    }

    /**
     * Build a section node recursively.
     *
     * @param array<string, array{index: \Glaze\Content\ContentPage|null, pages: array<\Glaze\Content\ContentPage>, children: array<string, string>}> $nodes Node map.
     * @param string $path Current section path.
     */
    protected static function buildSection(array $nodes, string $path): Section
    {
        $node = $nodes[$path] ?? self::newNode();

        $children = [];
        foreach ($node['children'] as $key => $childPath) {
            $children[$key] = self::buildSection($nodes, $childPath);
        }

        uasort($children, static function (Section $left, Section $right): int {
            $weightComparison = $left->weight() <=> $right->weight();
            if ($weightComparison !== 0) {
                return $weightComparison;
            }

            return strcmp($left->path(), $right->path());
        });

        $label = self::resolveLabel($path, $node['index']);
        $weight = self::resolveWeight($node['index'], $node['pages'], $children);

        return new Section(
            path: $path,
            label: $label,
            weight: $weight,
            indexPage: $node['index'],
            pages: new PageCollection($node['pages']),
            children: $children,
        );
    }

    /**
     * Ensure section nodes exist for all path ancestors.
     *
     * @param array<string, array{index: \Glaze\Content\ContentPage|null, pages: array<\Glaze\Content\ContentPage>, children: array<string, string>}> $nodes Node map.
     * @param string $path Section path.
     */
    protected static function ensurePathNodes(array &$nodes, string $path): void
    {
        $current = '';
        foreach (self::pathSegments($path) as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            if (isset($nodes[$current])) {
                continue;
            }

            $nodes[$current] = self::newNode();
        }
    }

    /**
     * Return an empty section node shape.
     *
     * @return array{index: \Glaze\Content\ContentPage|null, pages: array<\Glaze\Content\ContentPage>, children: array<string, string>}
     */
    protected static function newNode(): array
    {
        return ['index' => null, 'pages' => [], 'children' => []];
    }

    /**
     * Resolve section display label from index page or folder key.
     *
     * @param string $path Section path.
     * @param \Glaze\Content\ContentPage|null $indexPage Section index page.
     */
    protected static function resolveLabel(string $path, ?ContentPage $indexPage): string
    {
        if ($indexPage instanceof ContentPage) {
            return $indexPage->title;
        }

        if ($path === '') {
            return 'Root';
        }

        return Inflector::humanize(str_replace('-', '_', self::pathKey($path)));
    }

    /**
     * Resolve section order weight.
     *
     * Uses index page weight when available, otherwise minimum subtree weight.
     *
     * @param \Glaze\Content\ContentPage|null $indexPage Section index page.
     * @param array<\Glaze\Content\ContentPage> $pages Direct section pages.
     * @param array<string, \Glaze\Template\Section> $children Child sections.
     */
    protected static function resolveWeight(?ContentPage $indexPage, array $pages, array $children): int
    {
        if ($indexPage instanceof ContentPage) {
            return self::extractWeight($indexPage);
        }

        $minimum = PHP_INT_MAX;
        foreach ($pages as $page) {
            $minimum = min($minimum, self::extractWeight($page));
        }

        foreach ($children as $childSection) {
            $minimum = min($minimum, $childSection->weight());
        }

        return $minimum;
    }

    /**
     * Extract sortable weight from page metadata.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected static function extractWeight(ContentPage $page): int
    {
        $weight = $page->meta['weight'] ?? null;

        return is_int($weight) ? $weight : PHP_INT_MAX;
    }

    /**
     * Determine whether a relative path targets an index file.
     *
     * @param string $relativePath Normalized path.
     */
    protected static function isIndexPath(string $relativePath): bool
    {
        return strtolower(basename($relativePath)) === 'index.dj';
    }

    /**
     * Resolve section path for a page using metadata overrides.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     * @param string $relativePath Normalized relative path.
     */
    protected static function resolveSectionPath(ContentPage $page, string $relativePath): string
    {
        $metaSection = $page->meta['section'] ?? null;
        if (is_string($metaSection) && trim($metaSection) !== '') {
            return Normalization::pathKey($metaSection);
        }

        return self::pathDirectory($relativePath);
    }

    /**
     * Return normalized parent directory path.
     *
     * @param string $path Section path.
     */
    protected static function parentPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $pos = strrpos($path, '/');
        if ($pos === false) {
            return '';
        }

        return substr($path, 0, $pos);
    }

    /**
     * Return section key from path.
     *
     * @param string $path Section path.
     */
    protected static function pathKey(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = self::pathSegments($path);
        if ($segments === []) {
            return '';
        }

        return end($segments);
    }

    /**
     * Return normalized directory for a relative file path.
     *
     * @param string $relativePath Relative file path.
     */
    protected static function pathDirectory(string $relativePath): string
    {
        $directory = dirname($relativePath);
        if ($directory === '.' || $directory === '/') {
            return '';
        }

        return Normalization::pathKey($directory);
    }

    /**
     * Return normalized path segments.
     *
     * @param string $path Path value.
     * @return array<string>
     */
    protected static function pathSegments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
    }
}
