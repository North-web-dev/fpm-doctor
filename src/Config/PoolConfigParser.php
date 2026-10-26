<?php

declare(strict_types=1);

namespace FpmDoctor\Config;

use FpmDoctor\Util\Bytes;
use FpmDoctor\Util\Text;

final class PoolConfigParser
{
    public const DEFAULT_PATHS = [
        '/etc/php/*/fpm/pool.d',
        '/etc/php-fpm.d',
        '/usr/local/etc/php-fpm.d',
        '/opt/homebrew/etc/php/*/php-fpm.d',
    ];

    /** @return Pool[] */
    public function parseFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read pool config: {$path}");
        }
        return $this->parseString($contents, $path);
    }

    /** @return Pool[] */
    public function parseString(string $contents, ?string $source = null): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                $current = trim($m[1]);
                if ($current !== 'global') {
                    $sections[$current] ??= [];
                }
                continue;
            }
            if ($current === null || $current === 'global') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = $this->stripInlineComment($value);
            $sections[$current][strtolower($key)] = $value;
        }

        $pools = [];
        foreach ($sections as $name => $kv) {
            $pools[] = $this->buildPool($name, $kv, $source);
        }
        return $pools;
    }

    private function buildPool(string $name, array $kv, ?string $source): Pool
    {
        $memRaw = $kv['php_admin_value[memory_limit]']
            ?? $kv['php_value[memory_limit]']
            ?? null;

        return new Pool(
            name: Text::safe($name),
            pm: isset($kv['pm']) ? Text::safe($kv['pm']) : null,
            maxChildren: $this->intOrNull($kv['pm.max_children'] ?? null),
            startServers: $this->intOrNull($kv['pm.start_servers'] ?? null),
            minSpareServers: $this->intOrNull($kv['pm.min_spare_servers'] ?? null),
            maxSpareServers: $this->intOrNull($kv['pm.max_spare_servers'] ?? null),
            maxRequests: $this->intOrNull($kv['pm.max_requests'] ?? null),
            listen: isset($kv['listen']) ? Text::safe($kv['listen']) : null,
            memoryLimitBytes: $memRaw !== null ? Bytes::parseShorthand($memRaw) : null,
            memoryLimitRaw: $memRaw !== null ? Text::safe($memRaw) : null,
            sourceFile: $source,
        );
    }

    /** @return string[] */
    public function discoverFiles(array $globs = self::DEFAULT_PATHS): array
    {
        $files = [];
        foreach ($globs as $glob) {
            foreach (glob($glob, GLOB_NOSORT) ?: [] as $dir) {
                if (is_dir($dir)) {
                    foreach (glob(rtrim($dir, '/') . '/*.conf', GLOB_NOSORT) ?: [] as $f) {
                        $files[] = $f;
                    }
                } elseif (is_file($dir)) {
                    $files[] = $dir;
                }
            }
        }
        sort($files);
        return array_values(array_unique($files));
    }

    private function intOrNull(?string $v): ?int
    {
        if ($v === null || !preg_match('/^-?\d+$/', trim($v))) {
            return null;
        }
        return (int) $v;
    }

    private function stripInlineComment(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            return trim($v, "\"'");
        }
        if (preg_match('/^(.*?)\s+;.*$/', $v, $m)) {
            return trim($m[1]);
        }
        return $v;
    }
}
