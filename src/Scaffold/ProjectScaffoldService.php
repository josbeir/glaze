<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use Glaze\Config\ProjectConfigurationReader;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * Creates a new Glaze project directory from a scaffold preset definition.
 *
 * The service loads a `ScaffoldSchema` from the registry using the preset name
 * declared in `ScaffoldOptions`, then processes each file entry defined by the
 * schema. Static files are copied directly; template files (`.tpl`) are rendered
 * via `TemplateRenderer` with variables derived from the options.
 *
 * A `glaze.neon` configuration file is always generated from the user-supplied
 * options and is deep-merged with any extra `config` block defined in the schema,
 * allowing presets to contribute additional configuration sections (e.g., Vite
 * build settings).
 *
 * Example:
 * ```php
 * $service = new ProjectScaffoldService($registry, $renderer);
 * $created = $service->scaffold($options); // returns absolute paths of created files
 * ```
 */
final class ProjectScaffoldService
{
    /**
     * Constructor.
     *
     * @param \Glaze\Scaffold\ScaffoldRegistry $registry Scaffold preset registry.
     * @param \Glaze\Scaffold\TemplateRenderer $renderer Template variable renderer.
     * @param \Glaze\Config\ProjectConfigurationReader $projectConfigurationReader Project configuration reader.
     */
    public function __construct(
        protected readonly ScaffoldRegistry $registry,
        protected readonly TemplateRenderer $renderer,
        protected readonly ProjectConfigurationReader $projectConfigurationReader = new ProjectConfigurationReader(),
    ) {
    }

    /**
     * Scaffold a new project into the target directory.
     *
     * Loads the preset schema from the registry, guards the target directory,
     * processes all file entries (copy or render), and generates `glaze.neon`.
     * Returns the absolute paths of all files written to disk.
     *
     * @param \Glaze\Scaffold\ScaffoldOptions $options Scaffold options.
     * @return list<string> Absolute paths of all files written during scaffolding.
     * @throws \RuntimeException If the target directory is non-empty and force is not set,
     *   or if any file operation fails.
     */
    public function scaffold(ScaffoldOptions $options): array
    {
        $schema = $this->registry->get($options->preset);

        $this->guardTargetDirectory($options->targetDirectory, $options->force);
        $this->ensureDirectory($options->targetDirectory);

        $variables = $this->buildTemplateVariables($options);
        $created = [];

        foreach ($schema->files as $fileEntry) {
            $destinationPath = $this->resolveDestinationPath(
                $options->targetDirectory,
                $fileEntry->destination,
            );
            $this->ensureDirectory(dirname($destinationPath));

            if ($fileEntry->isTemplate) {
                $source = $this->readFile($fileEntry->absoluteSource);
                $content = $this->renderer->render($source, $variables);
                $this->writeFile($destinationPath, $content);
            } else {
                $this->copyFile($fileEntry->absoluteSource, $destinationPath);
            }

            $created[] = $destinationPath;
        }

        $glazeNeonPath = $options->targetDirectory . DIRECTORY_SEPARATOR . 'glaze.neon';
        $this->writeFile($glazeNeonPath, $this->buildGlazeNeon($options, $schema));
        $created[] = $glazeNeonPath;

        return $created;
    }

    /**
     * Return the names of all available scaffold presets.
     *
     * @return list<string>
     */
    public function presetNames(): array
    {
        return $this->registry->names();
    }

    /**
     * Return all available scaffold presets as a name-to-description map.
     *
     * @return array<string, string>
     */
    public function presets(): array
    {
        return $this->registry->presets();
    }

    /**
     * Guard the target directory state before scaffold writes.
     *
     * Throws when the directory is non-empty and force is not enabled.
     *
     * @param string $targetDirectory Target project directory path.
     * @param bool $force Whether writes can overwrite existing files.
     * @throws \RuntimeException If the directory is non-empty and force is false.
     */
    protected function guardTargetDirectory(string $targetDirectory, bool $force): void
    {
        if (!is_dir($targetDirectory)) {
            return;
        }

        $entries = scandir($targetDirectory);
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Unable to inspect target directory "%s".', $targetDirectory));
        }

