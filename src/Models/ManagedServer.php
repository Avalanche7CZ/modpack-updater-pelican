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

    protected $attributes = ['policy_revision' => 0, 'comparison_status' => 'incomplete'];

    protected $hidden = ['worker_key', 'local_root', 'base_manifest_path', 'target_manifest_path',
        'staging_parent', 'prepared_root', 'backup_root', 'discord_webhook'];

    protected function casts(): array
    {
        return [
            'comparison_diagnostic' => 'array', 'baseline_provenance' => 'array', 'runtime_report' => 'array', 'adoption_report' => 'array',
            'release_metadata' => 'array', 'target_metadata' => 'array', 'disabled_mods' => 'array', 'mod_identity_mappings' => 'array', 'adopted_at' => 'datetime',
            'approved_at' => 'datetime', 'last_check_attempt_at' => 'datetime',
            'enabled' => 'boolean',
            'discord_enabled' => 'boolean', 'discord_webhook' => 'encrypted', 'discord_notify_events' => 'array',
            'deploy_enabled' => 'boolean',
            'directory_decisions' => 'array', 'file_rules' => 'array', 'file_inventory' => 'array', 'policy_revision' => 'integer',
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
            if ($server->isDirty(['base_manifest_path', 'provider', 'source', 'installed_version'])) {
                $server->forceFill(['comparison_status' => 'incomplete', 'comparison_diagnostic' => null, 'adoption_report' => null]);
            }
            if ($server->isDirty(['directory_decisions', 'file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories'])) {
                if ($server->isDirty('directory_decisions')) { $server->file_inventory = null; }
                (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->normalize($server->directory_decisions ?? []);
                (new UpdatePlanner)->plan([], [], [], $server->file_rules ?? [], [], $server->disabled_mods ?? []);
                $server->forceFill(['approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null,
                    'preview_summary' => null, 'adoption_report' => null, 'comparison_status' => 'incomplete', 'comparison_diagnostic' => null, 'policy_revision' => ($server->policy_revision ?? 0) + 1]);
            }
        });
        self::updated(function (self $server): void {
            if ($server->wasChanged('policy_revision')) {
                $fields = array_intersect_key($server->getChanges(), array_flip(['directory_decisions', 'file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories']));
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

    public function latestOperationEvent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UpdateEvent::class, 'managed_server_id')->ofMany(['id' => 'max'], fn ($query) => $query->whereIn('kind', ['baseline', 'prepare', 'comparison']));
    }

    public function getOperationStatusAttribute(): string
    {
        if ($this->active_operation_id) { return 'In progress'; }
        if ($this->comparison_status === 'failed') { return 'Comparison failed'; }
        return match ($this->latestOperationEvent?->status) {
            'failed' => 'Failed', 'cancelled' => 'Cancelled', 'interrupted' => 'Interrupted',
            'completed' => 'Completed', default => 'No active operation',
        };
    }

    public function getFailureSummaryAttribute(): ?string
    {
        return $this->comparison_status === 'failed' && $this->comparison_diagnostic
            ? \Arcturus\ModpackManager\Services\PreparationDiagnostic::message($this->comparison_diagnostic) : $this->last_error;
    }

    public function comparisonLabel(): string
    {
        return match ($this->comparison_status) {
            'complete' => 'Installation comparison complete', 'failed' => 'Installation comparison failed',
            'running' => 'Installation comparison in progress', 'queued' => 'Installation comparison queued',
            default => 'Installation comparison incomplete',
        };
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
