<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

interface MutationBatches extends BatchFiles
{
    public function permissionsBatch(array $paths): array;
    public function chmodBatch(array $modes): void;
    public function renameBatch(array $moves): void;
}
