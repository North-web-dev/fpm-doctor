<?php

declare(strict_types=1);

namespace FpmDoctor\Tests\Unit;

use FpmDoctor\Cli\Args;
use FpmDoctor\Cli\Output;
use FpmDoctor\Command\SlowlogCommand;
use PHPUnit\Framework\TestCase;

final class SlowlogRenderSafetyTest extends TestCase
{
    public function testMaliciousFrameCannotInjectTerminalEscapes(): void
    {
        $log = "[26-Oct-2026 09:15:32]  [pool \x1b[2Jwww] pid 2101\n"
            . "script_filename = /var/www/\x1b[31mapp\x07/index.php\n"
            . "[0x00007f0000000010] evil\x1b[31m() /var/www/\x1b]0;pwned\x07boom.php:7\n";

        $tmp = tempnam(sys_get_temp_dir(), 'slow');
        file_put_contents($tmp, $log);

        $stream = fopen('php://memory', 'r+');
        $out = new Output($stream, false);

        try {
            (new SlowlogCommand($out))->run(Args::parse([$tmp]));
            rewind($stream);
            $rendered = (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
            unlink($tmp);
        }

        self::assertStringNotContainsString("\x1b", $rendered, 'no ESC bytes reach the terminal');
        self::assertStringNotContainsString("\x07", $rendered, 'no BEL bytes reach the terminal');
        self::assertStringNotContainsString("\x00", $rendered);
        self::assertStringContainsString('index.php', $rendered, 'legible content is preserved');
    }

    public function testMaliciousFrameAlsoCleanInJsonPath(): void
    {
        $log = "[26-Oct-2026 09:15:32]  [pool www] pid 2101\n"
            . "script_filename = /app/\x1b[31mindex.php\n"
            . "[0x00007f0000000010] boom\x07() /app/x.php:1\n";

        $tmp = tempnam(sys_get_temp_dir(), 'slow');
        file_put_contents($tmp, $log);

        $stream = fopen('php://memory', 'r+');
        $out = new Output($stream, false);

        try {
            (new SlowlogCommand($out))->run(Args::parse([$tmp, '--json']));
            rewind($stream);
            $rendered = (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
            unlink($tmp);
        }

        self::assertStringNotContainsString("\x1b", $rendered);
        self::assertStringNotContainsString("\x07", $rendered);
    }
}
