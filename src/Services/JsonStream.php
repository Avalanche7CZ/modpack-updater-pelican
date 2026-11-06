<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bounded reads of immutable JSON evidence. Never materializes a whole inventory or change array. */
final class JsonStream
{
    private string $buffer = '';
    private int $position = 0;
    private int $offset = 0;
    private const CHUNK = 65536;
    private const VALUE_LIMIT = 1048576;

    public function __construct(private readonly \Closure $read) {}

    public static function column(int $id, string $column): self
    {
        if (! in_array($column, ['file_inventory', 'adoption_report', 'preview_summary'], true)) {
            throw new RuntimeException('Unsupported evidence column');
        }
        $binary = DB::getDriverName() === 'sqlite' ? 'BLOB' : 'BINARY';
        return new self(fn ($offset, $size) => (string) DB::table('arcturus_modpack_servers')->where('id', $id)
            ->selectRaw('substr(CAST('.$column.' AS '.$binary.'), ?, ?) AS fragment', [$offset + 1, $size])->value('fragment'));
    }

    public static function file(string $path): self
    {
        $file = fopen($path, 'rb');
        if (! $file) { throw new RuntimeException('Cannot open retained evidence'); }
        return new self(static function ($offset, $size) use ($file): string {
            if (fseek($file, $offset) !== 0) { throw new RuntimeException('Cannot seek retained evidence'); }
            $value = fread($file, $size);
            if ($value === false) { throw new RuntimeException('Cannot read retained evidence'); }
            return $value;
        });
    }

    private function peek(): string
    {
        if ($this->position >= strlen($this->buffer)) {
            $this->buffer = ($this->read)($this->offset, self::CHUNK);
            $this->offset += strlen($this->buffer);
            $this->position = 0;
        }
        return $this->buffer[$this->position] ?? '';
    }

    private function take(): string
    {
        $value = $this->peek();
        if ($value === '') { throw new RuntimeException('Truncated retained JSON evidence'); }
        $this->position++;
        return $value;
    }

    private function space(): void
    {
        while (($c = $this->peek()) !== '' && str_contains(" \r\n\t", $c)) { $this->position++; }
    }

    private function valueRaw(bool $capture = true, int $depth = 0): string
    {
        if ($depth > 64) { throw new RuntimeException('Retained JSON exceeds nesting limit'); }
        $this->space();
        $c = $this->take(); $raw = $capture ? $c : '';
        if ($c === '"') {
            for (;;) {
                $c = $this->peek();
                if ($c === '') { throw new RuntimeException('Truncated retained JSON string'); }
                $length = strcspn($this->buffer, "\"\\", $this->position);
                if ($capture) { $raw .= substr($this->buffer, $this->position, $length); }
                $this->position += $length;
                if ($capture && strlen($raw) > self::VALUE_LIMIT) { throw new RuntimeException('Evidence row exceeds bounded display limit'); }
                $c = $this->peek();
                if ($c === '"') { $this->position++; return $capture ? $raw.'"' : ''; }
                if ($c === '\\') {
                    $this->position++;
                    $escaped = $this->take();
                    if ($capture) { $raw .= '\\'.$escaped; }
                }
            }
        }
        if ($c === '{' || $c === '[') {
            $close = $c === '{' ? '}' : ']'; $this->space();
            while ($this->peek() !== $close) {
                if ($c === '{') {
                    if ($this->peek() !== '"') { throw new RuntimeException('Invalid retained JSON key'); }
                    $raw .= $this->valueRaw($capture, $depth + 1); $this->space();
                    if ($this->take() !== ':') { throw new RuntimeException('Invalid retained JSON object'); }
                    if ($capture) { $raw .= ':'; }
                }
                $raw .= $this->valueRaw($capture, $depth + 1); $this->space();
                if ($capture && strlen($raw) > self::VALUE_LIMIT) { throw new RuntimeException('Evidence row exceeds bounded display limit'); }
                if ($this->peek() === $close) { break; }
                if ($this->take() !== ',') { throw new RuntimeException('Invalid retained JSON separator'); }
                if ($capture) { $raw .= ','; }
                $this->space();
            }
            $this->take();
            return $capture ? $raw.$close : '';
        }
        while (($next = $this->peek()) !== '' && ! str_contains(",]} \r\n\t", $next)) {
            $next = $this->take(); if ($capture) { $raw .= $next; }
            if ($capture && strlen($raw) > self::VALUE_LIMIT) { throw new RuntimeException('Evidence scalar exceeds display limit'); }
        }
        return $raw;
    }

    private function seek(array $path): bool
    {
        if (! $path) { return true; }
        $this->space();
        if ($this->peek() !== '{') { return false; }
        $this->take(); $key = array_shift($path); $this->space();
        while ($this->peek() !== '}') {
            $name = json_decode($this->valueRaw(), true, flags: JSON_THROW_ON_ERROR); $this->space();
            if ($this->take() !== ':') { throw new RuntimeException('Invalid retained JSON object'); }
            if ($name === $key) { return $this->seek($path); }
            $this->valueRaw(false); $this->space();
            if ($this->peek() === '}') { break; }
            if ($this->take() !== ',') { throw new RuntimeException('Invalid retained JSON separator'); }
            $this->space();
        }
        return false;
    }

    public function entries(array $path): \Generator
    {
        if (! $this->seek($path)) { return; }
        $this->space(); $type = $this->peek();
        if (! in_array($type, ['{', '['], true)) { return; }
        $this->take(); $close = $type === '{' ? '}' : ']'; $index = 0; $this->space();
        while ($this->peek() !== $close) {
            if ($index > 100000) { throw new RuntimeException('Evidence entry limit exceeded'); }
            $key = $index++;
            if ($type === '{') {
                $key = json_decode($this->valueRaw(), true, flags: JSON_THROW_ON_ERROR); $this->space();
                if ($this->take() !== ':') { throw new RuntimeException('Invalid retained JSON object'); }
            }
            yield $key => json_decode($this->valueRaw(), true, flags: JSON_THROW_ON_ERROR);
            $this->space(); if ($this->peek() === $close) { break; }
            if ($this->take() !== ',') { throw new RuntimeException('Invalid retained JSON separator'); }
            $this->space();
        }
    }
}
