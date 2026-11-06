<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Policy writes never write Minecraft files. A deployment cannot race a policy edit. */
final class ServerPolicyService
{
    public function save(ManagedServer $server, array $rules, array $disabled, int $revision, array $attributes = []): void
    {
        Access::requireAdmin();
        $allowed = ['label', 'policy', 'enabled', 'asset_pattern', 'backup_mode', 'backup_excluded_directories', 'mod_identity_mappings'];
        if (array_diff(array_keys($attributes), $allowed)) {
            throw new RuntimeException('Invalid policy settings');
        }
        $rules = (new ProtectionPolicy)->normalize($rules);
        (new UpdatePlanner)->plan([], [], [], $rules, [], $disabled);
        $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$server->id, 60);
        if (! $lock->get()) {
            throw new RuntimeException('Deployment is active; policy changes are locked');
        }
        try {
            DB::transaction(function () use ($server, $rules, $disabled, $revision, $attributes): void {
                $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
                if ($fresh->active_operation_id || $fresh->policy_revision !== $revision) {
                    throw new RuntimeException('Policy changed or preparation is active. Reload before editing.');
                }
                $fresh->update([...$attributes, 'file_rules' => $rules, 'disabled_mods' => $disabled]);
            });
        } finally {
            $lock->release();
        }
    }

    public function assign(ManagedServer $server, array $paths, string $action, int $revision): void
    {
        Access::requireAdmin();
        if (! $paths || count($paths) > 250 || ! isset(ProtectionPolicy::ACTIONS[$action])) {
            throw new RuntimeException('Select 1–250 files and a valid policy');
        }
        $catalog = array_column((new FilePolicyCatalog)->rows($server), null, 'path');
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
