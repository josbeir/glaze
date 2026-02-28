<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\ScaffoldFileEntry;
use Glaze\Scaffold\ScaffoldSchema;
use Glaze\Scaffold\ScaffoldSchemaLoader;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ScaffoldSchemaLoader.
 */
final class ScaffoldSchemaLoaderTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure the loader parses all supported schema fields.
     */
    public function testLoadParsesSchemaFields(): void
    {
        $dir = $this->createSchemaDirectory([
            'name' => 'mypreset',
            'description' => 'My preset description',
            'extends' => null,
            'files' => ['.gitignore', 'content/index.dj'],
            'config' => [],
        ], ['files' => ['.gitignore', 'content/index.dj']]);

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertInstanceOf(ScaffoldSchema::class, $schema);
        $this->assertSame('mypreset', $schema->name);
        $this->assertSame('My preset description', $schema->description);
        $this->assertNull($schema->extends);
        $this->assertSame(0, $schema->weight);
        $this->assertCount(2, $schema->files);
    }

    /**
     * Ensure file entries with .tpl suffix are marked as templates.
     */
    public function testLoadMarksTemplateFiles(): void
    {
        $dir = $this->createSchemaDirectory(
            ['name' => 'test', 'description' => '', 'files' => ['glaze.neon.tpl', '.gitignore']],
            ['files' => ['glaze.neon.tpl', '.gitignore']],
        );

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $byDest = [];
        foreach ($schema->files as $entry) {
            $byDest[$entry->destination] = $entry;
        }

        $this->assertArrayHasKey('glaze.neon', $byDest);
        $this->assertTrue($byDest['glaze.neon']->isTemplate);

        $this->assertArrayHasKey('.gitignore', $byDest);
        $this->assertFalse($byDest['.gitignore']->isTemplate);
    }

    /**
     * Ensure the destination path strips the .tpl suffix.
     */
    public function testLoadStripsTemplateExtensionFromDestination(): void
    {
        $dir = $this->createSchemaDirectory(
            ['name' => 'test', 'description' => '', 'files' => ['package.json.tpl']],
            ['files' => ['package.json.tpl']],
        );

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertCount(1, $schema->files);
        $this->assertSame('package.json', $schema->files[0]->destination);
        $this->assertTrue($schema->files[0]->isTemplate);
    }

    /**
     * Ensure object notation for file entries is parsed correctly.
     */
    public function testLoadParsesObjectNotationFileEntries(): void
    {
        $neon = "name: test\nfiles:\n\t- src: some/file.txt\n\t- source: another.js\n";
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', $neon);
        mkdir($dir . '/some', 0755, true);
        touch($dir . '/some/file.txt');
        touch($dir . '/another.js');

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $destinations = array_map(
            static fn(ScaffoldFileEntry $f): string => $f->destination,
            $schema->files,
        );
        $this->assertContains('some/file.txt', $destinations);
        $this->assertContains('another.js', $destinations);
    }

    /**
     * Ensure extends field is parsed and returned on the schema.
     */
    public function testLoadParsesExtendsField(): void
    {
        $dir = $this->createSchemaDirectory(
            ['name' => 'child', 'description' => '', 'extends' => 'default', 'files' => []],
            [],
        );

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertSame('default', $schema->extends);
    }

    /**
     * Ensure the config block is loaded as an array.
     */
    public function testLoadParsesConfigBlock(): void
    {
        $neon = "name: test\nconfig:\n\tbuild:\n\t\tvite:\n\t\t\tenabled: true\n";
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', $neon);

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertArrayHasKey('build', $schema->config);
        $this->assertIsArray($schema->config['build']);
    }

    /**
     * Ensure a missing scaffold.neon throws a RuntimeException.
     */
    public function testLoadThrowsForMissingSchemaFile(): void
    {
        $dir = $this->createTempDirectory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffold schema file not found');

        (new ScaffoldSchemaLoader())->load($dir);
    }

    /**
     * Ensure the absolute source path is resolved from the schema directory.
     */
    public function testLoadResolvesAbsoluteSourcePath(): void
    {
        $dir = $this->createSchemaDirectory(
            ['name' => 'test', 'description' => '', 'files' => ['.gitignore']],
            ['files' => ['.gitignore']],
        );

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertCount(1, $schema->files);
        $this->assertSame($dir . DIRECTORY_SEPARATOR . '.gitignore', $schema->files[0]->absoluteSource);
    }

    /**
     * Ensure the name defaults to the directory basename when not specified.
     */
    public function testLoadDefaultsNameToDirectoryBasename(): void
    {
        $dir = $this->createTempDirectory() . '/my-preset';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/scaffold.neon', "description: No name field\n");

        $loader = new ScaffoldSchemaLoader();
        $schema = $loader->load($dir);

        $this->assertSame('my-preset', $schema->name);
    }

    /**
     * Ensure the description defaults to an empty string when omitted.
     */
    public function testLoadDefaultsDescriptionWhenMissing(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', "name: preset\n");

        $schema = (new ScaffoldSchemaLoader())->load($dir);

        $this->assertSame('', $schema->description);
    }

    /**
     * Ensure the weight field is parsed from the schema.
     */
    public function testLoadParsesWeightField(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', "name: weighted\nweight: 15\n");

        $schema = (new ScaffoldSchemaLoader())->load($dir);

        $this->assertSame(15, $schema->weight);
    }

    /**
     * Ensure the weight defaults to zero when omitted.
     */
    public function testLoadDefaultsWeightToZero(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', "name: noweight\n");

        $schema = (new ScaffoldSchemaLoader())->load($dir);

        $this->assertSame(0, $schema->weight);
    }

    /**
     * Ensure non-mapping NEON content is rejected.
     */
    public function testLoadThrowsWhenSchemaIsNotMapping(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', "true\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffold schema must be a NEON mapping');

        (new ScaffoldSchemaLoader())->load($dir);
    }

    /**
     * Ensure unsupported and empty file entries are ignored.
     */
    public function testLoadSkipsUnsupportedFileEntries(): void
    {
        $neon = "name: test\nfiles:\n\t- \n\t- src: \"   \"\n\t- source: \"\"\n\t- unsupported: value\n\t- 123\n\t- valid.txt\n";
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/scaffold.neon', $neon);
        touch($dir . '/valid.txt');

        $schema = (new ScaffoldSchemaLoader())->load($dir);

        $this->assertCount(1, $schema->files);
        $this->assertSame('valid.txt', $schema->files[0]->destination);
    }

    // ---------- Helpers ----------

    /**
     * Create a temporary scaffold directory with a NEON schema and stub scaffold files.
     *
     * @param array<mixed> $schemaData Schema data encoded to NEON.
     * @param array<string, list<string>> $stubFiles Map of logical groups to file paths to touch.
     */
    private function createSchemaDirectory(array $schemaData, array $stubFiles): string
    {
        $dir = $this->createTempDirectory();

        // Write scaffold.neon with just the name/description/extends fields
        $lines = [];
        if (isset($schemaData['name']) && is_string($schemaData['name'])) {
            $lines[] = 'name: ' . $schemaData['name'];
        }

        if (isset($schemaData['description']) && is_string($schemaData['description'])) {
            $lines[] = 'description: ' . $schemaData['description'];
        }

        if (isset($schemaData['extends']) && is_string($schemaData['extends'])) {
            $lines[] = 'extends: ' . $schemaData['extends'];
        }

        if (isset($schemaData['files']) && is_array($schemaData['files']) && $schemaData['files'] !== []) {
            $lines[] = 'files:';
            foreach ($schemaData['files'] as $file) {
                if (is_string($file)) {
                    $lines[] = "\t- " . $file;
                }
            }
        }

        file_put_contents($dir . '/scaffold.neon', implode("\n", $lines) . "\n");

        // Touch the referenced stub files
        $allFiles = array_merge(...array_values($stubFiles));
        foreach ($allFiles as $file) {
            $filePath = $dir . '/' . $file;
            $fileDir = dirname($filePath);
            if (!is_dir($fileDir)) {
                mkdir($fileDir, 0755, true);
            }

            touch($filePath);
        }

        return $dir;
    }
}
