<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Closure;
use Glaze\Command\NewCommand;
use Glaze\Config\BuildConfig;
use Glaze\Tests\Helper\ConsoleIoTestTrait;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for new command internals.
 */
final class NewCommandTest extends TestCase
{
    use ConsoleIoTestTrait;
    use ContainerTestTrait;

    /**
     * Ensure command description and parser options are registered.
     */
    public function testDescriptionAndParserConfiguration(): void
    {
        $command = $this->createCommand();
        $parser = $command->getOptionParser();
        $arguments = $parser->arguments();

        $this->assertSame('Create a new content page with frontmatter prompts.', NewCommand::getDescription());
        $this->assertNotEmpty($arguments);
        $this->assertSame('title', $arguments[0]->name());
        $this->assertArrayHasKey('root', $parser->options());
        $this->assertArrayHasKey('path', $parser->options());
        $this->assertArrayHasKey('date', $parser->options());
        $this->assertArrayHasKey('weight', $parser->options());
        $this->assertArrayHasKey('type', $parser->options());
        $this->assertArrayHasKey('draft', $parser->options());
        $this->assertArrayHasKey('index', $parser->options());
    }

    /**
     * Ensure date normalization accepts valid values and rejects invalid ones.
     */
    public function testNormalizeDateInputValidation(): void
    {
        $command = $this->createCommand();

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
        $command = $this->createCommand();
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
        $command = $this->createCommand();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No content types are configured');
        $this->callProtected($command, 'normalizeTypeInput', 'blog', []);
    }

    /**
     * Ensure slug helper slugifies path segments consistently.
     */
    public function testSlugifyPathNormalizesSegments(): void
    {
        $command = $this->createCommand();

        $slug = $this->callProtected($command, 'slugifyPath', 'Hello World/Another Post');

        $this->assertSame('hello-world/another-post', $slug);
    }

