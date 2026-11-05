<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ManagedServer extends Model
{
    protected $table = 'arcturus_modpack_servers';

    protected $guarded = ['id'];

    protected $hidden = ['worker_key', 'local_root', 'base_manifest_path', 'target_manifest_path',
        'staging_parent', 'prepared_root', 'backup_root'];

    protected function casts(): array
    {
        return [
            'baseline_provenance' => 'array', 'runtime_report' => 'array', 'adoption_report' => 'array',
            'release_metadata' => 'array', 'target_metadata' => 'array', 'disabled_mods' => 'array', 'mod_identity_mappings' => 'array', 'adopted_at' => 'datetime',
            'approved_at' => 'datetime', 'last_check_attempt_at' => 'datetime',
            'enabled' => 'boolean',
            'deploy_enabled' => 'boolean',
            'file_rules' => 'array',
            'backup_excluded_directories' => 'array',
            'preview_summary' => 'array',
            'last_preview_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function pelicanServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'pelican_server_uuid', 'uuid');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class, 'managed_server_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UpdateEvent::class, 'managed_server_id');
    }

    public function targetVersion(): ?string
    {
        return $this->selected_version ?? $this->available_version;
    }

    public function hasUpdate(): bool
    {
        return $this->available_version !== null
            && $this->available_version !== $this->installed_version;
    }
}
