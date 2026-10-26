<?php

declare(strict_types=1);

namespace FpmDoctor\Rss;

final class RssMeasurement
{
    /**
     * @param int[] $samples per-child RSS values in bytes
     */
    public function __construct(
        public readonly string $method,
        public readonly array $samples = [],
        public readonly ?string $note = null,
        private readonly ?int $peakOverride = null,
    ) {
    }

    public function count(): int
    {
        return count($this->samples);
    }

    public function hasData(): bool
    {
        return $this->samples !== [];
    }

    public function avg(): ?float
    {
        if ($this->samples === []) {
            return null;
        }
        return array_sum($this->samples) / count($this->samples);
    }

    public function peak(): ?int
    {
        if ($this->peakOverride !== null) {
            return $this->peakOverride;
        }
        return $this->samples === [] ? null : max($this->samples);
    }

    public function min(): ?int
    {
        return $this->samples === [] ? null : min($this->samples);
    }
}
