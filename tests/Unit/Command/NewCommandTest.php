<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Closure;
use Glaze\Command\NewCommand;
use Glaze\Config\BuildConfig;
use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for new command internals.
 */
final class NewCommandTest extends TestCase
{
    /**
     * Ensure command description and parser options are registered.
     */
    public function testDescriptionAndParserConfiguration(): void
    {
        $command = new NewCommand();
        $parser = $command->getOptionParser();
        $arguments = $parser->arguments();

        $this->assertSame('Create a new content page with frontmatter prompts.', NewCommand::getDescription());
        $this->assertNotEmpty($arguments);
        $this->assertSame('title', $arguments[0]->name());
        $this->assertArrayHasKey('root', $parser->options());
        $this->assertArrayHasKey('path', $parser->options());
        $this->assertArrayHasKey('date', $parser->options());
        $this->assertArrayHasKey('type', $parser->options());
        $this->assertArrayHasKey('draft', $parser->options());
        $this->assertArrayHasKey('index', $parser->options());
    }

    /**
     * Ensure date normalization accepts valid values and rejects invalid ones.
     */
    public function testNormalizeDateInputValidation(): void
    {
        $command = new NewCommand();

        $normalized = $this->callProtected($command, 'normalizeDateInput', '2026-02-24T14:30:00+01:00');
        $this->assertSame('2026-02-24T14:30:00+01:00', $normalized);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid date value');
        $this->callProtected($command, 'normalizeDateInput', 'not-a-date');
    }

    /**
     * Ensure type normalization lowercases known types and rejects unknown values.
     */
    public function testNormalizeTypeInputValidation(): void
    {
        $command = new NewCommand();
        $contentTypes = [
            'blog' => ['paths' => [['match' => 'blog', 'createPattern' => null]], 'defaults' => []],
            'docs' => ['paths' => [['match' => 'docs', 'createPattern' => null]], 'defaults' => []],
        ];

        $normalized = $this->callProtected($command, 'normalizeTypeInput', 'Blog', $contentTypes);
        $empty = $this->callProtected($command, 'normalizeTypeInput', '   ', $contentTypes);

        $this->assertSame('blog', $normalized);
        $this->assertNull($empty);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown content type');
        $this->callProtected($command, 'normalizeTypeInput', 'news', $contentTypes);
    }

    /**
     * Ensure type normalization rejects explicit type when no content types are configured.
     */
    public function testNormalizeTypeInputRejectsWhenNoContentTypesConfigured(): void
    {
        $command = new NewCommand();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No content types are configured');
        $this->callProtected($command, 'normalizeTypeInput', 'blog', []);
    }

    /**
     * Ensure slug helper slugifies path segments consistently.
     */
    public function testSlugifyPathNormalizesSegments(): void
    {
        $command = new NewCommand();

        $slug = $this->callProtected($command, 'slugifyPath', 'Hello World/Another Post');

        $this->assertSame('hello-world/another-post', $slug);
    }

