<?php

declare(strict_types=1);

namespace FpmDoctor\Recommend;

final class PoolRecommendation
{
    public const VERDICT_OK = 'OK';
    public const VERDICT_OOM_RISK = 'OOM_RISK';
    public const VERDICT_CONTENTION = 'CONTENTION';
    public const VERDICT_UNDERUTILIZED = 'UNDERUTILIZED';
    public const VERDICT_NO_DATA = 'NO_DATA';

    /**
     * @param string[] $reasons
     */
    public function __construct(
        public readonly string $pool,
        public readonly ?int $configuredMaxChildren,
        public readonly ?int $recommendedMaxChildren,
        public readonly string $verdict,
        public readonly array $reasons,
        public readonly ?float $avgRssBytes,
        public readonly ?int $peakRssBytes,
        public readonly int $ramBudgetBytes,
        public readonly string $rssMethod,
    ) {
    }

    public function toArray(): array
    {
        return [
            'pool' => $this->pool,
            'configured_max_children' => $this->configuredMaxChildren,
            'recommended_max_children' => $this->recommendedMaxChildren,
            'verdict' => $this->verdict,
            'reasons' => $this->reasons,
            'avg_rss_bytes' => $this->avgRssBytes === null ? null : (int) round($this->avgRssBytes),
            'peak_rss_bytes' => $this->peakRssBytes,
            'ram_budget_bytes' => $this->ramBudgetBytes,
            'rss_method' => $this->rssMethod,
        ];
    }
}
