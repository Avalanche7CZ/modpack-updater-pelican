<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;
use RuntimeException;

/** Policy writes never write Minecraft files. A deployment cannot race a policy edit. */
final class ServerPolicyService
{
    public function save(ManagedServer $server, array $rules, array $disabled, int $revision, array $attributes = []): void
    {
        Access::requireAdmin();
        $allowed = ['directory_decisions', 'label', 'policy', 'enabled', 'asset_pattern', 'backup_mode', 'backup_excluded_directories', 'mod_identity_mappings', 'pelican_server_uuid', 'provider', 'source', 'installed_version', 'transport', ...DiscordSettings::FIELDS];
        if (array_diff(array_keys($attributes), $allowed)) {
            throw new RuntimeException('Invalid policy settings');
        }
        $discord = array_intersect_key($attributes, array_flip(DiscordSettings::FIELDS));
        if ($discord) {
            $attributes = array_diff_key($attributes, array_flip(DiscordSettings::FIELDS)) + (new DiscordSettings)->attributes($server, $discord);
        }
        if (isset($attributes['directory_decisions'])) {
            $attributes['directory_decisions'] = (new DirectoryPolicy)->normalize($attributes['directory_decisions']);
            $known = array_column((new DirectoryPolicy)->rows($server), null, 'path');
            foreach ($attributes['directory_decisions'] as $row) {
                if (! isset($known[$row['path']]) || $known[$row['path']]['classification'] === 'protected') {
                    throw new RuntimeException('Only safe directories discovered in this server upstream may be reviewed');
                }
            }
        }
        $rules = (new ProtectionPolicy)->normalize($rules);
        (new UpdatePlanner)->plan([], [], [], $rules, [], $disabled);
        $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$server->id, 60);
        if (! $lock->get()) {
            throw new RuntimeException('Deployment is active; policy changes are locked');
        }
        $checkLock = Cache::lock('arcturus-modpack-manager-check-'.$server->id, 180);
        if (! $checkLock->get()) {
            $lock->release();
            throw new RuntimeException('A release check is active. Wait for it to finish before saving settings.');
        }
        try {
            DB::transaction(function () use ($server, $rules, $disabled, $revision, $attributes): void {
                $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
                if ($fresh->active_operation_id || $fresh->policy_revision !== $revision) {
                    throw new RuntimeException('Policy changed or preparation is active. Reload before editing.');
                }
                Validator::make($attributes, [
                    'pelican_server_uuid' => ['sometimes', 'required', 'uuid', 'exists:servers,uuid', Rule::unique('arcturus_modpack_servers', 'pelican_server_uuid')->ignore($fresh->id)],
                    'provider' => ['sometimes', 'required', Rule::in(array_keys(ProviderRegistry::PROVIDERS))],
                    'source' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/[\\x00-\\x1f\\x7f]/'],
                    'installed_version' => ['sometimes', 'required', 'string', 'max:120', 'not_regex:/[\\x00-\\x1f\\x7f]/'],
                    'transport' => ['sometimes', Rule::in(['wings', 'local'])],
                ])->validate();
                $transport = $attributes['transport'] ?? $fresh->transport;
                unset($attributes['transport']);
                $fresh->fill([...$attributes, 'file_rules' => $rules, 'disabled_mods' => $disabled]);
                $rebound = $fresh->isDirty('pelican_server_uuid');
                $associationChanged = $fresh->isDirty(['pelican_server_uuid', 'provider', 'source', 'installed_version', 'asset_pattern']);
                if ($associationChanged) {
                    $clear = ['available_version', 'available_asset_url', 'available_asset_sha256', 'available_release_url',
                        'release_metadata', 'selected_version', 'target_metadata', 'base_manifest_path', 'target_manifest_path',
                        'baseline_provenance', 'prepared_root', 'artifact_sha256', 'file_inventory', 'adoption_report',
                        'preview_summary', 'approved_plan_sha256', 'approved_at', 'approved_by', 'last_preview_at',
                        'last_checked_at', 'last_check_attempt_at', 'last_success_at', 'last_error', 'adopted_at'];
                    $fresh->forceFill(array_fill_keys($clear, null));
                    $fresh->forceFill(['baseline_status' => 'unverified', 'deploy_enabled' => false]);
                    // Changing the binding must never inherit a different server's runtime, backup or filesystem pins.
                    if ($rebound) {
                        $fresh->directory_decisions = [];
                        $fresh->forceFill(array_fill_keys(['local_root', 'staging_parent', 'worker_key', 'backup_root', 'last_backup_uuid', 'runtime_report'], null));
                    }
                    // Share the policy revision guard so stale forms/comparisons cannot revive old evidence.
                    if (! $fresh->isDirty(['directory_decisions', 'file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories'])) {
                        $fresh->policy_revision++;
                    }
                }
                if (! $associationChanged && $transport !== $fresh->transport && ! $fresh->isDirty(['directory_decisions', 'file_rules', 'disabled_mods', 'mod_identity_mappings', 'backup_mode', 'backup_excluded_directories'])) {
                    $fresh->policy_revision++;
                }
                $fresh->save();
                if ($transport !== $fresh->transport || ($rebound && $transport === 'local')) {
                    app(FilesystemBackendSettings::class)->save($fresh, $transport);
                }
                if ($associationChanged) {
                    $fresh->events()->create(['kind' => 'association', 'status' => 'warning',
                        'message' => 'Server/release association changed; a new verified baseline is required. No live files changed.',
                        'details' => ['actor_id' => auth()->id(), 'server_rebound' => $rebound]]);
                }
            });
        } finally {
            $checkLock->release();
            $lock->release();
        }
    }

    /** One durable receipt for a policy save and its queued comparison, including HTTP retries. */
    public function saveDirectories(ManagedServer $server, array $decisions, int $revision, string $requestId): \Arcturus\ModpackManager\Models\Operation
    {
        Access::requireAdmin();
        if (! \Illuminate\Support\Str::isUuid($requestId)) { throw new RuntimeException('Invalid directory review request ID'); }
        $decisions = (new DirectoryPolicy)->normalize($decisions);
        $fingerprint = hash('sha256', json_encode([$server->id, $revision, $decisions], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($server, $decisions, $revision, $requestId, $fingerprint) {
            $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
            $receipt = \Arcturus\ModpackManager\Models\Operation::where('managed_server_id', $fresh->id)->find($requestId);
            if ($receipt) {
                if (($receipt->context['directory_review'] ?? null) !== $fingerprint) { throw new RuntimeException('Request ID belongs to a different directory review'); }
                return $receipt;
            }
            $this->save($fresh, $fresh->file_rules ?? [], $fresh->disabled_mods ?? [], $revision, ['directory_decisions' => $decisions]);
            $fresh->refresh();
            $operation = app(QueuePreparation::class)->comparison($fresh, (bool) $fresh->target_manifest_path, $requestId);
            $operation->update(['context' => [...$operation->context, 'directory_review' => $fingerprint]]);
            return $operation;
        });
    }

    public function assign(ManagedServer $server, array $paths, string $action, int $revision): void
    {
        Access::requireAdmin();
        if (! $paths || count($paths) > 250 || ! isset(ProtectionPolicy::ACTIONS[$action])) {
            throw new RuntimeException('Select 1–250 files and a valid policy');
        }
        $catalog = (new FilePolicyCatalog)->selected($server, $paths);
        $rules = (new ProtectionPolicy)->normalize($server->file_rules ?? []);
        foreach (array_unique($paths) as $path) {
            UpdatePlanner::assertPath($path);
            if (! isset($catalog[$path])) {
                throw new RuntimeException('File is not in this server inventory');
            }
            $row = $catalog[$path];
            if ($row['invariant'] !== null) {
                throw new RuntimeException($row['invariant']);
            }
            $scope = $row['identity'] !== null && $row['is_mod'] && ! str_contains($row['identity'], '+') ? 'mod_id' : 'exact';
            $pattern = $scope === 'mod_id' ? $row['identity'] : $path;
            $hasExact = collect($rules)->contains(fn ($r) => $r['scope'] === 'exact' && $r['pattern'] === $path);
            $selectors = [['scope' => $scope, 'pattern' => $pattern]];
            if ($scope === 'mod_id' && $hasExact) {
                $selectors[] = ['scope' => 'exact', 'pattern' => $path];
            }
            foreach ($selectors as $selector) {
                $rules = array_values(array_filter($rules, fn ($r) => ! ($r['scope'] === $selector['scope'] &&
                    ($r['scope'] === 'mod_id' ? strtolower($r['pattern']) === strtolower($selector['pattern']) : $r['pattern'] === $selector['pattern']))));
                $rules[] = (new ProtectionPolicy)->normalize([[...$selector, 'mode' => $action]])[0];
            }
        }
        $this->save($server, $rules, $server->disabled_mods ?? [], $revision);
    }
}
