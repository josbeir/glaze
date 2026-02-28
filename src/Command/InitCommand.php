<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use Glaze\Process\NpmInstallProcess;
use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Scaffold\ScaffoldOptions;
use Glaze\Utility\Normalization;
use RuntimeException;

/**
 * Initialize a new Glaze project directory using a named scaffold preset.
 */
final class InitCommand extends AbstractGlazeCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Scaffold\ProjectScaffoldService $scaffoldService Scaffold service.
     * @param \Glaze\Process\NpmInstallProcess $npmInstallProcess NPM install process.
     */
    public function __construct(
        protected ProjectScaffoldService $scaffoldService,
        protected NpmInstallProcess $npmInstallProcess,
    ) {
        parent::__construct();
    }

    /**
     * Get command description text.
     */
    public static function getDescription(): string
    {
        return 'Create a new Glaze project with starter content and templates.';
    }

    /**
     * Configure command options and arguments.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Parser instance.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('directory', [
                'help' => 'Target project directory to create.',
                'required' => false,
            ])
            ->addOption('name', [
                'help' => 'Site name used for defaults.',
                'default' => null,
            ])
            ->addOption('title', [
                'help' => 'Site title.',
                'default' => null,
            ])
            ->addOption('description', [
                'help' => 'Default site description.',
                'default' => null,
            ])
            ->addOption('page-template', [
                'help' => 'Default page template name.',
                'default' => null,
            ])
            ->addOption('base-url', [
                'help' => 'Site base URL.',
                'default' => null,
            ])
            ->addOption('base-path', [
                'help' => 'Site base path for subfolder deployments, for example /docs.',
                'default' => null,
            ])
            ->addOption('taxonomies', [
                'help' => 'Comma-separated taxonomy keys.',
                'default' => null,
            ])
            ->addOption('preset', [
                'help' => 'Scaffold preset name (e.g. default, vite) or path to a custom preset directory.',
                'default' => null,
            ])
            ->addOption('skip-install', [
                'help' => 'Skip running npm install when the preset generates a package.json.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('force', [
                'help' => 'Allow scaffolding into a non-empty directory.',
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
     * Execute the scaffold command.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $this->renderVersionHeader($io);

        try {
            $options = $this->resolveScaffoldOptions($args, $io);
            $createdFiles = $this->scaffoldService->scaffold($options);

            $packageJsonPath = $options->targetDirectory . DIRECTORY_SEPARATOR . 'package.json';
            if (in_array($packageJsonPath, $createdFiles, true) && !(bool)$args->getOption('skip-install')) {
                $io->out('<info>running</info> npm install...');
                $this->npmInstallProcess->start([], $options->targetDirectory);
            }
        } catch (RuntimeException $runtimeException) {
            $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));

            return self::CODE_ERROR;
        }

        $io->out(sprintf('<success>created</success> %s', $options->targetDirectory));

        return self::CODE_SUCCESS;
    }

    /**
     * Resolve scaffold options from arguments and optional prompts.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    protected function resolveScaffoldOptions(Arguments $args, ConsoleIo $io): ScaffoldOptions
    {
        $nonInteractive = (bool)$args->getOption('yes');

        $directory = $this->normalizeString($args->getArgument('directory'));
        $siteName = $this->normalizeString($args->getOption('name'));

        if ($directory === null && $siteName !== null) {
            $directory = $siteName;
        }

        if ($directory !== null && $siteName === null) {
            $siteName = basename($directory);
        }

        if (!$nonInteractive) {
            if ($directory === null) {
                $directory = $io->ask('Project directory', 'my-glaze-site');
            }

            $siteName = $io->ask('Site name', $siteName ?? basename($directory));
        }

        if ($directory === null || trim($directory) === '') {
            throw new RuntimeException('Project directory is required.');
        }

        if ($siteName === null || trim($siteName) === '') {
            throw new RuntimeException('Site name is required.');
        }

        $titleDefault = $this->normalizeString($args->getOption('title')) ?? $this->defaultTitle($siteName);
        $pageTemplateDefault = $this->normalizeTemplateName($this->normalizeString($args->getOption('page-template')));
        $descriptionDefault = $this->normalizeString($args->getOption('description')) ?? '';
        $baseUrlDefault = $this->normalizeString($args->getOption('base-url'));
        $basePathDefault = $this->normalizeBasePath($this->normalizeString($args->getOption('base-path')));
        $taxonomiesDefault = $this->normalizeString($args->getOption('taxonomies')) ?? 'tags,categories';

        if (!$nonInteractive) {
            $titleDefault = $io->ask('Site title', $titleDefault);
            $descriptionDefault = $io->ask('Default description', $descriptionDefault);
            $baseUrlDefault = $io->ask('Base URL (optional)', $baseUrlDefault ?? '');
            $basePathDefault = $this->normalizeBasePath($io->ask('Base path (optional)', $basePathDefault ?? ''));
            $taxonomiesDefault = $io->ask('Taxonomies (comma separated)', $taxonomiesDefault);
        }

        $preset = $this->resolvePreset($args, $io, $nonInteractive);

        $directoryPath = Normalization::path($directory);
        if (!$this->isAbsolutePath($directoryPath)) {
            $currentDirectory = getcwd() ?: '.';
            $directoryPath = Normalization::path($currentDirectory . DIRECTORY_SEPARATOR . $directoryPath);
        }

        return new ScaffoldOptions(
            targetDirectory: $directoryPath,
            siteName: $siteName,
            siteTitle: $titleDefault,
            pageTemplate: $pageTemplateDefault,
            description: $descriptionDefault,
            baseUrl: $this->normalizeString($baseUrlDefault),
            basePath: $basePathDefault,
            taxonomies: $this->parseTaxonomies($taxonomiesDefault),
            preset: $preset,
            force: (bool)$args->getOption('force'),
        );
    }

    /**
     * Resolve the scaffold preset from CLI flags and interactive prompt.
     *
     * If the preset value contains a path separator it is treated as a directory path.
     * Relative paths are resolved to absolute using the current working directory.
     * Path-based presets skip the interactive choice; they must be passed explicitly
     * via `--preset`.
     *
     * Falls back to `default` when no preset is specified.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param bool $nonInteractive Whether prompts are disabled.
     */
    protected function resolvePreset(Arguments $args, ConsoleIo $io, bool $nonInteractive): string
    {
        $preset = $this->normalizeString($args->getOption('preset')) ?? 'default';

        if ($this->isPresetPath($preset)) {
            return $this->resolvePresetAbsolutePath($preset);
        }

        if ($nonInteractive) {
            return $preset;
        }

        $available = $this->scaffoldService->presetNames();
        if ($available === [] || $available === ['default']) {
            return $preset;
        }

        return strtolower($io->askChoice('Scaffold preset', $available, $preset));
    }

    /**
     * Determine whether a preset value should be treated as a directory path.
     *
     * @param string $preset Preset name or path.
     */
    protected function isPresetPath(string $preset): bool
    {
        return str_contains($preset, '/') || str_contains($preset, '\\');
    }

    /**
     * Resolve a preset path expression to an absolute directory path.
     *
     * Absolute paths are returned as-is. Relative paths are resolved against
     * the current working directory.
     *
     * @param string $preset Preset path (absolute or relative).
     */
    protected function resolvePresetAbsolutePath(string $preset): string
    {
        if ($this->isAbsolutePath($preset)) {
            return $preset;
        }

        $cwd = getcwd() ?: '.';

        return Normalization::path($cwd . DIRECTORY_SEPARATOR . $preset);
    }

    /**
     * Parse comma-separated taxonomy input.
     *
     * @param string $input Raw taxonomy input.
     * @return array<string>
     */
    protected function parseTaxonomies(string $input): array
    {
        $parts = preg_split('/\s*,\s*/', trim($input));
        if (!is_array($parts)) {
            return ['tags', 'categories'];
        }

        $normalized = [];
        foreach ($parts as $part) {
            $taxonomy = strtolower(trim($part));
            if ($taxonomy === '') {
                continue;
            }

            $normalized[] = $taxonomy;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? ['tags', 'categories'] : $normalized;
    }

    /**
     * Build a default title from the provided site name.
     *
     * @param string $siteName Site name value.
     */
    protected function defaultTitle(string $siteName): string
    {
        return Inflector::humanize(str_replace('-', '_', $siteName));
    }

    /**
     * Normalize optional string values.
     *
     * @param mixed $value Raw value.
     */
    protected function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize optional base path values for config output.
     *
     * @param string|null $basePath Raw base path value.
     */
    protected function normalizeBasePath(?string $basePath): ?string
    {
        if ($basePath === null) {
            return null;
        }

        $trimmed = trim($basePath);
        if ($trimmed === '' || $trimmed === '/') {
            return null;
        }

        $normalized = '/' . trim($trimmed, '/');

        return $normalized === '/' ? null : $normalized;
    }

    /**
     * Normalize optional template name values.
     *
     * @param string|null $template Raw template value.
     */
    protected function normalizeTemplateName(?string $template): string
    {
        if ($template === null) {
            return 'page';
        }

        $normalized = trim($template);

        return $normalized === '' ? 'page' : $normalized;
    }

    /**
     * Detect absolute path values on Unix and Windows.
     *
     * @param string $path Path to inspect.
     */
    protected function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }
}
