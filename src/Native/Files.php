<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

/** Internal authenticated Wings operations. Paths are relative to this server only. */
interface Files
{
    public function listing(string $directory = ''): array;
    public function stat(string $path): ?array;
    public function hash(string $path): ?string;
    public function upload(string $localFile, string $path, string $sha256): void;
    public function permissions(string $path, ?int $mode = null): ?int;
    public function mkdir(string $path): void;
    public function rename(string $from, string $to): void;
    public function delete(string $path): void;
}
