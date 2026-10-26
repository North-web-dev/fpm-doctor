<?php

declare(strict_types=1);

namespace FpmDoctor\Cli;

final class Args
{
    /** @var string[] */
    public array $positionals = [];

    /** @var array<string,string|bool> */
    public array $options = [];

    /**
     * @param string[] $argv raw args after the command name
     */
    public static function parse(array $argv): self
    {
        $self = new self();
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                for ($j = $i + 1; $j < $count; $j++) {
                    $self->positionals[] = $argv[$j];
                }
                break;
            }
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if (str_contains($body, '=')) {
                    [$k, $v] = explode('=', $body, 2);
                    $self->options[$k] = $v;
                } else {
                    $self->options[$body] = true;
                }
                continue;
            }
            $self->positionals[] = $arg;
        }
        return $self;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function bool(string $name): bool
    {
        return ($this->options[$name] ?? false) !== false;
    }

    public function str(string $name, ?string $default = null): ?string
    {
        $v = $this->options[$name] ?? null;
        if ($v === null || $v === true) {
            return $default;
        }
        return (string) $v;
    }

    public function int(string $name, ?int $default = null): ?int
    {
        $v = $this->str($name);
        if ($v === null || !preg_match('/^-?\d+$/', $v)) {
            return $default;
        }
        return (int) $v;
    }

    public function float(string $name, ?float $default = null): ?float
    {
        $v = $this->str($name);
        if ($v === null || !is_numeric($v)) {
            return $default;
        }
        return (float) $v;
    }
}
