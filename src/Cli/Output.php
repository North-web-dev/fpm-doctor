<?php

declare(strict_types=1);

namespace FpmDoctor\Cli;

final class Output
{
    private bool $color;

    public function __construct(
        private $stream = STDOUT,
        ?bool $color = null,
    ) {
        $this->color = $color ?? $this->detectColor();
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . "\n");
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
    }

    public function heading(string $text): void
    {
        $this->line();
        $this->line($this->paint($text, '1;36'));
        $this->line(str_repeat('-', mb_strlen($text)));
    }

    public function verdict(string $verdict): string
    {
        return match ($verdict) {
            'OK' => $this->paint('OK', '1;32'),
            'OOM_RISK' => $this->paint('OOM RISK', '1;31'),
            'CONTENTION' => $this->paint('CONTENTION', '1;33'),
            'UNDERUTILIZED' => $this->paint('UNDERUTILIZED', '36'),
            'NO_DATA' => $this->paint('NO DATA', '1;35'),
            default => $verdict,
        };
    }

    /**
     * @param string[]        $headers
     * @param array<int,array<int,string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = $this->visibleLen($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, $this->visibleLen($cell));
            }
        }

        $this->line($this->formatRow($headers, $widths, '1;37'));
        $sep = [];
        foreach ($widths as $w) {
            $sep[] = str_repeat('-', $w);
        }
        $this->line(implode('-+-', $sep));
        foreach ($rows as $row) {
            $this->line($this->formatRow($row, $widths));
        }
    }

    /**
     * @param array<int,string> $cells
     * @param array<int,int>    $widths
     */
    private function formatRow(array $cells, array $widths, ?string $style = null): string
    {
        $out = [];
        foreach ($widths as $i => $w) {
            $cell = $cells[$i] ?? '';
            $pad = $w - $this->visibleLen($cell);
            $padded = $cell . str_repeat(' ', max(0, $pad));
            $out[] = $style !== null ? $this->paint($padded, $style) : $padded;
        }
        return implode(' | ', $out);
    }

    public function paint(string $text, string $code): string
    {
        if (!$this->color) {
            return $text;
        }
        return "\033[" . $code . 'm' . $text . "\033[0m";
    }

    private function visibleLen(string $text): int
    {
        return mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text);
    }

    private function detectColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        return function_exists('posix_isatty') && @posix_isatty($this->stream);
    }
}
