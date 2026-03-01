<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Chronos\Chronos;
use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use Cake\Utility\Text;
use Glaze\Config\BuildConfig;
use Glaze\Config\BuildConfigFactory;
use Glaze\Scaffold\PageScaffoldOptions;
use Glaze\Scaffold\PageScaffoldService;
use Glaze\Utility\Normalization;
use Glaze\Utility\ProjectRootResolver;
use RuntimeException;
use Throwable;

/**
 * Create a new Djot content page with interactive prompts.
 */
final class NewCommand extends BaseCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Scaffold\PageScaffoldService $pageScaffoldService Page scaffold service.
     * @param \Glaze\Config\BuildConfigFactory $buildConfigFactory Build configuration factory.
     */
    public function __construct(
        protected PageScaffoldService $pageScaffoldService,
        protected BuildConfigFactory $buildConfigFactory,
    ) {
        parent::__construct();
    }

    /**
     * Get command description text.
     */
    public static function getDescription(): string
    {
        return 'Create a new content page with frontmatter prompts.';
    }

    /**
     * Configure command options and arguments.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Parser instance.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('title', [
                'help' => 'Page title.',
                'required' => false,
            ])
            ->addOption('root', [
                'help' => 'Project root directory containing content/.',
                'default' => null,
            ])
            ->addOption('title', [
                'help' => 'Page title (overrides positional title argument).',
                'default' => null,
            ])
            ->addOption('slug', [
                'help' => 'Optional page slug/path without extension, for example blog/my-post.',
                'default' => null,
            ])
            ->addOption('path', [
                'help' => 'Optional content subfolder prefix, for example posts/2026.',
                'default' => null,
            ])
            ->addOption('date', [
                'help' => 'Page date/datetime value parseable by Chronos. Defaults to current time.',
                'default' => null,
            ])
            ->addOption('weight', [
                'help' => 'Optional page sort weight (integer). Lower values sort first.',
                'default' => null,
            ])
            ->addOption('type', [
                'help' => 'Content type name, must exist in configured contentTypes.',
                'default' => null,
            ])
            ->addOption('draft', [
                'help' => 'Mark page as draft.',
                'boolean' => true,
                'default' => null,
            ])
            ->addOption('index', [
                'help' => 'Create page as a folder with index.dj (bundle-style content).',
                'boolean' => true,
                'default' => null,
            ])
            ->addOption('force', [
                'help' => 'Overwrite existing target file if it already exists.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('yes', [
                'help' => 'Skip interactive prompts and use provided/default values.',
                'boolean' => true,
                'default' => false,
            ]);

        return $parser;
    }

    /**
     * Execute new page command.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        try {
            $projectRoot = ProjectRootResolver::resolve($this->normalizeRootOption($args->getOption('root')));
            $config = $this->buildConfigFactory->fromProjectRoot($projectRoot, true);
            $input = $this->resolvePageInput($args, $io, $config);
            $targetPath = $this->pageScaffoldService->scaffold(
                $config->contentPath(),
                new PageScaffoldOptions(
                    title: $input['title'],
                    date: $input['date'],
                    type: $input['type'],
                    draft: $input['draft'],
                    slug: $input['slug'],
                    titleSlug: $this->slugifyPath($input['title']),
                    pathRule: $input['pathRule'],
                    pathPrefix: $input['pathPrefix'],
                    asIndex: $input['index'],
                    force: (bool)$args->getOption('force'),
                    weight: $input['weight'],
                ),
            );
        } catch (RuntimeException $runtimeException) {
            $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));

            return self::CODE_ERROR;
        }

        $io->out(sprintf('<success>created</success> %s', $this->relativeToRoot($targetPath, $config->projectRoot)));

        return self::CODE_SUCCESS;
    }

    /**
     * Resolve page input values from arguments, options, and prompts.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @return array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool, weight: int|null}
     */
    protected function resolvePageInput(Arguments $args, ConsoleIo $io, BuildConfig $config): array
    {
        $nonInteractive = (bool)$args->getOption('yes');
                $pathPrefix = Normalization::optionalPathFragment($args->getOption('path'));

        $slugInput = $this->normalizeString($args->getOption('slug'));
                $normalizedSlugInput = Normalization::optionalPathFragment($slugInput);

        $title = $this->normalizeString($args->getOption('title'))
            ?? $this->normalizeString($args->getArgument('title'));
        if ($title === null && $normalizedSlugInput !== null) {
            $title = $this->deriveTitleFromPath($normalizedSlugInput);
        }

        if (!$nonInteractive && $title === null) {
            $title = $this->normalizeString($io->ask('Post title'));
        }

        if ($title === null) {
            throw new RuntimeException('Page title is required.');
        }

        $dateInput = $this->normalizeString($args->getOption('date'));
        $dateDefault = Chronos::now()->toIso8601String();
        if (!$nonInteractive && $dateInput === null) {
            $dateInput = $this->normalizeString($io->ask('Date', $dateDefault));
        }

        $date = $this->normalizeDateInput($dateInput ?? $dateDefault);
        $weight = $this->normalizeWeightInput($args->getOption('weight'));

        $type = $this->resolveTypeInput($args, $io, $config->contentTypes, $nonInteractive);
        $pathRule = $this->resolveTypePathRule($io, $config->contentTypes, $type, $pathPrefix, $nonInteractive);
        if ($pathRule !== null) {
            $pathPrefix = null;
        }

        $draft = $this->resolveDraftInput($args, $io, $nonInteractive);
        $asIndex = $this->resolveIndexInput($args, $io, $nonInteractive);

        $slug = $this->slugifyPath($normalizedSlugInput ?? $title);

        return [
            'title' => $title,
            'date' => $date,
            'type' => $type,
            'draft' => $draft,
            'slug' => $slug,
            'pathRule' => $pathRule,
            'pathPrefix' => $pathPrefix,
            'index' => $asIndex,
            'weight' => $weight,
        ];
    }

    /**
     * Resolve concrete type path rule for new page placement.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param array<string, array{paths: array<mixed>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @param string|null $type Resolved content type.
     * @param string|null $selectedPath Selected path option.
     * @param bool $nonInteractive Whether prompts are disabled.
     * @return array{match: string, createPattern: string|null}|null
     */
    protected function resolveTypePathRule(
        ConsoleIo $io,
        array $contentTypes,
        ?string $type,
        ?string $selectedPath,
        bool $nonInteractive,
    ): ?array {
        if ($type === null) {
            return null;
        }

        $rules = $this->pageScaffoldService->typePathRules($contentTypes[$type]['paths'] ?? []);
        if ($rules === []) {
            return null;
        }

        if ($selectedPath !== null) {
            $rule = $this->pageScaffoldService->findPathRuleByMatch($rules, $selectedPath);
            if ($rule === null) {
                throw new RuntimeException(sprintf(
                    'Unknown path "%s" for type "%s". Available paths: %s',
                    $selectedPath,
                    $type,
                    implode(', ', array_map(static fn(array $entry): string => $entry['match'], $rules)),
                ));
            }

            return $rule;
        }

        if (count($rules) === 1) {
            return $rules[0];
        }

        if ($nonInteractive) {
            throw new RuntimeException(sprintf(
                'Type "%s" has multiple paths. Use --path with one of: %s',
                $type,
                implode(', ', array_map(static fn(array $entry): string => $entry['match'], $rules)),
            ));
        }

        $choices = array_map(static fn(array $entry): string => $entry['match'], $rules);
        $selected = strtolower($io->askChoice('Path', $choices, $choices[0] ?? null));
        $rule = $this->pageScaffoldService->findPathRuleByMatch($rules, $selected);
        if ($rule !== null) {
            return $rule;
        }

        return $rules[0];
    }

    /**
     * Resolve type from option/prompt when content types are configured.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @param bool $nonInteractive Whether prompts are disabled.
     */
    protected function resolveTypeInput(
        Arguments $args,
        ConsoleIo $io,
        array $contentTypes,
        bool $nonInteractive,
    ): ?string {
        $typeInput = $this->normalizeString($args->getOption('type'));

        if ($contentTypes === []) {
            return $this->normalizeTypeInput($typeInput, $contentTypes);
        }

        if ($typeInput === null && !$nonInteractive) {
            $typeInput = $this->promptType($io, $contentTypes);
        }

        if ($typeInput === null) {
            throw new RuntimeException('A content type is required when contentTypes are configured. Use --type.');
        }

        return $this->normalizeTypeInput($typeInput, $contentTypes);
    }

    /**
     * Prompt for optional type value.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     */
    protected function promptType(ConsoleIo $io, array $contentTypes): ?string
    {
        if ($contentTypes === []) {
            return null;
        }

        $choices = array_keys($contentTypes);
        $default = $choices[0] ?? null;

        return strtolower($io->askChoice('Type', $choices, $default));
    }

    /**
     * Resolve index-bundle mode from option or prompt.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param bool $nonInteractive Whether prompts are disabled.
     */
    protected function resolveIndexInput(Arguments $args, ConsoleIo $io, bool $nonInteractive): bool
    {
        $indexOption = $args->getOption('index');

        if ($nonInteractive) {
            return is_bool($indexOption) && $indexOption;
        }

        $default = is_bool($indexOption) && $indexOption ? 'yes' : 'no';
        $choice = strtolower($io->askChoice('Create post folder with index.dj', ['no', 'yes'], $default));

        return $choice === 'yes';
    }

    /**
     * Resolve draft value from option or prompt.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param bool $nonInteractive Whether prompts are disabled.
     */
    protected function resolveDraftInput(Arguments $args, ConsoleIo $io, bool $nonInteractive): bool
    {
        $draftOption = $args->getOption('draft');
        if (is_bool($draftOption)) {
            return $draftOption;
        }

        if ($nonInteractive) {
            return false;
        }

        $draftChoice = strtolower($io->askChoice('Draft', ['no', 'yes'], 'no'));

        return $draftChoice === 'yes';
    }

    /**
     * Normalize date input to a strict ISO-8601 string.
     *
     * @param string $dateInput Raw date input.
     */
    protected function normalizeDateInput(string $dateInput): string
    {
        try {
            return Chronos::parse($dateInput)->toIso8601String();
        } catch (Throwable) {
            throw new RuntimeException('Invalid date value. Use a date/datetime parseable by Chronos.');
        }
    }

    /**
     * Normalize optional weight input to an integer value.
     *
     * @param mixed $weightInput Raw weight input.
     */
    protected function normalizeWeightInput(mixed $weightInput): ?int
    {
        $normalized = $this->normalizeString($weightInput);
        if ($normalized === null) {
            return null;
        }

        $weight = filter_var($normalized, FILTER_VALIDATE_INT);
        if (!is_int($weight)) {
            throw new RuntimeException('Invalid weight value. Use an integer.');
        }

        return $weight;
    }

    /**
     * Normalize and validate optional type input against configured content types.
     *
     * @param string|null $typeInput Raw type input.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     */
    protected function normalizeTypeInput(?string $typeInput, array $contentTypes): ?string
    {
        $normalized = $this->normalizeString($typeInput);
        if ($normalized === null) {
            return null;
        }

        $type = strtolower($normalized);
        if ($contentTypes === []) {
            throw new RuntimeException('No content types are configured in glaze.neon.');
        }

        if (!isset($contentTypes[$type])) {
            throw new RuntimeException(sprintf(
                'Unknown content type "%s". Available types: %s',
                $normalized,
                implode(', ', array_keys($contentTypes)),
            ));
        }

        return $type;
    }

    /**
     * Convert absolute path to root-relative display path.
     *
     * @param string $path Absolute file path.
     * @param string $rootPath Project root path.
     */
    protected function relativeToRoot(string $path, string $rootPath): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        if (str_starts_with($normalizedPath, $normalizedRootPath . '/')) {
            return substr($normalizedPath, strlen($normalizedRootPath) + 1);
        }

        return $path;
    }

    /**
     * Normalize optional string values.
     *
     * @param mixed $value Raw value.
     */
    protected function normalizeString(mixed $value): ?string
    {
        return Normalization::optionalString($value);
    }

    /**
     * Normalize optional root option values.
     *
     * @param mixed $rootOption Raw root option value.
     */
    protected function normalizeRootOption(mixed $rootOption): ?string
    {
        return Normalization::optionalPath($rootOption);
    }

    /**
     * Slugify title or path-like input.
     *
     * @param string $value Raw input value.
     */
    protected function slugifyPath(string $value): string
    {
        $segments = array_filter(
            explode('/', trim(str_replace('\\', '/', $value), '/')),
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
     * Derive a human-readable title from a slug/path value.
     *
     * @param string $path Raw path value.
     */
    protected function deriveTitleFromPath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $last = end($segments);

        return Inflector::humanize(str_replace('-', '_', $last));
    }
}
