<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

/**
 * Represents a single file entry within a scaffold schema.
 *
 * A file entry is either a static copy or a template render operation.
 * Template entries have their `.tpl` extension stripped from the destination
 * and undergo `{variable}` token substitution before being written.
 *
 * Example:
 * ```php
 * // Static copy
 * new ScaffoldFileEntry('/scaffolds/default/.gitignore', '.gitignore', false);
 *
 * // Template render: glaze.neon.tpl → glaze.neon
 * new ScaffoldFileEntry('/scaffolds/default/glaze.neon.tpl', 'glaze.neon', true);
 * ```
 */
final class ScaffoldFileEntry
{
    /**
     * Constructor.
     *
     * @param string $absoluteSource Absolute path to the source file on disk.
     * @param string $destination Relative destination path within the target project directory.
     * @param bool $isTemplate Whether `{variable}` substitution should be applied to the file content.
     */
    public function __construct(
        public readonly string $absoluteSource,
        public readonly string $destination,
        public readonly bool $isTemplate,
    ) {
    }
}
