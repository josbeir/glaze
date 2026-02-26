<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use RuntimeException;

/**
 * Holds named template extensions and invokes them with per-name result memoization.
 *
 * Extensions are registered as callables under a string name. When called via
 * `call()`, the result is cached for the lifetime of the registry instance so that
 * expensive operations (HTTP fetches, file reads, `git describe`, etc.) run at most
 * once per build or request regardless of how many templates reference the extension.
 *
 * Example registration and use:
 *
 * ```php
 * $registry = new ExtensionRegistry();
 * $registry->register('version', fn() => trim(file_get_contents('VERSION')));
 *
 * $registry->call('version'); // reads file
 * $registry->call('version'); // returns cached result
 * ```
 */
final class ExtensionRegistry
{
    /**
     * @var array<string, callable>
     */
    private array $extensions = [];

    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Register a callable under a named extension key.
     *
     * Replaces any existing registration for the same name and invalidates
     * any cached result for that name.
     *
     * @param string $name Extension lookup name.
     * @param callable $callable Invokable to execute when the extension is called.
     */
    public function register(string $name, callable $callable): void
    {
        $this->extensions[$name] = $callable;
        unset($this->cache[$name]);
    }

    /**
     * Check whether a named extension has been registered.
     *
     * @param string $name Extension name to check.
     */
    public function has(string $name): bool
    {
        return isset($this->extensions[$name]);
    }

    /**
     * Invoke a named extension and return its result, caching it for future calls.
     *
     * Arguments are forwarded to the callable on the first invocation only.
     * Subsequent calls for the same name always return the cached value regardless
     * of the arguments passed.
     *
     * @param string $name Extension name.
     * @param mixed ...$args Arguments forwarded to the callable on first invocation.
     * @throws \RuntimeException When no extension with the given name is registered.
     */
    public function call(string $name, mixed ...$args): mixed
    {
        if (!$this->has($name)) {
            throw new RuntimeException(sprintf(
                'Glaze extension "%s" is not registered. Available: %s',
                $name,
                $this->extensions === [] ? '(none)' : implode(', ', $this->names()),
            ));
        }

        if (!array_key_exists($name, $this->cache)) {
            $this->cache[$name] = ($this->extensions[$name])(...$args);
        }

        return $this->cache[$name];
    }

    /**
     * Return the names of all registered extensions.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->extensions);
    }
}
