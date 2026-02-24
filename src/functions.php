<?php
declare(strict_types=1);

if (!function_exists('glaze_dump_to_string')) {
    /**
     * Convert debug values into plain-text dump output.
     *
     * @param array<mixed> $values Values to dump.
     */
    function glaze_dump_to_string(array $values): string
    {
        ob_start();

        foreach ($values as $value) {
            if (is_scalar($value) || $value === null) {
                echo (string)$value;
                echo PHP_EOL;

                continue;
            }

            print_r($value);
            echo PHP_EOL;
        }

        $buffer = ob_get_clean();

        return is_string($buffer) ? $buffer : '';
    }
}

if (!function_exists('dump')) {
    /**
     * Dump one or more values for quick debugging output.
     *
     * @param mixed ...$values Values to dump.
     */
    function dump(mixed ...$values): void
    {
        $output = glaze_dump_to_string($values);

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            echo $output;

            return;
        }

        $preStyle = 'background:#111827;color:#f3f4f6;padding:12px;border-radius:8px;'
            . 'overflow:auto;line-height:1.4;';

        echo sprintf('<pre style="%s">', $preStyle);
        echo htmlspecialchars(rtrim($output), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '</pre>';
        echo PHP_EOL;
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
        $dumpedOutput = trim(glaze_dump_to_string($values));

        $message = 'Execution stopped by dd().';
        if ($dumpedOutput !== '') {
            $message .= PHP_EOL . PHP_EOL . $dumpedOutput;
        }

        throw new RuntimeException($message);
    }
}
