<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Contracts;

interface RequiredFileResolver
{
    /** Resolve a bounded, resumable batch. Completeness must include every required dependency. */
    public function resolveBatch(array $manifest, array $resolved = [], int $limit = 3): array;
}
