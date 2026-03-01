<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Closure;
use DateTimeImmutable;
use Glaze\Content\ContentPage;
use Glaze\Template\Collection\PageCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for page collection filtering, sorting, and grouping.
 */
final class PageCollectionTest extends TestCase
{
    /**
     * Validate where, sorting, and grouping operations.
     */
    public function testCollectionQueryAndGroupingOperations(): void
    {
        $pages = new PageCollection([
            $this->makePage('blog/a', '/blog/a/', [
                'date' => '2026-02-01',
                'tags' => ['php', 'cake'],
                'draft' => false,
                'weight' => 2,
            ], 'Post A', 'blog'),
            $this->makePage('blog/b', '/blog/b/', [
                'date' => '2026-03-01',
                'tags' => ['php'],
                'draft' => true,
                'weight' => 1,
            ], 'Post B', 'blog'),
            $this->makePage('docs/intro', '/docs/intro/', [
                'date' => '2025-12-12',
                'tags' => ['docs'],
                'draft' => false,
                'weight' => 10,
            ], 'Intro', 'docs'),
        ]);

        $this->assertCount(2, $pages->where('meta.draft', false));
        $this->assertCount(2, $pages->where('meta.tags', 'intersect', ['php']));
        $this->assertCount(2, $pages->where('draft', false));
        $this->assertCount(2, $pages->where('tags', 'intersect', ['php']));
        $this->assertSame('Post B', $pages->byDate('desc')->first()?->title);
        $this->assertSame('Intro', $pages->byTitle('asc')->first()?->title);
        $this->assertSame('Post B', $pages->by('meta.weight', 'asc')->first()?->title);
        $this->assertSame('Post B', $pages->by('weight', 'asc')->first()?->title);

        $groups = $pages->groupBy('meta.draft');
        $this->assertArrayHasKey('false', $groups);
        $this->assertArrayHasKey('true', $groups);
        $this->assertCount(2, $groups['false']);

        $shorthandGroups = $pages->groupBy('draft');
        $this->assertArrayHasKey('false', $shorthandGroups);
        $this->assertArrayHasKey('true', $shorthandGroups);
        $this->assertCount(2, $shorthandGroups['false']);

        $dateGroups = $pages->groupByDate('Y-m', 'desc');
        $this->assertSame(['2026-03', '2026-02', '2025-12'], array_keys($dateGroups));
        $this->assertCount(2, $pages->whereType('blog'));
        $this->assertCount(1, $pages->whereType('docs'));
        $this->assertCount(0, $pages->whereType('   '));
    }

    /**
     * Validate regex matching and list slicing helpers.
     */
    public function testCollectionLikeTakeAndSliceHelpers(): void
    {
        $pages = new PageCollection([
            $this->makePage('blog/a', '/blog/a/', [], 'Alpha'),
            $this->makePage('blog/b', '/blog/b/', [], 'Beta'),
            $this->makePage('blog/c', '/blog/c/', [], 'Gamma'),
        ]);

        $this->assertCount(2, $pages->where('title', 'like', '/^[ab]/i'));
        $this->assertCount(2, $pages->take(2));
        $this->assertSame('Beta', $pages->slice(1, 1)->first()?->title);
        $this->assertSame('Gamma', $pages->reverse()->first()?->title);
    }

    /**
     * Validate groupBy key ordering with explicit sort direction options.
     */
    public function testGroupBySupportsOptionalSortDirection(): void
    {
        $pages = new PageCollection([
            $this->makePage('a', '/a/', ['group' => 'Templating'], 'A'),
            $this->makePage('b', '/b/', ['group' => 'Getting Started'], 'B'),
            $this->makePage('c', '/c/', ['group' => 'Reference'], 'C'),
        ]);

        $insertionGroups = $pages->groupBy('group');
        $ascendingGroups = $pages->groupBy('group', 'asc');
        $descendingGroups = $pages->groupBy('group', 'desc');

        $this->assertSame(['Templating', 'Getting Started', 'Reference'], array_keys($insertionGroups));
        $this->assertSame(['Getting Started', 'Reference', 'Templating'], array_keys($ascendingGroups));
        $this->assertSame(['Templating', 'Reference', 'Getting Started'], array_keys($descendingGroups));
    }

