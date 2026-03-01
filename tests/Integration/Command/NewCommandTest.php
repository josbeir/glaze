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
     * Ensure new command prints version header and creates page content.
     */
    public function testNewCommandPrintsVersionHeaderAndCreatesPage(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<?= $content |> raw() ?>');

        $this->exec(sprintf('new "My Post" --root "%s" --yes', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Glaze version');
        $this->assertOutputContains('<success>created</success>');
        $this->assertFileExists($projectRoot . '/content/my-post.dj');
    }
}
