<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ManagedServer extends Model
{
    protected $table = 'modpack_manager_servers';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'file_rules' => 'array',
            'preview_summary' => 'array',
            'last_preview_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(UpdateEvent::class, 'managed_server_id');
    }

    public function hasUpdate(): bool
    {
        return $this->available_version !== null
            && $this->available_version !== $this->installed_version;
    }
}
