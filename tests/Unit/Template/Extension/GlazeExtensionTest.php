<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template\Extension;

use Glaze\Template\Extension\GlazeExtension;
use Glaze\Tests\Fixture\Extension\NamedTestExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the GlazeExtension attribute.
 */
final class GlazeExtensionTest extends TestCase
{
    /**
     * Validate that the attribute stores its name correctly.
     */
    public function testAttributeStoresName(): void
    {
        $attribute = new GlazeExtension('my-extension');

        $this->assertSame('my-extension', $attribute->name);
    }

    /**
     * Validate the attribute stores an empty string without modification.
     */
    public function testAttributeStoresEmptyName(): void
    {
        $attribute = new GlazeExtension('');

        $this->assertSame('', $attribute->name);
    }

    /**
     * Validate the attribute allows a null name for pure event-subscriber extensions.
     */
    public function testAttributeAllowsNullName(): void
    {
        $attribute = new GlazeExtension();

        $this->assertNull($attribute->name);
    }

    /**
     * Validate that helper defaults to false.
     */
    public function testHelperDefaultsToFalse(): void
    {
        $attribute = new GlazeExtension('my-extension');

        $this->assertFalse($attribute->helper);
    }

    /**
     * Validate that helper: true is stored correctly.
     */
    public function testHelperCanBeSetToTrue(): void
    {
        $attribute = new GlazeExtension('my-extension', helper: true);

        $this->assertTrue($attribute->helper);
    }

    /**
     * Validate the attribute is readable via reflection on a decorated class,
     * and that the fixture has helper: true set.
     */
    public function testAttributeIsReadableViaReflection(): void
    {
        $reflection = new ReflectionClass(NamedTestExtension::class);
        $attributes = $reflection->getAttributes(GlazeExtension::class);

        $this->assertCount(1, $attributes);

        /** @var GlazeExtension $instance */
        $instance = $attributes[0]->newInstance();

        $this->assertSame('test-extension', $instance->name);
        $this->assertTrue($instance->helper);
    }

    /**
     * Validate that classes without the attribute return an empty attributes list.
     */
    public function testClassWithoutAttributeHasNoAttributes(): void
    {
        $reflection = new ReflectionClass(self::class);
        $attributes = $reflection->getAttributes(GlazeExtension::class);

        $this->assertCount(0, $attributes);
    }
}
