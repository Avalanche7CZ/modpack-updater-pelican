<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Services\DiscordNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Only a database ID enters the queue/failed-job payload; never a webhook token. */
final class SendDiscordNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(DiscordNotifier $notifier): void
    {
        $notifier->deliver($this->deliveryId);
    }

    public function failed(?Throwable $error): void
    {
        // Never report the underlying transport exception, which may contain a secret URL.
        app(DiscordNotifier::class)->interrupted($this->deliveryId, $error);
    }
}
