<?php

declare(strict_types=1);

namespace FpmDoctor\Status;

/**
 * Minimal FastCGI client, just enough to GET the FPM status path over a
 * UNIX socket or TCP host:port. It only ever connects to the exact target the
 * caller passes; it builds no URLs and follows no redirects.
 */
class FastCgiClient
{
    private const FCGI_VERSION = 1;
    private const FCGI_BEGIN_REQUEST = 1;
    private const FCGI_END_REQUEST = 3;
    private const FCGI_PARAMS = 4;
    private const FCGI_STDIN = 5;
    private const FCGI_STDOUT = 6;
    private const FCGI_STDERR = 7;
    private const FCGI_RESPONDER = 1;

    public function __construct(
        private readonly float $timeout = 5.0,
    ) {
    }

    /**
     * @param string $target  "unix:/path/to.sock" or "host:port"
     * @param string $path    status path, e.g. "/status"
     * @param string $query   query string, e.g. "json&full"
     */
    public function request(string $target, string $path, string $query = ''): string
    {
        $remote = $this->remoteFor($target);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if ($sock === false) {
            throw new \RuntimeException("Cannot connect to FPM socket {$target}: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, (int) $this->timeout);

        try {
            $params = [
                'SCRIPT_NAME' => $path,
                'SCRIPT_FILENAME' => $path,
                'QUERY_STRING' => $query,
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $query === '' ? $path : $path . '?' . $query,
                'GATEWAY_INTERFACE' => 'FastCGI/1.0',
                'SERVER_SOFTWARE' => 'fpm-doctor',
                'REMOTE_ADDR' => '127.0.0.1',
            ];

            $requestId = 1;
            $packet = $this->record(
                self::FCGI_BEGIN_REQUEST,
                $requestId,
                pack('nCC', self::FCGI_RESPONDER, 0, 0) . "\x00\x00\x00"
            );
            $paramsBody = '';
            foreach ($params as $k => $v) {
                $paramsBody .= $this->nameValuePair($k, (string) $v);
            }
            $packet .= $this->record(self::FCGI_PARAMS, $requestId, $paramsBody);
            $packet .= $this->record(self::FCGI_PARAMS, $requestId, '');
            $packet .= $this->record(self::FCGI_STDIN, $requestId, '');

            if (@fwrite($sock, $packet) === false) {
                throw new \RuntimeException("Failed to write FastCGI request to {$target}");
            }

            return $this->readResponse($sock, $target);
        } finally {
            fclose($sock);
        }
    }

    private function remoteFor(string $target): string
    {
        if (str_starts_with($target, 'unix:')) {
            $sockPath = substr($target, 5);
            if ($sockPath === '' || str_contains($sockPath, "\0")) {
                throw new \RuntimeException('Invalid UNIX socket path.');
            }
            return 'unix://' . $sockPath;
        }

        if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.\-]+):(\d{1,5})$/', $target, $m)) {
            throw new \RuntimeException("Invalid FastCGI target '{$target}'. Use host:port or unix:/path.sock");
        }
        $port = (int) $m[2];
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException("Invalid port in FastCGI target '{$target}'.");
        }
        return 'tcp://' . $m[1] . ':' . $port;
    }

    private function readResponse($sock, string $target): string
    {
        $stdout = '';
        $stderr = '';

        while (!feof($sock)) {
            $header = $this->readExactly($sock, 8);
            if ($header === '' || strlen($header) < 8) {
                break;
            }
            $h = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);
            $content = $h['contentLength'] > 0 ? $this->readExactly($sock, $h['contentLength']) : '';
            if ($h['paddingLength'] > 0) {
                $this->readExactly($sock, $h['paddingLength']);
            }

            switch ($h['type']) {
                case self::FCGI_STDOUT:
                    $stdout .= $content;
                    break;
                case self::FCGI_STDERR:
                    $stderr .= $content;
                    break;
                case self::FCGI_END_REQUEST:
                    break 2;
            }
        }

        if (trim($stdout) === '' && trim($stderr) !== '') {
            throw new \RuntimeException("FastCGI error from {$target}: " . trim($stderr));
        }

        return $this->stripCgiHeaders($stdout);
    }

    private function readExactly($sock, int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($sock, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                if ($meta['timed_out'] ?? false) {
                    throw new \RuntimeException('FastCGI read timed out.');
                }
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function stripCgiHeaders(string $out): string
    {
        $pos = strpos($out, "\r\n\r\n");
        if ($pos !== false) {
            return substr($out, $pos + 4);
        }
        $pos = strpos($out, "\n\n");
        if ($pos !== false) {
            return substr($out, $pos + 2);
        }
        return $out;
    }

    private function record(int $type, int $requestId, string $content): string
    {
        $length = strlen($content);
        return pack('CCnnCC', self::FCGI_VERSION, $type, $requestId, $length, 0, 0) . $content;
    }

    private function nameValuePair(string $name, string $value): string
    {
        $nameLen = strlen($name);
        $valueLen = strlen($value);
        $out = $nameLen < 128 ? chr($nameLen) : pack('N', $nameLen | 0x80000000);
        $out .= $valueLen < 128 ? chr($valueLen) : pack('N', $valueLen | 0x80000000);
        return $out . $name . $value;
    }
}
