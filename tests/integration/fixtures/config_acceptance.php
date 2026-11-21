<?php

declare(strict_types=1);
use App\Models\Backup;
use Arcturus\ModpackManager\Livewire\DeploymentStatus;
use Arcturus\ModpackManager\Native\ManualStart;
use Illuminate\Support\Facades\Artisan;

$originalAfter = $operation->context['native_after'];
check($originalAfter['config/removed.cfg'] === null, 'Removed-path fixture was not part of deployed receipt');
$runtimePlan = $operation->context['native_target_runtime_plan']; $runtimePlan['dependency_hashes']['config/runtime.cfg'] = $originalAfter['config/runtime.cfg'];
$operation->update(['context' => [...$operation->context, 'native_target_runtime_plan' => $runtimePlan]]);
$local = "B:feature=false\n# <script>review_only</script>\n";
$systems[$uuid]['data']['config/Botania.cfg'] = $systems[$uuid]['data']['config/Another.cfg'] = $local;
$systems[$uuid]['data']['config/binary.cfg'] = "binary\x00config";
$systems[$uuid]['data']['config/runtime.cfg'] = 'changed runtime dependency';
$systems[$uuid]['data']['config/strict.jar'] = 'changed JAR';
$systems[$uuid]['data']['config/removed.cfg'] = 'unexpected recreated path';
$overview = $service->confirmRunningDeployment($operation->id, 1);
$rows = array_column($overview['differences'], null, 'path');
check(count($rows) === 6 && ! $overview['can_confirm'], 'Overview did not return all deployed mismatches at once');
foreach (['config/Botania.cfg', 'config/Another.cfg', 'config/binary.cfg', 'config/runtime.cfg', 'config/strict.jar', 'config/removed.cfg'] as $path) {
    check(isset($rows[$path]) && array_key_exists('expected_sha256', $rows[$path]) && array_key_exists('actual_sha256', $rows[$path]), 'Overview omitted exact deployed path/digests: '.$path);
}
foreach (['config/runtime.cfg', 'config/strict.jar', 'config/removed.cfg'] as $path) {
    check(! $rows[$path]['reviewable'], 'Strict path offered config acceptance: '.$path);
    rejects(fn () => $service->deploymentConfigChange($operation->id, 1, $path, $rows[$path]['actual_sha256']), 'Strict JAR/runtime/removal could be accepted');
}
rejects(fn () => $service->deploymentConfigChange($operation->id, 1, 'config/binary.cfg', $rows['config/binary.cfg']['actual_sha256']), 'Binary cfg offered a text acceptance');
$component = \Livewire\Livewire::test(DeploymentStatus::class, ['managedServerId' => $managed->id]);
$component->call('reviewRunningDeployment', $operation->id);
foreach (array_keys($rows) as $path) { check(str_contains($component->html(), $path), 'UI overview omitted '.$path); }
// Reviewing is read-only; accepting stale content, another path or another manifest must fail.
$botaniaSha = hash('sha256', $local);
$diff = $service->deploymentConfigChange($operation->id, 1, 'config/Botania.cfg', $botaniaSha);
check(str_contains($diff['diff'], '-B:feature=true') && str_contains($diff['diff'], '+B:feature=false'), 'Diff did not compare verified target → actual local config');
rejects(fn () => $service->deploymentConfigChange($operation->id, 1, 'config/Another.cfg', $botaniaSha, true, $diff['proof']), 'Proof was reusable for another path with identical hashes');
$managed->update(['target_manifest_path' => $managed->base_manifest_path]);
rejects(fn () => $service->deploymentConfigChange($operation->id, 1, 'config/Botania.cfg', $botaniaSha, true, $diff['proof']), 'Proof accepted a different target manifest');
$managed->update(['target_manifest_path' => $targetPath]);
$systems[$uuid]['data']['config/Botania.cfg'] .= "# changed after diff\n";
rejects(fn () => $service->deploymentConfigChange($operation->id, 1, 'config/Botania.cfg', $botaniaSha, true, $diff['proof']), 'Stale config hash was accepted');
check(! ($operation->fresh()->context['native_admin_config_acceptances'] ?? []), 'Failed acceptance changed the checkpoint');
// The strict differences are resolved only by fixture setup, never by application writes.
foreach (['config/binary.cfg', 'config/runtime.cfg', 'config/strict.jar'] as $path) { $systems[$uuid]['data'][$path] = $configTargets[$path]; }
unset($systems[$uuid]['data']['config/removed.cfg']);
$botaniaSha = hash('sha256', $systems[$uuid]['data']['config/Botania.cfg']);
$args = ['operation' => $operation->id, '--actor' => 1, '--managed-server' => $managed->id, '--backup' => $operation->context['backup_uuid'], '--target-version' => '2.9.0', '--path' => 'config/Botania.cfg', '--sha256' => $botaniaSha];
check(Artisan::call('arcturus-modpack:manual-start', [...$args, '--action' => 'review-config']) === 0, 'CLI text diff review failed');
$cliReview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
check(Artisan::call('arcturus-modpack:manual-start', [...$args, '--action' => 'accept-config', '--apply' => true, '--confirm' => $cliReview['proof']]) === 0, 'CLI exact config acceptance failed');
check($operation->fresh()->phase === ManualStart::PHASE && $managed->fresh()->installed_version === '2.8.1', 'Accepting one config completed the deployment');
$operation->refresh(); $accepted = $operation->context['native_admin_config_acceptances'];
check($accepted['config/Botania.cfg']['operation_id'] === $operation->id && $accepted['config/Botania.cfg']['target_manifest_sha256'] === $overview['target_manifest_sha256'] && $accepted['config/Botania.cfg']['actual_sha256'] === $botaniaSha, 'Acceptance lacked operation/manifest/hash binding');
// Even a copied receipt cannot approve another path, operation or manifest.
$bad = $accepted; $bad['config/Another.cfg'] = $accepted['config/Botania.cfg'];
$operation->update(['context' => [...$operation->context, 'native_admin_config_acceptances' => $bad]]);
$blocked = $service->confirmRunningDeployment($operation->id, 1);
$byPath = array_column($blocked['differences'], null, 'path'); check(! $byPath['config/Another.cfg']['accepted'], 'Copied receipt accepted another path');
foreach (['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'target_manifest_sha256' => str_repeat('f', 64)] as $key => $value) {
    $bad = $accepted; $bad['config/Botania.cfg'][$key] = $value;
    $operation->update(['context' => [...$operation->context, 'native_admin_config_acceptances' => $bad]]);
    $blocked = $service->confirmRunningDeployment($operation->id, 1); $byPath = array_column($blocked['differences'], null, 'path');
    check(! $byPath['config/Botania.cfg']['accepted'], 'Receipt reused across '.$key);
}
$operation->update(['context' => [...$operation->context, 'native_admin_config_acceptances' => $accepted]]);
$component->call('reviewDeploymentConfig', $operation->id, 'config/Another.cfg', hash('sha256', $local));
check(str_contains($component->html(), '&lt;script&gt;review_only&lt;/script&gt;') && ! str_contains($component->html(), '<script>review_only</script>'), 'Config diff was not HTML-escaped');
$component->call('acceptDeploymentConfig');
$ready = $service->confirmRunningDeployment($operation->id, 1);
check($ready['can_confirm'] && count($ready['differences']) === 2 && ! array_filter($ready['differences'], fn ($row) => ! $row['accepted']), 'Individual config acceptances did not unblock review');
// A later content change invalidates both the per-file acceptance and the full completion proof.
$systems[$uuid]['data']['config/Botania.cfg'] .= "# another runtime rewrite\n";
$blocked = $service->confirmRunningDeployment($operation->id, 1); $byPath = array_column($blocked['differences'], null, 'path');
check(! $blocked['can_confirm'] && ! $byPath['config/Botania.cfg']['accepted'] && $byPath['config/Another.cfg']['accepted'], 'Later config rewrite retained stale acceptance');
rejects(fn () => $service->confirmRunningDeployment($operation->id, 1, true, $ready['proof'], true), 'Old global proof bypassed later config rewrite');
$component->call('confirmRunningDeployment', $operation->id);
app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
check($operation->fresh()->phase === ManualStart::PHASE, 'UI silently completed a changed config');
$newSha = hash('sha256', $systems[$uuid]['data']['config/Botania.cfg']);
$component->call('reviewDeploymentConfig', $operation->id, 'config/Botania.cfg', $newSha)->call('acceptDeploymentConfig');
$component->call('confirmRunningDeployment', $operation->id);
app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
$operation->refresh(); $managed->refresh();
check($operation->phase === 'completed' && $managed->installed_version === '2.9.0' && $managed->active_operation_id === null, 'Deploy was not closed as 2.9.0 after exact individual acceptances');
check($operation->context['native_after'] === $originalAfter && $managed->base_manifest_path === $targetPath && file_get_contents($targetPath) === $targetBytes, 'Accepted local config rewrote deployed receipt or upstream baseline');
$baseline = $store->load($managed->base_manifest_path);
check($baseline['files']['config/Botania.cfg'] === hash('sha256', $configTargets['config/Botania.cfg']) && hash('sha256', $systems[$uuid]['data']['config/Botania.cfg']) === $newSha, 'Completion replaced either upstream or local config');
check(count($operation->context['native_admin_running_confirmation']['accepted_local_configs']) === 2 && $operation->context['startup_verified'] === false, 'Completion omitted accepted config audit or invented verified boot');
check($managed->events()->where('kind', 'deployment_config_acceptance')->count() === 3, 'Per-path acceptance audit was not retained');
check(Backup::where('uuid', $operation->context['backup_uuid'])->firstOrFail()->is_locked && ! array_filter(array_slice($requests, $beforeConfirmation), fn ($r) => $r[0] !== 'GET'), 'Config acceptance/completion wrote live files or sent power/restore');
echo "Config acceptance: complete overview, verified diff, exact path/operation/manifest/hash binding, strict JAR/runtime/removals, stale rejection, pure upstream baseline and power-free 2.9.0 completion passed\n";
