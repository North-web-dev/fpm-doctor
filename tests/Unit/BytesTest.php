<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Util\Bytes;
use PHPUnit\Framework\TestCase;

final class BytesTest extends TestCase
{
    /**
     * @dataProvider shorthandCases
     */
    public function testParseShorthand(string $input, ?int $expected): void
    {
        self::assertSame($expected, Bytes::parseShorthand($input));
    }

    public static function shorthandCases(): array
    {
        return [
            ['256M', 256 * 1024 * 1024],
            ['512m', 512 * 1024 * 1024],
            ['1G', 1024 * 1024 * 1024],
            ['128K', 128 * 1024],
            ['64', 64],
            ['256MB', 256 * 1024 * 1024],
            ['-1', -1],
            ['', null],
            ['abc', null],
            ['12x', null],
        ];
    }

    public function testToMiB(): void
    {
        self::assertSame(1.0, Bytes::toMiB(1024 * 1024));
    }
}
