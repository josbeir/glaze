<?php
declare(strict_types=1);

namespace Glaze\Config;

use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Nette\Neon\Exception as NeonException;
use RuntimeException;

/**
 * Reads merged project configuration from reference and project NEON files.
 *
 * Loads `resources/config/reference.neon` as a baseline and overlays the
 * project's `glaze.neon` on top via CakePHP's Configure subsystem.
 * After each read the merged result is available through Configure::read().
 */
final class ProjectConfigurationReader
{
    /**
     * Read decoded project configuration for the given project root.
     *
     * Clears Configure state, loads the bundled reference configuration,
     * then merges the project's `glaze.neon` on top. The merged result
     * is both returned and left in Configure for direct access.
     *
     * @param string $projectRoot Project root directory.
     * @return array<string, mixed>
     */
    public function read(string $projectRoot): array
    {
        $projectConfigurationPath = $projectRoot . DIRECTORY_SEPARATOR . 'glaze.neon';

        try {
            return $this->loadMergedConfiguration($projectConfigurationPath);
        } catch (RuntimeException | NeonException | CakeException $exception) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration (%s): %s',
                $projectConfigurationPath,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * Read the bundled reference configuration only.
     *
     * @return array<string, mixed>
     */
    public function readReference(): array
    {
        try {
            return $this->loadMergedConfiguration(null);
        } catch (RuntimeException | NeonException | CakeException $exception) {
            throw new RuntimeException(sprintf(
                'Invalid reference configuration (%s): %s',
                $this->referenceConfigurationPath(),
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * Load and merge reference + optional project configuration through Configure.
     *
     * Clears all Configure values first so stale keys from previous loads
     * cannot bleed into the current result, then loads the reference with
     * merge disabled (baseline) and the project file with merge enabled.
     *
     * @param string|null $projectConfigurationPath Optional project configuration path.
     * @return array<string, mixed>
     */
    protected function loadMergedConfiguration(?string $projectConfigurationPath): array
    {
        Configure::clear();
        Configure::load($this->referenceConfigurationPath(), merge: false);

        if (is_string($projectConfigurationPath) && is_file($projectConfigurationPath)) {
            Configure::load($projectConfigurationPath, merge: true);
        }

        /** @var array<string, mixed> $values */
        $values = Configure::read();

        return $values;
    }

    /**
     * Resolve the absolute path to the bundled reference configuration file.
     */
    protected function referenceConfigurationPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/config/reference.neon';
    }
}
