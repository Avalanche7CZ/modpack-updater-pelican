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
        'staging_parent', 'prepared_root', 'backup_root', 'discord_webhook', 'file_inventory', 'adoption_report', 'preview_summary'];

    public bool $evidenceProjection = false;

    public function scopeLightweight($query)
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($this->getTable());
        $columns = array_diff($columns, ['file_inventory', 'adoption_report', 'preview_summary']);
        $query->select(array_map(fn ($c) => $this->getTable().'.'.$c, $columns))->selectRaw('1 AS _evidence_projection');
        $sqlite = $this->getConnection()->getDriverName() === 'sqlite';
        $integer = $sqlite ? 'INTEGER' : 'UNSIGNED';
        $length = $sqlite ? 'json_array_length' : 'json_length';
        $files = $sqlite ? "(SELECT COUNT(*) FROM json_each(file_inventory, '$.files'))" : "json_length(file_inventory, '$.files')";
        $query->selectRaw("CASE WHEN file_inventory IS NULL THEN NULL ELSE json_object(
            'inventory_summary', json_extract(file_inventory, '$.inventory_summary'),
            'world_path_unreadable', json_extract(file_inventory, '$.world_path_unreadable'),
            'observed_at', json_extract(file_inventory, '$.observed_at'),
            'files_count', COALESCE(CAST(json_extract(file_inventory, '$.inventory_summary.files_hashed') AS $integer), $files, 0),
            'unreadable_count', COALESCE($length(file_inventory, '$.unreadable_paths'), 0),
            'symlink_count', COALESCE($length(file_inventory, '$.skipped_symlinks'), 0)
        ) END AS file_inventory");
        foreach (['adoption_report', 'preview_summary'] as $column) {
            $query->selectRaw("json_remove($column, '$.changes', '$.sample', '$.policy_context', '$.excluded_roots', '$.unreadable_paths', '$.skipped_symlinks') AS $column");
        }
        return $query;
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $projection = isset($attributes['_evidence_projection']);
        unset($attributes['_evidence_projection']);
        $model = parent::newFromBuilder($attributes, $connection);
        $model->evidenceProjection = $projection;
        return $model;
    }

    public function newQueryForRestoration($ids)
    {
        // Livewire restores its locked model between requests; do not hydrate the large JSON columns.
        return parent::newQueryForRestoration($ids)->lightweight();
    }

    public function refresh()
    {
        if (! $this->evidenceProjection) { return parent::refresh(); }
        $fresh = static::query()->lightweight()->useWritePdo()->findOrFail($this->getKey());
        $this->setRawAttributes($fresh->getAttributes(), true);
        $this->unsetRelations();
        return $this;
    }

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
                (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->normalize($server->directory_decisions ?? []);
                (new UpdatePlanner)->plan([], [], [], $server->file_rules ?? [], [], $server->disabled_mods ?? []);
                $server->forceFill(['approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null,
                    'comparison_status' => 'incomplete', 'comparison_diagnostic' => null, 'policy_revision' => ($server->policy_revision ?? 0) + 1]);
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