    /**
     * Validate groupBy rejects unsupported direction values.
     */
    public function testGroupByRejectsInvalidSortDirection(): void
    {
        $pages = new PageCollection([
            $this->makePage('a', '/a/', ['group' => 'Docs'], 'A'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid group sort direction');

        $pages->groupBy('group', 'sideways');
    }

    /**
     * Validate accessor helpers and empty-collection edge paths.
     */
    public function testCollectionAccessorsAndSliceVariants(): void
    {
        $empty = PageCollection::from([]);
        $this->assertSame([], $empty->all());
        $this->assertTrue($empty->isEmpty());
        $this->assertNotInstanceOf(ContentPage::class, $empty->first());
        $this->assertNotInstanceOf(ContentPage::class, $empty->last());

        $pages = new PageCollection([
            $this->makePage('a', '/a/', [], 'A'),
            $this->makePage('b', '/b/', [], 'B'),
        ]);
        $this->assertFalse($pages->isEmpty());
        $this->assertSame('a', $pages->first()?->slug);
        $this->assertSame('b', $pages->last()?->slug);
        $this->assertCount(0, $pages->take(-1));
        $this->assertCount(1, $pages->slice(1));
    }

    /**
     * Validate all where operators and date grouping edge paths.
     */
    public function testCollectionWhereOperatorsAndGroupingEdgeCases(): void
    {
        $withStringable = new class {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $pages = new PageCollection([
            $this->makePage('a', '/a/', [
                'score' => 10,
                'label' => 'alpha',
                'tags' => ['one', 'two'],
                'date' => '2026-01-01',
                'obj' => $withStringable,
                'draft' => false,
            ], 'Alpha'),
            $this->makePage('b', '/b/', [
                'score' => 20,
                'label' => 'beta',
                'tags' => ['three'],
                'date' => 'invalid-date',
                'draft' => true,
            ], 'Beta'),
        ]);

        $this->assertCount(1, $pages->where('meta.score', 'gt', 10));
        $this->assertCount(2, $pages->where('meta.score', 'ge', 10));
        $this->assertCount(1, $pages->where('meta.score', 'lt', 20));
        $this->assertCount(2, $pages->where('meta.score', 'le', 20));
        $this->assertCount(1, $pages->where('meta.label', 'in', ['alpha', 'gamma']));
        $this->assertCount(1, $pages->where('meta.label', 'not in', ['alpha']));
        $this->assertCount(1, $pages->where('meta.tags', 'intersect', ['two']));
        $this->assertCount(1, $pages->where('meta.label', 'like', 'alpha'));
        $this->assertCount(0, $pages->where('meta.label', 'like', '('));
        $this->assertCount(0, $pages->where('meta.draft', 'unknown-op', false));

        $this->assertSame('a', $pages->by('meta.score', 'asc')->first()?->slug);
        $this->assertSame('b', $pages->by('meta.score', 'desc')->first()?->slug);
        $this->assertSame('a', $pages->byDate('asc')->first()?->slug);

        $dateGroups = $pages->groupByDate('Y-m', 'asc');
        $this->assertSame(['2026-01', 'unknown'], array_keys($dateGroups));
    }

    /**
     * Cover protected helper branches through bound-closure invocation.
     */
    public function testCollectionProtectedHelpersCoverage(): void
    {
        $collection = new PageCollection([$this->makePage('x', '/x/', [], 'X')]);
        $resource = fopen('php://memory', 'rb');

        $normalizedPattern = $this->callProtected($collection, 'normalizeRegexPattern', '/x/');
        $invalidPattern = $this->callProtected($collection, 'normalizeRegexPattern', '(');
        $unknownGroup = $this->callProtected($collection, 'normalizeGroupKey', $resource);
        $safeMatch = $this->callProtected($collection, 'safePregMatch', '/x/', 'x');
        $safeNoMatch = $this->callProtected($collection, 'safePregMatch', '/x/', 'y');
        $safeInvalid = $this->callProtected($collection, 'safePregMatch', '(', 'x');
        $matchesInString = $this->callProtected($collection, 'matchesIn', 'alp', 'alpha');
        $matchesInFalse = $this->callProtected($collection, 'matchesIn', 1, 2);
        $intersectFalse = $this->callProtected($collection, 'matchesIntersect', 'nope', ['x']);
        $likeFalse = $this->callProtected($collection, 'matchesLike', 123, 'x');
        $emptyPattern = $this->callProtected($collection, 'normalizeRegexPattern', '   ');
        $timestampFromDate = $this->callProtected($collection, 'toTimestamp', new DateTimeImmutable('2026-01-01'));
        $timestampFromInt = $this->callProtected($collection, 'toTimestamp', 10);
        $timestampFromEmpty = $this->callProtected($collection, 'toTimestamp', '');
        $groupNull = $this->callProtected($collection, 'normalizeGroupKey', null);
        $groupScalar = $this->callProtected($collection, 'normalizeGroupKey', 12);
        $groupArray = $this->callProtected($collection, 'normalizeGroupKey', ['a', 2]);
        $sortableTimestamp = $this->callProtected($collection, 'sortableValue', '2026-01-01');
        $sortableNull = $this->callProtected($collection, 'sortableValue', null);
        $sortableBool = $this->callProtected($collection, 'sortableValue', true);
        $sortableArray = $this->callProtected($collection, 'sortableValue', ['a', 1]);
        $sortableUnknownResource = fopen('php://memory', 'rb');
        $sortableUnknown = $this->callProtected($collection, 'sortableValue', $sortableUnknownResource);
        $stringifiedObject = $this->callProtected($collection, 'stringifyValue', new class {
            public function __toString(): string
            {
                return 'obj';
            }
        });
        $stringifiedNull = $this->callProtected($collection, 'stringifyValue', null);
        $stringifiedUnknown = $this->callProtected($collection, 'stringifyValue', new class {
        });
        $stringifiedArray = $this->callProtected($collection, 'stringifyValue', ['a', 1, true]);
        $normalizedPages = $this->callProtected($collection, 'normalizeContentPages', [
            $this->makePage('a', '/a/', [], 'A'),
            'invalid',
        ]);
        $pageArray = $this->callProtected($collection, 'pageToArray', $this->makePage('z', '/z/', [], 'Z'));
        $weightPage = $this->makePage('w', '/w/', ['weight' => 7], 'Weight');
        $resolvedWeight = $this->callProtected($collection, 'resolveValue', $weightPage, 'weight');
        $taxonomyPage = new ContentPage(
            sourcePath: '/tmp/t.dj',
            relativePath: 't.dj',
            slug: 't',
            urlPath: '/t/',
            outputRelativePath: 't/index.html',
            title: 'Taxonomy',
            source: '# Taxonomy',
            draft: false,
            meta: [],
            taxonomies: ['tags' => ['php']],
        );
        $resolvedTaxonomyTags = $this->callProtected($collection, 'resolveValue', $taxonomyPage, 'tags');
        $resolvedMetaWeight = $this->callProtected($collection, 'resolveValue', $weightPage, 'meta.weight');
        $notEqual = $this->callProtected($collection, 'matchesWhere', 'a', 'ne', 'b');
        $nullCompared = $this->callProtected($collection, 'compareValues', null, 1);
        $dateCompared = $this->callProtected($collection, 'compareValues', '2026-01-01', '2026-01-02');
        $missingTypeCollection = new PageCollection([$this->makePage('none', '/none/', [], 'None')]);
        $missingTypeCount = $missingTypeCollection->whereType('blog')->count();
        if (is_resource($resource)) {
            fclose($resource);
        }

        if (is_resource($sortableUnknownResource)) {
            fclose($sortableUnknownResource);
        }

        $this->assertSame('/x/', $normalizedPattern);
        $this->assertSame('#(#u', $invalidPattern);
        $this->assertSame('unknown', $unknownGroup);
        $this->assertSame(1, $safeMatch);
        $this->assertSame(0, $safeNoMatch);
        $this->assertNull($safeInvalid);
        $this->assertTrue($matchesInString);
        $this->assertFalse($matchesInFalse);
        $this->assertFalse($intersectFalse);
        $this->assertFalse($likeFalse);
        $this->assertSame('/^$/', $emptyPattern);
        $this->assertIsInt($timestampFromDate);
        $this->assertSame(10, $timestampFromInt);
        $this->assertNull($timestampFromEmpty);
        $this->assertSame('null', $groupNull);
        $this->assertSame('12', $groupScalar);
        $this->assertSame('a, 2', $groupArray);
        $this->assertIsInt($sortableTimestamp);
        $this->assertSame('', $sortableNull);
        $this->assertSame(1, $sortableBool);
        $this->assertSame('a, 1', $sortableArray);
        $this->assertSame('', $sortableUnknown);
        $this->assertSame('obj', $stringifiedObject);
        $this->assertSame('', $stringifiedNull);
        $this->assertSame('', $stringifiedUnknown);
        $this->assertSame('a, 1, 1', $stringifiedArray);
        $this->assertIsArray($normalizedPages);
        $this->assertCount(1, $normalizedPages);
        $this->assertIsArray($pageArray);
        $this->assertSame('z', $pageArray['slug']);
        $this->assertSame(7, $resolvedWeight);
        $this->assertSame(['php'], $resolvedTaxonomyTags);
        $this->assertSame(7, $resolvedMetaWeight);
        $this->assertTrue($notEqual);
        $this->assertSame(1, $nullCompared);
        $this->assertLessThan(0, $dateCompared);
        $this->assertSame(0, $missingTypeCount);
    }

    /**
     * Create a content page object for test scenarios.
     *
     * @param string $slug Page slug.
     * @param string $urlPath Page URL path.
     * @param array<string, mixed> $meta Page metadata.
     * @param string $title Page title.
     */
    protected function makePage(string $slug, string $urlPath, array $meta, string $title, ?string $type = null): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/' . $slug . '.dj',
            relativePath: $slug . '.dj',
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: trim($slug, '/') . '/index.html',
            title: $title,
            source: '# ' . $title,
            draft: (bool)($meta['draft'] ?? false),
            meta: $meta,
            type: $type,
        );
    }

    /**
     * Invoke a protected method using scope-bound closure.
     *
     * @param object $object Object to invoke method on.
     * @param string $method Protected method name.
     * @param mixed ...$arguments Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$arguments): mixed
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
