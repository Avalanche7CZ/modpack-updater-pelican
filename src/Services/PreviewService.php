<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use RuntimeException;

final class PreviewService
{
    public function __construct(private readonly DirectoryInventory $inventory,
        private readonly ManifestJson $manifests, private readonly UpdatePlanner $planner,
        private readonly ReleaseStore $store, private readonly ServerFilesFactory $files,
        private readonly RuntimeInspector $runtime) {}

    public function generate(ManagedServer $server): array
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
        $rules = $server->file_rules ?? [];
        if ($server->transport === 'local') {
            $root = realpath((string) $server->local_root);
            $mapping = config('arcturus-modpack-manager.local_server_roots.'.$server->pelican_server_uuid);
            if (! $mapping || ! $root || is_link($server->local_root) || realpath($mapping) !== $root) {
                throw new RuntimeException('Local root must match the operator-configured UUID-to-volume mapping');
            }
            // Only managed paths are scanned; world trees never enter the preview pipeline.
            $paths = array_filter(array_unique(array_merge(array_keys($base['files']), array_keys($target['files']))),
                fn ($p) => ! preg_match('~^(?:world[^/]*|saves|dimensions|data|backup|backups|logs|DIM-1|DIM1)/~', $p));
            $local = $this->inventory->hashPaths($root, $paths);
            $identities = [];
        } else {
            $remote = $this->files->wings($server->pelicanServer)->inventory();
            $local = $remote['files'];
            $identities = ['base' => $base['identities'] ?? [], 'target' => $target['identities'] ?? [], 'local' => $remote['identities'] ?? []];
            foreach ($remote['excluded_roots'] as $root) {
                $rules[] = ['pattern' => $root.'/**', 'mode' => 'protect'];
            }
        }
        $identities = (new ModIdentityMappings)->apply($identities, $server->mod_identity_mappings ?? [], ['base' => $base['files'], 'local' => $local, 'target' => $target['files']]);
        $plan = $this->planner->plan($base['files'], $local, $target['files'], $rules, $identities, $server->disabled_mods ?? []);
        $compatibility = $this->runtime->preflight($server->runtime_report ?? [], $target['runtime'] ?? []);
        $gateReasons = [];
        if (! $verified) {
            $gateReasons[] = 'Unverified upstream baseline';
        }
        if (! $server->adopted_at) {
            $gateReasons[] = 'Complete Adopt Existing Server first';
        }
        if ($plan['blocked']) {
            $gateReasons[] = $plan['blocked'].' unresolved file changes';
        }
        $gateReasons = array_merge($gateReasons, $compatibility['blockers']);
        if ($server->transport !== 'local') {
            $gateReasons[] = 'Remote deployment is disabled pending transaction/restore integration qualification';
        }
        $binding = ['uuid' => $server->pelican_server_uuid, 'from' => $base['version'], 'to' => $target['version'],
            'base' => $base['files'], 'local' => $local, 'target' => $target['files'], 'rules' => $rules,
            'disabled' => $server->disabled_mods, 'runtime' => $compatibility, 'backup_mode' => $server->backup_mode,
            'exclusions' => $server->backup_excluded_directories];
        $digest = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
        $preview = ['from' => $base['version'], 'to' => $target['version'], 'blocked' => $plan['blocked'],
            'counts' => $plan['counts'], 'total' => count($plan['changes']), 'applicable' => $gateReasons === [],
            'plan_sha256' => $digest, 'capability_blockers' => array_values(array_unique($gateReasons)),
            'compatibility' => $compatibility, 'changes' => $plan['changes'],
            'sample' => array_slice(array_values(array_filter($plan['changes'], fn ($c) => $c['action'] !== 'unchanged')), 0, 250),
            'download_bytes' => $server->target_metadata['download_bytes'] ?? $server->release_metadata['download_bytes'] ?? null,
            'backup' => ['backend' => $server->backup_mode, 'locked_required' => true,
                'exclusions' => $server->backup_excluded_directories],
            'notice' => 'Preview only. Review loader/world migrations separately. No runtime settings are changed automatically.'];
        $server->forceFill(['preview_summary' => $preview, 'last_preview_at' => now(),
            'approved_plan_sha256' => $server->approved_plan_sha256 === $digest ? $digest : null])->save();
        $server->events()->create(['kind' => 'preview', 'status' => $preview['applicable'] ? 'success' : 'blocked',
            'from_version' => $base['version'], 'to_version' => $target['version'],
            'message' => $plan['blocked'].' unresolved changes; '.count($gateReasons).' deployment gates',
            'details' => ['counts' => $plan['counts'], 'plan_sha256' => $digest]]);

        return $preview;
    }
}
