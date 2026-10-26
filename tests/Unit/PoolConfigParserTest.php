<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Config\PoolConfigParser;
use PHPUnit\Framework\TestCase;

final class PoolConfigParserTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__) . '/fixtures';
    }

    public function testParsesSinglePool(): void
    {
        $pools = (new PoolConfigParser())->parseFile($this->fixtures . '/www.conf');

        self::assertCount(1, $pools);
        $www = $pools[0];
        self::assertSame('www', $www->name);
        self::assertSame('dynamic', $www->pm);
        self::assertSame(120, $www->maxChildren);
        self::assertSame(10, $www->startServers);
        self::assertSame(5, $www->minSpareServers);
        self::assertSame(20, $www->maxSpareServers);
        self::assertSame(500, $www->maxRequests);
        self::assertSame('/run/php/php8.2-fpm.sock', $www->listen);
        self::assertSame('256M', $www->memoryLimitRaw);
        self::assertSame(256 * 1024 * 1024, $www->memoryLimitBytes);
    }

    public function testParsesMultiplePoolsAndIgnoresGlobal(): void
    {
        $pools = (new PoolConfigParser())->parseFile($this->fixtures . '/shared.conf');

        $names = array_map(static fn ($p) => $p->name, $pools);
        self::assertSame(['site_a', 'site_b', 'site_c'], $names);
        self::assertNotContains('global', $names);

        self::assertSame(80, $pools[0]->maxChildren);
        self::assertSame(512 * 1024 * 1024, $pools[0]->memoryLimitBytes);
        self::assertSame('ondemand', $pools[1]->pm);
        self::assertSame(384 * 1024 * 1024, $pools[1]->memoryLimitBytes);
        self::assertSame('127.0.0.1:9002', $pools[2]->listen);
        self::assertNull($pools[2]->memoryLimitBytes);
    }

    public function testStripsInlineComments(): void
    {
        $pools = (new PoolConfigParser())->parseString(
            "[p]\npm.max_children = 42 ; keep low\nlisten = 127.0.0.1:9000\n"
        );
        self::assertSame(42, $pools[0]->maxChildren);
        self::assertSame('127.0.0.1:9000', $pools[0]->listen);
    }

    public function testMalformedConfigDoesNotCrash(): void
    {
        $pools = (new PoolConfigParser())->parseString("garbage\n[p]\nno_equals_here\npm = static\n");
        self::assertCount(1, $pools);
        self::assertSame('static', $pools[0]->pm);
        self::assertNull($pools[0]->maxChildren);
    }
}
