<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Rss\ProcRssMeasurer;
use PHPUnit\Framework\TestCase;

final class ProcRssMeasurerTest extends TestCase
{
    private string $fakeProc;

    protected function setUp(): void
    {
        $this->fakeProc = sys_get_temp_dir() . '/fpmdoc-proc-' . getmypid() . '-' . uniqid();
        $this->makeProcess(2101, 'php-fpm: pool www', 16384);
        $this->makeProcess(2102, 'php-fpm: pool www', 20480);
        $this->makeProcess(3301, 'php-fpm: pool api', 8192);
        $this->makeProcess(9001, 'nginx: worker process', 1000);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fakeProc . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->fakeProc . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->fakeProc);
    }

    public function testMatchesWorkersByPoolAndComputesRss(): void
    {
        $page = $this->pageSize();
        $m = (new ProcRssMeasurer($this->fakeProc))->measurePool('www');

        self::assertSame(2, $m->count());
        self::assertSame((16384 + 20480) / 2 * (float) $page, $m->avg());
        self::assertSame(20480 * $page, $m->peak());
        self::assertSame('proc', $m->method);
    }

    public function testIgnoresOtherPoolsAndNonFpm(): void
    {
        $m = (new ProcRssMeasurer($this->fakeProc))->measurePool('api');
        self::assertSame(1, $m->count());
        self::assertSame(8192 * $this->pageSize(), $m->peak());
    }

    private function pageSize(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGE_SIZE')) {
            $v = @posix_sysconf(POSIX_SC_PAGE_SIZE);
            if (is_int($v) && $v > 0) {
                return $v;
            }
        }
        return 4096;
    }

    public function testUnknownPoolReturnsNoteNotCrash(): void
    {
        $m = (new ProcRssMeasurer($this->fakeProc))->measurePool('ghost');
        self::assertFalse($m->hasData());
        self::assertNotNull($m->note);
    }

    public function testMissingProcRootDegradesGracefully(): void
    {
        $m = (new ProcRssMeasurer('/nonexistent/proc/root'))->measurePool('www');
        self::assertFalse($m->hasData());
        self::assertStringContainsString('not readable', (string) $m->note);
    }

    private function makeProcess(int $pid, string $title, int $residentPages): void
    {
        $dir = $this->fakeProc . '/' . $pid;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/cmdline', str_replace(' ', "\0", $title) . "\0");
        file_put_contents($dir . '/statm', "40000 {$residentPages} 2000 10 0 3000 0\n");
    }
}
