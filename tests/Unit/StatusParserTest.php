<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Status\StatusParser;
use PHPUnit\Framework\TestCase;

final class StatusParserTest extends TestCase
{
    public function testParsesFullStatusJson(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/status-full.json');
        $status = (new StatusParser())->parseJson($json);

        self::assertSame('www', $status->pool);
        self::assertSame('dynamic', $status->processManager);
        self::assertSame(34, $status->listenQueue);
        self::assertSame(128, $status->maxListenQueue);
        self::assertSame(120, $status->activeProcesses);
        self::assertSame(7, $status->maxChildrenReached);
        self::assertSame(12, $status->slowRequests);
        self::assertSame(184233, $status->acceptedConn);
        self::assertTrue($status->hasPerProcessData());
    }

    public function testExtractsLastRequestMemory(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/status-full.json');
        $status = (new StatusParser())->parseJson($json);

        self::assertSame([73400320, 65011712, 58720256], $status->lastRequestMemories());
    }

    public function testMissingFieldsDefaultToZero(): void
    {
        $status = (new StatusParser())->parseJson('{"pool":"bare"}');
        self::assertSame('bare', $status->pool);
        self::assertSame(0, $status->maxChildrenReached);
        self::assertFalse($status->hasPerProcessData());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        (new StatusParser())->parseJson('{not json');
    }
}
