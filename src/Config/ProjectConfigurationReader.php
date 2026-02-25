<?php
declare(strict_types=1);

namespace Glaze\Config;

use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * Reads decoded project configuration from glaze.neon files.
 */
final class ProjectConfigurationReader
{
    /**
     * Read decoded project configuration for the given project root.
     *
     * @param string $projectRoot Project root directory.
     * @return array<string, mixed>
     */
    public function read(string $projectRoot): array
    {
        $configurationPath = $projectRoot . DIRECTORY_SEPARATOR . 'glaze.neon';
        if (!is_file($configurationPath)) {
            return [];
        }

        try {
            $decoded = Neon::decodeFile($configurationPath);
        } catch (NeonException $neonException) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration (%s): %s',
                $configurationPath,
                $neonException->getMessage(),
            ), 0, $neonException);
        }

        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
