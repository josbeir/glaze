<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template\Extension;

use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\BuildStartedEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Config\BuildConfig;
use Glaze\Template\Extension\ExtensionLoader;
use Glaze\Template\Extension\ExtensionRegistry;
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
        $registry = ExtensionLoader::loadFromProjectRoot($dir);

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

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertSame([], $registry->names());
    }

    /**
     * Validate that an attribute-decorated invokable class is auto-discovered.
     */
    public function testAutoDiscoveryRegistersDecoratedClass(): void
    {
        $dir = $this->createTempDirectory();
        $class = $this->writeExtensionFile($dir, 'auto-discovered', "return 'found';");

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

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

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertSame([], $registry->names());
    }

    /**
     * Validate that a decorated but non-invokable named class in extensions/ throws.
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
                . "#[GlazeExtension('bad')]\nfinal class %s {}\n",
                $class,
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not invokable/i');

        ExtensionLoader::loadFromProjectRoot($dir);
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
                . "#[GlazeExtension('')]\nfinal class %s { public function __invoke(): string { return ''; } }\n",
                $class,
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty name/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that a custom extensions directory name is respected.
     */
    public function testCustomExtensionsDirIsRespected(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeExtensionFile($dir, 'custom-dir-ext', "return 'custom';", 'my-plugins');

        // Default 'extensions/' dir is absent — only 'my-plugins/' exists.
        $emptyRegistry = ExtensionLoader::loadFromProjectRoot($dir);
        $this->assertSame([], $emptyRegistry->names());

        $registry = ExtensionLoader::loadFromProjectRoot($dir, 'my-plugins');
        $this->assertTrue($registry->has('custom-dir-ext'));
        $this->assertSame('custom', $registry->call('custom-dir-ext'));
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
        ExtensionLoader::loadFromProjectRoot($dir, 'extensions', $dispatcher);

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
        $registry = ExtensionLoader::loadFromProjectRoot($dir, 'extensions', $dispatcher);

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
                . "#[GlazeExtension('hybrid-helper')]\n"
                . "final class %s {\n"
                . "    public function __invoke(): string { return 'hybrid'; }\n"
                . "    #[ListensTo(BuildEvent::BuildStarted)]\n"
                . "    public function onBuildStarted(BuildStartedEvent \$event): void {}\n"
                . "}\n",
                $class,
            ),
        );

        $dispatcher = new EventDispatcher();
        $registry = ExtensionLoader::loadFromProjectRoot($dir, 'extensions', $dispatcher);

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

        ExtensionLoader::loadFromProjectRoot($dir);
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
        ExtensionLoader::loadFromProjectRoot($dir, 'extensions', $dispatcher);

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
                . "#[GlazeExtension('%s')]\nfinal class %s { public function __invoke(): mixed { %s } }\n",
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
}
