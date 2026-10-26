<?php

declare(strict_types=1);

namespace FpmDoctor\Command;

use FpmDoctor\Cli\Args;
use FpmDoctor\Cli\Output;
use FpmDoctor\Config\Pool;
use FpmDoctor\Config\PoolConfigParser;
use FpmDoctor\Recommend\AnalysisReport;
use FpmDoctor\Recommend\PoolInput;
use FpmDoctor\Recommend\Recommender;
use FpmDoctor\Rss\ProcRssMeasurer;
use FpmDoctor\Rss\RssMeasurement;
use FpmDoctor\Status\FpmStatus;
use FpmDoctor\Status\StatusClient;
use FpmDoctor\Status\StatusParser;
use FpmDoctor\Util\Bytes;
use FpmDoctor\Util\Ram;

final class AnalyzeCommand
{
    public const DEFAULT_FRACTION = 0.7;

    public function __construct(
        private readonly Output $out,
        private readonly PoolConfigParser $configParser = new PoolConfigParser(),
        private readonly ProcRssMeasurer $procMeasurer = new ProcRssMeasurer(),
        private readonly StatusParser $statusParser = new StatusParser(),
        private readonly Ram $ram = new Ram(),
    ) {
    }

    public function run(Args $args): int
    {
        $files = $this->resolveConfigFiles($args);
        if ($files === []) {
            $this->out->line('No php-fpm pool .conf files found.');
            $this->out->line('Pass --conf=<file> or --conf-dir=<dir>, or run where the pools live, e.g.:');
            foreach (PoolConfigParser::DEFAULT_PATHS as $p) {
                $this->out->line('  ' . $p);
            }
            return 2;
        }

        $pools = [];
        foreach ($files as $file) {
            foreach ($this->configParser->parseFile($file) as $pool) {
                $pools[] = $pool;
            }
        }
        if ($pools === []) {
            $this->out->line('Found config files but no pool sections ([www], ...) in them.');
            return 2;
        }

        $status = $this->maybeFetchStatus($args);

        $fraction = $args->float('ram-fraction', self::DEFAULT_FRACTION);
        [$budget, $budgetSource] = $this->ram->budget(
            $args->int('ram'),
            $this->ram->totalBytes(),
            $fraction
        );
        if ($budget <= 0) {
            $this->out->line('Could not determine a RAM budget. Pass --ram=<MB> (the memory you want FPM to use).');
            return 2;
        }

        $inputs = [];
        foreach ($pools as $pool) {
            $poolStatus = ($status !== null && $status->pool === $pool->name) ? $status : null;
            $inputs[] = new PoolInput($pool, $this->measureRss($args, $pool, $poolStatus), $poolStatus);
        }

        $report = (new Recommender())->analyze($inputs, $budget, $budgetSource);

        if ($args->bool('json')) {
            $this->out->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $report->hasProblems() ? 1 : 0;
        }

        $this->render($report, $files, $pools, $args);
        return $report->hasProblems() ? 1 : 0;
    }

    private function measureRss(Args $args, Pool $pool, ?FpmStatus $status): RssMeasurement
    {
        $avgOverride = $args->int('avg-rss');
        if ($avgOverride !== null) {
            $peakOverride = $args->int('peak-rss', $avgOverride);
            return new RssMeasurement(
                'override',
                [$avgOverride * 1024 * 1024],
                'avg/peak RSS supplied via --avg-rss/--peak-rss.',
                $peakOverride * 1024 * 1024,
            );
        }

        if ($this->procMeasurer->isAvailable()) {
            $m = $this->procMeasurer->measurePool($pool->name);
            if ($m->hasData()) {
                return $m;
            }
            if ($status !== null && $status->hasPerProcessData()) {
                return $this->fromStatus($status);
            }
            return $m;
        }

        if ($status !== null && $status->hasPerProcessData()) {
            return $this->fromStatus($status);
        }

        return new RssMeasurement(
            'none',
            [],
            '/proc unavailable and no per-process status data; pass --avg-rss=<MB> (measured on a comparable host).'
        );
    }

    private function fromStatus(FpmStatus $status): RssMeasurement
    {
        return new RssMeasurement(
            'status:last-request-memory',
            $status->lastRequestMemories(),
            'Using FPM "last request memory" (PHP request peak, an under-estimate of process RSS). '
            . 'For true RSS run on the Linux host so /proc can be read, or pass --avg-rss.'
        );
    }

