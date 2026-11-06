<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DiscordDelivery extends Model
{
    protected $table = 'arcturus_modpack_discord_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['webhook', 'payload'];

    protected function casts(): array
    {
        return ['webhook' => 'encrypted', 'payload' => 'encrypted:array', 'attempts' => 'integer',
            'claimed_at' => 'datetime', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function managedServer(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(UpdateEvent::class, 'source_event_id');
    }
}
