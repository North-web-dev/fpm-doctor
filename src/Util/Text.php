<?php

declare(strict_types=1);

namespace FpmDoctor\Util;

final class Text
{
    /**
     * Neutralises externally-derived text before it is printed or serialised.
     * Strips C0/C1 control bytes, ESC and DEL so a crafted slowlog frame, pool
     * name or status field cannot rewrite the operator's terminal via ANSI
     * escapes. Invalid UTF-8 is handled so the replace cannot silently drop the
     * whole string.
     */
    public static function safe(string $s): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '?', $s);
        if ($clean === null) {
            $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '?', $s) ?? '';
        }
        return $clean;
    }
}
