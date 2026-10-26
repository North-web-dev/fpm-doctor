<?php

declare(strict_types=1);

namespace FpmDoctor\Recommend;

use FpmDoctor\Util\Bytes;

final class Recommender
{
    /**
     * @param PoolInput[] $inputs
     */
    public function analyze(array $inputs, int $ramBudgetBytes, string $ramBudgetSource): AnalysisReport
    {
        $recs = [];
        $committedPeak = 0;

        foreach ($inputs as $input) {
            $rec = $this->recommendPool($input, $ramBudgetBytes);
            $recs[] = $rec;

            $peak = $rec->peakRssBytes;
            $configured = $rec->configuredMaxChildren;
            if ($peak !== null && $configured !== null) {
                $committedPeak += $peak * $configured;
            }
        }

        [$oversubscribed, $sharedWarnings] = $this->assessShared($inputs, $recs, $ramBudgetBytes, $committedPeak);

        return new AnalysisReport(
            pools: $recs,
            ramBudgetBytes: $ramBudgetBytes,
            ramBudgetSource: $ramBudgetSource,
            oversubscribed: $oversubscribed,
            sharedPoolWarnings: $sharedWarnings,
            committedPeakBytes: $committedPeak,
        );
    }

    private function recommendPool(PoolInput $input, int $ramBudgetBytes): PoolRecommendation
    {
        $pool = $input->pool;
        $rss = $input->rss;
        $status = $input->status;
        $configured = $pool->maxChildren;

        $avg = $rss->avg();
        $peak = $rss->peak();

        if ($avg === null || $avg <= 0.0) {
            return new PoolRecommendation(
                pool: $pool->name,
                configuredMaxChildren: $configured,
                recommendedMaxChildren: null,
                verdict: PoolRecommendation::VERDICT_NO_DATA,
                reasons: [
                    $rss->note ?? 'No RSS samples available for this pool.',
                    'Supply --avg-rss to compute a recommendation, or enable /proc / the FPM status page.',
                ],
                avgRssBytes: null,
                peakRssBytes: $peak,
                ramBudgetBytes: $ramBudgetBytes,
                rssMethod: $rss->method,
            );
        }

        $recommended = (int) floor($ramBudgetBytes / $avg);
        $peakForOom = $peak ?? (int) round($avg);

        $reasons = [];
        $reasons[] = sprintf(
            'avg child RSS %s, peak %s (method: %s, %d sample%s).',
            Bytes::humanMiB($avg),
            $peak !== null ? Bytes::humanMiB($peak) : 'n/a',
            $rss->method,
            $rss->count(),
            $rss->count() === 1 ? '' : 's'
        );
        $reasons[] = sprintf(
            'budget %s / avg %s = %d children.',
            Bytes::humanMiB($ramBudgetBytes),
            Bytes::humanMiB($avg),
            $recommended
        );

        $verdict = PoolRecommendation::VERDICT_OK;

        if ($configured !== null && $configured * $peakForOom > $ramBudgetBytes) {
            $verdict = PoolRecommendation::VERDICT_OOM_RISK;
            $reasons[] = sprintf(
                'TOO HIGH: %d children x peak %s = %s exceeds budget %s (OOM risk under full load).',
                $configured,
                Bytes::humanMiB($peakForOom),
                Bytes::humanMiB($configured * $peakForOom),
                Bytes::humanMiB($ramBudgetBytes)
            );
        }

        $maxChildrenReached = $status?->maxChildrenReached ?? 0;
        $listenQueue = $status?->listenQueue ?? 0;
        if ($verdict !== PoolRecommendation::VERDICT_OOM_RISK
            && ($maxChildrenReached > 0 || $listenQueue > 0)
            && $configured !== null
            && $recommended > $configured
        ) {
            $verdict = PoolRecommendation::VERDICT_CONTENTION;
            $reasons[] = sprintf(
                'TOO LOW: status shows "max children reached"=%d, listen queue=%d; RAM allows %d. '
                . 'Requests are queuing/502-ing while memory is free.',
                $maxChildrenReached,
                $listenQueue,
                $recommended
            );
        }

        if ($verdict === PoolRecommendation::VERDICT_OK
            && $configured !== null
            && $configured > 0
            && $configured < (int) floor($recommended / 2)
        ) {
            $verdict = PoolRecommendation::VERDICT_UNDERUTILIZED;
            $reasons[] = sprintf(
                'Headroom: configured %d is well under the %d RAM allows; raise it if you see '
                . '"max children reached" climb under load.',
                $configured,
                $recommended
            );
        }

        if ($configured === null) {
            $reasons[] = 'pm.max_children not set in this pool config; showing the RAM-derived recommendation only.';
        }

        return new PoolRecommendation(
            pool: $pool->name,
            configuredMaxChildren: $configured,
            recommendedMaxChildren: $recommended,
            verdict: $verdict,
            reasons: $reasons,
            avgRssBytes: $avg,
            peakRssBytes: $peak,
            ramBudgetBytes: $ramBudgetBytes,
            rssMethod: $rss->method,
        );
    }

    /**
     * @param PoolInput[]            $inputs
     * @param PoolRecommendation[]   $recs
     * @return array{0:bool,1:string[]}
     */
    private function assessShared(array $inputs, array $recs, int $ramBudgetBytes, int $committedPeak): array
    {
        $warnings = [];
        $oversubscribed = false;

        $poolsWithChildren = array_filter(
            $recs,
            static fn (PoolRecommendation $r) => $r->configuredMaxChildren !== null && $r->peakRssBytes !== null
        );

        if (count($poolsWithChildren) > 1 && $committedPeak > $ramBudgetBytes) {
            $oversubscribed = true;
            $warnings[] = sprintf(
                'SHARED-POOL OVERSUBSCRIPTION: %d pools share this master. Their combined worst case '
                . '(sum of max_children x peak RSS) is %s, over the %s budget. A burst on one pool can '
                . 'starve the others and OOM the box - the classic 502 storm. Split the budget per pool '
                . 'so the totals fit, and consider separate masters for noisy neighbours.',
                count($poolsWithChildren),
                Bytes::humanMiB($committedPeak),
                Bytes::humanMiB($ramBudgetBytes)
            );
        }

        foreach ($inputs as $input) {
            $status = $input->status;
            if ($status !== null && $status->maxChildrenReached > 0) {
                $warnings[] = sprintf(
                    'Pool "%s" has hit pm.max_children %d time(s) since start. Under a shared master this '
                    . 'is where sites 502 even though each pool "looks" fine in isolation.',
                    $input->pool->name,
                    $status->maxChildrenReached
                );
            }
        }

        return [$oversubscribed, $warnings];
    }
}
