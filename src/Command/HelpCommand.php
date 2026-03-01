<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\BaseCommand;
use Cake\Console\Command\HelpCommand as CakeHelpCommand;
use Cake\Console\CommandHiddenInterface;
use Cake\Console\ConsoleIo;

/**
 * Display help information for Glaze commands.
 *
 * Wraps the CakePHP HelpCommand to prepend the branded Glaze header.
 */
final class HelpCommand extends CakeHelpCommand implements CommandHiddenInterface
{
    /**
     * Output help text with Glaze-specific footer guidance.
     *
     * @param \Cake\Console\ConsoleIo $io Console output service.
     * @param iterable<string, string|object> $commands Command collection to output.
     * @param bool $verbose Whether to show verbose output with descriptions.
     */
    protected function asText(ConsoleIo $io, iterable $commands, bool $verbose = false): void
    {
        $invert = [];
        foreach ($commands as $name => $class) {
            if (is_subclass_of($class, CommandHiddenInterface::class)) {
                continue;
            }

            if (is_object($class)) {
                $class = $class::class;
            }

            $invert[$class] ??= [];
            $invert[$class][] = $name;
        }

        $commandList = [];
        foreach ($invert as $class => $names) {
            preg_match('/^(.+)\\\\Command\\\\/', $class, $matches);
            if ($matches === []) {
                continue;
            }

            $shortestName = $this->getShortestName($names);
            if (str_contains($shortestName, '.')) {
                [, $shortestName] = explode('.', $shortestName, 2);
            }

            $commandList[] = [
                'name' => $shortestName,
                'description' => is_subclass_of($class, BaseCommand::class) ? $class::getDescription() : '',
            ];
        }

        sort($commandList);

        if ($verbose) {
            $this->outputPaths($io);
            $this->outputGrouped($io, $invert);
        } else {
            $this->outputCompactCommands($io, $commandList);
            $io->out('');
        }

        $root = $this->getRootName();
        $io->out(sprintf('To run a Glaze command, type <info>`%s command_name [args|options]`</info>', $root));
        $io->out(sprintf(
            'To get help for a specific Glaze command, type <info>`%s command_name --help`</info>',
            $root,
        ));

        $io->out('', 1);
    }
}
