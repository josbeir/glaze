<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Closure;
use Glaze\Command\InitCommand;
use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Scaffold\ScaffoldOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for init command internals.
 */
final class InitCommandTest extends TestCase
{
    /**
     * Ensure taxonomy parsing handles empty values and deduplication.
     */
    public function testParseTaxonomiesNormalizesInput(): void
    {
        $command = new InitCommand();

        $parsed = $this->callProtected($command, 'parseTaxonomies', ' Tags, categories, tags, , ');
        $fallback = $this->callProtected($command, 'parseTaxonomies', ' , ');

        $this->assertSame(['tags', 'categories'], $parsed);
        $this->assertSame(['tags', 'categories'], $fallback);
    }

    /**
     * Ensure path helpers and title defaults resolve expected values.
     */
    public function testPathAndTitleHelpers(): void
    {
        $command = new InitCommand();

        $title = $this->callProtected($command, 'defaultTitle', 'my-site');
        $relative = $this->callProtected($command, 'normalizePath', 'tmp/new-site/');
        $absoluteUnix = $this->callProtected($command, 'isAbsolutePath', '/tmp/new-site');
        $absoluteWindows = $this->callProtected($command, 'isAbsolutePath', 'C:\\tmp\\new-site');
        $notAbsolute = $this->callProtected($command, 'isAbsolutePath', 'tmp/new-site');
        $emptyPath = $this->callProtected($command, 'isAbsolutePath', '');

        $this->assertSame('My Site', $title);
        $this->assertIsString($relative);
        $this->assertStringEndsWith('tmp' . DIRECTORY_SEPARATOR . 'new-site', $relative);
        $this->assertTrue($absoluteUnix);
        $this->assertTrue($absoluteWindows);
        $this->assertFalse($notAbsolute);
        $this->assertFalse($emptyPath);
    }

    /**
     * Ensure scaffold service accessor caches single instance.
     */
    public function testScaffoldServiceAccessorCachesInstance(): void
    {
        $command = new InitCommand();

        $first = $this->callProtected($command, 'scaffoldService');
        $second = $this->callProtected($command, 'scaffoldService');

        $this->assertInstanceOf(ProjectScaffoldService::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * Ensure normalize string helper handles empty and non-string values.
     */
    public function testNormalizeStringReturnsNullForInvalidValues(): void
    {
        $command = new InitCommand();

        $empty = $this->callProtected($command, 'normalizeString', '   ');
        $nonString = $this->callProtected($command, 'normalizeString', ['site']);
        $value = $this->callProtected($command, 'normalizeString', '  my-site  ');

        $this->assertNull($empty);
        $this->assertNull($nonString);
        $this->assertSame('my-site', $value);
    }

    /**
     * Ensure base path normalization handles empty and relative values.
     */
    public function testNormalizeBasePathNormalizesInput(): void
    {
        $command = new InitCommand();

        $empty = $this->callProtected($command, 'normalizeBasePath', null);
        $root = $this->callProtected($command, 'normalizeBasePath', '/');
        $relative = $this->callProtected($command, 'normalizeBasePath', 'docs');
        $trimmed = $this->callProtected($command, 'normalizeBasePath', '/blog/');

        $this->assertNull($empty);
        $this->assertNull($root);
        $this->assertSame('/docs', $relative);
        $this->assertSame('/blog', $trimmed);
    }

    /**
     * Ensure template normalization falls back to default template.
     */
    public function testNormalizeTemplateNameNormalizesInput(): void
    {
        $command = new InitCommand();

        $defaultTemplate = $this->callProtected($command, 'normalizeTemplateName', null);
        $emptyTemplate = $this->callProtected($command, 'normalizeTemplateName', '  ');
        $customTemplate = $this->callProtected($command, 'normalizeTemplateName', ' landing ');

        $this->assertSame('page', $defaultTemplate);
        $this->assertSame('page', $emptyTemplate);
        $this->assertSame('landing', $customTemplate);
    }

    /**
     * Ensure non-interactive options resolve relative paths against current directory.
     */
    public function testResolveScaffoldOptionsNormalizesRelativeDirectory(): void
    {
        $command = new InitCommand();
        $arguments = new Arguments(
            ['relative-site'],
            ['yes' => true, 'base-path' => 'docs'],
            ['directory'],
        );
        $io = new ConsoleIo();

        $options = $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);

        $this->assertInstanceOf(ScaffoldOptions::class, $options);
        $this->assertSame(getcwd() . DIRECTORY_SEPARATOR . 'relative-site', $options->targetDirectory);
        $this->assertSame('relative-site', $options->siteName);
        $this->assertSame('page', $options->pageTemplate);
        $this->assertSame('/docs', $options->basePath);
    }

    /**
     * Ensure non-interactive options reject missing directory input.
     */
    public function testResolveScaffoldOptionsRejectsMissingDirectory(): void
    {
        $command = new InitCommand();
        $arguments = new Arguments([], ['yes' => true], ['directory']);
        $io = new ConsoleIo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project directory is required.');

        $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);
    }

    /**
     * Ensure non-interactive mode rejects empty site name derived from root directory.
     */
    public function testResolveScaffoldOptionsRejectsMissingDerivedSiteName(): void
    {
        $command = new InitCommand();
        $arguments = new Arguments(['/'], ['yes' => true], ['directory']);
        $io = new ConsoleIo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Site name is required.');

        $this->callProtected($command, 'resolveScaffoldOptions', $arguments, $io);
    }

    /**
     * Invoke a protected method on an object using scope-bound closure.
     *
     * @param object $object Object to invoke method on.
     * @param string $method Protected method name.
     * @param mixed ...$arguments Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $invoker = Closure::bind(
            function (string $method, mixed ...$arguments): mixed {
                return $this->{$method}(...$arguments);
            },
            $object,
            $object::class,
        );

        return $invoker($method, ...$arguments);
    }
}
