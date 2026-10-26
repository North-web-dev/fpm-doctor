<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Status\FastCgiClient;
use FpmDoctor\Status\StatusClient;
use PHPUnit\Framework\TestCase;

final class StatusClientTest extends TestCase
{
    public function testSplitsUnixSocketTargetAndForcesJson(): void
    {
        $fcgi = new RecordingFastCgiClient();
        $client = new StatusClient($fcgi);

        $client->fetch('unix:/run/php/php8.2-fpm.sock:/status?full');

        self::assertSame('unix:/run/php/php8.2-fpm.sock', $fcgi->target);
        self::assertSame('/status', $fcgi->path);
        self::assertStringContainsString('json', $fcgi->query);
        self::assertStringContainsString('full', $fcgi->query);
    }

    public function testSplitsTcpTarget(): void
    {
        $fcgi = new RecordingFastCgiClient();
        $client = new StatusClient($fcgi);

        $client->fetch('127.0.0.1:9000:/fpm-status?json&full');

        self::assertSame('127.0.0.1:9000', $fcgi->target);
        self::assertSame('/fpm-status', $fcgi->path);
        self::assertSame('json&full', $fcgi->query);
    }

    public function testDefaultsPathQueryWhenAbsent(): void
    {
        $fcgi = new RecordingFastCgiClient();
        (new StatusClient($fcgi))->fetch('unix:/run/php.sock:/status');
        self::assertSame('json&full', $fcgi->query);
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->expectException(\RuntimeException::class);
        (new StatusClient())->fetch('file:///etc/passwd');
    }

    public function testRejectsGarbageTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        (new StatusClient())->fetch('not a target at all');
    }
}

final class RecordingFastCgiClient extends FastCgiClient
{
    public string $target = '';
    public string $path = '';
    public string $query = '';

    public function request(string $target, string $path, string $query = ''): string
    {
        $this->target = $target;
        $this->path = $path;
        $this->query = $query;
        return '{"pool":"www"}';
    }
}
