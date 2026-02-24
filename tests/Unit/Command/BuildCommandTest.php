<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Command;

use Closure;
use Glaze\Build\SiteBuilder;
use Glaze\Command\BuildCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for build command internals.
 */
final class BuildCommandTest extends TestCase
{
    /**
     * Ensure root-relative conversion handles both matching and non-matching roots.
     */
    public function testRelativeToRootHandlesMatchingAndNonMatchingRoots(): void
    {
        $command = new BuildCommand();

        $relative = $this->callProtected(
            $command,
            'relativeToRoot',
            '/tmp/project/public/index.html',
            '/tmp/project',
        );

        $unchanged = $this->callProtected(
            $command,
            'relativeToRoot',
            '/other/public/index.html',
            '/tmp/project',
        );

        $this->assertSame('public/index.html', $relative);
        $this->assertSame('/other/public/index.html', $unchanged);
    }

    /**
     * Ensure command caches and reuses its site builder instance.
     */
    public function testSiteBuilderAccessorCachesInstance(): void
    {
        $command = new BuildCommand();

        $firstBuilder = $this->callProtected($command, 'siteBuilder');
        $secondBuilder = $this->callProtected($command, 'siteBuilder');

        $this->assertInstanceOf(SiteBuilder::class, $firstBuilder);
        $this->assertSame($firstBuilder, $secondBuilder);
    }

    /**
     * Ensure root option normalization trims string values and rejects invalid input.
     */
    public function testNormalizeRootOptionHandlesVariants(): void
    {
        $command = new BuildCommand();

        $trimmed = $this->callProtected($command, 'normalizeRootOption', ' /tmp/site ');
        $blank = $this->callProtected($command, 'normalizeRootOption', '   ');
        $invalid = $this->callProtected($command, 'normalizeRootOption', 123);

        $this->assertSame('/tmp/site', $trimmed);
        $this->assertNull($blank);
        $this->assertNull($invalid);
    }

    /**
     * Invoke a protected method on an object using scope-bound closure.
     *
     * @param object $object Object to invoke method on.
     * @param string $method Protected method name.
     * @param mixed ...$arguments Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $invoker = Closure::bind(
            function (string $method, mixed ...$arguments): mixed {
                return $this->{$method}(...$arguments);
            },
            $object,
            $object::class,
        );

        return $invoker($method, ...$arguments);
    }
}
