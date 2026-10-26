<?php

declare(strict_types=1);

namespace FpmDoctor\Slowlog;

use FpmDoctor\Util\Text;

/**
 * Streams a php-fpm slowlog and aggregates stack traces by (script, top frame).
 *
 * The slowlog records that request_slowlog_timeout was exceeded, not the
 * elapsed time, so this ranks by how often each trace trips and how recently -
 * it never invents durations.
 */
final class SlowlogParser
{
    private const HEADER = '/^\[(?<ts>[^\]]+)\]\s+\[pool\s+(?<pool>[^\]]+)\]\s+pid\s+(?<pid>\d+)/';
    private const FRAME = '/^\[0x[0-9a-fA-F]+\]\s+(?<fn>[^\s]+)\s+(?<loc>.+)$/';

    /** @var array<string,SlowlogGroup> */
    private array $groups = [];
    private int $totalEntries = 0;
    private int $malformed = 0;

    public function parseFile(string $path, int $maxSampleFrames = 50): SlowlogReport
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open slowlog: {$path}");
        }
        try {
            return $this->parseStream($fh, $maxSampleFrames);
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param resource $fh
     */
    public function parseStream($fh, int $maxSampleFrames = 50): SlowlogReport
    {
        $this->groups = [];
        $this->totalEntries = 0;
        $this->malformed = 0;

        $curTs = null;
        $curScript = null;
        $curTopFrame = null;
        $curTrace = [];
        $inEntry = false;

        $flush = function () use (&$curTs, &$curScript, &$curTopFrame, &$curTrace, &$inEntry, $maxSampleFrames): void {
            if ($inEntry && $curScript !== null) {
                $this->record($curScript, $curTopFrame ?? '(no frames)', $curTs, $curTrace, $maxSampleFrames);
            }
            $curTs = $curScript = $curTopFrame = null;
            $curTrace = [];
            $inEntry = false;
        };

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");

            if (preg_match(self::HEADER, $line, $m)) {
                $flush();
                $inEntry = true;
                $curTs = Text::safe(trim($m['ts']));
                continue;
            }

            if ($line === '') {
                $flush();
                continue;
            }

            if (!$inEntry) {
                $this->malformed++;
                continue;
            }

            if (preg_match('/^script_filename\s*=\s*(.+)$/', $line, $m)) {
                $curScript = Text::safe(trim($m[1]));
                continue;
            }

            if (preg_match(self::FRAME, $line, $m)) {
                $frame = Text::safe($m['fn'] . ' ' . trim($m['loc']));
                if ($curTopFrame === null) {
                    $curTopFrame = $frame;
                }
                if (count($curTrace) < $maxSampleFrames) {
                    $curTrace[] = $frame;
                }
                continue;
            }

            $this->malformed++;
        }

        $flush();

        return new SlowlogReport(array_values($this->groups), $this->totalEntries, $this->malformed);
    }

    /**
     * @param string[] $trace
     */
    private function record(string $script, string $topFrame, ?string $ts, array $trace, int $maxSampleFrames): void
    {
        $this->totalEntries++;
        $key = $script . "\n" . $topFrame;
        $group = $this->groups[$key] ??= new SlowlogGroup($script, $topFrame);
        $group->count++;

        $epoch = $ts !== null ? $this->toEpoch($ts) : null;
        if ($group->firstSeen === null) {
            $group->firstSeen = $ts;
            $group->firstEpoch = $epoch;
        }
        $group->lastSeen = $ts;
        $group->lastEpoch = $epoch;

        if ($group->sampleTrace === [] && $trace !== []) {
            $group->sampleTrace = array_slice($trace, 0, $maxSampleFrames);
        }
    }

    private function toEpoch(string $ts): ?int
    {
        $t = strtotime($ts);
        return $t === false ? null : $t;
    }
}
