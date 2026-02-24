<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

/**
 * Integration tests for the new command.
 */
final class NewCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure help output includes available new command options.
     */
    public function testNewCommandHelpContainsOptions(): void
    {
        $this->exec('new --help');

        $this->assertExitCode(0);
        $this->assertOutputContains('--name');
        $this->assertOutputContains('--title');
        $this->assertOutputContains('--description');
        $this->assertOutputContains('--base-url');
        $this->assertOutputContains('--taxonomies');
        $this->assertOutputContains('--yes');
    }

    /**
     * Ensure new command scaffolds project from command arguments.
     */
    public function testNewCommandCreatesProjectWithArguments(): void
    {
        $target = $this->createTempDirectory() . '/arg-site';

        $this->exec(sprintf(
            'new "%s" --name "arg-site" --title "Arg Site" --description "Arg description" --base-url "https://arg.example" --taxonomies tags,categories --yes',
            $target,
        ));

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
        $this->assertFileExists($target . '/glaze.neon');
        $this->assertOutputContains('<success>created</success>');
    }

    /**
     * Ensure interactive flow collects values and creates scaffold.
     */
    public function testNewCommandInteractiveFlowCreatesProject(): void
    {
        $target = $this->createTempDirectory() . '/interactive-site';

        $this->exec(
            sprintf('new "%s"', $target),
            ['interactive-site', 'Interactive Site', 'Interactive description', 'https://interactive.example', 'tags,categories'],
        );

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/templates/page.sugar.php');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
        $this->assertFileExists($target . '/glaze.neon');
    }

    /**
     * Ensure non-empty target fails without force option.
     */
    public function testNewCommandRejectsNonEmptyDirectoryWithoutForce(): void
    {
        $target = $this->createTempDirectory() . '/existing';
        mkdir($target, 0755, true);
        file_put_contents($target . '/keep.txt', 'keep');

        $this->exec(sprintf('new "%s" --yes', $target));

        $this->assertExitCode(1);
        $this->assertErrorContains('is not empty');
    }

    /**
     * Ensure non-interactive mode requires either directory argument or --name.
     */
    public function testNewCommandRejectsMissingDirectoryInNonInteractiveMode(): void
    {
        $this->exec('new --yes');

        $this->assertExitCode(1);
        $this->assertErrorContains('Project directory is required.');
    }

    /**
     * Ensure --name is used as target directory when directory argument is absent.
     */
    public function testNewCommandUsesNameAsDirectoryWhenMissingArgument(): void
    {
        $target = $this->createTempDirectory() . '/name-derived-site';

        $this->exec(sprintf('new --name "%s" --title "Name Derived" --yes', $target));

        $this->assertExitCode(0);
        $this->assertFileExists($target . '/content/index.dj');
        $this->assertFileExists($target . '/templates/layout/page.sugar.php');
        $this->assertFileExists($target . '/glaze.neon');
    }
}
