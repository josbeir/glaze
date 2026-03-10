<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Glaze\Command\RoutesCommand;
use Glaze\Tests\Helper\ConsoleIoTestTrait;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the glaze routes command.
 */
final class RoutesCommandTest extends TestCase
{
    use ConsoleIoTestTrait;
    use ContainerTestTrait;
    use FilesystemTestTrait;

    // -------------------------------------------------------------------------
    // Routing / discovery tests
    // -------------------------------------------------------------------------

    /**
     * execute() returns CODE_SUCCESS and outputs the URL path in the table.
     */
    public function testExecuteDisplaysRouteTableForBasicProject(): void
    {
        $root = $this->createMinimalProject(['index.dj' => "# Home\n"]);
        [$io, $read] = $this->captureConsoleIo();

        $exitCode = $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertSame(RoutesCommand::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('/', $output);
    }

    /**
     * execute() shows a "No routes found" message when the content directory is empty.
     */
    public function testExecuteShowsNoRoutesMessageForEmptyContent(): void
    {
        $root = $this->createMinimalProject([]);
        [$io, $read] = $this->captureConsoleIo();

        $exitCode = $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertSame(RoutesCommand::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('No routes found', $output);
    }

    /**
     * execute() returns CODE_ERROR when the project root is invalid.
     */
    public function testExecuteReturnsErrorCodeForInvalidRoot(): void
    {
        [$io] = $this->captureConsoleIo();

        $exitCode = $this->createCommand()->execute(
            $this->makeArgs(['root' => '/non/existent/dir/glaze_test_' . uniqid()]),
            $io,
        );

        $this->assertSame(RoutesCommand::CODE_ERROR, $exitCode);
    }

    /**
     * execute() excludes draft pages by default.
     */
    public function testExecuteExcludesDraftsByDefault(): void
    {
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'draft-page.dj' => "---\ndraft: true\n---\n# Draft\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertStringNotContainsString('/draft-page/', $output);
    }

    /**
     * execute() includes draft pages when --drafts flag is set.
     */
    public function testExecuteIncludesDraftsWithDraftsFlag(): void
    {
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'draft-page.dj' => "---\ndraft: true\n---\n# Draft\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'drafts' => true]),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('draft', $output);
    }

    /**
     * execute() filters results by content type when --type is given.
     */
    public function testExecuteFiltersRoutesByContentType(): void
    {
        $neon = <<<'NEON'
contentTypes:
    post:
        paths:
            - match: post
              createPattern: ~
        defaults: {}
NEON;
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'post/hello.dj' => "# Hello\n",
        ], $neon);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'type' => 'post']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('hello', $output);
        $this->assertStringContainsString('type: post', $output);
    }

    /**
     * execute() filters by multiple comma-separated content types.
     */
    public function testExecuteFiltersRoutesByMultipleTypes(): void
    {
        $neon = <<<'NEON'
contentTypes:
    post:
        paths:
            - match: post
              createPattern: ~
        defaults: {}
    note:
        paths:
            - match: note
              createPattern: ~
        defaults: {}
NEON;
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'post/hello.dj' => "# Hello\n",
            'note/idea.dj' => "# Idea\n",
        ], $neon);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'type' => 'post,note']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('hello', $output);
        $this->assertStringContainsString('idea', $output);
        $this->assertStringContainsString('type: post, note', $output);
    }

    /**
     * execute() filters by taxonomy presence (key only, any value).
     */
    public function testExecuteFiltersByTaxonomyKeyPresence(): void
    {
        $neon = <<<'NEON'
taxonomies:
    tag: ~
NEON;
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'tagged.dj' => "---\ntag: php\n---\n# Tagged\n",
        ], $neon);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'taxonomy' => 'tag']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('tagged', $output);
        $this->assertStringNotContainsString('Home', $output);
        $this->assertStringContainsString('taxonomy: tag', $output);
    }

    /**
     * execute() filters by taxonomy key=value.
     */
    public function testExecuteFiltersByTaxonomyKeyValue(): void
    {
        $neon = <<<'NEON'
taxonomies:
    tag: ~
NEON;
        $root = $this->createMinimalProject([
            'php-post.dj' => "---\ntag: php\n---\n# PHP Post\n",
            'js-post.dj' => "---\ntag: js\n---\n# JS Post\n",
        ], $neon);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'taxonomy' => 'tag=php']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('php-post', $output);
        $this->assertStringNotContainsString('js-post', $output);
        $this->assertStringContainsString('tag=php', $output);
    }

    /**
     * execute() truncates long cell values when --truncate is given.
     */
    public function testExecuteTruncatesCellsWhenTruncateOptionIsSet(): void
    {
        $root = $this->createMinimalProject([
            'a-very-long-slug-that-should-be-truncated-in-the-output.dj' => "# Long\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'truncate' => '20']),
            $io,
        );

        $output = $read();
        // The full URL would be > 20 chars; expect truncation ellipsis
        $this->assertStringContainsString('…', $output);
    }

    /**
     * execute() outputs a summary line with route count.
     */
    public function testExecuteOutputsSummaryLineWithRouteCount(): void
    {
        $root = $this->createMinimalProject([
            'index.dj' => "# Home\n",
            'about.dj' => "# About\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertMatchesRegularExpression('/\d+ route/', $output);
    }

    /**
     * execute() lists multiple pages sorted alphabetically by URL path.
     */
    public function testExecuteListsPagesSortedByUrlPath(): void
    {
        $root = $this->createMinimalProject([
            'zzz.dj' => "# Zzz\n",
            'aaa.dj' => "# Aaa\n",
            'index.dj' => "# Home\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $posAaa = strpos($output, 'aaa');
        $posZzz = strpos($output, 'zzz');

        $this->assertNotFalse($posAaa);
        $this->assertNotFalse($posZzz);
        $this->assertLessThan($posZzz, $posAaa);
    }

    // -------------------------------------------------------------------------
    // i18n tests
    // -------------------------------------------------------------------------

    /**
     * execute() adds a Language column when the site has i18n enabled.
     */
    public function testExecuteShowsLanguageColumnForI18nSites(): void
    {
        $root = $this->createI18nProject([
            'index.dj' => "# Home EN\n",
        ], [
            'nl/index.dj' => "# Home NL\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('Language', $output);
    }

    /**
     * execute() does not add a Language column for non-i18n sites.
     */
    public function testExecuteDoesNotShowLanguageColumnForSingleLanguageSites(): void
    {
        $root = $this->createMinimalProject(['index.dj' => "# Home\n"]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertStringNotContainsString('Language', $output);
    }

    /**
     * execute() filters to a single language when --lang is given.
     */
    public function testExecuteFiltersRoutesByLanguage(): void
    {
        $root = $this->createI18nProject([
            'index.dj' => "# Home EN\n",
        ], [
            'nl/index.dj' => "# Home NL\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'lang' => 'nl']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('nl', $output);
        $this->assertStringContainsString('lang: nl', $output);
        // Should only contain 1 route (nl)
        $this->assertMatchesRegularExpression('/1 route/', $output);
    }

    /**
     * execute() filters to multiple languages when --lang contains comma-separated codes.
     */
    public function testExecuteFiltersRoutesByMultipleLanguages(): void
    {
        $root = $this->createI18nProject([
            'index.dj' => "# Home EN\n",
            'about.dj' => "# About\n",
        ], [
            'nl/index.dj' => "# Home NL\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'lang' => 'en,nl']),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('lang: en, nl', $output);
        // 2 EN + 1 NL = 3 routes
        $this->assertMatchesRegularExpression('/3 route/', $output);
    }

    /**
     * execute() includes a per-language route count breakdown in the summary when i18n is active.
     */
    public function testExecuteSummaryIncludesLanguageBreakdown(): void
    {
        $root = $this->createI18nProject([
            'index.dj' => "# Home EN\n",
        ], [
            'nl/index.dj' => "# Home NL\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root]),
            $io,
        );

        $output = $read();
        $this->assertStringContainsString('en: 1', $output);
        $this->assertStringContainsString('nl: 1', $output);
    }

    /**
     * execute() shows the source path relative to a language-specific contentDir.
     */
    public function testExecuteResolvesSourceRelativeToLanguageContentDir(): void
    {
        $root = $this->createI18nProject([], [
            'nl/article.dj' => "# NL Article\n",
        ]);
        [$io, $read] = $this->captureConsoleIo();

        $this->createCommand()->execute(
            $this->makeArgs(['root' => $root, 'lang' => 'nl']),
            $io,
        );

        $output = $read();
        // Source should be relative to content/nl/, not the full absolute path
        $this->assertStringContainsString('article.dj', $output);
        // Must not contain the absolute project temp root in the source column
        $this->assertStringNotContainsString($root, $output);
    }

    /**
     * Create a temporary project with two-language i18n configuration.
     *
     * English content files are written to `content/en/`, Dutch content to
     * `content/nl/`. A `glaze.neon` is created with the i18n block pre-filled.
     * An optional extra NEON string may be appended for additional config.
     *
     * @param array<string, string> $enFiles Map of EN content path → content (relative to `content/en/`).
     * @param array<string, string> $nlFiles Map of NL content path → content (relative to `content/nl/`).
     * @param string|null $extraNeon Additional NEON configuration to append.
     * @throws \RuntimeException When a directory cannot be created.
     */
    protected function createI18nProject(
        array $enFiles,
        array $nlFiles,
        ?string $extraNeon = null,
    ): string {
        $neon = <<<'NEON'
i18n:
    defaultLanguage: en
    languages:
        en:
            label: English
            urlPrefix: ""
            contentDir: content/en
        nl:
            label: Nederlands
            urlPrefix: /nl
            contentDir: content/nl
NEON;

        if ($extraNeon !== null) {
            $neon .= "\n" . $extraNeon;
        }

        $root = $this->createTempDirectory();

        foreach (['en', 'nl'] as $lang) {
            $dir = $root . '/content/' . $lang;
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $dir));
            }
        }

        $templatesDir = $root . '/templates';
        if (!mkdir($templatesDir, 0755, true) && !is_dir($templatesDir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $templatesDir));
        }

        foreach ($enFiles as $relativePath => $fileContent) {
            $fullPath = $root . '/content/en/' . $relativePath;
            $fileDir = dirname($fullPath);
            if (!is_dir($fileDir) && !mkdir($fileDir, 0755, true) && !is_dir($fileDir)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $fileDir));
            }

            file_put_contents($fullPath, $fileContent);
        }

        foreach ($nlFiles as $relativePath => $fileContent) {
            // Accept either bare filenames or "nl/filename" — strip the leading "nl/" if present
            $strippedPath = preg_replace('#^nl/#', '', $relativePath) ?? $relativePath;
            $fullPath = $root . '/content/nl/' . $strippedPath;
            $fileDir = dirname($fullPath);
            if (!is_dir($fileDir) && !mkdir($fileDir, 0755, true) && !is_dir($fileDir)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $fileDir));
            }

            file_put_contents($fullPath, $fileContent);
        }

        file_put_contents($root . '/glaze.neon', $neon);

        return $root;
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Create and return a RoutesCommand instance via the container.
     */
    protected function createCommand(): RoutesCommand
    {
        /** @var \Glaze\Command\RoutesCommand */
        return $this->service(RoutesCommand::class);
    }

    /**
     * Create a ConsoleIo whose stdout is captured in a temp file.
     *
     * Returns the ConsoleIo alongside a reader closure for retrieving output.
     *
     * @return array{0: \Cake\Console\ConsoleIo, 1: \Closure(): string}
     */
    protected function captureConsoleIo(): array
    {
        $stdoutPath = (string)tempnam(sys_get_temp_dir(), 'glaze-routes-test-out-');
        $stderrPath = (string)tempnam(sys_get_temp_dir(), 'glaze-routes-test-err-');

        $io = new ConsoleIo(
            new ConsoleOutput($stdoutPath),
            new ConsoleOutput($stderrPath),
        );

        $read = static function () use ($stdoutPath): string {
            return (string)file_get_contents($stdoutPath);
        };

        return [$io, $read];
    }

    /**
     * Build an Arguments object with the given options merged over defaults.
     *
     * @param array<string, array<string>|bool|string|null> $options Overrides for the default argument map.
     */
    protected function makeArgs(array $options = []): Arguments
    {
        /** @var array<string, array<string>|bool|string|null> $defaults */
        $defaults = [
            'root' => null,
            'type' => null,
            'taxonomy' => null,
            'lang' => null,
            'truncate' => '60',
            'drafts' => false,
        ];

        return new Arguments([], array_replace($defaults, $options), []);
    }

    /**
     * Create a minimal temporary project directory.
     *
     * Content files are written relative to `content/`. A `glaze.neon` is
     * written when `$neon` is provided; otherwise only the reference defaults
     * are used. The `templates/` directory is created to satisfy any template
     * resolution that falls through to the filesystem.
     *
     * @param array<string, string> $files Map of relative content path → file content.
     * @param string|null $neon Optional glaze.neon contents.
     * @throws \RuntimeException When a directory cannot be created.
     */
    protected function createMinimalProject(array $files, ?string $neon = null): string
    {
        $root = $this->createTempDirectory();
        $contentDir = $root . '/content';
        $templatesDir = $root . '/templates';

        if (!mkdir($contentDir, 0755, true) && !is_dir($contentDir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $contentDir));
        }

        if (!mkdir($templatesDir, 0755, true) && !is_dir($templatesDir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $templatesDir));
        }

        foreach ($files as $relativePath => $fileContent) {
            $fullPath = $contentDir . '/' . $relativePath;
            $fileDir = dirname($fullPath);

            if (!is_dir($fileDir) && !mkdir($fileDir, 0755, true) && !is_dir($fileDir)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $fileDir));
            }

            file_put_contents($fullPath, $fileContent);
        }

        if ($neon !== null) {
            file_put_contents($root . '/glaze.neon', $neon);
        }

        return $root;
    }
}
