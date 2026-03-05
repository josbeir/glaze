<?php
declare(strict_types=1);

namespace Glaze\Command\Helper;

use Cake\Console\Helper;

/**
 * Renders an ASCII-art table from two-dimensional array data.
 *
 * The first row is treated as a header row by default and is highlighted
 * using the configured `headerStyle`. Additional rows follow below a
 * separator. Column widths are calculated automatically from the widest
 * cell in each column, with inline style tags (e.g. `<info>`, `<comment>`)
 * excluded from width calculations so styled text aligns correctly.
 *
 * When `maxWidth` is set to a positive integer, any cell whose visible
 * content exceeds that width is truncated and suffixed with `…`.
 *
 * Example usage inside a command:
 *
 * ```php
 * $table = new TableHelper($io, ['maxWidth' => 60]);
 * $table->output([
 *     ['URL Path', 'Type', 'Template'],
 *     ['/about/', 'page', 'page'],
 *     ['/blog/post/', '<info>blog</info>', 'post'],
 * ]);
 * ```
 */
class TableHelper extends Helper
{
    /**
     * Default configuration.
     *
     * - `headers`     — treat the first row as a header row.
     * - `rowSeparator` — emit a separator line between every data row.
     * - `headerStyle` — console style tag applied to header cell text.
     * - `maxWidth`    — truncate cells wider than this (0 = no limit).
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'headers' => true,
        'rowSeparator' => false,
        'headerStyle' => 'info',
        'maxWidth' => 0,
    ];

    /**
     * Calculate the required column widths from all rows in the dataset.
     *
     * Each column width is the maximum cell width found for that column position
     * across all rows, where width is measured in visible characters (style tags excluded).
     *
     * @param array<array<string>> $rows All table rows (including header).
     * @return array<int, int> Column widths keyed by zero-based column index.
     */
    protected function calculateWidths(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $cellWidth = $this->cellWidth((string)$cell);
                if ($cellWidth >= ($widths[$i] ?? 0)) {
                    $widths[$i] = $cellWidth;
                }
            }
        }

        return $widths;
    }

    /**
     * Strip all known console style tags from a string, returning only visible text.
     *
     * @param string $text Text possibly containing style tags such as `<info>text</info>`.
     */
    protected function stripStyles(string $text): string
    {
        if (!str_contains($text, '<') && !str_contains($text, '>')) {
            return $text;
        }

        $styles = $this->_io->styles();
        $tags = implode('|', array_keys($styles));

        return (string)preg_replace('#</?(?:' . $tags . ')>#', '', $text);
    }

    /**
     * Truncate a cell value so its visible width does not exceed `$maxWidth`.
     *
     * Style tags are stripped before measuring. If the visible content fits,
     * the original tagged string is returned unchanged. If it exceeds the
     * limit the stripped text is truncated and suffixed with `…`.
     *
     * @param string $text Cell text, possibly containing style tags.
     * @param int $maxWidth Maximum visible width (0 = no limit).
     */
    protected function truncateCell(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return $text;
        }

        $visible = $this->stripStyles($text);
        if (mb_strwidth($visible) <= $maxWidth) {
            return $text;
        }

        return mb_strimwidth($visible, 0, $maxWidth, '…');
    }

    /**
     * Measure the visible width of a cell value, stripping console style tags.
     *
     * Style tags such as `<info>`, `</info>`, `<comment>`, `<success>`, etc. are
     * removed before measuring width so that column alignment is correct.
     *
     * @param string $text Cell text, possibly containing style tags.
     * @return int Width in visible characters.
     */
    protected function cellWidth(string $text): int
    {
        return mb_strwidth($this->stripStyles($text));
    }

    /**
     * Output a horizontal separator row scaled to the given column widths.
     *
     * @param array<int, int> $widths Column widths keyed by position.
     */
    protected function renderSeparator(array $widths): void
    {
        $line = '';
        foreach ($widths as $width) {
            $line .= '+' . str_repeat('-', $width + 2);
        }

        $line .= '+';
        $this->_io->out($line);
    }

    /**
     * Output a single table row, padding each cell to the required column width.
     *
     * @param array<string> $row Cell values for this row.
     * @param array<int, int> $widths Column widths keyed by position.
     * @param array<string, string> $options Rendering options (currently `style` for header rows).
     */
    protected function renderRow(array $row, array $widths, array $options = []): void
    {
        if ($row === []) {
            return;
        }

        $line = '';
        foreach (array_values($row) as $i => $cell) {
            $cell = (string)$cell;
            $pad = ($widths[$i] ?? 0) - $this->cellWidth($cell);

            if (!empty($options['style'])) {
                $cell = '<' . $options['style'] . '>' . $cell . '</' . $options['style'] . '>';
            }

            $line .= '| ' . $cell . str_repeat(' ', $pad) . ' ';
        }

        $line .= '|';
        $this->_io->out($line);
    }

    /**
     * Render the full table from a two-dimensional array.
     *
     * When `headers` is `true` (default), the first row is popped off as the header
     * and rendered with `headerStyle` between separator lines. Each remaining row is
     * rendered as a data row. A final separator closes the table.
     *
     * When `maxWidth` is set to a positive integer, all data cells (not headers) are
     * truncated to that visible width before rendering and width calculation.
     *
     * @param array<array<string>> $args Table data: first row is the header when `headers` is true.
     */
    public function output(array $args): void
    {
        if ($args === []) {
            return;
        }

        $config = $this->getConfig();
        $maxWidth = is_int($config['maxWidth']) && $config['maxWidth'] > 0 ? $config['maxWidth'] : 0;

        if ($maxWidth > 0) {
            // Keep header row intact; truncate only data rows
            $offset = $config['headers'] === true ? 1 : 0;
            $args = array_merge(
                array_slice($args, 0, $offset),
                $this->applyTruncation(array_slice($args, $offset), $maxWidth),
            );
        }

        $widths = $this->calculateWidths($args);

        $this->renderSeparator($widths);

        if ($config['headers'] === true) {
            $headerStyle = is_string($config['headerStyle']) ? $config['headerStyle'] : '';
            $headerRow = array_shift($args);
            if ($headerRow !== null) {
                $this->renderRow($headerRow, $widths, ['style' => $headerStyle]);
            }

            $this->renderSeparator($widths);
        }

        if ($args === []) {
            return;
        }

        foreach ($args as $row) {
            $this->renderRow((array)$row, $widths);
            if ($config['rowSeparator'] === true) {
                $this->renderSeparator($widths);
            }
        }

        if ($config['rowSeparator'] !== true) {
            $this->renderSeparator($widths);
        }
    }

    /**
     * Apply cell truncation to every row in the dataset.
     *
     * @param array<array<string>> $rows Rows to process.
     * @param int $maxWidth Maximum visible cell width.
     * @return array<array<string>>
     */
    protected function applyTruncation(array $rows, int $maxWidth): array
    {
        return array_map(
            fn(array $row): array => array_map(
                fn(string $cell): string => $this->truncateCell($cell, $maxWidth),
                $row,
            ),
            $rows,
        );
    }
}
