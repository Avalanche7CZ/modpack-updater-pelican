<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UpdateEvent extends Model
{
    protected $table = 'arcturus_modpack_events';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function managedServer(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }
}
