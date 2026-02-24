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
     * If no explicit root is provided, shell-reported current directory (`PWD`) is preferred
     * and falls back to process working directory (`getcwd()`).
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

        $currentDirectory = self::resolveCurrentDirectory();

        return $currentDirectory ?? '.';
    }

    /**
     * Resolve current directory from environment and runtime context.
     */
    protected static function resolveCurrentDirectory(): ?string
    {
        $shellDirectory = Normalization::optionalPath(getenv('PWD') ?: null);
        if ($shellDirectory !== null && is_dir($shellDirectory)) {
            return $shellDirectory;
        }

        return Normalization::optionalPath(getcwd() ?: '.');
    }
}
