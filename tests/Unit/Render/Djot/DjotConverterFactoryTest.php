<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Glaze\Config\DjotOptions;
use Glaze\Render\Djot\PhikiThemeResolver;
use Glaze\Support\ResourcePathRewriter;
use Phiki\Theme\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DjotConverterFactory Phiki renderer caching behaviour.
 *
 * The factory must share a single {@see PhikiCodeBlockRenderer} instance across
 * all pages that use an identical highlight configuration, so that Phiki's
 * grammar and theme caches persist across pages in a single build.
 */
final class DjotConverterFactoryTest extends TestCase
{
    private DjotConverterFactoryTestDouble $factory;

    /**
     * Set up a factory test double that exposes the protected caching methods under test.
     */
    protected function setUp(): void
    {
        $this->factory = new DjotConverterFactoryTestDouble(new ResourcePathRewriter(), new PhikiThemeResolver());
    }

    /**
     * The same renderer instance must be returned for subsequent calls that share the same configuration.
     */
    public function testResolveRendererReturnsSameInstanceForIdenticalOptions(): void
    {
        $djot = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'nord');

        $first = $this->factory->getRenderer($djot);
        $second = $this->factory->getRenderer($djot);

        $this->assertSame($first, $second);
    }

    /**
     * A new renderer must be created when the theme changes between calls.
     */
    public function testResolveRendererCreatesNewInstanceWhenThemeChanges(): void
    {
        $nord = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'nord');
        $github = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'github-dark');

        $rendererNord = $this->factory->getRenderer($nord);
        $rendererGithub = $this->factory->getRenderer($github);

        $this->assertNotSame($rendererNord, $rendererGithub);
    }

    /**
     * A new renderer must be created when the gutter setting differs, even if the theme is the same.
     */
    public function testResolveRendererCreatesNewInstanceWhenGutterChanges(): void
    {
        $withoutGutter = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'nord', codeHighlightingWithGutter: false);
        $withGutter = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'nord', codeHighlightingWithGutter: true);

        $rendererWithout = $this->factory->getRenderer($withoutGutter);
        $rendererWith = $this->factory->getRenderer($withGutter);

        $this->assertNotSame($rendererWithout, $rendererWith);
    }

    /**
     * After switching options, going back to the original config must produce a fresh renderer,
     * not silently return a stale cached instance from a prior config.
     */
    public function testResolveRendererCreatesNewInstanceAfterRevertingToOriginalTheme(): void
    {
        $nord = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'nord');
        $github = new DjotOptions(codeHighlightingEnabled: true, codeHighlightingTheme: 'github-dark');

        $first = $this->factory->getRenderer($nord);
        $this->factory->getRenderer($github);
        $third = $this->factory->getRenderer($nord);

        // The factory only remembers the last renderer; reverting to a prior theme
        // creates a new instance rather than resurrecting the original one.
        $this->assertNotSame($first, $third);
    }

    /**
     * The signature produced for the same inputs must be identical on repeated calls.
     */
    public function testBuildRendererSignatureIsDeterministicForThemeEnum(): void
    {
        $sig1 = $this->factory->getSignature(Theme::Nord, false);
        $sig2 = $this->factory->getSignature(Theme::Nord, false);

        $this->assertSame($sig1, $sig2);
    }

    /**
     * Signatures for distinct Theme enum values must differ.
     */
    public function testBuildRendererSignatureDiffersForDifferentThemeEnums(): void
    {
        $nordSig = $this->factory->getSignature(Theme::Nord, false);
        $githubSig = $this->factory->getSignature(Theme::GithubDark, false);

        $this->assertNotSame($nordSig, $githubSig);
    }

    /**
     * Signatures must differ when only the gutter flag differs.
     */
    public function testBuildRendererSignatureDiffersWhenGutterChanges(): void
    {
        $withoutGutter = $this->factory->getSignature(Theme::Nord, false);
        $withGutter = $this->factory->getSignature(Theme::Nord, true);

        $this->assertNotSame($withoutGutter, $withGutter);
    }

    /**
     * String theme signatures must be deterministic and differ for different string values.
     */
    public function testBuildRendererSignatureHandlesStringTheme(): void
    {
        $sig1 = $this->factory->getSignature('monokai', false);
        $sig2 = $this->factory->getSignature('monokai', false);
        $sig3 = $this->factory->getSignature('solarized-dark', false);

        $this->assertSame($sig1, $sig2);
        $this->assertNotSame($sig1, $sig3);
    }

    /**
     * Array (multi-theme) signatures must be order-independent and deterministic.
     */
    public function testBuildRendererSignatureHandlesArrayThemeAndIsSortOrderIndependent(): void
    {
        $themes1 = ['light' => 'github-light', 'dark' => 'github-dark'];
        $themes2 = ['dark' => 'github-dark', 'light' => 'github-light'];
        $themes3 = ['light' => 'github-light', 'dark' => 'nord'];

        $sig1 = $this->factory->getSignature($themes1, false);
        $sig2 = $this->factory->getSignature($themes2, false);
        $sig3 = $this->factory->getSignature($themes3, false);

        $this->assertSame($sig1, $sig2, 'Key order must not affect the signature');
        $this->assertNotSame($sig1, $sig3, 'Different theme values must produce different signatures');
    }
}
