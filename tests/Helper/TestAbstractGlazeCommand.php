<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Glaze\Command\AbstractGlazeCommand;

/**
 * Concrete test command exposing protected base helpers.
 */
final class TestAbstractGlazeCommand extends AbstractGlazeCommand
{
    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        return self::CODE_SUCCESS;
    }

    /**
     * Proxy protected helper for tests.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function renderVersionHeaderForTest(ConsoleIo $io): void
    {
        $this->renderVersionHeader($io);
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
}
