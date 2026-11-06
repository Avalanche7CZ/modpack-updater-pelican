<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use App\Models\Server;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ManagedServer extends Model
{
    protected $table = 'arcturus_modpack_servers';

    protected $guarded = ['id'];

    protected $attributes = ['policy_revision' => 0];

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
            'file_rules' => 'array', 'file_inventory' => 'array', 'policy_revision' => 'integer',
            'backup_excluded_directories' => 'array',
            'preview_summary' => 'array',
            'last_preview_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $server): void {
            if ($server->isDirty(['file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories'])) {
                (new UpdatePlanner)->plan([], [], [], $server->file_rules ?? [], [], $server->disabled_mods ?? []);
                $server->forceFill(['approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null,
                    'preview_summary' => null, 'adoption_report' => null, 'policy_revision' => ($server->policy_revision ?? 0) + 1]);
            }
        });
        self::updated(function (self $server): void {
            if ($server->wasChanged('policy_revision')) {
                $fields = array_intersect_key($server->getChanges(), array_flip(['file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories']));
                $before = [];
                $after = [];
                foreach ($fields as $key => $value) {
                    $before[$key] = $server->getOriginal($key);
                    $after[$key] = $server->$key;
                }
                $server->events()->create(['kind' => 'policy', 'status' => 'success', 'message' => 'Server protection policy changed; previous plan approval invalidated',
                    'details' => ['actor_id' => auth()->id(), 'revision' => $server->policy_revision, 'before' => $before, 'after' => $after]]);
            }
        });
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
