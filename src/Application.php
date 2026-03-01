<?php
declare(strict_types=1);

namespace Glaze;

use Cake\Console\CommandCollection;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\Container;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Glaze\Command\BuildCommand;
use Glaze\Command\CacheCommand;
use Glaze\Command\HelpCommand;
use Glaze\Command\InitCommand;
use Glaze\Command\NewCommand;
use Glaze\Command\ServeCommand;
use Glaze\Image\GlideImageTransformer;
use Glaze\Image\ImagePresetResolver;
use Glaze\Image\ImageTransformerInterface;
use Glaze\Scaffold\ScaffoldRegistry;
use Glaze\Scaffold\ScaffoldSchemaLoader;
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
        $commands->add('help', HelpCommand::class);
        $commands->add('build', BuildCommand::class);
        $commands->add('cc', CacheCommand::class);
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
        $container->addShared(
            ImageTransformerInterface::class,
            function () use ($container): ImageTransformerInterface {
                /** @var \Glaze\Image\ImagePresetResolver $presetResolver */
                $presetResolver = $container->get(ImagePresetResolver::class);

                return new GlideImageTransformer($presetResolver);
            },
        );

        $container->addShared(
            ScaffoldRegistry::class,
            static function (): ScaffoldRegistry {
                $scaffoldsDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scaffolds';

                return new ScaffoldRegistry($scaffoldsDirectory, new ScaffoldSchemaLoader());
            },
        );
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
