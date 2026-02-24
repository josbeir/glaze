<?php
declare(strict_types=1);

namespace Glaze;

use Cake\Console\CommandCollection;
use Cake\Core\ConsoleApplicationInterface;
use Glaze\Command\BuildCommand;
use Glaze\Command\NewCommand;
use Glaze\Command\ServeCommand;

/**
 * Console application entrypoint for Glaze commands.
 */
final class Application implements ConsoleApplicationInterface
{
    /**
     * Load application bootstrap logic.
     */
    public function bootstrap(): void
    {
    }

    /**
     * Register all available CLI commands.
     *
     * @param \Cake\Console\CommandCollection $commands Command collection.
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('build', BuildCommand::class);
        $commands->add('new', NewCommand::class);
        $commands->add('serve', ServeCommand::class);

        return $commands;
    }
}
