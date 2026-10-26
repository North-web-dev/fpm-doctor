<?php

declare(strict_types=1);

namespace FpmDoctor\Status;

use FpmDoctor\Util\Text;

final class StatusParser
{
    public function parseJson(string $json): FpmStatus
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('FPM status JSON is not an object.');
        }
        return $this->fromArray($data);
    }

    public function fromArray(array $d): FpmStatus
    {
        $procs = [];
        if (isset($d['processes']) && is_array($d['processes'])) {
            foreach ($d['processes'] as $p) {
                if (is_array($p)) {
                    $procs[] = $p;
                }
            }
        }

        return new FpmStatus(
            pool: Text::safe((string) ($d['pool'] ?? 'unknown')),
            processManager: isset($d['process manager']) ? Text::safe((string) $d['process manager']) : null,
            listenQueue: $this->int($d, 'listen queue'),
            maxListenQueue: $this->int($d, 'max listen queue'),
            listenQueueLen: $this->int($d, 'listen queue len'),
            idleProcesses: $this->int($d, 'idle processes'),
            activeProcesses: $this->int($d, 'active processes'),
            totalProcesses: $this->int($d, 'total processes'),
            maxActiveProcesses: $this->int($d, 'max active processes'),
            maxChildrenReached: $this->int($d, 'max children reached'),
            slowRequests: $this->int($d, 'slow requests'),
            acceptedConn: $this->int($d, 'accepted conn'),
            processes: $procs,
        );
    }

    private function int(array $d, string $key): int
    {
        $v = $d[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }
}
