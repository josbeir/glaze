<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Glaze\Build\Event\EventDispatcher;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Loads project extensions from the `extensions/` directory and builds a populated registry.
 *
 * **Auto-discovery**
 * Any class decorated with `#[GlazeExtension]` placed in the project's `extensions/` directory
 * is discovered and registered automatically. A class must satisfy at least one of:
 *
 * - Have a non-empty `name` in `#[GlazeExtension('name')]` **and** implement `__invoke()` — registered
 *   as a named template helper callable from Sugar templates.
 * - Have at least one public method decorated with `#[ListensTo(BuildEvent::X)]` — registered
 *   as a build-pipeline event listener on the supplied `EventDispatcher`.
 *
 * Both conditions may be satisfied simultaneously. Classes without `#[GlazeExtension]` are silently skipped.
 *
 * Example `extensions/SitemapGenerator.php` (event subscriber):
 *
 * ```php
 * #[GlazeExtension]
 * class SitemapGenerator
 * {
 *     #[ListensTo(BuildEvent::BuildCompleted)]
 *     public function write(BuildCompletedEvent $event): void { ... }
 * }
 * ```
 *
 * Example `extensions/VersionExtension.php` (template helper):
 *
 * ```php
 * #[GlazeExtension('version')]
 * class VersionExtension
 * {
 *     public function __invoke(): string { return trim(file_get_contents('VERSION')); }
 * }
 * ```
 */
final class ExtensionLoader
{
    /**
     * Conventional auto-discovery directory relative to the project root.
     */
    public const EXTENSIONS_DIR = 'extensions';

    /**
     * Build a populated ExtensionRegistry from the given project root.
     *
     * Scans the configured extensions directory for `#[GlazeExtension]`-decorated classes.
     * Template helpers are registered on the returned `ExtensionRegistry`.
     * Event-subscriber methods are registered on `$dispatcher`.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $extensionsDir Relative directory name to scan for extension classes.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Event dispatcher to register listeners on.
     *        When `null` a fresh, no-op dispatcher is used internally.
     * @throws \InvalidArgumentException When an extension definition is invalid.
     */
    public static function loadFromProjectRoot(
        string $projectRoot,
        string $extensionsDir = self::EXTENSIONS_DIR,
        ?EventDispatcher $dispatcher = null,
    ): ExtensionRegistry {
        $registry = new ExtensionRegistry();
        $dispatcher ??= new EventDispatcher();

        self::scanExtensionsDirectory($registry, $dispatcher, $projectRoot, $extensionsDir);

        return $registry;
    }

    /**
     * Scan a project directory and register all decorated extension classes.
     *
     * PHP files are required in alphabetical order. Classes that do not carry the
     * `#[GlazeExtension]` attribute are silently skipped.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target template extension registry.
     * @param \Glaze\Build\Event\EventDispatcher $dispatcher Target event dispatcher.
     * @param string $projectRoot Absolute project root path.
     * @param string $extensionsDir Relative directory name to scan.
     * @throws \InvalidArgumentException When a decorated class is invalid.
     */
    protected static function scanExtensionsDirectory(
        ExtensionRegistry $registry,
        EventDispatcher $dispatcher,
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

                self::registerFromClass($registry, $dispatcher, $className, $file);
            }
        }
    }

    /**
     * Resolve the `#[GlazeExtension]` attribute on a class and register it.
     *
     * Validates and registers the class as a template helper, event subscriber, or both:
     * - A non-empty name requires `__invoke()` and registers the class as a template helper.
     * - Methods decorated with `#[ListensTo]` are registered as event listeners.
     * - A class must contribute at least one of the above; otherwise an exception is thrown.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target template extension registry.
     * @param \Glaze\Build\Event\EventDispatcher $dispatcher Target event dispatcher.
     * @param string $className Fully-qualified class name to reflect and register.
     * @param string $sourceFile Source file path used in error messages.
     * @throws \InvalidArgumentException When the class definition is invalid.
     */
    public static function registerFromClass(
        ExtensionRegistry $registry,
        EventDispatcher $dispatcher,
        string $className,
        string $sourceFile,
    ): void {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" defined in "%s" could not be found. '
                . 'Ensure it is autoloadable.',
                $className,
                $sourceFile,
            ));
        }

        $reflectionClass = new ReflectionClass($className);
        $attributes = $reflectionClass->getAttributes(GlazeExtension::class);

        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" is missing the #[GlazeExtension] attribute.',
                $className,
            ));
        }

        /** @var \Glaze\Template\Extension\GlazeExtension $attribute */
        $attribute = $attributes[0]->newInstance();
        $instance = new $className();
        $registeredAnything = false;

        // --- Template helper registration ---
        if ($attribute->name !== null) {
            if ($attribute->name === '') {
                throw new InvalidArgumentException(sprintf(
                    'Extension class "%s" has an empty name in #[GlazeExtension]. '
                    . 'Provide a non-empty name or omit the argument for a pure event subscriber.',
                    $className,
                ));
            }

            if (!is_callable($instance)) {
                throw new InvalidArgumentException(sprintf(
                    'Extension class "%s" has a name ("%s") but is not invokable. '
                    . 'Add a __invoke() method or remove the name to use it as a pure event subscriber.',
                    $className,
                    $attribute->name,
                ));
            }

            $registry->register($attribute->name, $instance);
            $registeredAnything = true;
        }

        // --- Event listener registration ---
        $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $listensToAttributes = $method->getAttributes(ListensTo::class);
            if ($listensToAttributes === []) {
                continue;
            }

            /** @var \Glaze\Template\Extension\ListensTo $listensTo */
            $listensTo = $listensToAttributes[0]->newInstance();
            $dispatcher->on($listensTo->event, function (object $event) use ($instance, $method): void {
                $method->invoke($instance, $event);
            });
            $registeredAnything = true;
        }

        if (!$registeredAnything) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" is decorated with #[GlazeExtension] but contributes nothing. '
                . 'Either provide a name and __invoke() for a template helper, '
                . 'or add #[ListensTo(BuildEvent::X)] methods for event subscriptions.',
                $className,
            ));
        }
    }
}
