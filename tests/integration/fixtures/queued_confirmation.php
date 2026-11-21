<?php

declare(strict_types=1);

use App\Models\Backup;
use Arcturus\ModpackManager\Jobs\ConfirmDeployment;
use Arcturus\ModpackManager\Livewire\DeploymentStatus;
use Arcturus\ModpackManager\Models\{DeploymentConfirmation, UpdateEvent};
use Arcturus\ModpackManager\Native\{DeploymentLease, ManualStart};
use Arcturus\ModpackManager\Services\QueueDeploymentConfirmation;
use Arcturus\ModpackManager\Tests\FixtureClock;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Illuminate\Support\Str;

$originalContext = $operation->context;
foreach (range(0, 204) as $i) {
    $systems[$uuid]['data']['config/gtpp-'.$i.'.cfg'] = $i === 95 ? str_repeat("č=✓\n", 130000) : "feature=false # $i\n";
}
$systems[$uuid]['data']['server.properties'] .= "\n# rewritten by Minecraft\n";
$queue = app(QueueDeploymentConfirmation::class);
$startProof = fn () => $service->acceptCurrentConfiguration($operation->id, 1);
$enqueue = fn (array $proof) => $queue->enqueue($operation->id, 1, $proof['review_event_id'], $proof['proof'], true);
$runJob = function () use ($adminUser): void {
    Auth::guard()->forgetUser();
    try { app('queue.worker')->runNextJob('deployment-fixture', 'default', new WorkerOptions(sleep: 0, timeout: 0)); }
    finally { Auth::guard()->setUser($adminUser); }
};
$cacheDir = storage_path('framework/cache/arcturus-confirmations-'.Str::uuid());
config(['cache.default' => 'arcturus-fixture-file', 'cache.stores.arcturus-fixture-file' => ['driver' => 'file', 'path' => $cacheDir]]);
try {
    DB::table('jobs')->delete();
    $review = $startProof();
    check($review['can_confirm'] && count($review['differences']) >= 206, 'Large UTF-8 configuration set could not be reviewed');
    $reviewEvent = UpdateEvent::findOrFail($review['review_event_id']);
    $created = $reviewEvent->created_at;
    $reviewEvent->update(['created_at' => now()->subMinutes(16)]);
    $at = count($requests);
    rejects(fn () => $enqueue($review), 'Stale review admitted');
    check(count($requests) === $at && DB::table('jobs')->count() === 0, 'Expired admission called Wings or queued a job');
    $reviewEvent->update(['created_at' => $created]);
    config(['queue.default' => 'sync']);
    rejects(fn () => $enqueue($review), 'Synchronous queue accepted');
    config(['queue.default' => 'deployment-fixture']);
    rejects(fn () => $queue->enqueue($operation->id, 999, $review['review_event_id'], $review['proof'], true), 'Non-root queued approval');

    $component = \Livewire\Livewire::test(DeploymentStatus::class, ['managedServerId' => $managed->id]);
    $component->call('reviewCurrentConfiguration', $operation->id);
    check(str_contains($component->html(), 'config/gtpp-95.cfg'), 'Large path missing from current configuration review');
    $displayed = $component->get('configurationSetReview');
    $systems[$uuid]['during_file_read'] = static function (): void {
        FixtureClock::advance(31); // A slow verification read must only be reached by the job.
    };
    $at = count($requests);
    $clock = FixtureClock::now();
    $limit = ini_get('max_execution_time');
    set_time_limit(30);
    try { $component->call('acceptCurrentConfiguration'); }
    finally { set_time_limit((int) $limit); }
    check(str_contains($component->html(), 'Confirmation queued'), 'Livewire did not show the queued confirmation');
    $first = DeploymentConfirmation::findOrFail($component->get('confirmationId'));
    check(count($requests) === $at && FixtureClock::now() - $clock < 30, 'Livewire confirmation performed a slow remote read');
    check($enqueue($displayed)->id === $first->id && $enqueue($review)->id === $first->id && DB::table('jobs')->count() === 1,
        'Duplicate admission produced independent confirmation jobs');
    $checkNoLease = function () use ($managed, $operation): void {
        $probe = new DeploymentLease($managed->id, $operation->id, 'fixture_probe');
        check($probe->get(), 'Verification held an orphan FileStore deployment lease');
        $probe->release();
    };
    $checkNoLease();
    $systems[$uuid]['data']['config/gtpp-1.cfg'] .= '# after review';
    $runJob();
    check($first->refresh()->state === 'failed' && $operation->fresh()->phase === ManualStart::PHASE, 'Edited configuration passed queued proof');
    $checkNoLease();

    foreach (['checkpoint', 'manifest', 'instance', 'network'] as $scenario) {
        $approval = $enqueue($startProof());
        $boot = $systems[$uuid]['boot_started'];
        $at = count($requests);
        if ($scenario === 'checkpoint') { $operation->update(['context' => [...$originalContext, 'test_checkpoint_change' => true]]); }
        if ($scenario === 'manifest') { file_put_contents($targetPath, '{}'); }
        if ($scenario === 'instance') { $systems[$uuid]['boot_started'] -= 120; }
        if ($scenario === 'network') { $systems[$uuid]['confirmation_read_fault'] = 'config/gtpp-201.cfg'; }
        $runJob();
        check($approval->refresh()->state === 'failed' && $managed->fresh()->installed_version === '2.8.1', 'Queued confirmation accepted '.$scenario.' drift');
        $checkNoLease();
        if ($scenario === 'checkpoint') {
            check(count($requests) === $at, 'Changed checkpoint was not rejected before Wings access');
            $operation->update(['context' => $originalContext]);
        }
        if ($scenario === 'manifest') { file_put_contents($targetPath, $targetBytes); }
        if ($scenario === 'instance') { $systems[$uuid]['boot_started'] = $boot; }
        unset($systems[$uuid]['confirmation_read_fault']);
    }

    $exception = $enqueue($startProof());
    UpdateEvent::creating(static function (UpdateEvent $event): void {
        if ($event->kind === 'deployment_config_set_acceptance' && ($GLOBALS['fixture_fail_finalization'] ?? false)) {
            throw new RuntimeException('Fixture SQL finalization exception');
        }
    });
    $GLOBALS['fixture_fail_finalization'] = true;
    try { $runJob(); } finally { $GLOBALS['fixture_fail_finalization'] = false; }
    check($exception->refresh()->state === 'failed' && $operation->fresh()->phase === ManualStart::PHASE
        && empty($operation->context['native_admin_config_acceptances']), 'Finalization exception left partial SQL side effects');
    $checkNoLease();

    $lastReview = $startProof();
    $approved = $enqueue($lastReview);
    $other = new DeploymentLease($managed->id, $operation->id, 'fixture_recovery');
    check($other->get(), 'Fixture could not hold recovery lease');
    $runJob();
    check($approved->refresh()->state === 'blocked' && $other->isOwnedByCurrentProcess(), 'Confirmation cleared or overlapped a recovery lease');
    $other->release();
    $checkNoLease();

    $queue->retry($approved->id, 1);
    if (function_exists('pcntl_fork') && function_exists('posix_kill')) {
        $child = pcntl_fork();
        check($child >= 0, 'Could not fork interrupted confirmation fixture');
        if ($child === 0) {
            $systems[$uuid]['during_file_read'] = static function (): void { posix_kill(getmypid(), SIGKILL); };
            $queue->run($approved->id);
            exit(42);
        }
        pcntl_waitpid($child, $termination);
        check(pcntl_wifsignaled($termination) && pcntl_wtermsig($termination) === SIGKILL,
            'Confirmation worker did not reach the interrupted Wings read');
        $checkNoLease();
    }

    // Model a queue insertion lost after committing the approved request.
    DB::table('jobs')->delete();
    $approved->refresh()->update(['dispatched_at' => null, 'updated_at' => now()->subMinutes(6)]);
    $queue->recover();
    $queue->recover();
    check(DB::table('jobs')->count() === 1 && $approved->refresh()->dispatched_at !== null, 'Outbox did not publish exactly one recovered confirmation');

    $abandoned = (string) Str::uuid();
    $approved->refresh()->update(['state' => 'verifying_files', 'attempt_id' => $abandoned,
        'attempt_started_at' => now()->subSeconds(ConfirmDeployment::TIMEOUT + 61), 'worker' => 'fixture-dead-process']);
    $at = count($requests);
    $checkNoLease();
    FixtureClock::advance(16 * 60);
    $runJob();
    $approved->refresh();
    check($approved->state === 'completed' && $approved->attempt_id !== $abandoned && count($requests) > $at, 'Abandoned verification did not resume with a new fenced attempt');
    $checkNoLease();

    $audit = $operation->refresh()->context['native_admin_running_confirmation'];
    $managed->refresh();
    check($operation->phase === 'completed' && $managed->installed_version === '2.9.0' && $managed->active_operation_id === null
        && $audit['confirmation_id'] === $approved->id && $audit['review_event_id'] === $lastReview['review_event_id']
        && count($audit['accepted_local_configs']) >= 206, 'Queued confirmation lost exact approvals or deployment completion');
    check($operation->context['startup_verified'] === false && $operation->context['running_confirmed_by_admin'] === true
        && $operation->context['native_after'] === $originalContext['native_after'] && $managed->base_manifest_path === $targetPath
        && file_get_contents($targetPath) === $targetBytes && Backup::where('uuid', $audit['backup_uuid'])->firstOrFail()->is_locked,
        'Queued confirmation changed the upstream baseline, backup or automatic boot status');
    $managedPage = \Livewire\Livewire::test(\Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer::class, ['record' => $managed->id]);
    check(str_contains($managedPage->html(), 'Confirmation completed') && str_contains($managedPage->html(), $approved->id),
        'Managed server hid the completed confirmation after releasing the active operation');
    $at = count($requests);
    $queue->run($approved->id);
    check($enqueue($lastReview)->id === $approved->id && count($requests) === $at
        && $managed->events()->where('kind', 'deployment_config_set_acceptance')->count() === 1, 'Completed confirmation replayed side effects');
    check(! array_filter(array_slice($requests, $beforeConfirmation), fn ($request) => $request[0] !== 'GET'), 'Confirmation issued a Wings write, sync, power or restore');
} finally {
    Cache::forgetDriver('arcturus-fixture-file');
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\File::deleteDirectory($cacheDir);
}
echo "Queued confirmation: 206+ files, request/worker boundary, duplicate and delayed delivery, stale checkpoints, network and SQL failures, FileStore leases and read-only completion passed\n";
