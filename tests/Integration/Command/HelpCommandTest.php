<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

/**
 * Integration tests for the help command.
 */
final class HelpCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure compact help output lists public commands and prints footer guidance.
     */
    public function testHelpCommandOutputsCompactCommandList(): void
    {
        $this->exec('help');

        $this->assertExitCode(0);
        $this->assertOutputContains('Available Commands:');
        $this->assertOutputContains('build');
        $this->assertOutputContains('serve');
        $this->assertOutputContains('command_name [args|options]');
        $this->assertOutputContains('command_name --help');
        $this->assertOutputNotContains("\n  help ");
    }

    /**
     * Ensure verbose help output uses grouped command formatting.
     */
    public function testHelpCommandOutputsVerboseGroupedCommands(): void
    {
        $this->exec('help --verbose');

        $this->assertExitCode(0);
        $this->assertOutputContains(' - build');
        $this->assertOutputContains(' - serve');
        $this->assertOutputContains('Generate static HTML from content and templates.');
        $this->assertOutputContains('To run a Glaze command, type');
    }
}
