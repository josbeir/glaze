<?php
declare(strict_types=1);

if (!function_exists('dump')) {
    /**
     * Dump one or more values for quick debugging output.
     *
     * @param mixed ...$values Values to dump.
     */
    function dump(mixed ...$values): void
    {
        foreach ($values as $value) {
            if (is_scalar($value) || $value === null) {
                echo (string)$value;
                echo PHP_EOL;
                continue;
            }

            print_r($value);
            echo PHP_EOL;
        }
    }
}

if (!function_exists('debug')) {
    /**
     * Alias for `dump()`.
     *
     * @param mixed ...$values Values to dump.
     */
    function debug(mixed ...$values): void
    {
        dump(...$values);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump values and terminate execution.
     *
     * @param mixed ...$values Values to dump.
     */
    function dd(mixed ...$values): never
    {
        dump(...$values);

        throw new RuntimeException('Execution stopped by dd().');
    }
}
