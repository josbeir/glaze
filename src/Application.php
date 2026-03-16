<?php
declare(strict_types=1);

namespace Glaze;

use Cake\Console\CommandCollection;
use Cake\Core\Configure;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\Container;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Cake\Http\MiddlewareQueue;
use Glaze\Build\SiteBuilder;
use Glaze\Command\BuildCommand;
use Glaze\Command\CacheCommand;
use Glaze\Command\HelpCommand;
use Glaze\Command\InitCommand;
use Glaze\Command\NewCommand;
use Glaze\Command\RoutesCommand;
use Glaze\Command\ServeCommand;
use Glaze\Config\BuildConfig;
use Glaze\Config\NeonConfigEngine;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\ControllerMiddleware;
use Glaze\Http\Middleware\CoreAssetMiddleware;
use Glaze\Http\Middleware\ErrorHandlingMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;
use Glaze\Http\Middleware\StaticAssetMiddleware;
use Glaze\Http\Routing\ControllerRouter;
use Glaze\Http\Routing\ControllerViewRenderer;
use Glaze\Http\StaticPageRequestHandler;
use Glaze\Image\GlideImageTransformer;
use Glaze\Image\ImagePresetResolver;
use Glaze\Image\ImageTransformerInterface;
use Glaze\Scaffold\ScaffoldRegistry;
use Glaze\Scaffold\ScaffoldSchemaLoader;
use League\Container\ReflectionContainer;
use Psr\Http\Server\RequestHandlerInterface;

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
     * Register HTTP middleware stack for dev-router execution.
     *
     * @param \Cake\Http\MiddlewareQueue $queue Middleware queue to populate.
     * @param bool $staticMode Whether static mode is active.
     * @return \Cake\Http\MiddlewareQueue Populated middleware queue.
     */
    public function middleware(MiddlewareQueue $queue, bool $staticMode): MiddlewareQueue
    {
        $container = $this->getContainer();
        $debug = (bool)Configure::read('debug', false);

        $queue->add(new ErrorHandlingMiddleware($debug));

        if ($staticMode) {
            /** @var \Glaze\Http\Middleware\PublicAssetMiddleware $publicAssetMiddleware */
            $publicAssetMiddleware = $container->get(PublicAssetMiddleware::class);
            $queue->add($publicAssetMiddleware);
        }

        /** @var \Glaze\Http\Middleware\StaticAssetMiddleware $staticAssetMiddleware */
        $staticAssetMiddleware = $container->get(StaticAssetMiddleware::class);
        /** @var \Glaze\Http\Middleware\ContentAssetMiddleware $contentAssetMiddleware */
        $contentAssetMiddleware = $container->get(ContentAssetMiddleware::class);
        $queue->add($staticAssetMiddleware);
        $queue->add($contentAssetMiddleware);

        if (!$staticMode) {
            /** @var \Glaze\Http\Middleware\CoreAssetMiddleware $coreAssetMiddleware */
            $coreAssetMiddleware = $container->get(CoreAssetMiddleware::class);
            /** @var \Glaze\Http\Middleware\ControllerMiddleware $controllerMiddleware */
            $controllerMiddleware = $container->get(ControllerMiddleware::class);
            $queue->add($coreAssetMiddleware);
            $queue->add($controllerMiddleware);
        }

        return $queue;
    }

    /**
     * Resolve the request fallback handler for the selected router mode.
     *
     * @param bool $staticMode Whether static mode is active.
     * @return \Psr\Http\Server\RequestHandlerInterface Fallback request handler.
     */
    public function fallbackHandler(bool $staticMode): RequestHandlerInterface
    {
        $container = $this->getContainer();

        if ($staticMode) {
            /** @var \Glaze\Http\StaticPageRequestHandler $fallbackHandler */
            $fallbackHandler = $container->get(StaticPageRequestHandler::class);

            return $fallbackHandler;
        }

        /** @var \Glaze\Config\BuildConfig $config */
        $config = $container->get(BuildConfig::class);
        /** @var \Glaze\Build\SiteBuilder $siteBuilder */
        $siteBuilder = $container->get(SiteBuilder::class);

        return new DevPageRequestHandler($config, $siteBuilder);
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
