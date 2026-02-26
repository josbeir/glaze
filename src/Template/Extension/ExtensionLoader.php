<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * Loads project extensions from two independent sources and builds a populated ExtensionRegistry.
 *
 * **Auto-discovery (no configuration required)**
 * Any invokable class decorated with `#[GlazeExtension('name')]` placed in the project's
 * `extensions/` directory is discovered and registered automatically. Classes without
 * the attribute are silently skipped.
 *
 * **Bootstrap file (`glaze.php`)**
 * When a `glaze.php` file exists in the project root it is loaded for explicit
 * registrations. Each array entry is either:
 *
 * - A FQCN string for an invokable class decorated with `#[GlazeExtension('name')]`
 * - A named `[string => callable]` pair for inline definitions
 *
 * Both sources are independent and optional. You can use either or both.
 *
 * Example `extensions/LatestRelease.php` (no `glaze.php` required):
 *
 * ```php
 * #[GlazeExtension('version')]
 * final class LatestRelease
 * {
 *     public function __invoke(): string { return trim(file_get_contents('VERSION')); }
 * }
 * ```
 *
 * Example `glaze.php` (inline callables only):
 *
 * ```php
 * return [
 *     'buildDate' => fn() => date('Y-m-d'),
 * ];
 * ```
 */
final class ExtensionLoader
{
    /**
     * Bootstrap file name resolved relative to the project root.
     */
    public const BOOTSTRAP_FILE = 'glaze.php';

    /**
     * Conventional auto-discovery directory relative to the project root.
     */
    public const EXTENSIONS_DIR = 'extensions';

    /**
     * Build a populated ExtensionRegistry from the given project root.
     *
     * Scans the configured extensions directory for `#[GlazeExtension]`-decorated classes,
     * then additionally processes `glaze.php` when present. Both sources are optional.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $extensionsDir Relative directory name to scan for extension classes.
     * @throws \RuntimeException When the bootstrap file returns a non-array value.
     * @throws \InvalidArgumentException When an extension definition is invalid.
     */
    public static function loadFromProjectRoot(
        string $projectRoot,
        string $extensionsDir = self::EXTENSIONS_DIR,
    ): ExtensionRegistry {
        $registry = new ExtensionRegistry();

        self::scanExtensionsDirectory($registry, $projectRoot, $extensionsDir);
        self::loadBootstrapFile($registry, $projectRoot);

        return $registry;
    }

    /**
     * Scan a project directory and auto-register all decorated extension classes.
     *
     * PHP files are required in alphabetical order. Classes that do not carry the
     * `#[GlazeExtension]` attribute are silently skipped. Classes that do carry the
     * attribute but are not invokable or have an empty name throw immediately.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target registry.
     * @param string $projectRoot Absolute project root path.
     * @param string $extensionsDir Relative directory name to scan.
     * @throws \InvalidArgumentException When a decorated class is invalid.
     */
    protected static function scanExtensionsDirectory(
        ExtensionRegistry $registry,
        string $projectRoot,
        string $extensionsDir = self::EXTENSIONS_DIR,
    ): void {
        $dir = $projectRoot . DIRECTORY_SEPARATOR . $extensionsDir;

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false || $files === []) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $before = get_declared_classes();

            (static function () use ($file): void {
                require_once $file;
            })();

            $declared = array_diff(get_declared_classes(), $before);

            foreach ($declared as $className) {
                $reflectionClass = new ReflectionClass($className);
                $attributes = $reflectionClass->getAttributes(GlazeExtension::class);

                if ($attributes === []) {
                    continue;
                }

                self::registerFromClass($registry, $className, $file);
            }
        }
    }

    /**
     * Load `glaze.php` from the project root and register all defined extensions.
     *
     * Does nothing when `glaze.php` is absent.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target registry.
     * @param string $projectRoot Absolute project root path.
     * @throws \RuntimeException When the bootstrap file returns a non-array value.
     * @throws \InvalidArgumentException When an extension definition is invalid.
     */
    protected static function loadBootstrapFile(ExtensionRegistry $registry, string $projectRoot): void
    {
        $bootstrapFile = $projectRoot . DIRECTORY_SEPARATOR . self::BOOTSTRAP_FILE;

        if (!is_file($bootstrapFile)) {
            return;
        }

        $definitions = (static function () use ($bootstrapFile): mixed {
            return require $bootstrapFile;
        })();

        if (!is_array($definitions)) {
            throw new RuntimeException(sprintf(
                'Extension bootstrap file "%s" must return an array, got %s.',
                $bootstrapFile,
                get_debug_type($definitions),
            ));
        }

        foreach ($definitions as $key => $value) {
            if (is_int($key) && is_string($value)) {
                self::registerFromClass($registry, $value, $bootstrapFile);
                continue;
            }

            if (is_string($key) && is_callable($value)) {
                $registry->register($key, $value);
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid extension definition at key "%s" in "%s". '
                . 'Expected a class-name string or a [string => callable] pair.',
                (string)$key,
                $bootstrapFile,
            ));
        }
    }

    /**
     * Resolve the `#[GlazeExtension]` attribute on a class and register it.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target registry.
     * @param string $className Fully-qualified class name to reflect.
     * @param string $bootstrapFile Bootstrap file path used in error messages.
     * @throws \InvalidArgumentException When the class is missing, not invokable, or has no valid attribute.
     */
    protected static function registerFromClass(
        ExtensionRegistry $registry,
        string $className,
        string $bootstrapFile,
    ): void {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" listed in "%s" could not be found. '
                . 'Ensure it is autoloadable.',
                $className,
                $bootstrapFile,
            ));
        }

        $reflectionClass = new ReflectionClass($className);
        $attributes = $reflectionClass->getAttributes(GlazeExtension::class);

        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" is missing the #[GlazeExtension(\'name\')] attribute.',
                $className,
            ));
        }

        /** @var \Glaze\Template\Extension\GlazeExtension $attribute */
        $attribute = $attributes[0]->newInstance();

        if ($attribute->name === '') {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" has an empty name in #[GlazeExtension].',
                $className,
            ));
        }

        $instance = new $className();

        if (!is_callable($instance)) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" is not invokable. Add a __invoke() method.',
                $className,
            ));
        }

        $registry->register($attribute->name, $instance);
    }
}
