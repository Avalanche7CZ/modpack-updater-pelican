<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

interface BulkStaging extends StagingUploads
{
    public function uploadBundle(string $directory, array $bundle, string $verification): void;
}
