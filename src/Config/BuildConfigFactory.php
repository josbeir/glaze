<?php
declare(strict_types=1);

namespace Glaze\Config;

/**
 * Factory service for creating build configuration objects.
 */
final class BuildConfigFactory
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\ProjectConfigurationReader $projectConfigurationReader Project configuration reader service.
     */
    public function __construct(protected ProjectConfigurationReader $projectConfigurationReader)
    {
    }

    /**
     * Create a build configuration from a project root directory.
     *
     * @param string $projectRoot Project root path.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public function fromProjectRoot(string $projectRoot, bool $includeDrafts = false): BuildConfig
    {
        return BuildConfig::fromProjectRoot(
            projectRoot: $projectRoot,
            includeDrafts: $includeDrafts,
            projectConfigurationReader: $this->projectConfigurationReader,
        );
    }
}
