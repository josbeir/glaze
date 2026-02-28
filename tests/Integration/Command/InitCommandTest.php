<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

/**
 * Integration tests for the init command.
 */
final class InitCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure help output includes available init command options.
     */
    public function testInitCommandHelpContainsOptions(): void
    {
        $this->exec('init --help');

        $this->assertExitCode(0);
        $this->assertOutputContains('--name');
        $this->assertOutputContains('--title');
        $this->assertOutputContains('--page-template');
        $this->assertOutputContains('--description');
        $this->assertOutputContains('--base-url');
        $this->assertOutputContains('--base-path');
        $this->assertOutputContains('--taxonomies');
        $this->assertOutputContains('--preset');
        $this->assertOutputContains('--skip-install');
        $this->assertOutputContains('--yes');
    }

    /**
     * Ensure init command scaffolds project from command arguments.
     */
    public function testInitCommandCreatesProjectWithArguments(): void
    {
        $target = $this->createTempDirectory() . '/arg-site';

        $this->exec(sprintf(
            'init "%s" --name "arg-site" --title "Arg Site" --page-template "landing" --description "Arg description" --base-url "https://arg.example" --base-path "/blog" --taxonomies tags,categories --yes',
            $target,
        ));

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/base.sugar.php');
        $this->assertFileExists($target . '/.gitignore');
        $this->assertFileExists($target . '/.editorconfig');
        $this->assertFileExists($target . '/glaze.neon');
        $this->assertOutputContains('version <success>');
        $this->assertOutputContains('<success>created</success>');
        $this->assertStringContainsString('pageTemplate: landing', (string)file_get_contents($target . '/glaze.neon'));
        $this->assertStringContainsString('basePath: /blog', (string)file_get_contents($target . '/glaze.neon'));
    }

    /**
     * Ensure vite preset generates vite config file and enables Vite in project config.
     */
    public function testInitCommandCreatesViteConfigurationWhenEnabled(): void
    {
        $target = $this->createTempDirectory() . '/vite-site';

        $this->exec(sprintf(
            'init "%s" --name "vite-site" --title "Vite Site" --preset vite --skip-install --yes',
            $target,
        ));

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/vite.config.js');
        $config = (string)file_get_contents($target . '/glaze.neon');
        $this->assertStringContainsString('build:', $config);
        $this->assertStringContainsString('devServer:', $config);
        $this->assertStringContainsString('vite:', $config);
        $this->assertStringContainsString('enabled: true', $config);
    }

    /**
     * Ensure interactive flow collects values and creates scaffold.
     */
    public function testInitCommandInteractiveFlowCreatesProject(): void
    {
        $target = $this->createTempDirectory() . '/interactive-site';

        $this->exec(
            sprintf('init "%s"', $target),
            ['interactive-site', 'Interactive Site', 'Interactive description', 'https://interactive.example', '/interactive', 'tags,categories', 'default'],
        );

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/base.sugar.php');
        $this->assertFileExists($target . '/.gitignore');
        $this->assertFileExists($target . '/.editorconfig');
        $this->assertFileExists($target . '/glaze.neon');
        $this->assertStringContainsString('pageTemplate: page', (string)file_get_contents($target . '/glaze.neon'));
        $this->assertStringContainsString('basePath: /interactive', (string)file_get_contents($target . '/glaze.neon'));
    }

    /**
     * Ensure interactive flow asks for project directory when omitted.
     */
    public function testInitCommandInteractiveFlowAsksForDirectoryWhenMissing(): void
    {
        $target = $this->createTempDirectory() . '/prompted-directory-site';

        $this->exec(
            'init',
            [$target, 'prompted-directory-site', 'Prompted Site', 'Prompted description', 'https://prompted.example', '/prompted', 'tags,categories', 'default'],
        );

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/layout/base.sugar.php');
        $this->assertFileExists($target . '/glaze.neon');
    }

    /**
     * Ensure non-empty target fails without force option.
     */
    public function testInitCommandRejectsNonEmptyDirectoryWithoutForce(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $this->exec(sprintf('init "%s" --yes', $target));

        $this->assertExitCode(1);
        $this->assertErrorContains('is not empty');
    }

    /**
     * Ensure non-interactive mode requires either directory argument or --name.
     */
    public function testInitCommandRejectsMissingDirectoryInNonInteractiveMode(): void
    {
        $this->exec('init --yes');

        $this->assertExitCode(1);
        $this->assertErrorContains('Project directory is required.');
    }

    /**
     * Ensure --name is used as target directory when directory argument is absent.
     */
    public function testInitCommandUsesNameAsDirectoryWhenMissingArgument(): void
    {
        $target = $this->createTempDirectory() . '/name-derived-site';

        $this->exec(sprintf('init --name "%s" --title "Name Derived" --yes', $target));

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/static/.gitkeep');
        $this->assertFileExists($target . '/templates/layout/base.sugar.php');
        $this->assertFileExists($target . '/glaze.neon');
    }
}
