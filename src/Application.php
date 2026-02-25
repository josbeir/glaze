<?php
declare(strict_types=1);

namespace Glaze;

use Cake\Console\CommandCollection;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\Container;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Glaze\Build\SiteBuilder;
use Glaze\Command\BuildCommand;
use Glaze\Command\InitCommand;
use Glaze\Command\NewCommand;
use Glaze\Command\ServeCommand;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Scaffold\PageScaffoldService;
use Glaze\Scaffold\ProjectScaffoldService;
use Glaze\Serve\PhpServerService;
use Glaze\Serve\ViteBuildService;
use Glaze\Serve\ViteProcessService;
use League\Container\ReflectionContainer;

/**
 * Console application entrypoint for Glaze commands.
 */
final class Application implements ConsoleApplicationInterface, ContainerApplicationInterface
{
    protected ?ContainerInterface $container = null;

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
        $commands->add('init', InitCommand::class);
        $commands->add('new', NewCommand::class);
        $commands->add('serve', ServeCommand::class);

        return $commands;
    }

    /**
     * Register application services in the dependency injection container.
     *
     * @param \Cake\Core\ContainerInterface $container The container to populate.
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(SiteBuilder::class);
        $container->addShared(ProjectConfigurationReader::class);
        $container->addShared(ViteBuildService::class);
        $container->addShared(ViteProcessService::class);
        $container->addShared(PhpServerService::class);
        $container->addShared(ProjectScaffoldService::class);
        $container->addShared(PageScaffoldService::class);
    }

    /**
     * Build and return the application dependency injection container.
     */
    public function getContainer(): ContainerInterface
    {
        if ($this->container instanceof ContainerInterface) {
            return $this->container;
        }

        $container = new Container();
        $container->delegate(new ReflectionContainer(true));
        $this->services($container);
        $this->container = $container;

        return $container;
    }
}
