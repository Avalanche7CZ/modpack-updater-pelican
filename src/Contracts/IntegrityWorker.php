<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Contracts;

interface IntegrityWorker
{
    public function request(string $operation, array $payload = []): array;
}
