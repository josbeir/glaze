<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\DjotConverter;
use Glaze\Render\Djot\PhikiExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Djot Phiki extension registration.
 */
final class PhikiExtensionTest extends TestCase
{
    /**
     * Ensure extension registration applies highlighted code block rendering.
     */
    public function testRegisterHooksCodeBlockRendering(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new PhikiExtension());

        $html = $converter->convert("```php\necho 1;\n```\n");

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-php', $html);
    }
}
