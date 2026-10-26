<?php

declare(strict_types=1);

namespace FpmDoctor\Status;

/**
 * Fetches the raw FPM status body from either an HTTP(S) status URL or a
 * FastCGI target. The transport is chosen from the scheme of the argument.
 */
final class StatusClient
{
    public function __construct(
        private readonly FastCgiClient $fcgi = new FastCgiClient(),
        private readonly float $timeout = 5.0,
    ) {
    }

    public function fetch(string $status): string
    {
        $scheme = strtolower(parse_url($status, PHP_URL_SCHEME) ?? '');

        if ($scheme === 'http' || $scheme === 'https') {
            return $this->fetchHttp($status);
        }

        if ($scheme === 'fcgi' || $scheme === 'unix' || $this->looksLikeFcgi($status)) {
            return $this->fetchFcgi($status);
        }

        throw new \RuntimeException(
            "Unrecognised --status '{$status}'. Use an http(s) URL, " .
            'unix:/run/php/php-fpm.sock:/status, or host:port:/status.'
        );
    }

    private function fetchHttp(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array(($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new \RuntimeException("Refusing non-http(s) status URL: {$url}");
        }

        if (!str_contains($url, 'json')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'json';
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: fpm-doctor\r\n",
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException(
                "Cannot fetch status URL {$url}. Is the FPM status page enabled " .
                '(pm.status_path) and reachable?'
            );
        }
        return $body;
    }

    private function fetchFcgi(string $status): string
    {
        if (str_starts_with($status, 'fcgi://')) {
            $status = substr($status, 7);
        }

        // unix:/path.sock:/status?json&full
        if (str_starts_with($status, 'unix:')) {
            $rest = substr($status, 5);
            $colon = strpos($rest, ':');
            if ($colon === false) {
                throw new \RuntimeException(
                    'UNIX FastCGI target needs a path. Use unix:/run/php/php-fpm.sock:/status'
                );
            }
            $sockPath = substr($rest, 0, $colon);
            [$path, $query] = $this->splitPath(substr($rest, $colon + 1));
            return $this->fcgi->request('unix:' . $sockPath, $path, $query);
        }

        // host:port:/status?json&full
        if (preg_match('#^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.\-]+:\d{1,5}):(/.*)$#', $status, $m)) {
            [$path, $query] = $this->splitPath($m[2]);
            return $this->fcgi->request($m[1], $path, $query);
        }

        throw new \RuntimeException(
            "Cannot parse FastCGI status target '{$status}'. " .
            'Use unix:/path.sock:/status?json&full or host:port:/status?json&full'
        );
    }

    /** @return array{0:string,1:string} */
    private function splitPath(string $pathAndQuery): array
    {
        $q = strpos($pathAndQuery, '?');
        if ($q === false) {
            return [$pathAndQuery, 'json&full'];
        }
        $path = substr($pathAndQuery, 0, $q);
        $query = substr($pathAndQuery, $q + 1);
        if (!str_contains($query, 'json')) {
            $query = 'json&' . $query;
        }
        return [$path, $query];
    }

    private function looksLikeFcgi(string $status): bool
    {
        return (bool) preg_match('#^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.\-]+):\d{1,5}:/#', $status);
    }
}
