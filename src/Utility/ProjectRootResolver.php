<?php
declare(strict_types=1);

namespace Glaze\Utility;

/**
 * Resolves effective project root path for CLI commands.
 */
final class ProjectRootResolver
{
    /**
     * Resolve project root from optional CLI root value.
     *
     * If no explicit root is provided, current working directory is used.
     * When `glaze.neon` exists in current working directory, it is always treated as project root.
     *
     * @param string|null $rootOption Optional CLI root option.
     */
    public static function resolve(?string $rootOption): string
    {
        $normalizedOption = Normalization::optionalPath($rootOption);
        if ($normalizedOption !== null) {
            return $normalizedOption;
        }

        $currentDirectory = Normalization::optionalPath(getcwd() ?: '.');

        return $currentDirectory ?? '.';
    }
}
