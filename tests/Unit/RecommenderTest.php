<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Config\Pool;
use FpmDoctor\Recommend\PoolInput;
use FpmDoctor\Recommend\PoolRecommendation;
use FpmDoctor\Recommend\Recommender;
use FpmDoctor\Rss\RssMeasurement;
use FpmDoctor\Status\FpmStatus;
use PHPUnit\Framework\TestCase;

final class RecommenderTest extends TestCase
{
    private const MIB = 1048576;

    /**
     * @dataProvider recommendationCases
     *
     * @param int[] $rssSamplesMib
     */
    public function testRecommendationMath(
        int $budgetMib,
        array $rssSamplesMib,
        ?int $configured,
        ?int $maxChildrenReached,
        ?int $listenQueue,
        int $expectedRecommended,
        string $expectedVerdict
    ): void {
        $status = null;
        if ($maxChildrenReached !== null || $listenQueue !== null) {
            $status = $this->makeStatus('www', $maxChildrenReached ?? 0, $listenQueue ?? 0);
        }

        $input = new PoolInput(
            new Pool('www', 'dynamic', $configured),
            $this->rss($rssSamplesMib),
            $status,
        );

        $report = (new Recommender())->analyze([$input], $budgetMib * self::MIB, 'test');
        $rec = $report->pools[0];

        self::assertSame($expectedRecommended, $rec->recommendedMaxChildren ?? -1, 'recommended children');
        self::assertSame($expectedVerdict, $rec->verdict, 'verdict');
    }

    public static function recommendationCases(): array
    {
        return [
            'too high => OOM risk' => [1000, [20, 80], 50, null, null, 20, PoolRecommendation::VERDICT_OOM_RISK],
            'balanced => OK' => [1000, [40, 60], 15, null, null, 20, PoolRecommendation::VERDICT_OK],
            'too low with status => contention' => [2000, [45, 55], 10, 5, 0, 40, PoolRecommendation::VERDICT_CONTENTION],
            'listen queue backing up => contention' => [2000, [45, 55], 10, 0, 12, 40, PoolRecommendation::VERDICT_CONTENTION],
            'far under budget => underutilized' => [2000, [45, 55], 5, null, null, 40, PoolRecommendation::VERDICT_UNDERUTILIZED],
            'no samples => no data' => [1000, [], 50, null, null, -1, PoolRecommendation::VERDICT_NO_DATA],
        ];
    }

    public function testOomRiskOutranksContention(): void
    {
        $input = new PoolInput(
            new Pool('www', 'dynamic', 60),
            $this->rss([20, 80]),
            $this->makeStatus('www', 9, 50),
        );
        $report = (new Recommender())->analyze([$input], 1000 * self::MIB, 'test');
        self::assertSame(PoolRecommendation::VERDICT_OOM_RISK, $report->pools[0]->verdict);
    }

    public function testSharedPoolOversubscriptionDetected(): void
    {
        $inputs = [
            new PoolInput(new Pool('site_a', 'dynamic', 12), $this->rss([50])),
            new PoolInput(new Pool('site_b', 'dynamic', 12), $this->rss([50])),
        ];
        $report = (new Recommender())->analyze($inputs, 1000 * self::MIB, 'test');

        self::assertTrue($report->oversubscribed, 'committed peak 600+600=1200 MiB exceeds 1000 MiB budget');
        self::assertSame(PoolRecommendation::VERDICT_OK, $report->pools[0]->verdict, 'each pool is fine alone');
        self::assertSame(PoolRecommendation::VERDICT_OK, $report->pools[1]->verdict);
        self::assertNotEmpty($report->sharedPoolWarnings);
        self::assertTrue($report->hasProblems());
    }

    public function testNoOversubscriptionWhenTotalsFit(): void
    {
        $inputs = [
            new PoolInput(new Pool('site_a', 'dynamic', 5), $this->rss([50])),
            new PoolInput(new Pool('site_b', 'dynamic', 5), $this->rss([40])),
        ];
        $report = (new Recommender())->analyze($inputs, 2000 * self::MIB, 'test');
        self::assertFalse($report->oversubscribed);
    }

    public function testNullMaxChildrenStillRecommends(): void
    {
        $input = new PoolInput(new Pool('www', 'dynamic', null), $this->rss([50]));
        $report = (new Recommender())->analyze([$input], 1000 * self::MIB, 'test');
        $rec = $report->pools[0];
        self::assertNull($rec->configuredMaxChildren);
        self::assertSame(20, $rec->recommendedMaxChildren);
        self::assertSame(PoolRecommendation::VERDICT_OK, $rec->verdict);
    }

    /**
     * @param int[] $samplesMib
     */
    private function rss(array $samplesMib): RssMeasurement
    {
        $samples = array_map(static fn (int $m) => $m * self::MIB, $samplesMib);
        return new RssMeasurement('override', $samples);
    }

    private function makeStatus(string $pool, int $maxChildrenReached, int $listenQueue): FpmStatus
    {
        return new FpmStatus(
            pool: $pool,
            processManager: 'dynamic',
            listenQueue: $listenQueue,
            maxListenQueue: $listenQueue,
            listenQueueLen: 128,
            idleProcesses: 0,
            activeProcesses: 1,
            totalProcesses: 1,
            maxActiveProcesses: 1,
            maxChildrenReached: $maxChildrenReached,
            slowRequests: 0,
            acceptedConn: 1,
            processes: [],
        );
    }
}
