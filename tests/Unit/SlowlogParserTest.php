<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Slowlog\SlowlogParser;
use PHPUnit\Framework\TestCase;

final class SlowlogParserTest extends TestCase
{
    public function testAggregatesByScriptAndTopFrame(): void
    {
        $report = (new SlowlogParser())->parseFile(dirname(__DIR__) . '/fixtures/php-fpm.slow.log');

        self::assertSame(5, $report->totalEntries);

        $top = $report->byFrequency()[0];
        self::assertSame('/var/www/shop/public/index.php', $top->script);
        self::assertStringContainsString('curl_exec()', $top->topFrame);
        self::assertSame(3, $top->count, 'three identical curl_exec checkout traces');
    }

    public function testCountsMalformedLinesWithoutCrashing(): void
    {
        $report = (new SlowlogParser())->parseFile(dirname(__DIR__) . '/fixtures/php-fpm.slow.log');

        self::assertGreaterThanOrEqual(2, $report->malformedLines);
        self::assertSame(3, count($report->groups));
    }

    public function testRecencyOrdering(): void
    {
        $report = (new SlowlogParser())->parseFile(dirname(__DIR__) . '/fixtures/php-fpm.slow.log');

        $recent = $report->byRecency(1)[0];
        self::assertSame('/var/www/shop/public/search.php', $recent->script);
    }

    public function testEmptyLogProducesEmptyReport(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'slow');
        file_put_contents($tmp, "\n\n");
        try {
            $report = (new SlowlogParser())->parseFile($tmp);
            self::assertSame(0, $report->totalEntries);
            self::assertSame([], $report->groups);
        } finally {
            unlink($tmp);
        }
    }

    public function testTruncatedEntryDoesNotCrash(): void
    {
        $parser = new SlowlogParser();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "[26-Oct-2026 09:15:32]  [pool www] pid 2101\nscript_filename = /app/index.php\n");
        rewind($stream);
        $report = $parser->parseStream($stream);
        fclose($stream);

        self::assertSame(1, $report->totalEntries);
        self::assertSame('(no frames)', $report->groups[0]->topFrame);
    }
}
