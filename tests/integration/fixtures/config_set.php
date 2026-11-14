<?php

declare(strict_types=1);
use App\Models\Backup;
use Arcturus\ModpackManager\Livewire\DeploymentStatus;
use Arcturus\ModpackManager\Native\ManualStart;

$originalContext = $operation->context; $originalAfter = $originalContext['native_after'];
$originalProperties = $systems[$uuid]['data']['server.properties'];
$local = "B:feature=false\n# runtime rewrite\n";
$systems[$uuid]['data']['config/Botania.cfg'] = $systems[$uuid]['data']['config/Another.cfg'] = $local;
$systems[$uuid]['data']['server.properties'] .= "\n# changed at boot\n";
$recipePaths = ['config/opencomputers/default.recipes', 'config/opencomputers/gregtech.recipes'];
foreach ($recipePaths as $path) { $systems[$uuid]['data'][$path] = '# local '.$path."\n"; }
$runtimePlan = $originalContext['native_target_runtime_plan']; $runtimePlan['dependency_hashes']['config/runtime.cfg'] = $originalAfter['config/runtime.cfg'];
$operation->update(['context' => [...$originalContext, 'native_target_runtime_plan' => $runtimePlan]]);
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 999), 'Non-root accepted configuration set');
// Every strict path still blocks the whole action, with no partial DB acceptance.
foreach (['config/strict.jar', 'config/runtime.cfg', 'config/removed.cfg', 'config/binary.cfg', 'config/opencomputers/other.recipes'] as $strict) {
    $systems[$uuid]['data'][$strict] = $strict === 'config/binary.cfg' ? "bad\x00config" : 'strict drift';
    $blocked = $service->acceptCurrentConfiguration($operation->id, 1);
    check(str_contains($blocked['message'], 'confirm it once') && ! str_contains($blocked['message'], 'Only individually'), 'Bulk blocker message still requires individual approval');
    $rows = array_column($blocked['differences'], null, 'path');
    check(! $blocked['can_confirm'] && $rows[$strict]['reason'] !== null && isset($rows['server.properties'], $rows['config/Botania.cfg']), 'Bulk overview omitted config list or allowed strict path: '.$strict);
    rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, 'invalid'), 'Strict path permitted completion');
    if ($strict === 'config/removed.cfg') { unset($systems[$uuid]['data'][$strict]); } else { $systems[$uuid]['data'][$strict] = $configTargets[$strict]; }
}
// Only exactly root server.properties is the additional exception.
$ctx = $operation->fresh()->context;
$operation->update(['context' => [...$ctx, 'native_after' => [...$originalAfter, 'other/server.properties' => hash('sha256', 'original')]]]);
$systems[$uuid]['data']['other/server.properties'] = 'changed';
$blocked = $service->acceptCurrentConfiguration($operation->id, 1); $rows = array_column($blocked['differences'], null, 'path');
check(! $blocked['can_confirm'] && $rows['other/server.properties']['reason'] !== null, 'Non-root server.properties was accepted');
$operation->update(['context' => $ctx]); unset($systems[$uuid]['data']['other/server.properties']);
$review = $service->acceptCurrentConfiguration($operation->id, 1);
check($review['can_confirm'] && count($review['differences']) === 6, 'Bulk config set was not confirmable without per-file diffs');
check(empty($operation->fresh()->context['native_admin_config_acceptances']), 'Read-only list accepted configs');
// Only the two exact recipe paths are eligible, and only in bulk mode.
foreach ($recipePaths as $path) {
    $sha = hash('sha256', $systems[$uuid]['data'][$path]);
    $rows = array_column($review['differences'], null, 'path');
    check($rows[$path]['reviewable'] && $rows[$path]['expected_sha256'] === $originalAfter[$path] && $rows[$path]['actual_sha256'] === $sha, 'Recipe overview lost manifest/current hash: '.$path);
    rejects(fn () => $service->deploymentConfigChange($operation->id, 1, $path, $sha), 'Recipe exception escaped bulk mode');
    $bytes = $systems[$uuid]['data'][$path];
    foreach (["invalid\xff", "binary\x00", null] as $invalid) {
        if ($invalid === null) { unset($systems[$uuid]['data'][$path]); } else { $systems[$uuid]['data'][$path] = $invalid; }
        $blocked = $service->acceptCurrentConfiguration($operation->id, 1); $rows = array_column($blocked['differences'], null, 'path');
        check(! $blocked['can_confirm'] && $rows[$path]['reason'] !== null, 'Invalid UTF-8/binary/missing recipe accepted: '.$path);
    }
    $systems[$uuid]['data'][$path] = $bytes;
    $ctx = $operation->fresh()->context;
    $operation->update(['context' => [...$ctx, 'native_after' => [...$originalAfter, $path => hash('sha256', 'wrong target')]]]);
    $blocked = $service->acceptCurrentConfiguration($operation->id, 1); $rows = array_column($blocked['differences'], null, 'path');
    check(! $blocked['can_confirm'] && str_contains($rows[$path]['reason'], 'manifest digest'), 'Recipe bypassed authenticated target digest: '.$path);
    $operation->update(['context' => $ctx]);
    $systems[$uuid]['data'][$path] .= '# changed after review';
    rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Stale recipe hash accepted: '.$path);
    $systems[$uuid]['data'][$path] = $bytes;
}
// Hash drift, a changed set, operation/manifest identity and a new process all invalidate the exact list.
$systems[$uuid]['data']['config/Botania.cfg'] .= '# changed again';
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Old config hash accepted');
$systems[$uuid]['data']['config/Botania.cfg'] = $local;
$systems[$uuid]['data']['config/Another.cfg'] = $configTargets['config/Another.cfg'];
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Changed list accepted');
$systems[$uuid]['data']['config/Another.cfg'] = $local;
$managed->update(['target_manifest_path' => $managed->base_manifest_path]);
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Changed target accepted');
$managed->update(['target_manifest_path' => $targetPath]);
$managed->update(['active_operation_id' => null]);
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Changed active operation accepted');
$managed->update(['active_operation_id' => $operation->id]);
$bootStarted = $systems[$uuid]['boot_started']; $systems[$uuid]['boot_started'] -= 120;
rejects(fn () => $service->acceptCurrentConfiguration($operation->id, 1, true, $review['proof']), 'Changed running instance accepted');
$systems[$uuid]['boot_started'] = $bootStarted;
check($operation->fresh()->phase === ManualStart::PHASE && $managed->fresh()->installed_version === '2.8.1' && empty($operation->fresh()->context['native_admin_config_acceptances']), 'Failed bulk acceptance partially changed checkpoint/version');
$component = \Livewire\Livewire::test(DeploymentStatus::class, ['managedServerId' => $managed->id]);
$component->call('reviewCurrentConfiguration', $operation->id);
foreach ([...$recipePaths, 'config/Botania.cfg', 'config/Another.cfg', 'server.properties', hash('sha256', $local), hash('sha256', $systems[$uuid]['data']['server.properties'])] as $visible) {
    check(str_contains($component->html(), $visible), 'Confirmation omitted exact displayed list/hash: '.$visible);
}
// UI must apply its displayed proof, never silently replace it with a new review.
$systems[$uuid]['data']['server.properties'] .= '# stale UI';
$component->call('acceptCurrentConfiguration');
check($operation->fresh()->phase === ManualStart::PHASE, 'UI accepted an undisplayed properties hash');
$component->call('reviewCurrentConfiguration', $operation->id)->call('acceptCurrentConfiguration');
$operation->refresh(); $managed->refresh();
check($operation->phase === 'completed' && $managed->installed_version === '2.9.0' && $managed->active_operation_id === null, 'One configuration-set confirmation did not complete 2.9.0');
$accepted = $operation->context['native_admin_running_confirmation']['accepted_local_configs'];
check(count($accepted) === 6 && $accepted['server.properties']['actual_sha256'] === hash('sha256', $systems[$uuid]['data']['server.properties']), 'Accepted config receipt omitted exact root properties');
foreach ($recipePaths as $path) { check($accepted[$path]['actual_sha256'] === hash('sha256', $systems[$uuid]['data'][$path]) && $accepted[$path]['expected_sha256'] === $originalAfter[$path], 'Final recipe receipt did not retain exact hashes'); }
foreach ($accepted as $path => $receipt) {
    check($receipt['operation_id'] === $operation->id && $receipt['path'] === $path && $receipt['target_manifest_sha256'] === $review['target_manifest_sha256'] && $receipt['actor_id'] === 1 && $receipt['approval'] === 'configuration_set', 'Acceptance audit not bound to exact op/target/path/admin');
}
check($operation->context['native_after'] === $originalAfter && $managed->base_manifest_path === $targetPath && file_get_contents($targetPath) === $targetBytes, 'Bulk acceptance changed original receipt/upstream baseline');
check($systems[$uuid]['data']['config/Botania.cfg'] === $local && $operation->context['startup_verified'] === false && Backup::where('uuid', $operation->context['backup_uuid'])->firstOrFail()->is_locked, 'Config content, boot attestation or locked backup changed');
check($managed->events()->where('kind', 'deployment_config_set_acceptance')->count() === 1 && $managed->events()->where('kind', 'deployment_config_review')->count() === 0, 'Bulk action required individual diffs or omitted audit');
check(! array_filter(array_slice($requests, $beforeConfirmation), fn ($r) => $r[0] !== 'GET'), 'Bulk confirmation wrote files/power/restore/sync');
echo "Configuration set: exact OpenComputers recipes (bulk only, UTF-8, manifest and stale hash checks), one root confirmation, exact config and root properties list, strict paths, stale hashes/list/identity/instance refusal, atomic completion and immutable upstream baseline without power/restore passed\n";
