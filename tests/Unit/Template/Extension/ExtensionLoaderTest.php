<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template\Extension;

use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\BuildStartedEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Config\BuildConfig;
use Glaze\Extension\SitemapExtension;
use Glaze\Template\Extension\ExtensionLoader;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Tests\Fixture\Extension\NamedTestExtension;
use Glaze\Tests\Fixture\Extension\NoAttributeExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExtensionLoader: auto-discovery of #[GlazeExtension]-decorated classes
 * and attribute-based #[ListensTo] event subscriber registration.
 */
final class ExtensionLoaderTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Unique counter used to generate distinct class names across test methods.
     */
    private static int $classCounter = 0;

    // ------------------------------------------------------------------ //
    // Auto-discovery (`extensions/` directory)
    // ------------------------------------------------------------------ //

    /**
     * Validate an empty project root (no extensions/ dir) returns an empty registry.
     */
    public function testEmptyProjectRootReturnsEmptyRegistry(): void
    {
        $dir = $this->createTempDirectory();
        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir));

        $this->assertInstanceOf(ExtensionRegistry::class, $registry);
        $this->assertSame([], $registry->names());
    }

    /**
     * Validate an empty extensions/ directory returns an empty registry.
     */
    public function testEmptyExtensionsDirReturnsEmptyRegistry(): void
    {
        $dir = $this->createTempDirectory();
        mkdir($dir . '/extensions');

        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir));

        $this->assertSame([], $registry->names());
    }

    /**
     * Validate that an attribute-decorated invokable class is auto-discovered.
     */
    public function testAutoDiscoveryRegistersDecoratedClass(): void
    {
        $dir = $this->createTempDirectory();
        $class = $this->writeExtensionFile($dir, 'auto-discovered', "return 'found';");

        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir));

        $this->assertTrue($registry->has('auto-discovered'));
        $this->assertSame('found', $registry->call('auto-discovered'));
        $this->assertFalse($registry->has($class));
    }

    /**
     * Validate that classes without the attribute in extensions/ are silently skipped.
     */
    public function testAutoDiscoverySkipsClassesWithoutAttribute(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeNoAttr' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/noattr.php',
            sprintf("<?php\nfinal class %s { public function __invoke(): string { return 'x'; } }\n", $class),
        );

        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir));

        $this->assertSame([], $registry->names());
    }

    /**
     * Validate that a decorated class declaring helper: true but missing __invoke() throws.
     */
    public function testAutoDiscoveryNonInvokableClassThrows(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeNotInv' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/notinvokable.php',
            sprintf(
                "<?php\nuse Glaze\Template\Extension\GlazeExtension;\n"
                . "#[GlazeExtension('bad', helper: true)]\nfinal class %s {}\n",
                $class,
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not invokable/i');

        ExtensionLoader::load(new BuildConfig(projectRoot: $dir));
    }

    /**
     * Validate that a decorated class with an empty name in extensions/ throws.
     */
    public function testAutoDiscoveryEmptyNameThrows(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeEmptyName' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/empty.php',
            sprintf(
                "<?php\nuse Glaze\Template\Extension\GlazeExtension;\n"
                . "#[GlazeExtension('', helper: true)]\nfinal class %s { public function __invoke(): string { return ''; } }\n",
                $class,
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no name/i');

        ExtensionLoader::load(new BuildConfig(projectRoot: $dir));
    }

    /**
     * Validate that a custom extensions directory name is respected.
     */
    public function testCustomExtensionsDirIsRespected(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeExtensionFile($dir, 'custom-dir-ext', "return 'custom';", 'my-plugins');

        // Default 'extensions/' dir is absent — only 'my-plugins/' exists.
        $emptyRegistry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir));
        $this->assertSame([], $emptyRegistry->names());

        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir, extensionsDir: 'my-plugins'));
        $this->assertTrue($registry->has('custom-dir-ext'));
        $this->assertSame('custom', $registry->call('custom-dir-ext'));
    }

    /**
     * Validate configured extensions resolve by extension name and receive options.
     */
    public function testLoadRegistersConfiguredExtensionWithOptions(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeConfigurableExtensionFile($dir, 'configured-extension', 'configured_extension_result');

        $config = new BuildConfig(
            projectRoot: $dir,
            enabledExtensions: [
                'configured-extension' => [
                    'option1' => 'value',
                    'option2' => 10,
                ],
            ],
        );

        $registry = ExtensionLoader::load($config);

        $this->assertTrue($registry->has('configured-extension'));
        $this->assertSame('value|10', $registry->call('configured-extension'));
    }

    /**
     * Validate options for non-configurable extensions are rejected.
     */
    public function testLoadThrowsWhenOptionsProvidedForNonConfigurableExtension(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeExtensionFile($dir, 'plain-extension', "return 'ok';");

        $config = new BuildConfig(
            projectRoot: $dir,
            enabledExtensions: [
                'plain-extension' => ['enabled' => true],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement .*ConfigurableExtension/i');

        ExtensionLoader::load($config);
    }

    /**
     * Validate core-extension short names resolve via the CORE_EXTENSIONS list using the attribute name.
     */
    public function testLoadResolvesCoreExtensionByShortName(): void
    {
        $config = new BuildConfig(
            projectRoot: $this->createTempDirectory(),
            enabledExtensions: [
                'sitemap' => [],
            ],
        );

        $dispatcher = new EventDispatcher();
        ExtensionLoader::load($config, $dispatcher);

        // SitemapExtension listens to BuildCompleted — verifies it was resolved and registered.
        $this->assertGreaterThan(0, $dispatcher->listenerCount(BuildEvent::BuildCompleted));
    }

    /**
     * Validate an extension can be enabled by its fully-qualified class name from the CORE_EXTENSIONS pool.
     */
    public function testLoadResolvesByFqcnFromPool(): void
    {
        $config = new BuildConfig(
            projectRoot: $this->createTempDirectory(),
            enabledExtensions: [
                SitemapExtension::class => [],
            ],
        );

        $dispatcher = new EventDispatcher();
        ExtensionLoader::load($config, $dispatcher);

        // SitemapExtension listens to BuildCompleted — confirms FQCN was resolved from the pool.
        $this->assertGreaterThan(0, $dispatcher->listenerCount(BuildEvent::BuildCompleted));
    }

    /**
     * Validate an extension can be enabled by its FQCN when not in the pool but autoloaded (class_exists fallback).
     */
    public function testLoadResolvesByFqcnViaClassExists(): void
    {
        $config = new BuildConfig(
            projectRoot: $this->createTempDirectory(),
            enabledExtensions: [
                NamedTestExtension::class => [],
            ],
        );

        $registry = ExtensionLoader::load($config);

        // NamedTestExtension is helper: true with name 'test-extension'.
        $this->assertTrue($registry->has('test-extension'));
    }

    /**
     * Validate that options are forwarded when an extension is enabled by FQCN and implements ConfigurableExtension.
     */
    public function testLoadResolvesByFqcnWithOptions(): void
    {
        $config = new BuildConfig(
            projectRoot: $this->createTempDirectory(),
            enabledExtensions: [
                SitemapExtension::class => [
                    'changefreq' => 'weekly',
                    'priority' => 0.5,
                ],
            ],
        );

        $dispatcher = new EventDispatcher();
        // Must not throw — SitemapExtension implements ConfigurableExtension,
        // so fromConfig() is called with the options map.
        ExtensionLoader::load($config, $dispatcher);

        $this->assertGreaterThan(0, $dispatcher->listenerCount(BuildEvent::BuildCompleted));
    }

    /**
     * Validate unresolved configured extensions fail fast with a clear message.
     */
    public function testLoadThrowsForUnknownConfiguredExtension(): void
    {
        $config = new BuildConfig(
            projectRoot: $this->createTempDirectory(),
            enabledExtensions: [
                'missing-extension' => [],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/i');

        ExtensionLoader::load($config);
    }

    // ------------------------------------------------------------------ //
    // #[ListensTo] event subscriber registration
    // ------------------------------------------------------------------ //

    /**
     * Validate that a public method decorated with #[ListensTo] is registered on the dispatcher.
     */
    public function testListensToMethodRegistersOnDispatcher(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeEventSubscriberFile(
            $dir,
            BuildEvent::PageWritten,
            'PageWrittenEvent',
        );

        $dispatcher = new EventDispatcher();
        ExtensionLoader::load(new BuildConfig(projectRoot: $dir), $dispatcher);

        $this->assertSame(1, $dispatcher->listenerCount(BuildEvent::PageWritten));
        $this->assertSame(0, $dispatcher->listenerCount(BuildEvent::BuildStarted));
    }

    /**
     * Validate that a pure event subscriber (no name, no __invoke) is accepted.
     */
    public function testPureEventSubscriberDoesNotNeedInvoke(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeEventSubscriberFile(
            $dir,
            BuildEvent::BuildStarted,
            'BuildStartedEvent',
        );

        $dispatcher = new EventDispatcher();

        // Must not throw — the #[ListensTo] method counts as contribution.
        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir), $dispatcher);

        $this->assertSame([], $registry->names());
        $this->assertSame(1, $dispatcher->listenerCount(BuildEvent::BuildStarted));
    }

    /**
     * Validate that an extension with both a name and a #[ListensTo] method registers on both.
     */
    public function testExtensionWithBothNameAndListensToRegistersOnBoth(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeHybrid' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/hybrid.php',
            sprintf(
                "<?php\n"
                . "use Glaze\Build\Event\BuildEvent;\n"
                . "use Glaze\Build\Event\BuildStartedEvent;\n"
                . "use Glaze\Template\Extension\GlazeExtension;\n"
                . "use Glaze\Template\Extension\ListensTo;\n"
                . "#[GlazeExtension('hybrid-helper', helper: true)]\n"
                . "final class %s {\n"
                . "    public function __invoke(): string { return 'hybrid'; }\n"
                . "    #[ListensTo(BuildEvent::BuildStarted)]\n"
                . "    public function onBuildStarted(BuildStartedEvent \$event): void {}\n"
                . "}\n",
                $class,
            ),
        );

        $dispatcher = new EventDispatcher();
        $registry = ExtensionLoader::load(new BuildConfig(projectRoot: $dir), $dispatcher);

        $this->assertTrue($registry->has('hybrid-helper'));
        $this->assertSame('hybrid', $registry->call('hybrid-helper'));
        $this->assertSame(1, $dispatcher->listenerCount(BuildEvent::BuildStarted));
    }

    /**
     * Validate that a #[GlazeExtension] class with neither a name nor #[ListensTo] methods throws.
     */
    public function testExtensionWithNoContributionThrows(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeEmpty' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/empty.php',
            sprintf(
                "<?php\nuse Glaze\Template\Extension\GlazeExtension;\n"
                . "#[GlazeExtension]\nfinal class %s {}\n",
                $class,
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contributes nothing/i');

        ExtensionLoader::load(new BuildConfig(projectRoot: $dir));
    }

    // ------------------------------------------------------------------ //
    // registerFromClass (public API, defensive guards)
    // ------------------------------------------------------------------ //

    /**
     * Validate that registerFromClass throws when the class does not exist.
     */
    public function testRegisterFromClassThrowsForUnknownClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be found/i');

        ExtensionLoader::registerFromClass(
            new ExtensionRegistry(),
            new EventDispatcher(),
            'App\\DoesNotExist',
            '/fake/path/DoesNotExist.php',
        );
    }

    /**
     * Validate that registerFromClass throws when the class lacks the #[GlazeExtension] attribute.
     */
    public function testRegisterFromClassThrowsForClassWithoutAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing the #\[GlazeExtension/i');

        ExtensionLoader::registerFromClass(
            new ExtensionRegistry(),
            new EventDispatcher(),
            NoAttributeExtension::class,
            '/fake/path/NoAttributeExtension.php',
        );
    }

    /**
     * Validate that the event listener is called when the event is dispatched.
     */
    public function testListensToListenerIsCalledOnDispatch(): void
    {
        $dir = $this->createTempDirectory();
        $extDir = $dir . '/extensions';
        mkdir($extDir);

        $class = 'GlazeRecorder' . (++self::$classCounter);
        file_put_contents(
            $extDir . '/recorder.php',
            sprintf(
                "<?php\n"
                . "use Glaze\Build\Event\BuildEvent;\n"
                . "use Glaze\Build\Event\BuildStartedEvent;\n"
                . "use Glaze\Template\Extension\GlazeExtension;\n"
                . "use Glaze\Template\Extension\ListensTo;\n"
                . "#[GlazeExtension]\n"
                . "final class %s {\n"
                . "    public static array \$log = [];\n"
                . "    #[ListensTo(BuildEvent::BuildStarted)]\n"
                . "    public function onBuilt(BuildStartedEvent \$event): void {\n"
                . "        self::\$log[] = 'fired';\n"
                . "    }\n"
                . "}\n",
                $class,
            ),
        );

        $dispatcher = new EventDispatcher();
        ExtensionLoader::load(new BuildConfig(projectRoot: $dir), $dispatcher);

        $this->assertSame(1, $dispatcher->listenerCount(BuildEvent::BuildStarted));

        // Dispatch the event to verify the reflection-based closure body is executed.
        $config = BuildConfig::fromProjectRoot($dir);
        $dispatcher->dispatch(BuildEvent::BuildStarted, new BuildStartedEvent($config));

        $this->assertSame(['fired'], $class::$log);
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    /**
     * Write a PHP file to the extensions/ sub-directory declaring an invokable extension class.
     *
     * Returns the generated class name.
     *
     * @param string $dir Project root temp directory.
     * @param string $extensionName #[GlazeExtension] name to assign.
     * @param string $invokeBody Body of the __invoke method (e.g. `return 'ok';`).
     * @param string $extensionsDir Subdirectory name (default: 'extensions').
     */
    private function writeExtensionFile(
        string $dir,
        string $extensionName,
        string $invokeBody,
        string $extensionsDir = 'extensions',
    ): string {
        $extDir = $dir . '/' . $extensionsDir;
        if (!is_dir($extDir)) {
            mkdir($extDir);
        }

        $class = 'GlazeAutoDiscover' . (++self::$classCounter);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $extensionName) ?? $class;

        file_put_contents(
            $extDir . '/' . $slug . '.php',
            sprintf(
                "<?php\nuse Glaze\Template\Extension\GlazeExtension;\n"
                . "#[GlazeExtension('%s', helper: true)]\nfinal class %s { public function __invoke(): mixed { %s } }\n",
                $extensionName,
                $class,
                $invokeBody,
            ),
        );

        return $class;
    }

    /**
     * Write a PHP file to extensions/ declaring a pure event-subscriber class with one #[ListensTo] method.
     *
     * @param string $dir Project root temp directory.
     * @param BuildEvent $event The event case to listen to.
     * @param string $payloadClass Short class name of the event payload (e.g. 'BuildStartedEvent').
     */
    private function writeEventSubscriberFile(
        string $dir,
        BuildEvent $event,
        string $payloadClass,
    ): string {
        $extDir = $dir . '/extensions';
        if (!is_dir($extDir)) {
            mkdir($extDir);
        }

        $class = 'GlazeSubscriber' . (++self::$classCounter);
        $eventCase = $event->name;

        file_put_contents(
            $extDir . '/subscriber.php',
            sprintf(
                "<?php\n"
                . "use Glaze\Build\Event\BuildEvent;\n"
                . "use Glaze\Build\Event\\%s;\n"
                . "use Glaze\Template\Extension\GlazeExtension;\n"
                . "use Glaze\Template\Extension\ListensTo;\n"
                . "#[GlazeExtension]\n"
                . "final class %s {\n"
                . "    #[ListensTo(BuildEvent::%s)]\n"
                . "    public function handle(%s \$event): void {}\n"
                . "}\n",
                $payloadClass,
                $class,
                $eventCase,
                $payloadClass,
            ),
        );

        return $class;
    }

    /**
     * Write a configurable extension class for option-passing tests.
     *
     * @param string $dir Project root temp directory.
     * @param string $extensionName #[GlazeExtension] name to assign.
     * @param string $classBasename Stable base name used to generate class names.
     */
    private function writeConfigurableExtensionFile(string $dir, string $extensionName, string $classBasename): string
    {
        $extDir = $dir . '/extensions';
        if (!is_dir($extDir)) {
            mkdir($extDir);
        }

        $class = 'GlazeConfigurable' . (++self::$classCounter) . ucfirst($classBasename);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $extensionName) ?? 'configured';

        file_put_contents(
            $extDir . '/' . $slug . '.php',
            sprintf(
                "<?php\n"
                . "use Glaze\\Template\\Extension\\ConfigurableExtension;\n"
                . "use Glaze\\Template\\Extension\\GlazeExtension;\n"
                . "#[GlazeExtension('%s', helper: true)]\n"
                . "final class %s implements ConfigurableExtension {\n"
                . "    private function __construct(private array \$options) {}\n"
                . "    public static function fromConfig(array \$options): static { return new static(\$options); }\n"
                . "    public function __invoke(): string { return (string)(\$this->options['option1'] ?? '') . '|' . (string)(\$this->options['option2'] ?? ''); }\n"
                . "}\n",
                $extensionName,
                $class,
            ),
        );

        return $class;
    }
}
