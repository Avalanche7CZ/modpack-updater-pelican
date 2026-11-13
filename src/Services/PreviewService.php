<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PreviewService
{
    public function __construct(private readonly DirectoryInventory $inventory,
        private readonly ManifestJson $manifests, private readonly UpdatePlanner $planner,
        private readonly ReleaseStore $store, private readonly ServerFilesFactory $files,
        private readonly RuntimeInspector $runtime) {}

    public function generate(ManagedServer $server, ?\Arcturus\ModpackManager\Models\Operation $operation = null): array
    {
        if (! $server->base_manifest_path || ! $server->target_manifest_path) {
            throw new RuntimeException('Prepare the exact installed upstream baseline and target release first');
        }
        $verified = $server->baseline_status === 'verified';
        if ($verified) {
            $base = $this->store->load($server->base_manifest_path);
            $target = $this->store->load($server->target_manifest_path);
            $guard = new BaselineGuard;
            $guard->require($base, $server->provider, $server->source, $server->installed_version);
            $guard->require($target, $server->provider, $server->source, $server->targetVersion());
        } elseif ($server->transport === 'local') {
            $base = $this->manifests->load($server->base_manifest_path);
            $target = $this->manifests->load($server->target_manifest_path);
        } else {
            throw new RuntimeException('Upstream baseline is unverified. Destructive updates are restricted.');
        }
        if (! $base['files'] || ! $target['files'] || $base['version'] !== $server->installed_version ||
            $target['version'] !== $server->targetVersion()) {
            throw new RuntimeException('Upstream manifest identity or version mismatch');
        }
        if ($server->transport === 'native' && ($base['runtime_build']['builder'] ?? '') === \Arcturus\ModpackManager\Runtime\RuntimeBuild::SCHEMA) {
            // Official installers add runtimes; obsolete generated libraries are harmless local
            // extras. Do not turn them into automatic removals or repeated review requirements.
            // Explicit exact remove/disable rules still enter ManifestComparison independently.
            foreach ($base['runtime_build']['files'] ?? [] as $path => $hash) {
                if (str_starts_with($path, 'libraries/') && ! isset($target['files'][$path])) { unset($base['files'][$path]); }
            }
        }
        $rules = $server->file_rules ?? [];
        $safetyRoots = array_merge($server->runtime_report['protected_roots'] ?? [], (new DirectoryPolicy)->protectedRoots($server, $base, $target));
        $metadata = [];
        $fullTarget = $target;
        $upstreamIdentities = (new ModIdentityMappings)->apply(['base' => $base['identities'] ?? [], 'target' => $target['identities'] ?? []],
            $server->mod_identity_mappings ?? [], ['base' => $base['files'], 'target' => $target['files'], 'local' => []]);
        $comparisonPaths = ManifestComparison::paths($base['files'], $target['files'], $rules,
            $upstreamIdentities, $server->disabled_mods ?? []);
        $base['files'] = ManifestComparison::select($base['files'], $comparisonPaths);
        $target['files'] = ManifestComparison::select($target['files'], $comparisonPaths);
        if ($server->transport === 'local' && ! isset($server->baseline_provenance['transport'])) {
            $root = realpath((string) $server->local_root);
            $mapping = config('arcturus-modpack-manager.local_server_roots.'.$server->pelican_server_uuid);
            if (! $mapping || ! $root || is_link($server->local_root) || realpath($mapping) !== $root) {
                throw new RuntimeException('Local root must match the operator-configured UUID-to-volume mapping');
            }
            $safetyRoots = array_merge($safetyRoots, $this->inventory->safetyRoots($root));
            // Only managed paths are scanned; world trees never enter the preview pipeline.
            $paths = array_filter($comparisonPaths,
                fn ($p) => SafetyPaths::reason($p, $safetyRoots) === null);
            $local = $this->inventory->hashPaths($root, $paths);
            $identities = [];
        } else {
            $backend = $this->files->managed($server, $operation);
            $remote = $backend->inventory($comparisonPaths);
            $safetyRoots = array_merge($safetyRoots, $remote['excluded_roots'] ?? []);
            if ($server->transport === 'native') {
                $drift = $backend->inspectModDrift([...$base, 'identities' => $upstreamIdentities['base']],
                    [...$target, 'identities' => $upstreamIdentities['target']], $remote, $safetyRoots);
                $remote['files'] = array_replace($remote['files'], $drift['files']);
                $remote['metadata'] = array_replace($remote['metadata'] ?? [], $drift['metadata']);
                $remote['identities'] ??= [];
                foreach ($drift['metadata'] as $path => $item) { $remote['identities'][$path] = $item['identity']; }
                $remote['mod_drift_review'] = $drift['review'];
                $remote['missing'] = array_values(array_diff($remote['missing'] ?? [], array_keys($drift['files'])));
                $remote['inventory_summary']['files_hashed'] = count($remote['files']);
                $comparisonPaths = array_values(array_unique([...$comparisonPaths, ...array_keys($drift['files'])]));
                sort($comparisonPaths, SORT_STRING);
            }
            $local = $remote['files'];
            $identities = [...$upstreamIdentities, 'local' => $remote['identities'] ?? []];
            $metadata = array_replace($base['metadata'] ?? [], $target['metadata'] ?? [], $remote['metadata'] ?? []);
            $server->file_inventory = [...$remote, 'observed_at' => now()->toIso8601String()];
        }
        $modEvidence = ['base' => $base['metadata'] ?? [], 'target' => $target['metadata'] ?? [], 'local' => $remote['metadata'] ?? [],
            'drift_review' => $remote['mod_drift_review'] ?? [], 'diagnostics' => []];
        if ($operation) { PreparationProgress::phase($operation, 'comparing', 'planning'); }
        $identities = (new ModIdentityMappings)->apply($identities, $server->mod_identity_mappings ?? [], ['base' => $base['files'], 'local' => $local, 'target' => $target['files']]);
        $plan = $this->planner->plan($base['files'], $local, $target['files'], $rules, $identities, $server->disabled_mods ?? [], $safetyRoots, $metadata, $modEvidence);
        if ($operation) { PreparationProgress::phase($operation, 'comparing', 'planning', count($plan['changes']), count($plan['changes'])); }
        if ($server->file_inventory) {
            $server->file_inventory = [...$server->file_inventory, 'target_metadata' => $target['metadata'] ?? []];
        }
        $qualification = null;
        $native = $server->transport === 'native';
        $qualificationError = null;
        if ($native || ($server->transport === 'local' && isset($server->baseline_provenance['transport']))) {
            try {
                $lastPulse = 0;
                $pulse = $operation ? function () use ($operation, &$lastPulse): void { if (time() - $lastPulse >= 10) { $operation->update(['heartbeat_at' => now()]); $lastPulse = time(); } } : null;
                $qualification = app($native ? \Arcturus\ModpackManager\Native\Qualification::class : SameHostDeployment::class)->qualify($server, $fullTarget, $plan, $pulse);
            } catch (\Throwable $error) {
                if (get_class($error) !== \RuntimeException::class) { report($error); }
                $qualificationError = get_class($error) === \RuntimeException::class ? $error->getMessage()
                    : ($native ? 'Native deployment qualification unavailable; inspect the private Panel log and operation ID.' : 'Same-host qualification unavailable: check approved local paths, runtime files, queue permissions and Pelican backup configuration.');
            }
        }
        $compatibility = $qualificationError !== null
            ? ['compatible' => false, 'blockers' => [$qualificationError], 'warnings' => [], 'changes_applied' => false, 'current' => $server->runtime_report ?? [], 'target' => $target['runtime'] ?? []]
            : ($native && $qualification !== null ? ['compatible' => true, 'blockers' => [], 'warnings' => [], 'changes_applied' => false, 'current' => $qualification['current'], 'target' => $qualification['target']] : $this->runtime->preflight($qualification['current'] ?? $server->runtime_report ?? [], $qualification['target'] ?? $target['runtime'] ?? []));
        $gateReasons = $modEvidence['diagnostics'];
        if ((new DirectoryPolicy)->pending($server)) {
            $gateReasons[] = 'Review newly discovered upstream directories';
        }
        if (! $verified) {
            $gateReasons[] = 'Unverified upstream baseline';
        }
        if (($server->runtime_report['world_path_verified'] ?? null) === false) {
            $gateReasons[] = 'World path could not be verified; resolve server.properties level-name before approval';
        }
        if (! $server->adopted_at) {
            $gateReasons[] = 'Complete Adopt Existing Server first';
        }
        if ($plan['blocked']) {
            $gateReasons[] = $plan['blocked'].' unresolved file changes';
        }
        $gateReasons = array_merge($gateReasons, $compatibility['blockers']);
        if (! in_array($server->transport, ['local', 'native'], true) || ((isset($server->baseline_provenance['transport']) || $native) && $qualification === null)) {
            $gateReasons[] = in_array($server->transport, ['local', 'native'], true)
                ? ($qualificationError ?? 'Complete same-host deployment qualification before approval')
                : 'Remote deployment is disabled pending transaction/restore integration qualification';
        }
        $binding = ['uuid' => $server->pelican_server_uuid, 'from' => $base['version'], 'to' => $target['version'],
            'base' => $base['files'], 'local' => $local, 'target' => $target['files'], 'rules' => $rules,
            'directory_decisions' => $server->directory_decisions ?? [], 'decisions' => $plan['changes'], 'identities' => $identities, 'safety_roots' => $safetyRoots, 'disabled' => $server->disabled_mods, 'runtime' => $compatibility, 'backup_mode' => $server->backup_mode,
            'exclusions' => $server->backup_excluded_directories, 'mod_evidence' => $modEvidence, 'qualification' => $qualification];
        if ($native) { $binding['native_mod_drift_schema'] = \Arcturus\ModpackManager\Native\ModDrift::SCHEMA; }
        $digest = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
        $preview = ['comparison_schema' => ManifestComparison::SCHEMA, 'candidate_count' => count($comparisonPaths), 'mod_plan_schema' => ModUpgradePlanner::SCHEMA, 'logical_mod_upgrades' => $plan['counts']['upgrade_mod'] ?? 0, 'qualification' => $qualification, 'policy_revision' => $server->policy_revision, 'from' => $base['version'], 'to' => $target['version'], 'blocked' => $plan['blocked'],
            'counts' => $plan['counts'], 'total' => count($plan['changes']), 'applicable' => $gateReasons === [],
            'plan_sha256' => $digest, 'capability_blockers' => array_values(array_unique($gateReasons)),
            'compatibility' => $compatibility, 'changes' => $plan['changes'],
            'policy_context' => ['comparison_paths' => $comparisonPaths, 'mod_evidence' => $modEvidence, 'local_mod_files' => array_filter($local, fn ($p) => UpdatePlanner::isMod($p), ARRAY_FILTER_USE_KEY), 'identities' => $identities, 'disabled' => $server->disabled_mods ?? [], 'safety_roots' => $safetyRoots, 'metadata' => $metadata],
            'sample' => array_slice(array_values(array_filter($plan['changes'], fn ($c) => $c['action'] !== 'unchanged')), 0, 250),
            'download_bytes' => $server->target_metadata['download_bytes'] ?? $server->release_metadata['download_bytes'] ?? null,
            'backup' => ['backend' => $server->backup_mode, 'locked_required' => true,
                'exclusions' => $server->backup_excluded_directories],
            'notice' => 'Preview only. Review loader/world migrations separately. No runtime settings are changed automatically.'];
        if ($native) { $preview['native_mod_drift_schema'] = $binding['native_mod_drift_schema']; }
        $saved = DB::transaction(function () use ($server, $preview, $digest): ManagedServer {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            foreach (['policy_revision', 'base_manifest_path', 'target_manifest_path', 'installed_version', 'selected_version', 'available_version', 'baseline_status', 'runtime_report'] as $field) {
                if ($fresh->$field !== $server->$field) {
                    throw new RuntimeException('Server policy or release changed during comparison; compare again');
                }
            }
            $stillApproved = $fresh->approved_plan_sha256 === $digest;
            $fresh->forceFill(['comparison_status' => 'complete', 'comparison_diagnostic' => null, 'preview_summary' => $preview, 'last_preview_at' => now(), 'file_inventory' => $server->file_inventory,
                'approved_plan_sha256' => $stillApproved ? $digest : null, 'approved_at' => $stillApproved ? $fresh->approved_at : null,
                'approved_by' => $stillApproved ? $fresh->approved_by : null])->save();

            return $fresh;
        });
        $server->setRawAttributes($saved->getAttributes(), true);
        $server->events()->create(['kind' => 'preview', 'status' => $preview['applicable'] ? 'success' : 'blocked',
            'from_version' => $base['version'], 'to_version' => $target['version'],
            'message' => $plan['blocked'].' unresolved changes; '.count($gateReasons).' deployment gates',
            'details' => ['counts' => $plan['counts'], 'plan_sha256' => $digest]]);

        return $preview;
    }
}
