<?php

declare(strict_types=1);

namespace FpmDoctor\Command;

use FpmDoctor\Cli\Args;
use FpmDoctor\Cli\Output;
use FpmDoctor\Slowlog\SlowlogParser;
use FpmDoctor\Slowlog\SlowlogReport;

final class SlowlogCommand
{
    public function __construct(
        private readonly Output $out,
        private readonly SlowlogParser $parser = new SlowlogParser(),
    ) {
    }

    public function run(Args $args): int
    {
        $path = $args->positionals[0] ?? null;
        if ($path === null) {
            $this->out->line('Usage: fpm-doctor slowlog <path/to/slow.log> [--top=N] [--json]');
            return 2;
        }
        if (!is_file($path)) {
            $this->out->line("Slowlog not found: {$path}");
            return 2;
        }

        $report = $this->parser->parseFile($path);
        $top = $args->int('top', 15);

        if ($args->bool('json')) {
            $data = $report->toArray();
            $data['groups'] = array_map(
                static fn ($g) => $g->toArray(),
                $report->byFrequency($top)
            );
            $this->out->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->render($report, $top, $path);
        return 0;
    }

    private function render(SlowlogReport $report, ?int $top, string $path): void
    {
        $this->out->heading('fpm-doctor slowlog');
        $this->out->line('File            : ' . $path);
        $this->out->line('Slow requests   : ' . $report->totalEntries);
        $this->out->line('Distinct traces : ' . count($report->groups));
        if ($report->malformedLines > 0) {
            $this->out->line('Skipped lines   : ' . $report->malformedLines . ' (unparseable; counted, not fatal)');
        }
        $this->out->line($this->out->paint(
            'Note: php-fpm slowlogs record that request_slowlog_timeout was exceeded, not the elapsed time. '
            . 'Ranking is by frequency and recency, never invented durations.',
            '2'
        ));

        if ($report->groups === []) {
            $this->out->line();
            $this->out->line('No slow requests found in this log.');
            return;
        }

        $rows = [];
        foreach ($report->byFrequency($top) as $g) {
            $rows[] = [
                (string) $g->count,
                $this->shorten($g->script, 46),
                $this->shorten($g->topFrame, 52),
                $g->lastSeen ?? 'n/a',
            ];
        }
        $this->out->heading('Top offenders by frequency');
        $this->out->table(['count', 'script', 'top frame', 'last seen'], $rows);

        $this->out->heading('Most recent offenders');
        $recent = [];
        foreach ($report->byRecency($top) as $g) {
            $recent[] = [
                $g->lastSeen ?? 'n/a',
                (string) $g->count,
                $this->shorten($g->script, 46),
                $this->shorten($g->topFrame, 52),
            ];
        }
        $this->out->table(['last seen', 'count', 'script', 'top frame'], $recent);

        $worst = $report->byFrequency(1)[0] ?? null;
        if ($worst !== null && $worst->sampleTrace !== []) {
            $this->out->heading('Sample trace for the most frequent offender');
            $this->out->line($worst->script . '  (x' . $worst->count . ')');
            foreach (array_slice($worst->sampleTrace, 0, 20) as $i => $frame) {
                $this->out->line(sprintf('  #%-2d %s', $i, $frame));
            }
        }
    }

    private function shorten(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return '...' . mb_substr($s, -($max - 3));
    }
}
