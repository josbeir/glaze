<?php
declare(strict_types=1);

namespace Glaze\Config;

use Cake\Core\Configure\ConfigEngineInterface;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * CakePHP Configure engine for reading and writing NEON configuration files.
 *
 * Registered as the default Configure engine in Application::bootstrap(),
 * this engine treats the key argument as an absolute file path to a NEON
 * configuration file.
 */
final class NeonConfigEngine implements ConfigEngineInterface
{
    /**
     * Read and decode a NEON file from disk.
     *
     * @param string $key Absolute path to the NEON file.
     * @return array<string, mixed>
     */
    public function read(string $key): array
    {
        if (!is_file($key)) {
            return [];
        }

        try {
            $decoded = Neon::decodeFile($key);
        } catch (NeonException $neonException) {
            throw new RuntimeException(sprintf(
                'Invalid configuration (%s): %s',
                $key,
                $neonException->getMessage(),
            ), 0, $neonException);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $configKey => $value) {
            if (!is_string($configKey)) {
                continue;
            }

            $normalized[$configKey] = $value;
        }

        return $normalized;
    }

    /**
     * Encode and write configuration values to a NEON file.
     *
     * @param string $key Absolute output file path.
     * @param array<string, mixed> $data Configuration payload.
     */
    public function dump(string $key, array $data): bool
    {
        $written = file_put_contents($key, Neon::encode($data, true) . PHP_EOL);

        return is_int($written);
    }
}
