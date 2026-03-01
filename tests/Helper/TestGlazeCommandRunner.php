<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Glaze\Application;
use Glaze\Command\GlazeCommandRunner;
use Glaze\Support\Ansi;

/**
 * Concrete test runner exposing protected Glaze command runner helpers.
 */
final class TestGlazeCommandRunner extends GlazeCommandRunner
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(new Application(), 'glaze');
    }

    /**
     * Proxy protected header rendering for tests.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param \Glaze\Support\Ansi|null $ansi Optional ANSI helper.
     */
    public function renderVersionHeaderForTest(ConsoleIo $io, ?Ansi $ansi = null): void
    {
        $this->renderVersionHeader($io, $ansi);
    }

    /**
     * Proxy animated header rendering for tests.
     *
     * @param \Glaze\Support\Ansi $ansi ANSI escape helper.
     * @param string $version Version string.
     */
    public function renderAnimatedHeaderForTest(Ansi $ansi, string $version): void
    {
        $this->renderAnimatedHeader($ansi, $version);
    }

    /**
     * Proxy protected helper for tests.
     */
    public function resolveAppVersionForTest(): string
    {
        return $this->resolveAppVersion();
    }

    /**
     * Proxy protected helper for tests.
     *
     * @param mixed $value Raw version candidate.
     */
    public function normalizeVersionCandidateForTest(mixed $value): ?string
    {
        return $this->normalizeVersionCandidate($value);
    }

    /**
     * Set static version cache value for deterministic tests.
     *
     * @param string|null $value Cached value.
     */
    public function setCachedVersionForTest(?string $value): void
    {
        self::$cachedVersion = $value;
    }

    /**
     * Proxy header policy decision for tests.
     *
     * @param \Cake\Console\CommandInterface $command Command instance.
     * @param array<int, string> $argv Raw command arguments.
     */
    public function shouldRenderVersionHeaderForTest(CommandInterface $command, array $argv): bool
    {
        return $this->shouldRenderVersionHeader($command, $argv);
    }
}
