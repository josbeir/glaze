<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Utility;

use Glaze\Utility\ProjectRootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CLI project root resolution.
 */
final class ProjectRootResolverTest extends TestCase
{
    /**
     * Ensure explicit root option has priority over cwd.
     */
    public function testResolveUsesExplicitRootWhenProvided(): void
    {
        $resolved = ProjectRootResolver::resolve(' /tmp/glaze-site/ ');

        $this->assertSame('/tmp/glaze-site', str_replace('\\', '/', $resolved));
    }

    /**
     * Ensure resolver falls back to cwd when root option is missing.
     */
    public function testResolveFallsBackToCurrentWorkingDirectory(): void
    {
        $currentDirectory = getcwd() ?: '.';
        $resolved = ProjectRootResolver::resolve(null);

        $this->assertSame(
            rtrim(str_replace('\\', '/', $currentDirectory), '/'),
            str_replace('\\', '/', $resolved),
        );
    }

    /**
     * Ensure blank root option values fall back to current working directory.
     */
    public function testResolveFallsBackToCurrentWorkingDirectoryForBlankRoot(): void
    {
        $currentDirectory = getcwd() ?: '.';
        $resolved = ProjectRootResolver::resolve('   ');

        $this->assertSame(
            rtrim(str_replace('\\', '/', $currentDirectory), '/'),
            str_replace('\\', '/', $resolved),
        );
    }
}
