<?php
declare(strict_types=1);

namespace Glaze\Serve;

/**
 * Immutable PHP built-in server runtime configuration.
 */
final class PhpServerConfig
{
    /**
     * Constructor.
     *
     * @param string $host Host interface.
     * @param int $port Port number.
     * @param string $docRoot Document root path.
     * @param string $projectRoot Project root path.
     * @param bool $staticMode Whether static mode is enabled.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $docRoot,
        public readonly string $projectRoot,
        public readonly bool $staticMode,
    ) {
    }

    /**
     * Return host:port address string.
     */
    public function address(): string
    {
        return $this->host . ':' . $this->port;
    }
}