    private function maybeFetchStatus(Args $args): ?FpmStatus
    {
        $statusFile = $args->str('status-file');
        if ($statusFile !== null) {
            if (!is_file($statusFile)) {
                $this->out->line($this->out->paint('! status file not found: ', '1;33') . $statusFile);
                return null;
            }
            try {
                return $this->statusParser->parseJson((string) file_get_contents($statusFile));
            } catch (\Throwable $e) {
                $this->out->line($this->out->paint('! status file parse failed: ', '1;33') . $e->getMessage());
                return null;
            }
        }

        $statusArg = $args->str('status');
        if ($statusArg === null) {
            return null;
        }
        try {
            $body = (new StatusClient())->fetch($statusArg);
            return $this->statusParser->parseJson($body);
        } catch (\Throwable $e) {
            $this->out->line($this->out->paint('! status fetch failed: ', '1;33') . $e->getMessage());
            $this->out->line('  Continuing without live status (RSS from /proc or --avg-rss only).');
            return null;
        }
    }

    /** @return string[] */
    private function resolveConfigFiles(Args $args): array
    {
        $files = [];
        if (($conf = $args->str('conf')) !== null) {
            foreach (explode(',', $conf) as $f) {
                $f = trim($f);
                if ($f !== '' && is_file($f)) {
                    $files[] = $f;
                }
            }
        }
        if (($dir = $args->str('conf-dir')) !== null) {
            foreach (explode(',', $dir) as $d) {
                $d = trim($d);
                foreach (glob(rtrim($d, '/') . '/*.conf', GLOB_NOSORT) ?: [] as $f) {
                    $files[] = $f;
                }
            }
        }
        if ($files === []) {
            $files = $this->configParser->discoverFiles();
        }
        return array_values(array_unique($files));
    }

    /**
     * @param string[] $files
     * @param Pool[]   $pools
     */
    private function render(AnalysisReport $report, array $files, array $pools, Args $args): void
    {
        $this->out->heading('fpm-doctor analyze');
        $this->out->line('Pool configs : ' . implode(', ', $files));
        $this->out->line('RAM budget   : ' . Bytes::humanMiB($report->ramBudgetBytes) . ' (' . $report->ramBudgetSource . ')');
        $this->out->line('Pools found  : ' . count($report->pools));
        $this->out->line($this->out->paint('Read-only: fpm-doctor never edits configs or restarts FPM. It prints recommendations only.', '2'));

        $rows = [];
        foreach ($report->pools as $rec) {
            $rows[] = [
                $rec->pool,
                $rec->configuredMaxChildren === null ? '(unset)' : (string) $rec->configuredMaxChildren,
                $rec->recommendedMaxChildren === null ? 'n/a' : (string) $rec->recommendedMaxChildren,
                $rec->avgRssBytes === null ? 'n/a' : Bytes::humanMiB($rec->avgRssBytes),
                $rec->peakRssBytes === null ? 'n/a' : Bytes::humanMiB($rec->peakRssBytes),
                $this->out->verdict($rec->verdict),
            ];
        }
        $this->out->line();
        $this->out->table(
            ['pool', 'configured', 'recommended', 'avg RSS', 'peak RSS', 'verdict'],
            $rows
        );

        foreach ($report->pools as $rec) {
            $this->out->line();
            $this->out->line($this->out->paint('• pool ' . $rec->pool, '1;37') . ' — ' . $this->out->verdict($rec->verdict));
            foreach ($rec->reasons as $reason) {
                $this->out->line('    - ' . $reason);
            }
        }

        if ($report->sharedPoolWarnings !== []) {
            $this->out->heading('Shared-pool contention');
            foreach ($report->sharedPoolWarnings as $w) {
                $this->out->line($this->out->paint('! ', '1;31') . $w);
            }
        }

        if ($args->str('format') === 'conf') {
            $this->renderConfSnippet($report);
        }

        $this->out->line();
        $this->out->line($this->out->paint('Heuristic, not a guarantee: ', '1') . 'RSS varies by workload and request mix. '
            . 'Re-measure under real traffic. Verify, then apply changes yourself and reload FPM.');
    }

    private function renderConfSnippet(AnalysisReport $report): void
    {
        $this->out->heading('Suggested snippet (paste yourself; fpm-doctor does NOT write it)');
        foreach ($report->pools as $rec) {
            if ($rec->recommendedMaxChildren === null) {
                continue;
            }
            $this->out->line('; pool ' . $rec->pool . ' — verdict ' . $rec->verdict);
            $this->out->line('[' . $rec->pool . ']');
            $this->out->line('pm.max_children = ' . $rec->recommendedMaxChildren);
            $this->out->line();
        }
    }
}
