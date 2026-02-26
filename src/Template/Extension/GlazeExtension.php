<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Attribute;

/**
 * Marks an invokable class as a named Glaze template extension.
 *
 * Apply this attribute to any invokable class to register it with the extension
 * loader. The `name` argument becomes the lookup key in `$this->extension('name')`
 * calls inside Sugar templates.
 *
 * Example:
 *
 * ```php
 * #[GlazeExtension('version')]
 * class VersionExtension
 * {
 *     public function __invoke(): string
 *     {
 *         return trim(file_get_contents(__DIR__ . '/../VERSION'));
 *     }
 * }
 * ```
 *
 * Register the class in `glaze.php` at the project root:
 *
 * ```php
 * return [
 *     VersionExtension::class,
 * ];
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class GlazeExtension
{
    /**
     * Constructor.
     *
     * @param string $name Extension name used as lookup key in template calls.
     */
    public function __construct(
        public readonly string $name,
    ) {
    }
}
