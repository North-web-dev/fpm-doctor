<?php

declare(strict_types=1);

namespace FpmDoctor\Slowlog;

final class SlowlogGroup
{
    public int $count = 0;
    public ?string $firstSeen = null;
    public ?string $lastSeen = null;
    public ?int $firstEpoch = null;
    public ?int $lastEpoch = null;

    /** @var string[] */
    public array $sampleTrace = [];

    public function __construct(
        public readonly string $script,
        public readonly string $topFrame,
    ) {
    }

    public function key(): string
    {
        return $this->script . "\n" . $this->topFrame;
    }

    public function toArray(): array
    {
        return [
            'script' => $this->script,
            'top_frame' => $this->topFrame,
            'count' => $this->count,
            'first_seen' => $this->firstSeen,
            'last_seen' => $this->lastSeen,
            'sample_trace' => $this->sampleTrace,
        ];
    }
}
