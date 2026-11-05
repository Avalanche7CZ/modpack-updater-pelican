<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Contracts;

use Arcturus\ModpackManager\Modpacks\Release;

interface ModpackProvider
{
    public function search(string $query, int $page = 0): array;

    public function project(string $source): array;

    public function versions(string $source): array;

    public function latest(string $source, ?string $asset = null): Release;

    public function release(string $source, string $version, ?string $asset = null): Release;
}
