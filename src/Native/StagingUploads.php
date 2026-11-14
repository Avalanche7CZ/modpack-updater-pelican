<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

interface StagingUploads extends MutationBatches
{
    /** At most four absent numeric leaves inside this operation's private new directory. */
    public function uploadBatch(string $directory, array $uploads): void;
}