        $visible = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));

        if ($visible === [] || $force) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Target directory "%s" is not empty. Use --force to continue.',
            $targetDirectory,
        ));
    }

    /**
     * Ensure a directory exists, creating it recursively if necessary.
     *
     * @param string $directory Absolute directory path.
     * @throws \RuntimeException If the directory cannot be created.
     */
    protected function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
    }

    /**
     * Resolve an absolute destination file path from the target directory and a relative path.
     *
     * @param string $targetDirectory Absolute project target directory.
     * @param string $relativePath Relative file path (forward-slash separated).
     */
    protected function resolveDestinationPath(string $targetDirectory, string $relativePath): string
    {
        return rtrim($targetDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    }

    /**
     * Copy a source file to a destination path.
     *
     * @param string $source Absolute source path.
     * @param string $destination Absolute destination path.
     * @throws \RuntimeException If the copy fails.
     */
    protected function copyFile(string $source, string $destination): void
    {
        if (!copy($source, $destination)) {
            throw new RuntimeException(sprintf('Unable to copy file "%s" to "%s".', $source, $destination));
        }
    }

    /**
     * Read a file from disk and return its content as a string.
     *
     * @param string $path Absolute file path.
     * @throws \RuntimeException If the file cannot be read.
     */
    protected function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read file "%s".', $path));
        }

        return $content;
    }

    /**
     * Write content to a file on disk.
     *
     * @param string $path Absolute file path.
     * @param string $content File content to write.
     * @throws \RuntimeException If the write fails.
     */
    protected function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    /**
     * Build the template variable map from scaffold options.
     *
     * Provides plain string variables for use in NEON/text templates as well as
     * JSON-encoded variants (`*Json`) for inclusion directly inside JSON templates
     * without manual escaping.
     *
     * @param \Glaze\Scaffold\ScaffoldOptions $options Scaffold options.
     * @return array<string, string>
     */
    protected function buildTemplateVariables(ScaffoldOptions $options): array
    {
        $siteDescription = trim($options->description) !== ''
            ? $options->description
            : $options->siteTitle;

        return [
            'siteName' => $options->siteName,
            'siteTitle' => $options->siteTitle,
            'pageTemplate' => $options->pageTemplate,
            'description' => $options->description,
            'siteNameJson' => json_encode($options->siteName, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'siteDescriptionJson' => json_encode($siteDescription, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Generate the `glaze.neon` configuration file content.
     *
     * Builds the base configuration from the scaffold options, deep-merges any
     * additional config contributed by the schema preset, and appends a commented
     * reference block listing all available configuration options.
     *
     * @param \Glaze\Scaffold\ScaffoldOptions $options Scaffold options.
     * @param \Glaze\Scaffold\ScaffoldSchema $schema Resolved scaffold schema.
     */
    protected function buildGlazeNeon(ScaffoldOptions $options, ScaffoldSchema $schema): string
    {
        $siteConfig = ['title' => $options->siteTitle];

        if (trim($options->description) !== '') {
            $siteConfig['description'] = $options->description;
        }

        if (is_string($options->baseUrl) && trim($options->baseUrl) !== '') {
            $siteConfig['baseUrl'] = trim($options->baseUrl);
        }

        if (is_string($options->basePath) && trim($options->basePath) !== '') {
            $siteConfig['basePath'] = trim($options->basePath);
        }

        $config = [
            'pageTemplate' => $options->pageTemplate,
            'site' => $siteConfig,
            'taxonomies' => $options->taxonomies,
        ];

        if ($schema->config !== []) {
            $config = $this->deepMerge($config, $schema->config);
        }

        return Neon::encode($config, true)
            . PHP_EOL
            . $this->commentedOptionsBlock();
    }

    /**
     * Recursively deep-merge two arrays with the override array taking precedence.
     *
     * String-keyed sub-arrays are merged recursively; other values (including
     * numeric-keyed arrays) are overwritten by the override value.
     *
     * @param array<mixed> $base Base configuration.
     * @param array<mixed> $override Override values that take precedence.
     * @return array<mixed>
     */
    protected function deepMerge(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (is_string($key) && isset($merged[$key]) && is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = $this->deepMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Build a commented reference block listing all available configuration options.
     *
     * This block is appended to the generated `glaze.neon` to help users discover
     * available settings without needing to consult external documentation.
     */
    protected function commentedOptionsBlock(): string
    {
        $referenceConfiguration = $this->projectConfigurationReader->readReference();
        $referenceConfigurationBlock = Neon::encode($referenceConfiguration, true);
        $referenceLines = preg_split('/\R/u', rtrim($referenceConfigurationBlock));
        if (!is_array($referenceLines)) {
            $referenceLines = [];
        }

        $commentedReferenceBlock = implode(PHP_EOL, array_map(
            static fn(string $line): string => '# ' . $line,
            $referenceLines,
        ));

        return '# --- Available options (uncomment and adjust as needed) ---'
            . PHP_EOL
            . $commentedReferenceBlock
            . PHP_EOL;
    }
}
