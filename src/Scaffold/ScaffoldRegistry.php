<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use RuntimeException;

/**
 * Discovers and resolves available scaffold presets from a scaffolds directory.
 *
 * The registry scans a directory for subdirectories that each contain a
 * `scaffold.neon` file. Schemas that declare an `extends` property are
 * resolved by merging the parent schema's files and config into the child:
 * child-defined entries override parent entries by destination path, while
 * config arrays are deep-merged with the child taking precedence.
 *
 * In addition to named presets (e.g. `default`, `vite`), the registry also
 * accepts absolute directory paths. This allows user-defined presets outside
 * the bundled `scaffolds/` directory while still inheriting from built-in
 * presets via `extends` in their `scaffold.neon`.
 *
 * Example usage:
 * ```php
 * $registry = new ScaffoldRegistry('/path/to/scaffolds');
 * $schema = $registry->get('vite');                   // named built-in preset
 * $schema = $registry->get('/home/user/my-preset');   // path-based custom preset
 * $names = $registry->names();                        // ['default', 'vite']
 * ```
 */
final class ScaffoldRegistry
{
    /**
     * Constructor.
     *
     * @param string $scaffoldsDirectory Absolute path to the scaffolds root directory.
     * @param \Glaze\Scaffold\ScaffoldSchemaLoader $loader Schema loader instance.
     */
    public function __construct(
        protected readonly string $scaffoldsDirectory,
        protected readonly ScaffoldSchemaLoader $loader = new ScaffoldSchemaLoader(),
    ) {
    }

    /**
     * Retrieve a scaffold schema by preset name or absolute directory path, resolving any inheritance chain.
     *
     * When `$name` is an absolute path to a directory, the schema is loaded from that
     * path directly. Any `extends` value in that schema is still resolved against the
     * bundled `$scaffoldsDirectory`, allowing custom presets to inherit from built-in ones.
     *
     * When `$name` is a plain name (no path separators), the matching subdirectory
     * inside `$scaffoldsDirectory` is used.
     *
     * @param string $name Preset name (e.g. `default`) or absolute directory path.
     * @return \Glaze\Scaffold\ScaffoldSchema Fully resolved schema with inherited files and config.
     * @throws \RuntimeException If the preset or path does not exist.
     */
    public function get(string $name): ScaffoldSchema
    {
        if ($this->isDirectoryPath($name)) {
            return $this->loadFromPath($name);
        }

        $directory = $this->scaffoldsDirectory . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Scaffold preset "%s" not found. Available presets: %s.',
                $name,
                implode(', ', $this->names()) ?: '(none)',
            ));
        }

        $schema = $this->loader->load($directory);

        if ($schema->extends !== null) {
            $parent = $this->get($schema->extends);

            return $this->merge($parent, $schema);
        }

        return $schema;
    }

    /**
     * Load a scaffold schema from an absolute directory path.
     *
     * The `extends` value (if any) is resolved through the normal named-preset
     * lookup, so custom presets can inherit from built-in ones.
     *
     * @param string $directory Absolute path to a scaffold preset directory.
     * @return \Glaze\Scaffold\ScaffoldSchema Fully resolved schema.
     * @throws \RuntimeException If the directory does not exist or the schema cannot be loaded.
     */
    protected function loadFromPath(string $directory): ScaffoldSchema
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Scaffold preset path "%s" not found.',
                $directory,
            ));
        }

        $schema = $this->loader->load($directory);

        if ($schema->extends !== null) {
            $parent = $this->get($schema->extends);

            return $this->merge($parent, $schema);
        }

        return $schema;
    }

    /**
     * Determine whether a preset identifier should be treated as a directory path
     * rather than a named preset.
     *
     * A value is considered a path when it contains a directory separator (`/` or `\`).
     *
     * @param string $name Preset name or path.
     */
    protected function isDirectoryPath(string $name): bool
    {
        return str_contains($name, '/') || str_contains($name, '\\');
    }

    /**
     * Return the names of all available scaffold presets, ordered by weight then name.
     *
     * Only directories containing a valid `scaffold.neon` file are included.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->presets());
    }

    /**
     * Return all available scaffold presets as a name-to-description map, ordered by weight then name.
     *
     * Each entry maps a preset name (directory name) to its human-readable description
     * as defined in the `scaffold.neon` file. Presets with a lower `weight` value are
     * listed first; presets sharing the same weight are sorted alphabetically by name.
     *
     * @return array<string, string>
     */
    public function presets(): array
    {
        if (!is_dir($this->scaffoldsDirectory)) {
            return [];
        }

        $entries = scandir($this->scaffoldsDirectory);
        if (!is_array($entries)) {
            return [];
        }

        /** @var array<string, array{description: string, weight: int}> $discovered */
        $discovered = [];
        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $this->scaffoldsDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . 'scaffold.neon')) {
                $schema = $this->loader->load($path);
                $discovered[$entry] = [
                    'description' => $schema->description,
                    'weight' => $schema->weight,
                ];
            }
        }

        uasort($discovered, function (array $a, array $b): int {
            return $a['weight'] <=> $b['weight'];
        });

        $presets = [];
        foreach ($discovered as $name => $meta) {
            $presets[$name] = $meta['description'];
        }

        return $presets;
    }

    /**
     * Merge a parent schema into a child schema, resolving inheritance.
     *
     * Files are merged by destination key: child entries override parent entries
     * that share the same destination path, while maintaining ordering (parent
     * files first, then new child-only files appended). Configuration arrays
     * are deep-merged with child values taking precedence.
     *
     * @param \Glaze\Scaffold\ScaffoldSchema $parent The parent schema to inherit from.
     * @param \Glaze\Scaffold\ScaffoldSchema $child The child schema extending the parent.
     */
    protected function merge(ScaffoldSchema $parent, ScaffoldSchema $child): ScaffoldSchema
    {
        $byDestination = [];

        foreach ($parent->files as $file) {
            $byDestination[$file->destination] = $file;
        }

        foreach ($child->files as $file) {
            $byDestination[$file->destination] = $file;
        }

        $mergedConfig = $this->deepMerge($parent->config, $child->config);

        return new ScaffoldSchema(
            name: $child->name,
            description: $child->description,
            files: array_values($byDestination),
            config: $mergedConfig,
            weight: $child->weight,
        );
    }

    /**
     * Recursively deep-merge two arrays, with values from `$override` taking precedence.
     *
     * Numeric-keyed arrays are appended; string-keyed arrays are recursively merged.
     *
     * @param array<mixed> $base Base array.
     * @param array<mixed> $override Override array whose values take precedence.
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
}
