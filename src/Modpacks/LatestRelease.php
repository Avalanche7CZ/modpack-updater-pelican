<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks;

use RuntimeException;

trait LatestRelease
{
    public function latest(string $source, ?string $asset = null): Release
    {
        foreach ($this->versions($source) as $version) {
            if ($version['stable'] ?? false) {
                return $this->release($source, (string) $version['id'], $asset);
            }
        }
        throw new RuntimeException('No stable upstream release found');
    }
}
