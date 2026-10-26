<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Util\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testStripsEscapeAndControlBytes(): void
    {
        $out = Text::safe("\x1b[31mred\x07\x00 bell");
        self::assertStringNotContainsString("\x1b", $out);
        self::assertStringNotContainsString("\x07", $out);
        self::assertStringNotContainsString("\x00", $out);
        self::assertStringContainsString('red', $out);
    }

    public function testLeavesPlainTextIntact(): void
    {
        $in = '/var/www/app/public/index.php:19';
        self::assertSame($in, Text::safe($in));
    }

    public function testStripsDelAndC1(): void
    {
        self::assertStringNotContainsString("\x7f", Text::safe("a\x7fb"));
        self::assertStringNotContainsString("\x9b", Text::safe("a\x9bb"));
    }
}
