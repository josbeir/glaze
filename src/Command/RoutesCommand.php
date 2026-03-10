<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Build\TaxonomyPageFactory;
use Glaze\Command\Helper\TableHelper;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Content\ContentPage;
use Glaze\Content\LocalizedContentDiscovery;
use Glaze\Utility\Path;
use Glaze\Utility\ProjectRootResolver;
use Throwable;

/**
 * Display all discovered content routes in a formatted table.
 *
 * Runs content discovery and taxonomy page generation without performing a
 * full build. Outputs a table of every discoverable URL, its content type,
 * effective page template, and source file. Draft pages and pages whose
 * source path is excluded by content discovery rules are listed with a flag.
 *
 * When i18n is enabled in `glaze.neon`, all language trees are discovered and
 * a `Language` column is added to the table. Use `--lang` to restrict output
 * to one or more specific language codes.
 *
 * Extensions (sitemap, rss, llms-txt, etc.) add virtual routes during the
 * build pipeline; those are not shown here since extensions are not invoked.
 *
 * Usage:
 * ```
 * glaze routes
 * glaze routes --type blog
 * glaze routes --lang nl
 * glaze routes --drafts
 * ```
 */
final class RoutesCommand extends BaseCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService $discoveryService Content discovery service.
     * @param \Glaze\Build\TaxonomyPageFactory $taxonomyPageFactory Factory for auto-generated taxonomy pages.
     * @param \Glaze\Content\LocalizedContentDiscovery $localizedDiscovery Localization-aware discovery coordinator.
     */
    public function __construct(
        protected ContentDiscoveryService $discoveryService,
        protected TaxonomyPageFactory $taxonomyPageFactory,
        protected ?LocalizedContentDiscovery $localizedDiscovery = null,
    ) {
        parent::__construct();
    }

    /**
     * Get command description text.
     */
    public static function getDescription(): string
    {
        return 'List all discovered content routes in a table.';
    }

    /**
     * Configure command options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Parser instance.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addOption('root', [
                'help' => 'Project root directory containing content/ and glaze.neon.',
                'default' => null,
            ])
            ->addOption('type', [
                'help' => 'Filter by content type(s). Accepts comma-separated values, e.g. "post,blog".',
                'default' => null,
            ])
            ->addOption('taxonomy', [
                'help' => 'Filter by taxonomy key or key=value. Accepts comma-separated filters (e.g. tag=php).',
                'default' => null,
            ])
            ->addOption('lang', [
                'help' => 'Filter by language code(s). Comma-separated, e.g. "en,nl". Only for i18n sites.',
                'default' => null,
            ])
            ->addOption('truncate', [
                'help' => 'Truncate cell values to this visible width (0 = no limit).',
                'default' => '60',
            ])
            ->addOption('drafts', [
                'help' => 'Include draft pages.',
                'boolean' => true,
                'default' => false,
            ]);

        return $parser;
    }

    /**
     * Execute routes listing command.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        try {
            $projectRoot = ProjectRootResolver::resolve(Path::optional($args->getOption('root')));
            $config = BuildConfig::fromProjectRoot($projectRoot, (bool)$args->getOption('drafts'));
        } catch (Throwable $throwable) {
            $io->err(sprintf('<error>%s</error>', $throwable->getMessage()));

            return self::CODE_ERROR;
        }

        $contentPath = $config->contentPath();
        if (!is_dir($contentPath)) {
            $io->err(sprintf('<error>Content directory not found: %s</error>', $contentPath));

            return self::CODE_ERROR;
        }

        $pages = $this->localizedDiscovery($config)->discover($config);

        $realPages = array_values(array_filter($pages, static fn(ContentPage $p): bool => !$p->virtual));
        $taxonomyPages = $this->taxonomyPageFactory->generate($realPages, $config->taxonomies);

        $allPages = array_merge($pages, $taxonomyPages);

        if (!(bool)$args->getOption('drafts')) {
            $allPages = array_values(
                array_filter($allPages, static fn(ContentPage $p): bool => !$p->draft),
            );
        }

        $filterTypes = $this->parseCommaSeparated((string)($args->getOption('type') ?? ''));
        if ($filterTypes !== []) {
            $allPages = array_values(
                array_filter($allPages, static fn(ContentPage $p): bool => in_array($p->type, $filterTypes, true)),
            );
        }

        $filterTaxonomies = $this->parseTaxonomyFilters((string)($args->getOption('taxonomy') ?? ''));
        foreach ($filterTaxonomies as $taxKey => $taxValues) {
            $allPages = array_values(
                array_filter(
                    $allPages,
                    static function (ContentPage $p) use ($taxKey, $taxValues): bool {
                        $pageValues = $p->taxonomies[$taxKey] ?? [];
                        if ($taxValues === []) {
                            return $pageValues !== [];
                        }

                        foreach ($taxValues as $required) {
                            if (in_array($required, $pageValues, true)) {
                                return true;
                            }
                        }

                        return false;
                    },
                ),
            );
        }

        $filterLanguages = $this->parseCommaSeparated((string)($args->getOption('lang') ?? ''));
        if ($filterLanguages !== []) {
            $allPages = array_values(
                array_filter(
                    $allPages,
                    static fn(ContentPage $p): bool => in_array($p->language, $filterLanguages, true),
                ),
            );
        }

        usort($allPages, static fn(ContentPage $a, ContentPage $b): int => strcmp($a->urlPath, $b->urlPath));

        $showLanguage = array_filter($allPages, static fn(ContentPage $p): bool => $p->language !== '') !== [];
        $rows = $this->buildTableRows($allPages, $config, $showLanguage);

        if ($rows === []) {
            $io->out('<info>No routes found.</info>');

            return self::CODE_SUCCESS;
        }

        $header = ['URL Path', 'Type', 'Template', 'Source', 'Flags'];
        if ($showLanguage) {
            array_splice($header, 1, 0, ['Language']);
        }

        $truncate = max(0, (int)($args->getOption('truncate') ?? 60));
        $table = new TableHelper($io, ['maxWidth' => $truncate]);
        $table->output(array_merge([$header], $rows));

        $io->out('');
        $io->out($this->formatSummary($allPages, $filterTypes, $filterTaxonomies, $filterLanguages, $showLanguage));

        return self::CODE_SUCCESS;
    }

    /**
     * Build table rows from a list of content pages.
     *
     * Each row contains the URL path, optional language code, content type,
     * effective page template, source file relative to the content directory,
     * and a string of applicable flags (`draft`, `unlisted`).
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages to render.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param bool $showLanguage Whether to include the Language column.
     * @return array<array<string>> Table rows (without header).
     */
    protected function buildTableRows(array $pages, BuildConfig $config, bool $showLanguage = false): array
    {
        $rows = [];
        foreach ($pages as $page) {
            $flags = $this->resolveFlags($page);
            $type = $page->type !== null ? sprintf('<info>%s</info>', $page->type) : '-';
            $template = $this->resolveTemplate($page, $config);
            $source = $this->resolveSource($page, $config);

            $row = [$page->urlPath];

            if ($showLanguage) {
                $row[] = $page->language !== '' ? $page->language : '-';
            }

            $row[] = $type;
            $row[] = $template;
            $row[] = $source;
            $row[] = $flags;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Resolve a comma-separated flags string for a page.
     *
     * Returns a `<comment>`-styled string listing `draft` and/or `unlisted`
     * when applicable, or an empty string for pages with no flags.
     *
     * @param \Glaze\Content\ContentPage $page Page to inspect.
     */
    protected function resolveFlags(ContentPage $page): string
    {
        $flags = [];
        if ($page->draft) {
            $flags[] = 'draft';
        }

        if ($page->unlisted) {
            $flags[] = 'unlisted';
        }

        if ($flags === []) {
            return '';
        }

        return sprintf('<comment>%s</comment>', implode(', ', $flags));
    }

    /**
     * Resolve the effective page template name for display.
     *
     * Reads `template` from page meta first, then falls back to the configured
     * default page template. Taxonomy-generated pages (no source path) return `-`.
     *
     * @param \Glaze\Content\ContentPage $page Page to resolve template for.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function resolveTemplate(ContentPage $page, BuildConfig $config): string
    {
        if ($page->sourcePath === '') {
            return '-';
        }

        $template = $page->meta['template'] ?? null;
        if (!is_string($template) || trim($template) === '') {
            return $config->pageTemplate;
        }

        return trim($template);
    }

    /**
     * Resolve the source file path relative to its content directory.
     *
     * Tries each known content base path (the default project content path plus
     * any per-language `contentDir` values) and returns the shortest matching
     * relative path. Taxonomy-generated pages (no source path) return `-`.
     *
     * @param \Glaze\Content\ContentPage $page Page to resolve source for.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function resolveSource(ContentPage $page, BuildConfig $config): string
    {
        if ($page->sourcePath === '') {
            return '-';
        }

        $sourcePath = str_replace('\\', '/', $page->sourcePath);

        foreach ($this->resolveContentBasePaths($config) as $base) {
            if (str_starts_with($sourcePath, $base)) {
                return substr($sourcePath, strlen($base));
            }
        }

        return $page->relativePath;
    }

    /**
     * Build the list of all known content base paths for source resolution.
     *
     * Includes the default project content path and, when i18n is enabled,
     * each language's absolute `contentDir` (resolved relative to the project
     * root). Each path is normalised to a trailing slash for prefix matching.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @return list<string>
     */
    protected function resolveContentBasePaths(BuildConfig $config): array
    {
        $paths = [rtrim(str_replace('\\', '/', $config->contentPath()), '/') . '/'];

        foreach ($config->i18n->languages as $langConfig) {
            if ($langConfig->contentDir === null) {
                continue;
            }

            $abs = rtrim(str_replace('\\', '/', $config->projectRoot), '/') . '/'
                . ltrim(str_replace('\\', '/', $langConfig->contentDir), '/') . '/';

            if (!in_array($abs, $paths, true)) {
                $paths[] = $abs;
            }
        }

        // Sort longest (most specific) first so subdirectory paths are matched
        // before their parent directories.
        usort($paths, static fn(string $a, string $b): int => strlen($b) - strlen($a));

        return $paths;
    }

    /**
     * Parse a comma-separated option value into a normalized lowercase list.
     *
     * Empty string and blank tokens are ignored. Each token is lowercased and trimmed.
     *
     * @param string $raw Raw option value (e.g. `"post,blog"`).
     * @return list<string>
     */
    protected function parseCommaSeparated(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', $raw)),
                static fn(string $v): bool => $v !== '',
            ),
        );
    }

    /**
     * Parse a comma-separated taxonomy filter string into a key→values map.
     *
     * Each token is either `key=value` (match specific value) or `key` (any value
     * present). Multiple tokens may share the same key; their values are merged.
     *
     * Examples:
     * - `"tag=php,tag=mysql"` → `['tag' => ['php', 'mysql']]`
     * - `"tag"` → `['tag' => []]`
     * - `"tag=php,category=tutorial"` → `['tag' => ['php'], 'category' => ['tutorial']]`
     *
     * @param string $raw Raw option value.
     * @return array<string, list<string>>
     */
    protected function parseTaxonomyFilters(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $filters = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (str_contains($token, '=')) {
                [$key, $value] = explode('=', $token, 2);
                $key = strtolower(trim($key));
                $value = trim($value);
                if ($key !== '') {
                    $filters[$key][] = $value;
                }
            } else {
                $key = strtolower($token);
                if (!isset($filters[$key])) {
                    $filters[$key] = [];
                }
            }
        }

        return $filters;
    }

    /**
     * Build a one-line summary string for the routes listing.
     *
     * Reports total page count with a per-type breakdown when multiple types
     * are present. When i18n is active, includes a per-language count.
     * Appends notes for any active type, language, or taxonomy filters.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages that were displayed.
     * @param list<string> $filterTypes Active type filters (empty = none).
     * @param array<string, list<string>> $filterTaxonomies Active taxonomy filters (empty = none).
     * @param list<string> $filterLanguages Active language filters (empty = none).
     * @param bool $showLanguage Whether i18n is active for the current result set.
     */
    protected function formatSummary(
        array $pages,
        array $filterTypes,
        array $filterTaxonomies,
        array $filterLanguages = [],
        bool $showLanguage = false,
    ): string {
        $total = count($pages);

        $byType = [];
        foreach ($pages as $page) {
            if ($page->type !== null) {
                $byType[$page->type] = ($byType[$page->type] ?? 0) + 1;
            }
        }

        $summary = sprintf('<info>%d route(s)</info>', $total);

        if ($byType !== []) {
            $typeParts = [];
            foreach ($byType as $type => $count) {
                $typeParts[] = sprintf('%s: %d', $type, $count);
            }

            $summary .= sprintf(' (%s)', implode(', ', $typeParts));
        }

        if ($showLanguage) {
            $byLang = [];
            foreach ($pages as $page) {
                if ($page->language !== '') {
                    $byLang[$page->language] = ($byLang[$page->language] ?? 0) + 1;
                }
            }

            if ($byLang !== []) {
                $langParts = [];
                foreach ($byLang as $lang => $count) {
                    $langParts[] = sprintf('%s: %d', $lang, $count);
                }

                $summary .= sprintf(' · <info>%s</info>', implode(', ', $langParts));
            }
        }

        if ($filterTypes !== []) {
            $summary .= sprintf(' · type: <info>%s</info>', implode(', ', $filterTypes));
        }

        if ($filterLanguages !== []) {
            $summary .= sprintf(' · lang: <info>%s</info>', implode(', ', $filterLanguages));
        }

        if ($filterTaxonomies !== []) {
            $taxParts = [];
            foreach ($filterTaxonomies as $key => $values) {
                if ($values === []) {
                    $taxParts[] = $key;
                } else {
                    $taxParts[] = sprintf('%s=%s', $key, implode('|', $values));
                }
            }

            $summary .= sprintf(' · taxonomy: <info>%s</info>', implode(', ', $taxParts));
        }

        return $summary;
    }

    /**
     * Return the localized discovery coordinator, constructing one lazily if not injected.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration (used for the lazy fallback).
     */
    protected function localizedDiscovery(BuildConfig $config): LocalizedContentDiscovery
    {
        if ($this->localizedDiscovery instanceof LocalizedContentDiscovery) {
            return $this->localizedDiscovery;
        }

        return new LocalizedContentDiscovery($this->discoveryService);
    }
}