    /**
     * Ensure page input resolution respects arguments and boolean draft option.
     */
    public function testResolvePageInputUsesArgumentsAndDraftOption(): void
    {
        $command = $this->createCommand();
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
                'weight' => '10',
                'type' => 'blog',
                'draft' => true,
                'slug' => null,
            ],
            ['title'],
        );

        $input = $this->callProtected($command, 'resolvePageInput', $args, $this->createConsoleIo(), $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool, weight: int|null} $input */

        $this->assertSame('My Post', $input['title']);
        $this->assertSame('2026-02-24T10:00:00+00:00', $input['date']);
        $this->assertSame('blog', $input['type']);
        $this->assertTrue($input['draft']);
        $this->assertSame('my-post', $input['slug']);
        $this->assertSame(['match' => 'blog', 'createPattern' => null], $input['pathRule']);
        $this->assertNull($input['pathPrefix']);
        $this->assertFalse($input['index']);
        $this->assertSame(10, $input['weight']);
    }

    /**
     * Ensure non-yes flow falls back to prompt defaults when IO is non-interactive.
     */
    public function testResolvePageInputUsesPromptDefaultsWhenNotInteractive(): void
    {
        $command = $this->createCommand();
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

        $io = $this->createConsoleIo();
        $io->setInteractive(false);

        $input = $this->callProtected($command, 'resolvePageInput', $args, $io, $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool, weight: int|null} $input */
        $this->assertSame('My Post', $input['title']);
        $this->assertNull($input['type']);
        $this->assertFalse($input['draft']);
        $this->assertSame('my-post', $input['slug']);
        $this->assertNull($input['pathRule']);
        $this->assertNull($input['pathPrefix']);
        $this->assertFalse($input['index']);
        $this->assertNull($input['weight']);
        $this->assertSame($input['date'], $this->callProtected($command, 'normalizeDateInput', $input['date']));
    }

    /**
     * Ensure title can be derived from explicit slug in non-interactive mode.
     */
    public function testResolvePageInputDerivesTitleFromSlug(): void
    {
        $command = $this->createCommand();
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

        $input = $this->callProtected($command, 'resolvePageInput', $args, $this->createConsoleIo(), $config);

        $this->assertIsArray($input);
        /** @var array{title: string, date: string, type: string|null, draft: bool, slug: string, pathRule: array{match: string, createPattern: string|null}|null, pathPrefix: string|null, index: bool, weight: int|null} $input */
        $this->assertSame('My New Post', $input['title']);
        $this->assertSame('posts/my-new-post', $input['slug']);
        $this->assertNull($input['weight']);
    }

    /**
     * Ensure non-interactive mode requires --path when type has multiple paths.
     */
    public function testResolvePageInputRequiresPathForMultiPathTypeInNonInteractiveMode(): void
    {
        $command = $this->createCommand();
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

        $this->callProtected($command, 'resolvePageInput', $args, $this->createConsoleIo(), $config);
    }

    /**
     * Ensure non-interactive mode rejects missing title input.
     */
    public function testResolvePageInputRejectsMissingTitleInNonInteractiveMode(): void
    {
        $command = $this->createCommand();
        $config = new BuildConfig(projectRoot: '/tmp/project');
        $args = new Arguments([], ['yes' => true], ['title']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Page title is required');

        $this->callProtected($command, 'resolvePageInput', $args, $this->createConsoleIo(), $config);
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

        $command = $this->createCommand();
        $args = new Arguments(
            ['My Post'],
            [
                'root' => $projectRoot,
                'yes' => true,
                'date' => '2026-02-24T10:00:00+00:00',
                'weight' => '5',
                'type' => 'blog',
                'draft' => true,
                'slug' => null,
                'title' => null,
                'force' => false,
            ],
            ['title'],
        );

        $code = $command->execute($args, $this->createConsoleIo());
        $targetPath = $projectRoot . '/content/blog/my-post.dj';

        $this->assertSame(NewCommand::CODE_SUCCESS, $code);
        $this->assertFileExists($targetPath);

        $output = file_get_contents($targetPath);
        $this->assertIsString($output);
        $this->assertStringContainsString('title: My Post', $output);
        $this->assertStringContainsString("date: '2026-02-24T10:00:00+00:00'", $output);
        $this->assertStringContainsString('type: blog', $output);
        $this->assertStringContainsString('draft: true', $output);
        $this->assertStringContainsString('weight: 5', $output);
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

        $command = $this->createCommand();
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

        $code = $command->execute($args, $this->createConsoleIo());
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

        $command = $this->createCommand();
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

        $code = $command->execute($args, $this->createConsoleIo());
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

        $command = $this->createCommand();
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

        $code = $command->execute($args, $this->createConsoleIo());
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

        $command = $this->createCommand();
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

        $code = $command->execute($args, $this->createConsoleIo());

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

        $command = $this->createCommand();
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

        $this->assertSame(NewCommand::CODE_ERROR, $command->execute($withoutForce, $this->createConsoleIo()));
        $this->assertSame(NewCommand::CODE_SUCCESS, $command->execute($withForce, $this->createConsoleIo()));
    }

    /**
     * Ensure helper normalization methods return expected fallback values.
     */
    public function testHelperNormalizationFallbacks(): void
    {
        $command = $this->createCommand();

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
     * Ensure type path resolution returns null when no rules are configured.
     */
    public function testResolveTypePathRuleReturnsNullWhenNoRules(): void
    {
        $command = $this->createCommand();

        $resolved = $this->callProtected(
            $command,
            'resolveTypePathRule',
            $this->createConsoleIo(),
            ['blog' => ['paths' => [], 'defaults' => []]],
            'blog',
            null,
            true,
        );

        $this->assertNull($resolved);
    }

    /**
     * Ensure type path resolution throws clear error for unknown explicit path option.
     */
    public function testResolveTypePathRuleThrowsWhenExplicitPathIsUnknown(): void
    {
        $command = $this->createCommand();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown path');

        $this->callProtected(
            $command,
            'resolveTypePathRule',
            $this->createConsoleIo(),
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => 'blog/{slug}'],
                        ['match' => 'posts', 'createPattern' => null],
                    ],
                    'defaults' => [],
                ],
            ],
            'blog',
            'unknown-path',
            true,
        );
    }

    /**
     * Ensure interactive type path selection falls back to first rule when answer is not matched.
     */
    public function testResolveTypePathRuleFallsBackToFirstRuleForUnknownChoice(): void
    {
        $command = $this->createCommand();
        $io = $this->createMock(ConsoleIo::class);
        $io->expects($this->once())
            ->method('askChoice')
            ->willReturn('unknown-path');

        $resolved = $this->callProtected(
            $command,
            'resolveTypePathRule',
            $io,
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => 'blog/{slug}'],
                        ['match' => 'posts', 'createPattern' => null],
                    ],
                    'defaults' => [],
                ],
            ],
            'blog',
            null,
            false,
        );

        $this->assertIsArray($resolved);
        /** @var array{match: string, createPattern: string|null} $resolved */
        $this->assertSame('blog', $resolved['match']);
    }

    /**
     * Ensure type input uses interactive prompt and requires explicit value in non-interactive mode.
     */
    public function testResolveTypeInputPromptAndRequiredBranch(): void
    {
        $command = $this->createCommand();
        $contentTypes = [
            'blog' => [
                'paths' => [['match' => 'blog', 'createPattern' => null]],
                'defaults' => [],
            ],
        ];

        $io = $this->createMock(ConsoleIo::class);
        $io->expects($this->once())
            ->method('askChoice')
            ->willReturn('blog');

        $interactive = $this->callProtected(
            $command,
            'resolveTypeInput',
            new Arguments([], ['type' => null], []),
            $io,
            $contentTypes,
            false,
        );
        $this->assertSame('blog', $interactive);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A content type is required');

        $this->callProtected(
            $command,
            'resolveTypeInput',
            new Arguments([], ['type' => null], []),
            $this->createConsoleIo(),
            $contentTypes,
            true,
        );
    }

    /**
     * Ensure draft and weight helper branches cover non-interactive default and invalid input.
     */
    public function testResolveDraftInputDefaultAndInvalidWeightBranches(): void
    {
        $command = $this->createCommand();

        $draft = $this->callProtected(
            $command,
            'resolveDraftInput',
            new Arguments([], ['draft' => null], []),
            $this->createConsoleIo(),
            true,
        );
        $this->assertFalse($draft);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid weight value');
        $this->callProtected($command, 'normalizeWeightInput', 'invalid-int');
    }

    /**
     * Ensure promptType and normalizeTypeInput cover empty input branches.
     */
    public function testPromptTypeAndNormalizeTypeInputEmptyBranches(): void
    {
        $command = $this->createCommand();

        $promptType = $this->callProtected($command, 'promptType', $this->createConsoleIo(), []);
        $this->assertNull($promptType);

        $normalizedType = $this->callProtected(
            $command,
            'normalizeTypeInput',
            null,
            ['blog' => ['paths' => [], 'defaults' => []]],
        );
        $this->assertNull($normalizedType);
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

    /**
     * Create a command instance with concrete dependencies.
     */
    protected function createCommand(): NewCommand
    {
        /** @var \Glaze\Command\NewCommand */
        return $this->service(NewCommand::class);
    }
}
