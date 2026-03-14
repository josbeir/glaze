<?php
declare(strict_types=1);

namespace Glaze;

use Cake\Console\CommandCollection;
use Cake\Core\Configure;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\Container;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Glaze\Command\BuildCommand;
use Glaze\Command\CacheCommand;
use Glaze\Command\HelpCommand;
use Glaze\Command\InitCommand;
use Glaze\Command\NewCommand;
use Glaze\Command\RoutesCommand;
use Glaze\Command\ServeCommand;
use Glaze\Config\BuildConfig;
use Glaze\Config\NeonConfigEngine;
use Glaze\Http\Middleware\ControllerMiddleware;
use Glaze\Http\Routing\ControllerRouter;
use Glaze\Http\Routing\ControllerViewRenderer;
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
     * Register the default NEON Configure engine so all configuration
     * loading goes through CakePHP's Configure subsystem.
     */
    public function bootstrap(): void
    {
        if (!Configure::isConfigured('default')) {
            Configure::config('default', new NeonConfigEngine());
        }
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
        $commands->add('routes', RoutesCommand::class);
        $commands->add('serve', ServeCommand::class);

        return $commands;
    }

    /**
     * Register application services in the dependency injection container.
     *
     * BuildConfig is registered as a shared service resolved lazily from
     * Configure state. Callers must ensure that project configuration is
     * loaded into Configure (via {@see \Glaze\Config\ProjectConfigurationReader})
     * and `projectRoot` is set before resolving BuildConfig.
     *
     * @param \Cake\Core\ContainerInterface $container The container to populate.
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(
            BuildConfig::class,
            static function (): BuildConfig {
                return BuildConfig::fromConfigure();
            },
        );

        $container->addShared(
            ImageTransformerInterface::class,
            static function () use ($container): ImageTransformerInterface {
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

        $container->addShared(
            ControllerMiddleware::class,
            static function () use ($container): ControllerMiddleware {
                /** @var \Glaze\Http\Routing\ControllerRouter $router */
                $router = $container->get(ControllerRouter::class);
                /** @var \Glaze\Http\Routing\ControllerViewRenderer $viewRenderer */
                $viewRenderer = $container->get(ControllerViewRenderer::class);
                /** @var \Glaze\Config\BuildConfig $config */
                $config = $container->get(BuildConfig::class);

                return new ControllerMiddleware($router, $viewRenderer, $container, $config);
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
