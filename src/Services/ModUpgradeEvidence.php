<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;

/** Refresh identity evidence from retained, checksum-bound JARs without downloading a pack again. */
final class ModUpgradeEvidence
{
    public function collect(ManagedServer $server, array $base, array $target, array $inventory, ?\Closure $heartbeat = null): array
    {
        $evidence = ['base' => $base['metadata'] ?? [], 'local' => $inventory['metadata'] ?? [], 'target' => $target['metadata'] ?? [], 'diagnostics' => []];
        $worker = null;
        foreach (['base' => $base, 'target' => $target] as $scope => $manifest) {
            $needed = [];
            foreach ($manifest['files'] as $path => $hash) {
                if (! UpdatePlanner::isMod($path) || ($base['files'][$path] ?? null) === ($target['files'][$path] ?? null)) { continue; }
                if (($inventory['files'][$path] ?? null) === $hash && ModUpgradePlanner::verifiedIdentity($hash, null, $inventory['metadata'][$path] ?? []) !== null) {
                    $evidence[$scope][$path] = $inventory['metadata'][$path];
                }
                if (ModUpgradePlanner::verifiedIdentity($hash, null, $evidence[$scope][$path] ?? []) === null) { $needed[] = $path; }
            }
            if (! $needed) { continue; }
            $stage = $scope === 'base' ? ($server->baseline_provenance['node_operation_id'] ?? null) : ($server->target_metadata['prepared']['node_operation_id'] ?? null);
            if (! is_string($stage) || ! preg_match('/^[a-f0-9]{32}$/D', $stage)) {
                $evidence['diagnostics'][] = 'Verified '.$scope.' mod metadata is unavailable. Retain the original files and prepare that exact release again.'; continue;
            }
            try {
                $worker ??= app(ServerFilesFactory::class)->worker($server);
                if (($worker->request('capabilities')['mod_identity'] ?? 0) < 2) { throw new \RuntimeException('Upgrade the selected filesystem worker for checksum-bound Forge/NeoForge mod identities.'); }
                foreach (array_chunk($needed, 256) as $paths) {
                    $heartbeat?->__invoke();
                    $result = $worker->request('stage_mod_metadata', ['operation_id' => $stage, 'paths' => $paths]);
                    if (($result['complete'] ?? false) !== true) { throw new \RuntimeException('Retained mod metadata read did not complete.'); }
                    foreach ($paths as $path) {
                        $meta = $result['metadata'][$path] ?? [];
                        if (is_array($meta) && ModUpgradePlanner::verifiedIdentity($manifest['files'][$path], null, $meta) !== null) { $evidence[$scope][$path] = $meta; }
                    }
                }
            } catch (\Throwable $error) {
                $evidence['diagnostics'][] = get_class($error) === \RuntimeException::class ? $error->getMessage()
                    : 'Retained '.$scope.' JAR metadata is unavailable. Check the filesystem worker and retained exact-release stage; no live files were changed.';
            }
        }
        return $evidence;
    }
}
