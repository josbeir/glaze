<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

/**
 * Lightweight synchronous event dispatcher for the Glaze build pipeline.
 *
 * Listeners are registered per `BuildEvent` case via `on()` and invoked in
 * registration order when `dispatch()` is called for the matching case.
 * Dispatch is synchronous and blocking — each listener runs to completion
 * before the next one is called.
 *
 * Example:
 *
 * ```php
 * $dispatcher = new EventDispatcher();
 * $dispatcher->on(BuildEvent::PageWritten, function (PageWrittenEvent $event): void {
 *     echo $event->destination . PHP_EOL;
 * });
 * $dispatcher->dispatch(BuildEvent::PageWritten, new PageWrittenEvent(...));
 * ```
 */
final class EventDispatcher
{
    /**
     * Registered listeners keyed by BuildEvent case name.
     *
     * @var array<string, array<callable>>
     */
    private array $listeners = [];

    /**
     * Register a callable to be invoked when the given build event is dispatched.
     *
     * Listeners for the same event are called in registration order.
     *
     * @param \Glaze\Build\Event\BuildEvent $event Build event case to subscribe to.
     * @param callable $listener Callable receiving the event payload object.
     */
    public function on(BuildEvent $event, callable $listener): void
    {
        $this->listeners[$event->name][] = $listener;
    }

    /**
     * Dispatch an event, invoking all registered listeners with the payload.
     *
     * @param \Glaze\Build\Event\BuildEvent $event Build event case being dispatched.
     * @param object $payload Typed event payload object passed to each listener.
     */
    public function dispatch(BuildEvent $event, object $payload): void
    {
        foreach ($this->listeners[$event->name] ?? [] as $listener) {
            $listener($payload);
        }
    }

    /**
     * Return the number of registered listeners for a given event.
     *
     * Primarily useful for testing and diagnostics.
     *
     * @param \Glaze\Build\Event\BuildEvent $event Build event case to query.
     */
    public function listenerCount(BuildEvent $event): int
    {
        return count($this->listeners[$event->name] ?? []);
    }
}
