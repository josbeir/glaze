<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\ScaffoldRegistry;
use Glaze\Scaffold\ScaffoldSchemaLoader;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ScaffoldRegistry.
 */
final class ScaffoldRegistryTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure the registry discovers preset names from the scaffolds directory.
     */
    public function testNamesReturnsAvailablePresets(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n",
            'vite' => "name: vite\nextends: default\nfiles:\n",
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());

        $this->assertSame(['default', 'vite'], $registry->names());
    }

    /**
     * Ensure the presets method returns a name-to-description map ordered by weight then name.
     */
    public function testPresetsReturnsNameToDescriptionMap(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\ndescription: Default starter\nfiles:\n",
            'vite' => "name: vite\ndescription: Vite-powered preset\nextends: default\nfiles:\n",
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());

        $this->assertSame([
            'default' => 'Default starter',
            'vite' => 'Vite-powered preset',
        ], $registry->presets());
    }

    /**
     * Ensure presets are ordered by weight, with lower weights appearing first.
     */
    public function testPresetsOrderedByWeight(): void
    {
        $root = $this->buildScaffoldsRoot([
            'alpha' => "name: alpha\ndescription: Alpha preset\nweight: 20\nfiles:\n",
            'beta' => "name: beta\ndescription: Beta preset\nweight: 0\nfiles:\n",
            'gamma' => "name: gamma\ndescription: Gamma preset\nweight: 10\nfiles:\n",
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());

        $this->assertSame(['beta', 'gamma', 'alpha'], $registry->names());
        $this->assertSame([
            'beta' => 'Beta preset',
            'gamma' => 'Gamma preset',
            'alpha' => 'Alpha preset',
        ], $registry->presets());
    }

    /**
     * Ensure the presets method returns an empty array for a non-existent directory.
     */
    public function testPresetsReturnsEmptyForMissingDirectory(): void
    {
        $registry = new ScaffoldRegistry('/non/existent/path', new ScaffoldSchemaLoader());

        $this->assertSame([], $registry->presets());
    }

    /**
     * Ensure getting a preset returns a schema with its name and files.
     */
    public function testGetReturnsSchemaForExistingPreset(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n\t- .gitignore\n",
        ], ['default' => ['.gitignore']]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get('default');

        $this->assertSame('default', $schema->name);
        $this->assertCount(1, $schema->files);
        $this->assertSame('.gitignore', $schema->files[0]->destination);
    }

    /**
     * Ensure getting a missing preset throws a RuntimeException.
     */
    public function testGetThrowsForUnknownPreset(): void
    {
        $root = $this->buildScaffoldsRoot(['default' => "name: default\n"]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffold preset "unknown" not found');

        $registry->get('unknown');
    }

    /**
     * Ensure child preset files are merged on top of parent files when extends is set.
     */
    public function testGetResolvesExtendsInheritance(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n\t- .gitignore\n\t- content/index.dj\n",
            'vite' => "name: vite\nextends: default\nfiles:\n\t- vite.config.js\n",
        ], [
            'default' => ['.gitignore', 'content/index.dj'],
            'vite' => ['vite.config.js'],
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get('vite');

        $destinations = array_map(
            static fn($f): string => $f->destination,
            $schema->files,
        );

        $this->assertContains('.gitignore', $destinations);
        $this->assertContains('content/index.dj', $destinations);
        $this->assertContains('vite.config.js', $destinations);
        $this->assertCount(3, $schema->files);
    }

    /**
     * Ensure child file entries override parent entries with the same destination.
     */
    public function testGetChildOverridesParentByDestination(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n\t- config.txt\n",
            'custom' => "name: custom\nextends: default\nfiles:\n\t- config.txt\n",
        ], [
            'default' => ['config.txt'],
            'custom' => ['config.txt'],
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get('custom');

        $this->assertCount(1, $schema->files);
        $this->assertStringContainsString('custom', $schema->files[0]->absoluteSource);
    }

    /**
     * Ensure schema configs are deep-merged when extending.
     */
    public function testGetDeepMergesConfigFromParentAndChild(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nconfig:\n\tsite:\n\t\ttitle: Default\n",
            'child' => "name: child\nextends: default\nconfig:\n\tbuild:\n\t\tvite:\n\t\t\tenabled: true\n",
        ]);

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get('child');

        $this->assertArrayHasKey('site', $schema->config);
        $this->assertArrayHasKey('build', $schema->config);
    }

    /**
     * Ensure names returns an empty list for a non-existent scaffolds directory.
     */
    public function testNamesReturnsEmptyForMissingDirectory(): void
    {
        $registry = new ScaffoldRegistry('/non/existent/path', new ScaffoldSchemaLoader());

        $this->assertSame([], $registry->names());
    }

    /**
     * Ensure the registry loads from the bundled scaffolds directory.
     */
    public function testBundledRegistryContainsExpectedPresets(): void
    {
        $scaffoldsDir = dirname(__DIR__, 3) . '/scaffolds';
        $registry = new ScaffoldRegistry($scaffoldsDir, new ScaffoldSchemaLoader());

        $names = $registry->names();
        $this->assertContains('default', $names);
        $this->assertContains('plain', $names);
        $this->assertContains('vite', $names);
    }

    /**
     * Ensure the registry loads a schema from an absolute directory path.
     */
    public function testGetLoadsFromAbsolutePath(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n",
        ]);
        $customDir = $this->createTempDirectory() . '/my-preset';
        mkdir($customDir, 0755, true);
        file_put_contents($customDir . '/scaffold.neon', "name: my-preset\nfiles:\n\t- custom.txt\n");
        touch($customDir . '/custom.txt');

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get($customDir);

        $this->assertSame('my-preset', $schema->name);
        $this->assertCount(1, $schema->files);
        $this->assertSame('custom.txt', $schema->files[0]->destination);
    }

    /**
     * Ensure a path-based preset can extend a named built-in preset.
     */
    public function testGetLoadsFromPathWithExtendsResolvesBuiltIn(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n\t- .gitignore\n",
        ], ['default' => ['.gitignore']]);

        $customDir = $this->createTempDirectory() . '/my-preset';
        mkdir($customDir, 0755, true);
        file_put_contents($customDir . '/scaffold.neon', "name: my-preset\nextends: default\nfiles:\n\t- custom.txt\n");
        touch($customDir . '/custom.txt');

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get($customDir);

        $destinations = array_map(static fn($f): string => $f->destination, $schema->files);

        $this->assertContains('.gitignore', $destinations);
        $this->assertContains('custom.txt', $destinations);
        $this->assertCount(2, $schema->files);
    }

    /**
     * Ensure a path-based preset can override parent files by destination.
     */
    public function testGetPathPresetOverridesParentFileByDestination(): void
    {
        $root = $this->buildScaffoldsRoot([
            'default' => "name: default\nfiles:\n\t- layout.php\n",
        ], ['default' => ['layout.php']]);

        $customDir = $this->createTempDirectory() . '/custom';
        mkdir($customDir, 0755, true);
        file_put_contents($customDir . '/scaffold.neon', "name: custom\nextends: default\nfiles:\n\t- layout.php\n");
        touch($customDir . '/layout.php');

        $registry = new ScaffoldRegistry($root, new ScaffoldSchemaLoader());
        $schema = $registry->get($customDir);

        $this->assertCount(1, $schema->files);
        $this->assertStringContainsString($customDir, $schema->files[0]->absoluteSource);
    }

    /**
     * Ensure a missing path-based preset throws a RuntimeException.
     */
    public function testGetThrowsForMissingPath(): void
    {
        $registry = new ScaffoldRegistry('/bundled/scaffolds', new ScaffoldSchemaLoader());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $registry->get('/non/existent/path/my-preset');
    }

    /**
     * Ensure backslash-separated values are treated as path-based presets.
     */
    public function testGetTreatsBackslashPathAsPathBasedPreset(): void
    {
        $registry = new ScaffoldRegistry('/bundled/scaffolds', new ScaffoldSchemaLoader());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffold preset path');

        $registry->get('C:\\fake\\preset');
    }

    // ---------- Helpers ----------

    /**
     * Build a temporary scaffolds root with named preset directories.
     *
     * @param array<string, string> $schemas Map of preset name to scaffold.neon content.
     * @param array<string, list<string>> $stubs Map of preset name to stub files to touch.
     */
    private function buildScaffoldsRoot(array $schemas, array $stubs = []): string
    {
        $root = $this->createTempDirectory();

        foreach ($schemas as $name => $neonContent) {
            $dir = $root . DIRECTORY_SEPARATOR . $name;
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/scaffold.neon', $neonContent);

            foreach ($stubs[$name] ?? [] as $stub) {
                $stubPath = $dir . '/' . $stub;
                $stubDir = dirname($stubPath);
                if (!is_dir($stubDir)) {
                    mkdir($stubDir, 0755, true);
                }

                touch($stubPath);
            }
        }

        return $root;
    }
}
