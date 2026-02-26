<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template\Extension;

use Glaze\Template\Extension\ExtensionLoader;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Tests\Fixture\Extension\EmptyNameExtension;
use Glaze\Tests\Fixture\Extension\NamedTestExtension;
use Glaze\Tests\Fixture\Extension\NoAttributeExtension;
use Glaze\Tests\Fixture\Extension\NotInvokableExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ExtensionLoader: auto-discovery and bootstrap file loading.
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
     * Validate an empty project root (no extensions/ dir, no glaze.php) returns an empty registry.
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
     * Validate that an attribute-decorated invokable class is auto-discovered without glaze.php.
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
     * Validate that a decorated but non-invokable class in extensions/ throws.
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

    // ------------------------------------------------------------------ //
    // Bootstrap file (`glaze.php`)
    // ------------------------------------------------------------------ //

    /**
     * Validate that a class-string entry with #[GlazeExtension] is registered correctly.
     */
    public function testClassNameEntryRegistersExtension(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', sprintf(
            "<?php\nreturn [\n    %s::class,\n];\n",
            NamedTestExtension::class,
        ));

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertTrue($registry->has('test-extension'));
        $this->assertSame('result', $registry->call('test-extension'));
    }

    /**
     * Validate that a named callable entry is registered correctly.
     */
    public function testNamedCallableEntryRegistersExtension(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', "<?php\nreturn [\n    'build-date' => fn() => '2026-01-01',\n];\n");

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertTrue($registry->has('build-date'));
        $this->assertSame('2026-01-01', $registry->call('build-date'));
    }

    /**
     * Validate that class and callable entries can coexist in the same bootstrap file.
     */
    public function testMixedDefinitionsAllRegistered(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', sprintf(
            "<?php\nreturn [\n    %s::class,\n    'extra' => fn() => 42,\n];\n",
            NamedTestExtension::class,
        ));

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertSame(['test-extension', 'extra'], $registry->names());
        $this->assertSame('result', $registry->call('test-extension'));
        $this->assertSame(42, $registry->call('extra'));
    }

    /**
     * Validate that a non-array return from glaze.php throws RuntimeException.
     */
    public function testNonArrayReturnThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', "<?php\nreturn 'oops';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must return an array/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that a class name without the #[GlazeExtension] attribute throws.
     */
    public function testClassWithoutAttributeThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', sprintf(
            "<?php\nreturn [\n    %s::class,\n];\n",
            NoAttributeExtension::class,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing the #\[GlazeExtension/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that a class without __invoke throws.
     */
    public function testNonInvokableClassThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', sprintf(
            "<?php\nreturn [\n    %s::class,\n];\n",
            NotInvokableExtension::class,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not invokable/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that an empty name in #[GlazeExtension] throws.
     */
    public function testEmptyAttributeNameThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', sprintf(
            "<?php\nreturn [\n    %s::class,\n];\n",
            EmptyNameExtension::class,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty name/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that a non-existent class name throws.
     */
    public function testUnknownClassThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', "<?php\nreturn [\n    'App\\\\DoesNotExist',\n];\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be found/i');

        ExtensionLoader::loadFromProjectRoot($dir);
    }

    /**
     * Validate that an invalid entry type (e.g. int key with non-string value) throws.
     */
    public function testInvalidDefinitionEntryThrows(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/glaze.php', "<?php\nreturn [\n    42,\n];\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid extension definition/i');

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
    // Combined sources
    // ------------------------------------------------------------------ //

    /**
     * Validate that auto-discovered classes and glaze.php callables are both registered.
     */
    public function testAutoDiscoveryAndBootstrapFileCombine(): void
    {
        $dir = $this->createTempDirectory();
        $this->writeExtensionFile($dir, 'from-dir', "return 'dir-value';");

        file_put_contents($dir . '/glaze.php', "<?php\nreturn [\n    'from-file' => fn() => 'file-value',\n];\n");

        $registry = ExtensionLoader::loadFromProjectRoot($dir);

        $this->assertTrue($registry->has('from-dir'));
        $this->assertTrue($registry->has('from-file'));
        $this->assertSame('dir-value', $registry->call('from-dir'));
        $this->assertSame('file-value', $registry->call('from-file'));
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
}
