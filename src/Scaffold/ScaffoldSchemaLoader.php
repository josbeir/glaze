<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use Nette\Neon\Neon;
use RuntimeException;

/**
 * Loads a scaffold schema from a NEON definition file.
 *
 * Each scaffold directory must contain a `scaffold.neon` file. The loader
 * parses it and resolves all file entry paths to absolute paths based on
 * the schema directory location.
 *
 * Supported `scaffold.neon` structure:
 * ```neon
 * name: default
 * description: Minimal Glaze starter project
 * extends: null
 *
 * files:
 *   - .gitignore
 *   - content/index.dj
 *   - glaze.neon.tpl        # .tpl suffix → template render, destination: glaze.neon
 *   - src: vite.config.js   # object notation
 *
 * config:
 *   build:
 *     vite:
 *       enabled: true
 * ```
 */
final class ScaffoldSchemaLoader
{
    /**
     * Load and parse a scaffold schema from the given directory.
     *
     * The directory must contain a `scaffold.neon` file.
     *
     * @param string $directory Absolute path to the scaffold preset directory.
     * @return \Glaze\Scaffold\ScaffoldSchema Parsed scaffold schema.
     * @throws \RuntimeException If the schema file cannot be read or parsed.
     */
    public function load(string $directory): ScaffoldSchema
    {
        $schemaPath = $directory . DIRECTORY_SEPARATOR . 'scaffold.neon';
        if (!is_file($schemaPath)) {
            throw new RuntimeException(sprintf('Scaffold schema file not found: %s', $schemaPath));
        }

        $raw = file_get_contents($schemaPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read scaffold schema: %s', $schemaPath));
        }

        $data = Neon::decode($raw);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Scaffold schema must be a NEON mapping in: %s', $schemaPath));
        }

        $name = isset($data['name']) && is_string($data['name'])
            ? $data['name']
            : basename($directory);

        $description = isset($data['description']) && is_string($data['description'])
            ? $data['description']
            : '';

        $extends = isset($data['extends']) && is_string($data['extends'])
            ? $data['extends']
            : null;

        $config = isset($data['config']) && is_array($data['config'])
            ? $data['config']
            : [];

        $files = $this->parseFileEntries($directory, (array)($data['files'] ?? []));

        return new ScaffoldSchema(
            name: $name,
            description: $description,
            files: $files,
            config: $config,
            extends: $extends,
        );
    }

    /**
     * Parse raw file entries from the schema into `ScaffoldFileEntry` objects.
     *
     * Entries may be specified as plain path strings or as `{src: path}` mappings.
     * Files whose source path ends in `.tpl` are marked as templates; the `.tpl`
     * suffix is stripped from the resulting destination path.
     *
     * @param string $directory Absolute path to the scaffold directory.
     * @param array<mixed> $rawFiles Raw file entries from the schema.
     * @return list<\Glaze\Scaffold\ScaffoldFileEntry>
     */
    protected function parseFileEntries(string $directory, array $rawFiles): array
    {
        $entries = [];

        foreach ($rawFiles as $entry) {
            $source = $this->resolveSourceFromEntry($entry);
            if ($source === null) {
                continue;
            }

            $isTemplate = str_ends_with($source, '.tpl');
            $destination = $isTemplate ? substr($source, 0, -4) : $source;

            $absoluteSource = $directory
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $source);

            $entries[] = new ScaffoldFileEntry(
                absoluteSource: $absoluteSource,
                destination: $destination,
                isTemplate: $isTemplate,
            );
        }

        return $entries;
    }

    /**
     * Resolve the source path string from a raw file entry.
     *
     * Accepts both plain string shorthands and `src`/`source` keyed arrays.
     *
     * @param mixed $entry Raw entry from the schema files list.
     */
    protected function resolveSourceFromEntry(mixed $entry): ?string
    {
        if (is_string($entry)) {
            $trimmed = trim($entry);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_array($entry)) {
            foreach (['src', 'source'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key])) {
                    $trimmed = trim($entry[$key]);

                    return $trimmed !== '' ? $trimmed : null;
                }
            }
        }

        return null;
    }
}
