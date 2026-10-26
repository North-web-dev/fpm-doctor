<?php

declare(strict_types=1);

namespace FpmDoctor\Util;

final class Bytes
{
    public static function parseShorthand(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(-?\d+)\s*([kKmMgG]?)[bB]?$/', $value, $m)) {
            return null;
        }
        $n = (int) $m[1];
        return match (strtolower($m[2])) {
            'k' => $n * 1024,
            'm' => $n * 1024 * 1024,
            'g' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }

    public static function toMiB(int|float $bytes): float
    {
        return $bytes / (1024 * 1024);
    }

    public static function humanMiB(int|float $bytes): string
    {
        return number_format(self::toMiB($bytes), 1) . ' MiB';
    }
}
