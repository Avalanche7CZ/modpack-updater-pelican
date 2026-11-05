<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Contracts;

interface ServerFiles
{
    public function listing(string $directory = ''): array;

    public function stat(string $path): ?array;

    public function read(string $path, int $limit = 1048576): string;

    public function write(string $path, string $content): void;

    public function mkdir(string $path): void;

    public function rename(string $from, string $to): void;

    public function delete(string $path): void;

    public function copy(string $path): void;

    public function hashes(array $paths): array;

    public function inventory(): array;

    public function capabilities(): array;
}
