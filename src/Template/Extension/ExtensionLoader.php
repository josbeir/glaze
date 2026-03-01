<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Glaze\Build\Event\EventDispatcher;
use Glaze\Config\BuildConfig;
use Glaze\Extension\LlmsTxtExtension;
use Glaze\Extension\SitemapExtension;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Loads extensions and builds a populated registry for a given build configuration.
 *
 * **Core extensions** are opt-in named classes that ship with Glaze and are listed in
 * {@see self::CORE_EXTENSIONS}. Enable them via the `extensions` key in `glaze.neon`:
 *
 * ```neon
 * extensions:
 *     - sitemap
 *     - llms-txt
 * ```
 *
 * **Project extensions** are classes decorated with `#[GlazeExtension]` placed in the
 * project's `extensions/` directory. They are auto-discovered on every build without any
 * explicit configuration. A class must satisfy at least one of:
 *
 * - Declare `helper: true` in `#[GlazeExtension('name', helper: true)]` **and** implement
 *   `__invoke()` — registered as a named template helper callable from Sugar templates.
 * - Have at least one public method decorated with `#[ListensTo(BuildEvent::X)]` — registered
 *   as a build-pipeline event listener on the supplied `EventDispatcher`.
 *
 * Example `extensions/VersionExtension.php` (template helper):
 *
 * ```php
 * #[GlazeExtension('version', helper: true)]
 * class VersionExtension
 * {
 *     public function __invoke(): string { return trim(file_get_contents('VERSION')); }
 * }
 * ```
 *
 * Example `extensions/BuildHooks.php` (event subscriber):
 *
 * ```php
 * #[GlazeExtension]
 * class BuildHooks
 * {
 *     #[ListensTo(BuildEvent::SugarRendererCreated)]
 *     public function register(SugarRendererCreatedEvent $event): void { ... }
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
     * Core (built-in) extensions that ship with Glaze.
     *
     * These are opt-in via the `extensions` key in `glaze.neon` and are
     * resolved by the attribute `name` declared on each class.
     *
     * @var list<class-string>
     */
    protected const CORE_EXTENSIONS = [
        SitemapExtension::class,
        LlmsTxtExtension::class,
    ];

    /**
     * Load extensions for a build configuration.
     *
     * Explicitly enabled extensions from `glaze.neon` are resolved and registered first.
     * Project extensions from the configured extensions directory are then auto-discovered
     * and registered, skipping any already registered by explicit configuration.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Event dispatcher to register listeners on.
     * @throws \InvalidArgumentException When a configured extension cannot be resolved or is invalid.
     */
    public static function load(BuildConfig $config, ?EventDispatcher $dispatcher = null): ExtensionRegistry
    {
        $registry = new ExtensionRegistry();
        $dispatcher ??= new EventDispatcher();

        [$byName, $byClass, $projectClasses] = self::buildDefinitionPool($config);
        $registeredClasses = [];

        foreach ($config->enabledExtensions as $identifier => $options) {
            $normalizedOptions = self::normalizeOptions($options);
            $definition = self::resolveDefinition($identifier, $byName, $byClass);

            if ($definition === null) {
                throw new InvalidArgumentException(sprintf(
                    'Configured extension "%s" could not be resolved. '
                    . 'Use a known extension name or a fully-qualified class name, '
                    . 'or add the extension class to "%s/%s".',
                    $identifier,
                    $config->projectRoot,
                    $config->extensionsDir,
                ));
            }

            self::registerFromClass(
                $registry,
                $dispatcher,
                $definition['className'],
                $definition['sourceFile'],
                $normalizedOptions,
            );
            $registeredClasses[$definition['className']] = true;
        }

        foreach ($projectClasses as $className => $definition) {
            if (isset($registeredClasses[$className])) {
                continue;
            }

            self::registerFromClass(
                $registry,
                $dispatcher,
                $className,
                $definition['sourceFile'],
            );
        }

        return $registry;
    }

    /**
     * Resolve the `#[GlazeExtension]` attribute on a class and register it.
     *
     * Validates and registers the class as a template helper, event subscriber, or both:
     * - `helper: true` requires a non-empty `name` and `__invoke()`, and registers the class
     *   as a named template helper.
     * - Methods decorated with `#[ListensTo]` are registered as event listeners.
     * - A class must contribute at least one of the above; otherwise an exception is thrown.
     *
     * @param \Glaze\Template\Extension\ExtensionRegistry $registry Target template extension registry.
     * @param \Glaze\Build\Event\EventDispatcher $dispatcher Target event dispatcher.
     * @param string $className Fully-qualified class name to reflect and register.
     * @param string $sourceFile Source file path used in error messages.
     * @param array<string, mixed> $options Per-extension option map.
     * @throws \InvalidArgumentException When the class definition is invalid.
     */
    public static function registerFromClass(
        ExtensionRegistry $registry,
        EventDispatcher $dispatcher,
        string $className,
        string $sourceFile,
        array $options = [],
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
        $instance = self::createExtensionInstance($className, $options);
        $registeredAnything = false;

        // --- Template helper registration ---
        if ($attribute->helper) {
            $helperName = $attribute->name;

            if ($helperName === null || $helperName === '') {
                throw new InvalidArgumentException(sprintf(
                    'Extension class "%s" declares helper: true but has no name. '
                    . 'Provide a non-empty name to use it as a template helper.',
                    $className,
                ));
            }

            if (!is_callable($instance)) {
                throw new InvalidArgumentException(sprintf(
                    'Extension class "%s" declares helper: true but is not invokable. '
                    . 'Add a __invoke() method or remove helper: true to use it as a pure event subscriber.',
                    $className,
                ));
            }

            $registry->register($helperName, $instance);
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
                . 'Either set helper: true with a name and __invoke() for a template helper, '
                . 'or add #[ListensTo(BuildEvent::X)] methods for event subscriptions.',
                $className,
            ));
        }
    }

    /**
     * Discover `#[GlazeExtension]` classes from a project extensions directory.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $extensionsDir Relative extensions directory.
     * @return array<array{className: string, sourceFile: string, extensionName: string|null}>
     */
    protected static function discoverExtensionsInDirectory(string $projectRoot, string $extensionsDir): array
    {
        $dir = $projectRoot . '/' . $extensionsDir;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.php');
        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $definitions = [];
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

                /** @var \Glaze\Template\Extension\GlazeExtension $attribute */
                $attribute = $attributes[0]->newInstance();
                $definitions[] = [
                    'className' => $className,
                    'sourceFile' => $file,
                    'extensionName' => $attribute->name,
                ];
            }
        }

        return $definitions;
    }

    /**
     * Build a unified definition pool from core and project extensions.
     *
     * Core extensions are indexed from {@see self::CORE_EXTENSIONS} using their attribute `name`.
     * Project extensions are discovered from the configured extensions directory.
     * Project definitions supersede core definitions when names collide.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @return array{
     *     0: array<string, array{className: string, sourceFile: string}>,
     *     1: array<string, array{className: string, sourceFile: string}>,
     *     2: array<string, array{className: string, sourceFile: string}>
     * } Tuple of [$byName, $byClass, $projectClasses].
     */
    protected static function buildDefinitionPool(BuildConfig $config): array
    {
        $byName = [];
        $byClass = [];
        $projectClasses = [];

        foreach (self::CORE_EXTENSIONS as $className) {
            $reflection = new ReflectionClass($className);
            $attrs = $reflection->getAttributes(GlazeExtension::class);
            if ($attrs === []) {
                continue;
            }

            /** @var \Glaze\Template\Extension\GlazeExtension $attr */
            $attr = $attrs[0]->newInstance();
            $definition = ['className' => $className, 'sourceFile' => $className];
            $byClass[$className] = $definition;
            if ($attr->name !== null && $attr->name !== '') {
                $byName[$attr->name] = $definition;
            }
        }

        foreach (self::discoverExtensionsInDirectory($config->projectRoot, $config->extensionsDir) as $definition) {
            $entry = ['className' => $definition['className'], 'sourceFile' => $definition['sourceFile']];
            $byClass[$definition['className']] = $entry;
            $projectClasses[$definition['className']] = $entry;
            if (is_string($definition['extensionName']) && $definition['extensionName'] !== '') {
                $byName[$definition['extensionName']] = $entry;
            }
        }

        return [$byName, $byClass, $projectClasses];
    }

    /**
     * Resolve a configured extension identifier to a concrete class definition.
     *
     * Resolution order:
     * 1) Explicit fully-qualified class name — matched against the pool or via `class_exists`.
     * 2) Named extension from the unified pool (core or project) matched by attribute `name`.
     *
     * @param string $identifier Extension identifier from configuration.
     * @param array<string, array{className: string, sourceFile: string}> $byName Pool keyed by name.
     * @param array<string, array{className: string, sourceFile: string}> $byClass Pool keyed by FQCN.
     * @return array{className: string, sourceFile: string}|null
     */
    protected static function resolveDefinition(
        string $identifier,
        array $byName,
        array $byClass,
    ): ?array {
        if (str_contains($identifier, '\\')) {
            $className = ltrim($identifier, '\\');
            if (isset($byClass[$className])) {
                return $byClass[$className];
            }

            return class_exists($className)
                ? ['className' => $className, 'sourceFile' => $className]
                : null;
        }

        return $byName[$identifier] ?? null;
    }

    /**
     * Create an extension instance, optionally from configuration options.
     *
     * @param class-string $className Fully-qualified extension class name.
     * @param array<string, mixed> $options Per-extension option map.
     * @throws \InvalidArgumentException When options are provided for a non-configurable extension.
     */
    protected static function createExtensionInstance(string $className, array $options): object
    {
        if (is_subclass_of($className, ConfigurableExtension::class)) {
            /** @var class-string<\Glaze\Template\Extension\ConfigurableExtension> $className */
            return $className::fromConfig($options);
        }

        if ($options !== []) {
            throw new InvalidArgumentException(sprintf(
                'Extension class "%s" received options in configuration but does not implement %s.',
                $className,
                ConfigurableExtension::class,
            ));
        }

        return new $className();
    }

    /**
     * Normalize arbitrary option payloads to string-keyed option maps.
     *
     * @param mixed $options Raw options payload.
     * @return array<string, mixed>
     */
    protected static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $trimmed = trim($key);
            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = $value;
        }

        return $normalized;
    }
}
