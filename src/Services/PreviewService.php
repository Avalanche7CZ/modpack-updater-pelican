<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;

/**
 * Read-only live server preview, for single-host/shared-volume Pelican setups.
 * A remote Wings node needs an external worker; no implicit SFTP/SSH access.
 */
final class PreviewService
{
    public function __construct(
        private readonly DirectoryInventory $inventory,
        private readonly ManifestJson $manifests,
        private readonly UpdatePlanner $planner,
    ) {}

    public function generate(ManagedServer $server): array
    {
        if (!$server->local_root || !$server->base_manifest_path || !$server->target_manifest_path) {
            throw new RuntimeException('Configure live root and both release manifests first');
        }
        $root = realpath($server->local_root);
        if (!$root || !is_dir($root) || is_link($server->local_root)) {
            throw new RuntimeException('Live root is not accessible or is a symlink');
        }
        $allowed = (array) config('modpack-manager.allowed_live_roots', []);
        $accepted = false;
        foreach ($allowed as $candidate) {
            $canonical = realpath($candidate);
            if ($canonical && ($canonical === $root ||
                str_starts_with($root, rtrim($canonical, '/') . '/'))) {
                $accepted = true;
                break;
            }
        }
        if (!$accepted) {
            throw new RuntimeException('Live root is not inside MODPACK_MANAGER_ALLOWED_LIVE_ROOTS');
        }
        $base = $this->manifests->load($server->base_manifest_path);
        $target = $this->manifests->load($server->target_manifest_path);
        if (!$base['files'] || !$target['files']) {
            throw new RuntimeException('Empty upstream manifest refused');
        }
        if ($base['version'] !== $server->installed_version) {
            throw new RuntimeException('Baseline version does not match installed version');
        }
        if ($server->available_version && $target['version'] !== $server->available_version) {
            throw new RuntimeException('Target manifest is not the release most recently discovered');
        }
        $paths = array_unique(array_merge(array_keys($base['files']), array_keys($target['files'])));
        $local = $this->inventory->hashPaths($root, $paths);
        $plan = $this->planner->plan($base['files'], $local, $target['files'],
            $server->file_rules ?? []);
        $preview = [
            'from' => $base['version'],
            'to' => $target['version'],
            'blocked' => $plan['blocked'],
            'counts' => $plan['counts'],
            'total' => count($plan['changes']),
            'applicable' => $plan['applicable'],
            'sample' => array_slice(array_values(array_filter($plan['changes'], fn($change) =>
                $change['action'] !== 'unchanged')), 0, 250),
            'notice' => 'Read-only comparison. Deploy requires a stopped server, backup, and worker.',
        ];
        $server->forceFill(['preview_summary' => $preview, 'last_preview_at' => now()])->save();
        $server->events()->create([
            'kind' => 'preview', 'status' => $plan['blocked'] ? 'warning' : 'success',
            'from_version' => $base['version'], 'to_version' => $target['version'],
            'message' => $plan['blocked'] . ' unresolved file conflicts',
            'details' => ['counts' => $plan['counts']],
        ]);
        return $preview;
    }
}
