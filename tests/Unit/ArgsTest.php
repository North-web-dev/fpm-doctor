<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Cli\Args;
use PHPUnit\Framework\TestCase;

final class ArgsTest extends TestCase
{
    public function testParsesOptionsAndPositionals(): void
    {
        $args = Args::parse(['/var/log/slow.log', '--top=5', '--json', '--ram=2048']);

        self::assertSame(['/var/log/slow.log'], $args->positionals);
        self::assertSame(5, $args->int('top'));
        self::assertTrue($args->bool('json'));
        self::assertSame(2048, $args->int('ram'));
    }

    public function testFloatAndDefaults(): void
    {
        $args = Args::parse(['--ram-fraction=0.8']);
        self::assertSame(0.8, $args->float('ram-fraction', 0.7));
        self::assertSame(0.7, $args->float('missing', 0.7));
        self::assertSame(15, $args->int('top', 15));
    }

    public function testDoubleDashStopsOptionParsing(): void
    {
        $args = Args::parse(['--json', '--', '--not-an-option']);
        self::assertTrue($args->bool('json'));
        self::assertSame(['--not-an-option'], $args->positionals);
    }

    public function testBoolFalseWhenAbsent(): void
    {
        $args = Args::parse([]);
        self::assertFalse($args->bool('json'));
        self::assertNull($args->str('conf'));
    }
}
