<?php

declare(strict_types=1);

namespace FpmDoctor;

use FpmDoctor\Cli\Args;
use FpmDoctor\Cli\Output;
use FpmDoctor\Command\AnalyzeCommand;
use FpmDoctor\Command\SlowlogCommand;
use FpmDoctor\Command\StatusCommand;

final class Application
{
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly Output $out = new Output(),
    ) {
    }

    /**
     * @param string[] $argv full argv including script name
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        $rest = array_slice($argv, 2);

        if ($command === null || in_array($command, ['help', '--help', '-h'], true)) {
            $this->usage();
            return $command === null ? 2 : 0;
        }
        if (in_array($command, ['--version', '-V', 'version'], true)) {
            $this->out->line('fpm-doctor ' . self::VERSION);
            return 0;
        }

        $args = Args::parse($rest);

        try {
            return match ($command) {
                'analyze' => (new AnalyzeCommand($this->out))->run($args),
                'slowlog' => (new SlowlogCommand($this->out))->run($args),
                'status' => (new StatusCommand($this->out))->run($args),
                default => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->out->line($this->out->paint('error: ', '1;31') . $e->getMessage());
            return 1;
        }
    }

    private function unknown(string $command): int
    {
        $this->out->line("Unknown command: {$command}");
        $this->usage();
        return 2;
    }

    private function usage(): void
    {
        $this->out->line('fpm-doctor ' . self::VERSION . ' — diagnose and tune PHP-FPM pools (read-only).');
        $this->out->line();
        $this->out->line('USAGE');
        $this->out->line('  fpm-doctor <command> [options]');
        $this->out->line();
        $this->out->line('COMMANDS');
        $this->out->line('  analyze   Size pm.max_children from measured RSS; flag OOM risk, contention, shared-pool 502 storms.');
        $this->out->line('  slowlog   Aggregate a php-fpm slowlog by script + top frame; rank by frequency and recency.');
        $this->out->line('  status    Pretty-print the live FPM status page.');
        $this->out->line();
        $this->out->line('analyze options');
        $this->out->line('  --conf=<file[,file]>     Pool .conf file(s).');
        $this->out->line('  --conf-dir=<dir[,dir]>   Directory of *.conf pool files.');
        $this->out->line('  --status=<url|target>    FPM status page (http(s) URL, unix:/sock:/status, or host:port:/status).');
        $this->out->line('  --status-file=<path>     Read a captured status JSON from disk instead of fetching it.');
        $this->out->line('  --ram=<MB>               RAM budget for FPM. Default: a fraction of detected total RAM.');
        $this->out->line('  --ram-fraction=<0..1>    Fraction of total RAM for FPM when --ram is absent (default 0.7).');
        $this->out->line('  --avg-rss=<MB>           Override measured avg child RSS (for non-Linux / remote).');
        $this->out->line('  --peak-rss=<MB>          Override peak child RSS (defaults to --avg-rss).');
        $this->out->line('  --format=conf            Also print a paste-ready pool snippet (never written to disk).');
        $this->out->line('  --json                   Machine-readable output.');
        $this->out->line();
        $this->out->line('slowlog options');
        $this->out->line('  --top=<N>                Show top N groups (default 15).');
        $this->out->line('  --json                   Machine-readable output.');
        $this->out->line();
        $this->out->line('fpm-doctor is read-only: it never writes configs and never restarts anything.');
    }
}
