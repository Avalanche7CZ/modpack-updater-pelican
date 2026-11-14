<?php

declare(strict_types=1);
use App\Models\{Backup, User};
use Arcturus\ModpackManager\Jobs\{DeployManagedServer, VerifyDeploymentRecovery};
use Arcturus\ModpackManager\Native\{ManualStart, RestorePolicy};
use Arcturus\ModpackManager\Services\{DeploymentApproval, PreviewService};
use Illuminate\Support\Facades\{Auth, DB, Http};
use Illuminate\Support\Str;

User::retrieved(function ($user): void {
    $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([(new \App\Models\Role)->forceFill(['name' => \App\Models\Role::ROOT_ADMIN])]));
});
$restoreCalls = fn (array $calls) => count(array_filter($calls, fn ($r) => str_ends_with($r[1], '/restore')));
foreach ([['partial_write', true], ['final_corrupt', true], ['failed_boot', true], ['interrupted_live', true], ['partial_write', false], ['interrupted_live', false]] as [$scenario, $disabled]) {
    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
    Auth::guard()->setUser($adminUser); $mode = $scenario;
    $managed = $make(false); $uuid = $managed->pelican_server_uuid;
    $originalBaseline = $managed->base_manifest_path;
    $plan = app(PreviewService::class)->generate($managed);
    $page = app(\Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer::class); $page->record = $managed; $page->tab = 'updates';
    $components = $page->applyUpdateAction()->getSchema(\Filament\Schemas\Schema::make($page))->getComponents();
    $choice = array_values(array_filter($components, fn ($component) => method_exists($component, 'getName') && $component->getName() === 'no_automatic_restore'))[0];
    check($choice->getLabel() === 'Při chybě neobnovovat automaticky' && $choice->getDefaultState() === false && $choice->isVisible(), 'Native approval checkbox label/default/visibility changed');
    $managed->transport = 'local'; check(! $choice->isVisible(), 'Restore opt-out leaked into legacy approval UI'); $managed->transport = 'native';
    $operation = app(DeploymentApproval::class)->queue($managed, $plan['plan_sha256'], (string) Str::uuid(), false, $disabled);
    check(($operation->fresh()->context['no_automatic_restore'] ?? null) === $disabled, 'Restore preference was not persisted');
    rejects(fn () => app(DeploymentApproval::class)->queue($managed, $plan['plan_sha256'], $operation->id, false, ! $disabled), 'Approval option changed on replay');
    $automatic = clone $operation; $automatic->context = [...$operation->context, 'automatic_approval' => true];
    check(! RestorePolicy::automaticRestoreDisabled($automatic), 'Manual opt-out changed automatic Schedule recovery');
    DB::table('jobs')->delete(); Auth::guard()->forgetUser(); $at = count($requests);
    // New job object, with no in-memory restore option: persisted approval is authoritative.
    rejects(fn () => app()->call([new DeployManagedServer($managed->id, false, $plan['plan_sha256'], $operation->id), 'handle']), 'Failure fixture unexpectedly completed');
    $operation->refresh(); $managed->refresh(); $calls = array_slice($requests, $at);
    check($managed->installed_version === '1' && $managed->base_manifest_path === $originalBaseline, 'Failure advanced installed version/baseline');
    check(Backup::where('uuid', $operation->context['backup_uuid'])->firstOrFail()->is_locked, 'Failure unlocked the backup');
    if (! $disabled) {
        if ($scenario === 'interrupted_live') {
            check($restoreCalls($calls) === 0 && $operation->phase === 'manual_intervention', 'Worker failure bypassed retained recovery');
            $operation->update(['phase' => 'recovery_queued', 'completed_at' => null, 'context' => [...$operation->context, 'recovery_actor_id' => 1, 'native_execution_id' => bin2hex(random_bytes(16))]]);
            $mode = 'success'; $beforeRecovery = count($requests);
            app()->call([new VerifyDeploymentRecovery($operation->id), 'handle']);
            check($restoreCalls(array_slice($requests, $beforeRecovery)) === 1 && $operation->fresh()->phase === 'restored', 'Default recovery job began requiring the opt-out approval');
            echo "Default worker recovery: follow-up job still restores without the new opt-out receipt passed\n";
            continue;
        }
        check($restoreCalls($calls) === 1 && $operation->phase === 'restored', 'Default automatic restore behavior changed');
        echo "Default approval: partial live write still restores exact locked backup and verifies original boot passed\n";
        continue;
    }
    check($restoreCalls($calls) === 0 && $operation->phase === 'manual_intervention', 'Opt-out performed direct Restore or lost manual checkpoint');
    check($managed->active_operation_id === $operation->id && ($operation->context['native_auto_restore_suppressed'] ?? false), 'Opt-out lost active ownership or suppression receipt');
    check($operation->progress['message'] === RestorePolicy::WAITING && ($operation->context['native_deploy_files_verified'] ?? true) === false, 'Unverified failure claimed file success or omitted waiting status');
    check($systems[$uuid]['configuration']['suspended'] && in_array($systems[$uuid]['state'], ['offline', 'exited'], true), 'Unverified files were not guarded/offline');
    if ($scenario === 'failed_boot') { check(isset($operation->context['native_target_boot_failure']) && isset($systems[$uuid]['data']['world/region/new-failed-boot.mca']), 'Suppression lost boot evidence or restored failed-boot data'); }
    $status = view('arcturus-modpack-manager::deployment-status', ['operation' => $operation, 'context' => $operation->context, 'receiptReadable' => true])->render();
    check(str_contains($status, RestorePolicy::WAITING), 'Waiting state was not rendered');
    // A stale/requeued recovery job with an actor ID is not explicit permission to restore.
    $operation->update(['phase' => 'recovery_queued', 'completed_at' => null, 'context' => [...$operation->context,
        'recovery_actor_id' => 1, 'native_execution_id' => bin2hex(random_bytes(16))]]);
    $beforeRecovery = count($requests);
    app()->call([new VerifyDeploymentRecovery($operation->id), 'handle']);
    check($restoreCalls(array_slice($requests, $beforeRecovery)) === 0 && $operation->fresh()->phase === 'manual_intervention', 'Follow-up recovery bypassed restore opt-out');
    // Root administrator explicitly authorizes the exact backup for a NEW recovery execution.
    $mode = 'success'; Auth::guard()->setUser($adminUser);
    app(DeploymentApproval::class)->verifyRecovery($managed->fresh()); DB::table('jobs')->delete();
    $approved = $operation->fresh()->context['native_explicit_rollback'];
    check($approved['backup_uuid'] === $operation->context['backup_uuid'], 'Explicit rollback approval selected another backup');
    $beforeExplicit = count($requests);
    app()->call([new VerifyDeploymentRecovery($operation->id), 'handle']);
    check($restoreCalls(array_slice($requests, $beforeExplicit)) === 1 && $operation->fresh()->phase === 'restored', 'Explicit admin rollback was blocked');
    echo "Opt-out $scenario: no direct/follow-up Restore, guarded checkpoint, unchanged baseline and explicit rollback passed\n";
}
// The two independently approved choices compose: verified deploy hands over, with no automatic Start/Restore.
Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
$mode = 'success'; Auth::guard()->setUser($adminUser); $managed = $make(false);
$plan = app(PreviewService::class)->generate($managed); $baseline = $managed->base_manifest_path;
$operation = app(DeploymentApproval::class)->queue($managed, $plan['plan_sha256'], (string) Str::uuid(), true, true);
DB::table('jobs')->delete(); $at = count($requests);
app()->call([new DeployManagedServer($managed->id, false, $plan['plan_sha256'], $operation->id), 'handle']);
check($operation->fresh()->phase === ManualStart::PHASE && $systems[$managed->pelican_server_uuid]['starts'] === 0 && $restoreCalls(array_slice($requests, $at)) === 0, 'Combined opt-out/manual start did not hand over safely');
check($managed->fresh()->installed_version === '1' && $managed->fresh()->base_manifest_path === $baseline && ($operation->fresh()->context['native_deploy_files_verified'] ?? false), 'Manual start handoff advanced baseline or omitted final file verification');
echo "Combined manual-start/no-auto-restore: files verified, controls handed over, no Start/Restore and baseline retained passed\n";
