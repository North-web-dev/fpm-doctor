<?php

declare(strict_types=1);

namespace FpmDoctor\Recommend;

final class AnalysisReport
{
    /**
     * @param PoolRecommendation[] $pools
     * @param string[]             $sharedPoolWarnings
     */
    public function __construct(
        public readonly array $pools,
        public readonly int $ramBudgetBytes,
        public readonly string $ramBudgetSource,
        public readonly bool $oversubscribed,
        public readonly array $sharedPoolWarnings,
        public readonly int $committedPeakBytes,
    ) {
    }

    public function hasProblems(): bool
    {
        if ($this->oversubscribed) {
            return true;
        }
        foreach ($this->pools as $p) {
            if (in_array($p->verdict, [PoolRecommendation::VERDICT_OOM_RISK, PoolRecommendation::VERDICT_CONTENTION], true)) {
                return true;
            }
        }
        return false;
    }

    public function toArray(): array
    {
        return [
            'ram_budget_bytes' => $this->ramBudgetBytes,
            'ram_budget_source' => $this->ramBudgetSource,
            'oversubscribed' => $this->oversubscribed,
            'committed_peak_bytes' => $this->committedPeakBytes,
            'shared_pool_warnings' => $this->sharedPoolWarnings,
            'pools' => array_map(static fn (PoolRecommendation $p) => $p->toArray(), $this->pools),
        ];
    }
}
