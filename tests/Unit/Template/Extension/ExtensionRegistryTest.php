<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template\Extension;

use Glaze\Template\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ExtensionRegistry: registration, memoized invocation, and error handling.
 */
final class ExtensionRegistryTest extends TestCase
{
    /**
     * Validate basic register, has, and call behaviour.
     */
    public function testRegisterAndCall(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('hello', fn() => 'world');

        $this->assertTrue($registry->has('hello'));
        $this->assertFalse($registry->has('other'));
        $this->assertSame('world', $registry->call('hello'));
    }

    /**
     * Validate that call results are memoized and the callable runs only once.
     */
    public function testCallResultIsMemoized(): void
    {
        $invocations = 0;
        $registry = new ExtensionRegistry();
        $registry->register('counter', function () use (&$invocations): int {
            return ++$invocations;
        });

        $first = $registry->call('counter');
        $second = $registry->call('counter');
        $third = $registry->call('counter');

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
        $this->assertSame(1, $third);
        $this->assertSame(1, $invocations);
    }

    /**
     * Validate that arguments are forwarded to the callable on first invocation.
     */
    public function testCallForwardsArguments(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('greet', fn(string $name) => sprintf('Hello, %s!', $name));

        $this->assertSame('Hello, Alice!', $registry->call('greet', 'Alice'));
        // Subsequent call returns cached value, not a new invocation with different args.
        $this->assertSame('Hello, Alice!', $registry->call('greet', 'Bob'));
    }

    /**
     * Validate that re-registering an extension invalidates its cache.
     */
    public function testReregisterInvalidatesCache(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('value', fn() => 'first');
        $this->assertSame('first', $registry->call('value'));

        $registry->register('value', fn() => 'second');
        $this->assertSame('second', $registry->call('value'));
    }

    /**
     * Validate that calling an unknown extension throws RuntimeException with helpful message.
     */
    public function testCallUnknownExtensionThrows(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('existing', fn() => 'ok');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown.*available.*existing/i');

        $registry->call('unknown');
    }

    /**
     * Validate that calling on an empty registry throws RuntimeException mentioning "(none)".
     */
    public function testCallOnEmptyRegistryMentionsNone(): void
    {
        $registry = new ExtensionRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\(none\)/');

        $registry->call('anything');
    }

    /**
     * Validate that names() returns all registered extension names.
     */
    public function testNamesReturnsRegisteredKeys(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('alpha', fn() => 1);
        $registry->register('beta', fn() => 2);

        $this->assertSame(['alpha', 'beta'], $registry->names());
    }

    /**
     * Validate that names() returns empty array for an empty registry.
     */
    public function testNamesOnEmptyRegistry(): void
    {
        $this->assertSame([], (new ExtensionRegistry())->names());
    }
}
