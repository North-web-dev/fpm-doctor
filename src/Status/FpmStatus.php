<?php

declare(strict_types=1);

namespace FpmDoctor\Status;

final class FpmStatus
{
    /**
     * @param array<int,array<string,mixed>> $processes
     */
    public function __construct(
        public readonly string $pool,
        public readonly ?string $processManager,
        public readonly int $listenQueue,
        public readonly int $maxListenQueue,
        public readonly int $listenQueueLen,
        public readonly int $idleProcesses,
        public readonly int $activeProcesses,
        public readonly int $totalProcesses,
        public readonly int $maxActiveProcesses,
        public readonly int $maxChildrenReached,
        public readonly int $slowRequests,
        public readonly int $acceptedConn,
        public readonly array $processes = [],
    ) {
    }

    public function hasPerProcessData(): bool
    {
        return $this->processes !== [];
    }

    /**
     * Per-process "last request memory" values (bytes) reported by ?full.
     * This is PHP request peak memory, NOT process RSS; callers must label it.
     *
     * @return int[]
     */
    public function lastRequestMemories(): array
    {
        $out = [];
        foreach ($this->processes as $p) {
            $mem = $p['last request memory'] ?? null;
            if (is_int($mem) && $mem > 0) {
                $out[] = $mem;
            }
        }
        return $out;
    }

    public function toArray(): array
    {
        return [
            'pool' => $this->pool,
            'process_manager' => $this->processManager,
            'listen_queue' => $this->listenQueue,
            'max_listen_queue' => $this->maxListenQueue,
            'listen_queue_len' => $this->listenQueueLen,
            'idle_processes' => $this->idleProcesses,
            'active_processes' => $this->activeProcesses,
            'total_processes' => $this->totalProcesses,
            'max_active_processes' => $this->maxActiveProcesses,
            'max_children_reached' => $this->maxChildrenReached,
            'slow_requests' => $this->slowRequests,
            'accepted_conn' => $this->acceptedConn,
            'process_count' => count($this->processes),
        ];
    }
}