    /**
     * Ensure page input resolution respects arguments and boolean draft option.
     */
    public function testResolvePageInputUsesArgumentsAndDraftOption(): void
    {
        $command = new NewCommand();
        $config = new BuildConfig(
            projectRoot: '/tmp/project',
            contentTypes: [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => null],
                    ],
                    'defaults' => [],
                ],
            ],
        );

        $args = new Arguments(
            ['My Post'],
            [
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'blog',
                'draft' => true,
                'slug' => null,
            ],
            ['title'],
        );

        $input = $this->callProtected($command, 'resolvePageInput', $args, new ConsoleIo(), $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool} $input */

        $this->assertSame('My Post', $input['title']);
        $this->assertSame('2026-02-24T10:00:00+00:00', $input['date']);
        $this->assertSame('blog', $input['type']);
        $this->assertTrue($input['draft']);
        $this->assertSame('my-post', $input['slug']);
        $this->assertSame(['match' => 'blog', 'createPattern' => null], $input['pathRule']);
        $this->assertNull($input['pathPrefix']);
        $this->assertFalse($input['index']);
    }

    /**
     * Ensure non-yes flow falls back to prompt defaults when IO is non-interactive.
     */
    public function testResolvePageInputUsesPromptDefaultsWhenNotInteractive(): void
    {
        $command = new NewCommand();
        $config = new BuildConfig(projectRoot: '/tmp/project');
        $args = new Arguments(
            ['My Post'],
            [
                'yes' => false,
                'draft' => null,
                'date' => null,
                'type' => null,
                'slug' => null,
                'title' => null,
            ],
            ['title'],
        );

        $io = new ConsoleIo();
        $io->setInteractive(false);

        $input = $this->callProtected($command, 'resolvePageInput', $args, $io, $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool} $input */
        $this->assertSame('My Post', $input['title']);
        $this->assertNull($input['type']);
        $this->assertFalse($input['draft']);
        $this->assertSame('my-post', $input['slug']);
        $this->assertNull($input['pathRule']);
        $this->assertNull($input['pathPrefix']);
        $this->assertFalse($input['index']);
        $this->assertSame($input['date'], $this->callProtected($command, 'normalizeDateInput', $input['date']));
    }

    /**
     * Ensure title can be derived from explicit slug in non-interactive mode.
     */
    public function testResolvePageInputDerivesTitleFromSlug(): void
    {
        $command = new NewCommand();
        $config = new BuildConfig(projectRoot: '/tmp/project');
        $args = new Arguments(
            [],
            [
                'yes' => true,
                'slug' => 'posts/my-new-post',
                'date' => '2026-02-24T10:00:00+00:00',
                'draft' => false,
                'type' => null,
            ],
            ['title'],
        );

        $input = $this->callProtected($command, 'resolvePageInput', $args, new ConsoleIo(), $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool} $input */
        $this->assertSame('My New Post', $input['title']);
        $this->assertSame('posts/my-new-post', $input['slug']);
    }

    /**
     * Ensure non-interactive mode requires --path when type has multiple paths.
     */
    public function testResolvePageInputRequiresPathForMultiPathTypeInNonInteractiveMode(): void
    {
        $command = new NewCommand();
        $config = new BuildConfig(
            projectRoot: '/tmp/project',
            contentTypes: [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => 'blog/{date:Y/m/d}'],
                        ['match' => 'posts', 'createPattern' => null],
                    ],
                    'defaults' => [],
                ],
            ],
        );

        $args = new Arguments(
            ['My Post'],
            [
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'blog',
                'draft' => false,
                'slug' => null,
                'path' => null,
            ],
            ['title'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has multiple paths');

        $this->callProtected($command, 'resolvePageInput', $args, new ConsoleIo(), $config);
    }

    /**
     * Ensure non-interactive mode rejects missing title input.
     */
    public function testResolvePageInputRejectsMissingTitleInNonInteractiveMode(): void
    {
        $command = new NewCommand();
        $config = new BuildConfig(projectRoot: '/tmp/project');
        $args = new Arguments([], ['yes' => true], ['title']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Page title is required');

        $this->callProtected($command, 'resolvePageInput', $args, new ConsoleIo(), $config);
    }

    /**
     * Ensure execute writes a new page file using provided options.
     */
    public function testExecuteCreatesPageFile(): void
    {
        $projectRoot = $this->createTempProjectRoot();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - blog\n    defaults: {}\n",
        );

        $command = new NewCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'blog',
                'draft' => true,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, new ConsoleIo());
        $targetPath = $projectRoot . '/content/blog/my-post.dj';

        $this->assertSame(NewCommand::CODE_SUCCESS, $code);
        $this->assertFileExists($targetPath);

        $output = file_get_contents($targetPath);
        $this->assertIsString($output);
        $this->assertStringContainsString('title: My Post', $output);
        $this->assertStringContainsString("date: '2026-02-24T10:00:00+00:00'", $output);
        $this->assertStringContainsString('type: blog', $output);
        $this->assertStringContainsString('draft: true', $output);
    }

    /**
     * Ensure execute supports subfolder path prefixes.
     */
    public function testExecuteCreatesPageInSubfolderPath(): void
    {
        $projectRoot = $this->createTempProjectRoot();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - match: blog\n        createPattern: 'blog/{date:Y}'\n      - match: posts\n    defaults: {}\n",
        );

        $command = new NewCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'blog',
                'draft' => false,
                'path' => 'posts',
                'index' => false,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, new ConsoleIo());
        $targetPath = $projectRoot . '/content/posts/my-post.dj';

        $this->assertSame(NewCommand::CODE_SUCCESS, $code);
        $this->assertFileExists($targetPath);
    }

    /**
     * Ensure execute creates pages from configured createPattern when path is selected.
     */
    public function testExecuteCreatesPageUsingCreatePattern(): void
    {
        $projectRoot = $this->createTempProjectRoot();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - match: blog\n        createPattern: 'blog/{date:Y/m}'\n    defaults: {}\n",
        );

        $command = new NewCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'blog',
                'path' => 'blog',
                'draft' => false,
                'index' => false,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, new ConsoleIo());
        $targetPath = $projectRoot . '/content/blog/2026/02/my-post.dj';

        $this->assertSame(NewCommand::CODE_SUCCESS, $code);
        $this->assertFileExists($targetPath);
    }

    /**
     * Ensure execute supports folder/index.dj bundle layout.
     */
    public function testExecuteCreatesIndexBundlePage(): void
    {
        $projectRoot = $this->createTempProjectRoot();

        $command = new NewCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => null,
                'draft' => false,
                'path' => 'blog',
                'index' => true,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, new ConsoleIo());
        $targetPath = $projectRoot . '/content/blog/my-post/index.dj';

        $this->assertSame(NewCommand::CODE_SUCCESS, $code);
        $this->assertFileExists($targetPath);
    }

    /**
     * Ensure execute returns an error for unknown types.
     */
    public function testExecuteReturnsErrorForUnknownType(): void
    {
        $projectRoot = $this->createTempProjectRoot();
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - blog\n    defaults: {}\n",
        );

        $command = new NewCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'type' => 'unknown',
                'draft' => false,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, new ConsoleIo());

        $this->assertSame(NewCommand::CODE_ERROR, $code);
    }

    /**
     * Ensure execute refuses overwrite unless force is enabled.
     */
    public function testExecuteRespectsForceOverwriteOption(): void
    {
        $projectRoot = $this->createTempProjectRoot();
        mkdir($projectRoot . '/content/blog', 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - blog\n    defaults: {}\n",
        );
        file_put_contents($projectRoot . '/content/blog/my-post.dj', 'existing');

        $command = new NewCommand();
        $baseOptions = [
            'root' => $projectRoot,
            'yes' => true,
            'date' => '2026-02-24T10:00:00+00:00',
            'type' => 'blog',
            'draft' => false,
            'slug' => null,
            'title' => null,
        ];

        $withoutForce = new Arguments(['My Post'], $baseOptions + ['force' => false], ['title']);
        $withForce = new Arguments(['My Post'], $baseOptions + ['force' => true], ['title']);

        $this->assertSame(NewCommand::CODE_ERROR, $command->execute($withoutForce, new ConsoleIo()));
        $this->assertSame(NewCommand::CODE_SUCCESS, $command->execute($withForce, new ConsoleIo()));
    }

    /**
     * Ensure helper normalization methods return expected fallback values.
     */
    public function testHelperNormalizationFallbacks(): void
    {
        $command = new NewCommand();

        $emptySlug = $this->callProtected($command, 'slugifyPath', '///');
        $relativePath = $this->callProtected($command, 'relativeToRoot', '/other/path/file.dj', '/tmp/project');
        $rootOption = $this->callProtected($command, 'normalizeRootOption', ' /tmp/site ');
        $normalizedPathPrefix = $this->callProtected($command, 'normalizePathPrefix', '/posts/2026/');
        $derivedTitle = $this->callProtected($command, 'deriveTitleFromPath', 'posts/my-post');

        $this->assertSame('index', $emptySlug);
        $this->assertSame('/other/path/file.dj', $relativePath);
        $this->assertSame(Normalization::path('/tmp/site'), $rootOption);
        $this->assertSame('posts/2026', $normalizedPathPrefix);
        $this->assertSame('My Post', $derivedTitle);
    }

    /**
     * Create a temporary project root path.
     */
    protected function createTempProjectRoot(): string
    {
        $path = sys_get_temp_dir() . '/glaze-new-' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
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
