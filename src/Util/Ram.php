<?php

declare(strict_types=1);

namespace FpmDoctor\Util;

final class Ram
{
    public function __construct(
        private readonly string $procRoot = '/proc',
    ) {
    }

    public function totalBytes(): ?int
    {
        $meminfo = @file_get_contents($this->procRoot . '/meminfo');
        if ($meminfo === false) {
            return null;
        }
        if (preg_match('/^MemTotal:\s+(\d+)\s*kB/mi', $meminfo, $m)) {
            return ((int) $m[1]) * 1024;
        }
        return null;
    }

    /**
     * @return array{0:int,1:string} budget bytes, human-readable source note
     */
    public function budget(?int $explicitMb, ?int $totalBytes, float $fraction): array
    {
        if ($explicitMb !== null) {
            return [$explicitMb * 1024 * 1024, "explicit --ram {$explicitMb} MB"];
        }
        if ($totalBytes !== null) {
            $budget = (int) floor($totalBytes * $fraction);
            return [
                $budget,
                sprintf(
                    '%.0f%% of detected %s total RAM',
                    $fraction * 100,
                    Bytes::humanMiB($totalBytes)
                ),
            ];
        }
        return [0, 'unknown (could not detect RAM; pass --ram)'];
    }
}
