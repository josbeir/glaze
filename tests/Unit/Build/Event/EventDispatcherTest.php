<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build\Event;

use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\Event\PageRenderedEvent;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for the EventDispatcher class.
 *
 * Verifies listener registration, dispatch ordering, no-op behaviour when no
 * listeners are registered, listener counts, and mutable payload mutation.
 */
final class EventDispatcherTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Validate that a registered listener is called when the matching event is dispatched.
     */
    public function testOnAndDispatch(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;
        $payload = new stdClass();

        $dispatcher->on(BuildEvent::BuildStarted, function (object $event) use (&$called, $payload): void {
            $this->assertSame($payload, $event);
            $called = true;
        });

        $dispatcher->dispatch(BuildEvent::BuildStarted, $payload);

        $this->assertTrue($called);
    }

    /**
     * Validate that multiple listeners for the same event are called in registration order.
     */
    public function testListenersCalledInRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $log = [];

        $dispatcher->on(BuildEvent::BuildStarted, function () use (&$log): void {
            $log[] = 'first';
        });
        $dispatcher->on(BuildEvent::BuildStarted, function () use (&$log): void {
            $log[] = 'second';
        });
        $dispatcher->on(BuildEvent::BuildStarted, function () use (&$log): void {
            $log[] = 'third';
        });

        $dispatcher->dispatch(BuildEvent::BuildStarted, new stdClass());

        $this->assertSame(['first', 'second', 'third'], $log);
    }

    /**
     * Validate that dispatching an event with no listeners is a no-op.
     */
    public function testNoListenersIsNoop(): void
    {
        $dispatcher = new EventDispatcher();

        // No exception should be thrown
        $dispatcher->dispatch(BuildEvent::BuildCompleted, new stdClass());

        $this->assertSame(0, $dispatcher->listenerCount(BuildEvent::BuildCompleted));
    }

    /**
     * Validate that listenerCount returns the correct number of registered listeners.
     */
    public function testListenerCountReturnsCorrectValue(): void
    {
        $dispatcher = new EventDispatcher();

        $this->assertSame(0, $dispatcher->listenerCount(BuildEvent::PageWritten));

        $dispatcher->on(BuildEvent::PageWritten, fn() => null);
        $this->assertSame(1, $dispatcher->listenerCount(BuildEvent::PageWritten));

        $dispatcher->on(BuildEvent::PageWritten, fn() => null);
        $this->assertSame(2, $dispatcher->listenerCount(BuildEvent::PageWritten));

        // Other events are unaffected
        $this->assertSame(0, $dispatcher->listenerCount(BuildEvent::BuildStarted));
    }

    /**
     * Validate that a listener can mutate a mutable event payload field.
     */
    public function testListenerReceivesMutableEventPayload(): void
    {
        $dispatcher = new EventDispatcher();
        $dir = $this->createTempDirectory();
        $config = BuildConfig::fromProjectRoot($dir);
        $page = new ContentPage(
            sourcePath: $dir . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: '# Home',
            draft: false,
            meta: [],
            taxonomies: [],
        );

        $event = new PageRenderedEvent($page, '<p>original</p>', $config);

        $dispatcher->on(BuildEvent::PageRendered, function (PageRenderedEvent $received): void {
            $received->html = '<p>mutated</p>';
        });

        $dispatcher->dispatch(BuildEvent::PageRendered, $event);

        $this->assertSame('<p>mutated</p>', $event->html);
    }

    /**
     * Validate that listeners for different events do not interfere with each other.
     */
    public function testListenersForDifferentEventsAreIsolated(): void
    {
        $dispatcher = new EventDispatcher();
        $log = [];

        $dispatcher->on(BuildEvent::BuildStarted, function () use (&$log): void {
            $log[] = 'started';
        });
        $dispatcher->on(BuildEvent::BuildCompleted, function () use (&$log): void {
            $log[] = 'completed';
        });

        $dispatcher->dispatch(BuildEvent::PageWritten, new stdClass());

        $this->assertSame([], $log);
    }
}
