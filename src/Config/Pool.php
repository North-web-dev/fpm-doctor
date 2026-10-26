<?php

declare(strict_types=1);

namespace FpmDoctor\Config;

final class Pool
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $pm = null,
        public readonly ?int $maxChildren = null,
        public readonly ?int $startServers = null,
        public readonly ?int $minSpareServers = null,
        public readonly ?int $maxSpareServers = null,
        public readonly ?int $maxRequests = null,
        public readonly ?string $listen = null,
        public readonly ?int $memoryLimitBytes = null,
        public readonly ?string $memoryLimitRaw = null,
        public readonly ?string $sourceFile = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'pm' => $this->pm,
            'pm.max_children' => $this->maxChildren,
            'pm.start_servers' => $this->startServers,
            'pm.min_spare_servers' => $this->minSpareServers,
            'pm.max_spare_servers' => $this->maxSpareServers,
            'pm.max_requests' => $this->maxRequests,
            'listen' => $this->listen,
            'memory_limit' => $this->memoryLimitRaw,
            'memory_limit_bytes' => $this->memoryLimitBytes,
            'source_file' => $this->sourceFile,
        ];
    }
}
