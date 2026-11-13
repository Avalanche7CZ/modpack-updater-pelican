<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

interface BatchFiles extends Files
{
    /** At most 32 explicit paths; includes proven absence as null. No partial results on failure. */
    public function hashBatch(array $paths): array;
}
