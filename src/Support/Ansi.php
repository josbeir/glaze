<?php
declare(strict_types=1);

namespace Glaze\Support;

/**
 * Low-level ANSI terminal escape sequence helpers.
 *
 * Most public methods return raw strings containing ANSI escape codes,
 * keeping them side-effect-free and easily testable. The `write()`
 * method sends text to the output stream (defaults to unbuffered
 * STDOUT so each write appears on screen immediately).
 *
 * Example:
 *
 *   $ansi = new Ansi();
 *   $ansi->write($ansi->gradient('Hello Glaze', [255, 140, 0], [0, 200, 255]));
 */
class Ansi
{
    /**
     * ESC character used in all ANSI sequences.
     */
    public const ESC = "\033";

    /**
     * Output stream for write operations.
     *
     * Defaults to STDOUT (unbuffered) so animation writes appear
     * immediately. Pass a custom stream (e.g. php://memory) for testing.
     *
     * @var resource
     */
    protected $output;

    /**
     * Constructor.
     *
     * @param resource|null $output Writable stream for `write()`. Defaults to STDOUT.
     */
    public function __construct($output = null)
    {
        if ($output !== null) {
            $this->output = $output;

            return;
        }

        if (defined('STDOUT')) {
            $this->output = STDOUT;

            return;
        }

        $fallback = fopen('php://output', 'w');
        assert($fallback !== false);
        $this->output = $fallback;
    }

    /**
     * Write text directly to the output stream.
     *
     * Uses the unbuffered output stream so each call appears on
     * screen immediately, which is essential for animations.
     *
     * @param string $text Text (including ANSI sequences) to write.
     */
    public function write(string $text): void
    {
        fwrite($this->output, $text);
    }

    /**
     * Build a truecolor (24-bit) foreground string for the given text.
     *
     * @param string $text Text to colorize.
     * @param int $r Red channel (0-255).
     * @param int $g Green channel (0-255).
     * @param int $b Blue channel (0-255).
     */
    public function truecolor(string $text, int $r, int $g, int $b): string
    {
        return sprintf('%s[38;2;%d;%d;%dm%s%s[0m', self::ESC, $r, $g, $b, $text, self::ESC);
    }

    /**
     * Apply a horizontal gradient across every character of the text.
     *
     * Interpolates linearly from `$from` to `$to` RGB values. For
     * single-character strings the `$from` color is used directly.
     *
     * @param string $text Text to render with gradient colors.
     * @param array{int, int, int} $from Starting RGB color.
     * @param array{int, int, int} $to Ending RGB color.
     */
    public function gradient(string $text, array $from, array $to): string
    {
        $chars = mb_str_split($text);
        $count = count($chars);

        if ($count === 0) {
            return '';
        }

        $result = '';
        $steps = max(1, $count - 1);

        foreach ($chars as $i => $char) {
            if ($char === ' ' || $char === "\t") {
                $result .= $char;
                continue;
            }

            $ratio = $i / $steps;
            $r = (int)round($from[0] + ($to[0] - $from[0]) * $ratio);
            $g = (int)round($from[1] + ($to[1] - $from[1]) * $ratio);
            $b = (int)round($from[2] + ($to[2] - $from[2]) * $ratio);

            $result .= $this->truecolor($char, $r, $g, $b);
        }

        return $result;
    }

    /**
     * Return the ANSI sequence to clear the entire screen and move cursor home.
     */
    public function clearScreen(): string
    {
        return self::ESC . '[2J' . self::ESC . '[H';
    }

    /**
     * Wrap text in ANSI bold escape sequences.
     *
     * @param string $text Text to render in bold.
     */
    public function bold(string $text): string
    {
        return sprintf('%s[1m%s%s[22m', self::ESC, $text, self::ESC);
    }

    /**
     * Wrap text in ANSI dim/faint escape sequences.
     *
     * @param string $text Text to render dimmed.
     */
    public function dim(string $text): string
    {
        return sprintf('%s[2m%s%s[22m', self::ESC, $text, self::ESC);
    }

    /**
     * Return the ANSI sequence to hide the terminal cursor.
     */
    public function hideCursor(): string
    {
        return self::ESC . '[?25l';
    }

    /**
     * Return the ANSI sequence to show the terminal cursor.
     */
    public function showCursor(): string
    {
        return self::ESC . '[?25h';
    }

    /**
     * Return the ANSI sequence to clear the current line and reset cursor.
     */
    public function clearLine(): string
    {
        return self::ESC . "[2K\r";
    }

    /**
     * Return the ANSI sequence to move the cursor up N lines.
     *
     * @param int $lines Number of lines to move up.
     */
    public function moveUp(int $lines = 1): string
    {
        return sprintf('%s[%dA', self::ESC, $lines);
    }

    /**
     * Return the ANSI sequence to move the cursor down N lines.
     *
     * @param int $lines Number of lines to move down.
     */
    public function moveDown(int $lines = 1): string
    {
        return sprintf('%s[%dB', self::ESC, $lines);
    }

    /**
     * Return the ANSI reset sequence to restore default styling.
     */
    public function reset(): string
    {
        return self::ESC . '[0m';
    }

    /**
     * Check whether STDOUT is connected to an interactive terminal.
     *
     * Returns false when output is piped or redirected, or when the
     * `NO_COLOR` environment variable is set.
     */
    public function isInteractive(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (!defined('STDOUT')) {
            return false;
        }

        return stream_isatty(STDOUT);
    }
}
