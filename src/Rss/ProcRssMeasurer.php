<?php

declare(strict_types=1);

namespace FpmDoctor\Rss;

/**
 * Measures php-fpm child RSS from Linux /proc. Workers are matched by their
 * process title "php-fpm: pool <name>", which FPM sets per pool.
 */
final class ProcRssMeasurer
{
    private int $pageSize;

    public function __construct(
        private readonly string $procRoot = '/proc',
    ) {
        $this->pageSize = $this->detectPageSize();
    }

    public function isAvailable(): bool
    {
        return is_dir($this->procRoot) && is_readable($this->procRoot);
    }

    public function measurePool(string $pool): RssMeasurement
    {
        if (!$this->isAvailable()) {
            return new RssMeasurement(
                'proc',
                [],
                "{$this->procRoot} is not readable (non-Linux host, or container without /proc)."
            );
        }

        $samples = [];
        foreach ($this->fpmChildren() as [$pid, $title]) {
            if ($this->titleMatchesPool($title, $pool)) {
                $rss = $this->rssForPid($pid);
                if ($rss !== null) {
                    $samples[] = $rss;
                }
            }
        }

        $note = $samples === []
            ? "No running php-fpm workers found for pool '{$pool}' in {$this->procRoot}."
            : null;

        return new RssMeasurement('proc', $samples, $note);
    }

    /** @return array<int,array{0:int,1:string}> [pid, process title] */
    public function fpmChildren(): array
    {
        $out = [];
        foreach (glob($this->procRoot . '/[0-9]*', GLOB_NOSORT) ?: [] as $dir) {
            $pid = (int) basename($dir);
            if ($pid <= 0) {
                continue;
            }
            $cmdline = @file_get_contents($dir . '/cmdline');
            if ($cmdline === false || $cmdline === '') {
                continue;
            }
            $title = trim(str_replace("\0", ' ', $cmdline));
            if (str_contains($title, 'php-fpm: pool ')) {
                $out[] = [$pid, $title];
            }
        }
        return $out;
    }

    private function titleMatchesPool(string $title, string $pool): bool
    {
        return (bool) preg_match('/php-fpm: pool\s+' . preg_quote($pool, '/') . '\b/', $title);
    }

    private function rssForPid(int $pid): ?int
    {
        $statm = @file_get_contents($this->procRoot . '/' . $pid . '/statm');
        if ($statm === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($statm));
        if (!isset($fields[1]) || !ctype_digit($fields[1])) {
            return null;
        }
        return ((int) $fields[1]) * $this->pageSize;
    }

    private function detectPageSize(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGE_SIZE')) {
            $v = @posix_sysconf(POSIX_SC_PAGE_SIZE);
            if (is_int($v) && $v > 0) {
                return $v;
            }
        }
        return 4096;
    }
}
