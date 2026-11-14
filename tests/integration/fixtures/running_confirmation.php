<?php

declare(strict_types=1);
use App\Models\{Backup, User};
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Native\{CrucibleRuntime, ManualStart, Preparation, Runtime, Wings};
use Arcturus\ModpackManager\Services\{DeploymentApproval, PreviewService, ReleaseStore, SameHostDeployment, ServerPolicyService};
use Illuminate\Support\Facades\{Artisan, Auth, DB, Http};
use Illuminate\Support\Str;
use function Arcturus\ModpackManager\Native\microtime;

User::retrieved(function ($user): void {
    $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([(new \App\Models\Role)->forceFill(['name' => \App\Models\Role::ROOT_ADMIN])]));
});
$service = app(ManualStart::class);
\Illuminate\Support\Facades\Schema::create('eggs', function (\Illuminate\Database\Schema\Blueprint $t): void {
    $t->id(); $t->string('uuid'); $t->boolean('force_outgoing_ip')->default(false); $t->json('file_denylist')->nullable(); $t->json('features')->nullable();
});
foreach ((getenv('ARCTURUS_CONFIG_ACCEPTANCE_ONLY') ? [true] : [false, true]) as $placeholder) {
    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings); Auth::guard()->setUser($adminUser); $mode = 'success';
    $managed = $make(false, false, false, true); $uuid = $managed->pelican_server_uuid;
    $store = app(ReleaseStore::class); $base = $store->load($managed->base_manifest_path); $target = $store->load($managed->target_manifest_path);
    $stage = (new Preparation($managed))->stage($managed->target_metadata['prepared']['node_operation_id'], $managed->target_metadata['prepared']['manifest_sha256']);
    $base['version'] = '2.8.1'; $target['version'] = '2.9.0';
    $base['files']['config/railcraft.cfg'] = $target['files']['config/railcraft.cfg'] = hash('sha256', 'upstream railcraft');
    file_put_contents($stage.'/config/railcraft.cfg', 'upstream railcraft');
    if (getenv('ARCTURUS_CONFIG_ACCEPTANCE_ONLY')) {
        $configTargets = ['config/Botania.cfg' => "B:feature=true\n", 'config/Another.cfg' => "B:feature=true\n", 'config/binary.cfg' => "valid target text\n", 'config/runtime.cfg' => "required runtime text\n", 'config/strict.jar' => $crucibleJar()];
        foreach ($configTargets as $configPath => $bytes) { $target['files'][$configPath] = hash('sha256', $bytes); file_put_contents($stage.'/'.$configPath, $bytes); }
        $base['files']['config/removed.cfg'] = hash('sha256', 'old removed config');
        $systems[$uuid]['data']['config/removed.cfg'] = 'old removed config';
    }
    file_put_contents(dirname($stage).'/manifest.json', json_encode($target, JSON_THROW_ON_ERROR));
    $prepared = [...$managed->target_metadata['prepared'], 'manifest_sha256' => hash_file('sha256', dirname($stage).'/manifest.json')];
    file_put_contents(dirname($stage).'/operation.json', json_encode(['state' => 'completed', 'manifest_sha256' => $prepared['manifest_sha256']]));
    $managed->update(['installed_version' => '2.8.1', 'selected_version' => '2.9.0', 'base_manifest_path' => $store->put($base), 'target_manifest_path' => $store->put($target), 'target_metadata' => ['prepared' => $prepared]]);
    $systems[$uuid]['data']['config/railcraft.cfg'] = 'local railcraft repair';
    app(ServerPolicyService::class)->save($managed, [...$managed->file_rules,
        ...(getenv('ARCTURUS_CONFIG_ACCEPTANCE_ONLY') ? [['scope' => 'exact', 'pattern' => 'config/removed.cfg', 'mode' => 'remove']] : []),
        ['scope' => 'exact', 'pattern' => 'java9args.txt', 'mode' => 'protect'], ['scope' => 'exact', 'pattern' => 'lwjgl3ify-forgePatches.jar', 'mode' => 'protect']], [], $managed->policy_revision, ['keep_existing_crucible_runtime' => true]);
    $managed->refresh();
    DB::table('eggs')->insert(['id' => $managed->id + 1000, 'uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'file_denylist' => '[]', 'features' => '[]']);
    $variableId = DB::table('egg_variables')->insertGetId(['egg_id' => $managed->id + 1000, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'Crucible-1.7.10-server.jar']);
    $startup = 'java @java9args.txt -jar '.($placeholder ? '{{SERVER_JARFILE}}' : 'Crucible-1.7.10-server.jar').' nogui';
    DB::table('servers')->where('uuid', $uuid)->update(['startup' => $startup, 'egg_id' => $managed->id + 1000]);
    $managed->unsetRelation('pelicanServer');
    $systems[$uuid]['configuration']['invocation'] = $startup;
    $systems[$uuid]['configuration']['environment'] = ['SERVER_JARFILE' => 'Crucible-1.7.10-server.jar'];
    $task = new \Arcturus\ModpackManager\Schedules\UpdateModpackTask; $schedule = new \App\Models\Schedule; $schedule->setRelation('server', $managed->pelicanServer);
    check($task->canCreate($schedule) && $task->canCreate($schedule, $adminUser, 'update'), 'RC2/RC3 schedule task signature rejected root admin');
    $subuser = new class extends User { public function isRootAdmin(): bool { return false; } };
    check(! $task->canCreate($schedule, $subuser, 'update') && ! $task->canCreate($schedule, $adminUser, 'unexpected'), 'RC3 task authorization accepted non-root/payload');
    $plan = app(PreviewService::class)->generate($managed);
    check($plan['applicable'], 'Crucible comparison failed: '.json_encode($plan['capability_blockers'] ?? []));
    $files = new Wings($managed->pelicanServer, app(DaemonFileRepository::class));
    $runtime = (new CrucibleRuntime)->inspect($managed->pelicanServer, $files);
    check(isset($runtime['launcher_files']['Crucible-1.7.10-server.jar']), 'Literal/default egg JAR was not content-inspected');
    if ($placeholder) {
        check($runtime['server_jarfile'] === 'Crucible-1.7.10-server.jar' && $runtime['startup'] === $startup, 'Effective variable/template not retained in comparison');
        $systems[$uuid]['data']['Crucible-alternate.jar'] = $systems[$uuid]['data']['Crucible-1.7.10-server.jar'];
        $override = DB::table('server_variables')->insertGetId(['server_id' => $managed->pelicanServer->id, 'variable_id' => $variableId, 'variable_value' => 'Crucible-alternate.jar']);
        $changed = (new CrucibleRuntime)->inspect($managed->pelicanServer, $files);
        check(isset($changed['launcher_files']['Crucible-alternate.jar']) && $changed !== $runtime, 'Server override did not replace egg default in launcher proof');
        rejects(fn () => app(DeploymentApproval::class)->queue($managed, $plan['plan_sha256'], (string) Str::uuid(), true), 'Changed SERVER_JARFILE reused an approved launcher');
        foreach (['../Crucible.jar', 'Crucible.jar nogui', 'Crucible.jar;stop', '{{OTHER}}', 'server.jar', ''] as $invalid) {
            DB::table('server_variables')->where('id', $override)->update(['variable_value' => $invalid]);
            rejects(fn () => (new CrucibleRuntime)->inspect($managed->pelicanServer, $files), 'Invalid variable JAR value was accepted');
        }
        DB::table('server_variables')->where('id', $override)->update(['variable_value' => 'Crucible-invalid.jar']);
        $systems[$uuid]['data']['Crucible-invalid.jar'] = 'not a real Crucible JAR';
        rejects(fn () => (new CrucibleRuntime)->inspect($managed->pelicanServer, $files), 'Variable-selected invalid JAR contents were accepted');
        DB::table('server_variables')->where('id', $override)->delete();
    }
    $plan = app(PreviewService::class)->generate($managed);
    $operation = app(DeploymentApproval::class)->queue($managed, $plan['plan_sha256'], (string) Str::uuid(), true, true); DB::table('jobs')->delete();
    app()->call([new DeployManagedServer($managed->id, false, $plan['plan_sha256'], $operation->id), 'handle']);
    $operation->refresh(); $managed->refresh(); $targetPath = $managed->target_manifest_path; $targetBytes = file_get_contents($targetPath);
    check(ManualStart::canConfirmRunningDeployment($operation) && ! array_key_exists('config/railcraft.cfg', $operation->context['native_after']), 'Verified deferred deployment scope/ownership is invalid');
    if ($placeholder) {
        unset($systems[$uuid]['data']['logs/latest.log']);
        app(\App\Repositories\Daemon\DaemonServerRepository::class)->setServer($managed->pelicanServer)->power('start');
        $systems[$uuid]['modified'] = now()->addSeconds(2)->toIso8601String();
        $normalReview = $service->verify($operation->id, 1);
        check(isset($normalReview['proof']) && $operation->fresh()->phase === ManualStart::PHASE, 'Normal Start/Verify did not share the effective SERVER_JARFILE launcher proof');
        $operation->refresh();
    }
    $handoff = $operation->context['native_manual_start']; $handoff['attempts'] = [['available' => false, 'problem' => 'Legacy parser rejected Startup placeholder']];
    $operation->update(['context' => [...$operation->context, 'native_manual_start' => $handoff]]);
    $systems[$uuid]['state'] = 'running'; $systems[$uuid]['boot_started'] = microtime(true) - 120; $systems[$uuid]['omit_uptime'] = false;
    unset($systems[$uuid]['data']['logs/latest.log']); $systems[$uuid]['console'] = [];
    $beforeConfirmation = count($requests);
    if (getenv('ARCTURUS_CONFIG_ACCEPTANCE_ONLY')) { require __DIR__.(getenv('ARCTURUS_CONFIG_SET_ONLY') ? '/config_set.php' : '/config_acceptance.php'); continue; }
    rejects(fn () => $service->verify($operation->id, 1), 'Normal Verify fabricated missing boot evidence');
    rejects(fn () => $service->confirmRunningDeployment($operation->id, 999), 'Non-admin confirmed running deployment');
    $systems[$uuid]['data']['config/options.cfg'] = 'changed after deploy';
    $blocked = $service->confirmRunningDeployment($operation->id, 1);
    check(! $blocked['can_confirm'] && $blocked['differences'][0]['path'] === 'config/options.cfg' && $blocked['differences'][0]['expected_sha256'] !== $blocked['differences'][0]['actual_sha256'], 'Deployed hash mismatch omitted exact path/digests');
    $systems[$uuid]['data']['config/options.cfg'] = 'new';
    $systems[$uuid]['data']['config/railcraft.cfg'] = 'local railcraft repair still retained';
    $review = $service->confirmRunningDeployment($operation->id, 1, false, null, false, '2.9.0');
    rejects(fn () => $service->confirmRunningDeployment($operation->id, 1, true, $review['proof']), 'Missing administrator attestation was accepted');
    check($managed->fresh()->installed_version === '2.8.1' && $managed->fresh()->active_operation_id === $operation->id, 'Review completed the operation');
    if ($placeholder) {
        $component = \Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\DeploymentStatus::class, ['managedServerId' => $managed->id]);
        check(str_contains($component->html(), 'Potvrdit běžící nasazení'), 'Confirmation action not in the waiting deployment card');
        $component->call('confirmRunningDeployment', $operation->id);
    } else {
        $args = ['operation' => $operation->id, '--action' => 'confirm-running', '--actor' => 1, '--managed-server' => $managed->id, '--backup' => $operation->context['backup_uuid'], '--target-version' => '2.9.0', '--apply' => true, '--admin-confirms-running' => true, '--confirm' => $review['proof']];
        check(Artisan::call('arcturus-modpack:manual-start', $args) === 0, 'CLI running confirmation failed: '.Artisan::output());
    }
    $operation->refresh(); $managed->refresh();
    check($operation->phase === 'completed' && $managed->active_operation_id === null && $managed->installed_version === '2.9.0', 'Administrator confirmation failed to close only the active target deploy');
    check($managed->base_manifest_path === $targetPath && file_get_contents($targetPath) === $targetBytes && $store->load($managed->base_manifest_path)['files']['config/railcraft.cfg'] === hash('sha256', 'upstream railcraft'), 'Local configuration/launcher replaced the upstream baseline');
    check($systems[$uuid]['data']['config/railcraft.cfg'] === 'local railcraft repair still retained' && $managed->pelicanServer->startup === $startup, 'Confirmation mutated local config or the Startup template');
    check(($operation->context['startup_verified'] ?? null) === false && ($operation->context['running_confirmed_by_admin'] ?? false) && isset($operation->context['native_admin_running_confirmation']), 'Administrator attestation was mislabeled as automatic boot verification');
    $event = $managed->events()->where('kind', 'deploy')->where('status', 'success')->latest('id')->firstOrFail();
    check($event->details['startup_verified'] === false && $event->details['running_confirmed_by_admin'] === true, 'Deployment history fabricated boot verification');
    check(Backup::where('uuid', $operation->context['backup_uuid'])->firstOrFail()->is_locked && ! array_filter(array_slice($requests, $beforeConfirmation), fn ($r) => $r[0] !== 'GET'), 'Confirmation sent power/restore/sync or unlocked backup');
    echo ($placeholder ? 'SERVER_JARFILE default/override/content/drift and card confirmation' : 'Literal Crucible and CLI confirmation').": running without boot log, strict hashes, immutable upstream baseline, no power/restore passed\n";
}

Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings); Auth::guard()->setUser($adminUser); $mode = 'success';
$automaticBoot = $make(false); $plan = app(PreviewService::class)->generate($automaticBoot);
$automaticOperation = app(DeploymentApproval::class)->queue($automaticBoot, $plan['plan_sha256'], (string) Str::uuid()); DB::table('jobs')->delete();
app()->call([new DeployManagedServer($automaticBoot->id, false, $plan['plan_sha256'], $automaticOperation->id), 'handle']);
check($automaticOperation->fresh()->phase === 'completed' && $automaticOperation->fresh()->context['startup_verified'] === true && ! ($automaticOperation->fresh()->context['running_confirmed_by_admin'] ?? false), 'Default automatic boot completion semantics changed');
echo "Default automatic boot completion retains startup_verified=true passed\n";
