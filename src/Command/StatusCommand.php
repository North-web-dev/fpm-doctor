<?php

declare(strict_types=1);

namespace FpmDoctor\Command;

use FpmDoctor\Cli\Args;
use FpmDoctor\Cli\Output;
use FpmDoctor\Status\StatusClient;
use FpmDoctor\Status\StatusParser;
use FpmDoctor\Util\Bytes;

final class StatusCommand
{
    public function __construct(
        private readonly Output $out,
        private readonly StatusParser $parser = new StatusParser(),
    ) {
    }

    public function run(Args $args): int
    {
        $statusFile = $args->str('status-file');
        $statusArg = $args->str('status');
        if ($statusArg === null && $statusFile === null) {
            $this->out->line('Usage: fpm-doctor status --status=<url|fcgi target> [--json]');
            $this->out->line('  e.g. --status=http://127.0.0.1/status?json&full');
            $this->out->line('       --status=unix:/run/php/php8.2-fpm.sock:/status?json&full');
            $this->out->line('       --status-file=/path/to/status.json  (offline / already captured)');
            return 2;
        }

        try {
            if ($statusFile !== null) {
                if (!is_file($statusFile)) {
                    throw new \RuntimeException("status file not found: {$statusFile}");
                }
                $body = (string) file_get_contents($statusFile);
            } else {
                $body = (new StatusClient())->fetch((string) $statusArg);
            }
            $status = $this->parser->parseJson($body);
        } catch (\Throwable $e) {
            $this->out->line($this->out->paint('status fetch failed: ', '1;31') . $e->getMessage());
            $this->out->line('Enable the status page by setting pm.status_path (e.g. pm.status_path = /status) in the pool,');
            $this->out->line('reload FPM, and allow the location in your web server or query it over the FPM socket.');
            return 2;
        }

        if ($args->bool('json')) {
            $this->out->line(json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->out->heading('FPM status: pool ' . $status->pool);
        $rows = [
            ['process manager', (string) ($status->processManager ?? 'n/a')],
            ['active processes', (string) $status->activeProcesses],
            ['idle processes', (string) $status->idleProcesses],
            ['total processes', (string) $status->totalProcesses],
            ['max active processes', (string) $status->maxActiveProcesses],
            ['listen queue', (string) $status->listenQueue],
            ['max listen queue', (string) $status->maxListenQueue],
            ['listen queue len', (string) $status->listenQueueLen],
            ['max children reached', $this->flag($status->maxChildrenReached)],
            ['slow requests', $this->flag($status->slowRequests)],
            ['accepted conn', (string) $status->acceptedConn],
        ];
        $this->out->table(['metric', 'value'], $rows);

        if ($status->maxChildrenReached > 0) {
            $this->out->line();
            $this->out->line($this->out->paint('! ', '1;31')
                . '"max children reached" > 0: this pool has saturated pm.max_children and queued/dropped requests. '
                . 'Run `fpm-doctor analyze` to size it against measured RSS.');
        }
        if ($status->listenQueue > 0) {
            $this->out->line($this->out->paint('! ', '1;33')
                . 'Requests are sitting in the listen queue right now (' . $status->listenQueue . ').');
        }

        if ($status->hasPerProcessData()) {
            $mems = $status->lastRequestMemories();
            if ($mems !== []) {
                $this->out->line();
                $this->out->line(sprintf(
                    'last request memory across %d processes: avg %s, peak %s (PHP request peak, not RSS).',
                    count($mems),
                    Bytes::humanMiB(array_sum($mems) / count($mems)),
                    Bytes::humanMiB(max($mems))
                ));
            }
        }

        return 0;
    }

    private function flag(int $n): string
    {
        return $n > 0 ? $this->out->paint((string) $n, '1;31') : '0';
    }
}
