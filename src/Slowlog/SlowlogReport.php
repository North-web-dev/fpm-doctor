<?php

declare(strict_types=1);

namespace FpmDoctor\Slowlog;

final class SlowlogReport
{
    /**
     * @param SlowlogGroup[] $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $totalEntries,
        public readonly int $malformedLines,
    ) {
    }

    /** @return SlowlogGroup[] */
    public function byFrequency(?int $limit = null): array
    {
        $groups = $this->groups;
        usort($groups, static function (SlowlogGroup $a, SlowlogGroup $b): int {
            return $b->count <=> $a->count ?: strcmp($a->script, $b->script);
        });
        return $limit === null ? $groups : array_slice($groups, 0, $limit);
    }

    /** @return SlowlogGroup[] */
    public function byRecency(?int $limit = null): array
    {
        $groups = $this->groups;
        usort($groups, static function (SlowlogGroup $a, SlowlogGroup $b): int {
            return ($b->lastEpoch ?? 0) <=> ($a->lastEpoch ?? 0);
        });
        return $limit === null ? $groups : array_slice($groups, 0, $limit);
    }

    public function toArray(): array
    {
        return [
            'total_entries' => $this->totalEntries,
            'malformed_lines' => $this->malformedLines,
            'distinct_groups' => count($this->groups),
            'groups' => array_map(static fn (SlowlogGroup $g) => $g->toArray(), $this->byFrequency()),
        ];
    }
}
