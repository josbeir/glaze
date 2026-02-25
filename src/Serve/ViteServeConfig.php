<?php
declare(strict_types=1);

namespace Glaze\Serve;

/**
 * Immutable Vite development server configuration.
 */
final class ViteServeConfig
{
    /**
     * Constructor.
     *
     * @param bool $enabled Whether Vite integration is enabled.
     * @param string $host Host interface Vite should bind to.
     * @param int $port Port Vite should bind to.
     * @param string $command Command template used to start Vite.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $host,
        public readonly int $port,
        public readonly string $command,
    ) {
    }

    /**
     * Build final command line for starting Vite.
     */
    public function commandLine(): string
    {
        return strtr($this->command, [
            '{host}' => $this->host,
            '{port}' => (string)$this->port,
        ]);
    }

    /**
     * Return Vite server URL.
     */
    public function url(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }
}
