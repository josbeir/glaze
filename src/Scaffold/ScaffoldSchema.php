<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

/**
 * Represents a scaffold preset loaded from a NEON schema file.
 *
 * A scaffold schema describes a named project template consisting of a set of
 * file entries to copy or render, plus an optional extra `config` block that is
 * deep-merged into the generated `glaze.neon` when the preset is used.
 *
 * Schemas can extend other schemas via the `extends` property. When a schema
 * extends another, the parent's files are inherited and can be selectively
 * overridden by destination path.
 *
 * Example:
 * ```php
 * $schema = new ScaffoldSchema(
 *     name: 'default',
 *     description: 'Minimal Glaze starter project',
 *     files: [...],
 *     config: [],
 * );
 * ```
 */
final class ScaffoldSchema
{
    /**
     * Constructor.
     *
     * @param string $name Unique preset name (matches directory name).
     * @param string $description Human-readable description of the preset.
     * @param list<\Glaze\Scaffold\ScaffoldFileEntry> $files Ordered list of file entries to process.
     * @param array<mixed> $config Extra configuration to deep-merge into the generated `glaze.neon`.
     * @param string|null $extends Name of the parent schema to inherit from, if any.
     * @param int $weight Sort weight for display ordering (lower values appear first).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $files,
        public readonly array $config,
        public readonly ?string $extends = null,
        public readonly int $weight = 0,
    ) {
    }
}
