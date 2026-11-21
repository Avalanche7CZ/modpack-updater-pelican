<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Services\QueueDeploymentConfirmation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ConfirmDeployment implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 3600;
    public int $timeout = self::TIMEOUT;
    public int $tries = 3;

    public function __construct(public readonly string $confirmationId) {}

    public function handle(QueueDeploymentConfirmation $confirmations): void
    {
        $confirmations->run($this->confirmationId);
    }
}
