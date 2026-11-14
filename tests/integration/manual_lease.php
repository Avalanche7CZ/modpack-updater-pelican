<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';
use Arcturus\ModpackManager\Native\{DeploymentLease, FileLeaseRepair};
use Illuminate\Support\Facades\{Cache, Log};

$directory = sys_get_temp_dir().'/arcturus-file-lease-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
config(['cache.stores.lease_fixture' => ['driver' => 'file', 'path' => $directory.'/cache', 'lock_path' => $directory.'/locks'], 'cache.default' => 'lease_fixture']);
\Illuminate\Support\Facades\DB::table('servers')->insert(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Parent lease fixture']);
$managed = (new \Arcturus\ModpackManager\Models\ManagedServer)->forceFill(['id' => 2, 'label' => 'Parent lease fixture', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'provider' => 'github', 'source' => 'fixture', 'installed_version' => '1', 'transport' => 'native']); $managed->save();
$operation = \Arcturus\ModpackManager\Models\Operation::create(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab', 'managed_server_id' => 2, 'kind' => 'deploy', 'phase' => 'manual_intervention', 'context' => []]);
$managed->update(['active_operation_id' => $operation->id]);
$invoke = fn ($mutation, $work) => (new ReflectionMethod(\Arcturus\ModpackManager\Native\ManualStart::class, 'locked'))->invoke(new \Arcturus\ModpackManager\Native\ManualStart, $operation->id, $mutation ? 'handoff_apply' : 'handoff_read', $work, $mutation);
$events = []; Log::listen(function ($event) use (&$events): void { if ($event->message === 'Arcturus deployment lease') { $events[] = $event->context; } });
$repair = new FileLeaseRepair;
$child = function (string $mode, int $signal) use ($directory): void {
    $process = proc_open([getenv('ARCTURUS_TEST_PHP') ?: PHP_BINARY, __DIR__.'/fixtures/file_lease_child.php', $directory, $mode], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    check(is_resource($process), 'Child process unavailable');
    try {
        stream_set_timeout($pipes[1], 15);
        if (trim((string) fgets($pipes[1])) !== 'READY') { proc_terminate($process, 9); throw new RuntimeException('Child did not reach the lease/read boundary: '.stream_get_contents($pipes[2])); }
        if (! in_array($mode, ['legacy', 'apply'], true)) {
            $start = new DeploymentLease(2, 'fixture', 'concurrent_start');
            check($start->get(), 'Read/observer blocked a concurrent mutation/Start'); check($start->release(), 'Concurrent Start release failed');
        }
        check(proc_terminate($process, $signal), 'Child termination failed');
    } finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); }
};
try {
    foreach ([false, true] as $mutation) {
        check($invoke($mutation, fn () => 'returned') === 'returned', 'ManualStart normal return failed');
        rejects(fn () => $invoke($mutation, fn () => throw new RuntimeException('expected fixture exception')), 'ManualStart fixture exception was swallowed');
        $probe = new DeploymentLease(2, 'fixture', 'after_manual_exception'); check($probe->get() && $probe->release(), 'ManualStart exception leaked its mutation lease');
    }
    $invoke(false, function () use ($operation): void {
        $fresh = $operation->fresh(); $fresh->update(['context' => [...$fresh->context, 'native_metrics' => ['diagnostic' => 'changed during read']]]);
    });
    rejects(fn () => $invoke(false, function () use ($operation): void {
        $fresh = $operation->fresh(); $fresh->update(['context' => [...$fresh->context, 'native_checkpoint' => ['step' => 'changed']]]);
    }), 'Read fence accepted a changed recovery journal');
    $unrelated = new DeploymentLease(99, 'unrelated', 'unrelated_server'); check($unrelated->get(), 'Unrelated lease unavailable');
    $lease = new DeploymentLease(2, 'fixture', 'normal_return');
    check($lease->get() && $lease->release(), 'Normal FileStore release failed');
    $lease = new DeploymentLease(2, 'fixture', 'exception');
    try { check($lease->get(), 'Exception fixture acquire failed'); throw new RuntimeException('fixture'); }
    catch (RuntimeException) {} finally { check($lease->release(), 'Exception finally failed to release FileStore'); }
    echo "FileStore normal return and exception: release=true, immediate reacquire passed\n";
    foreach ([15, 9] as $signal) {
        $child('legacy', $signal);
        check(Cache::getStore() instanceof \Illuminate\Cache\FileStore, 'Fixture is not real FileStore');
        $probe = new DeploymentLease(2, 'fixture', 'legacy_probe'); check(! $probe->get(), 'Interrupted legacy dry-run did not reproduce orphan: '.file_get_contents($directory.'/child-contract.json'));
        $record = $repair->inspect(2, 'fixture');
        check(strtotime($record['expires_at_utc']) - time() >= 14380, 'Legacy interruption did not retain four-hour TTL');
        rejects(fn () => $repair->expire(2, 'different-checkpoint', $record['proof'], fn () => null), 'Changed checkpoint was cleared');
        $repair->expire(2, 'fixture', $record['proof'], fn () => null);
        $new = new DeploymentLease(2, 'fixture', 'replacement'); check($new->get(), 'Exact orphan expiry failed');
        rejects(fn () => $repair->expire(2, 'fixture', $record['proof'], fn () => null), 'Changed owner was cleared');
        check($new->isOwnedByCurrentProcess() && $new->release(), 'Replacement owner was damaged');
    }
    echo "v0.5.100 FileStore SIGTERM/SIGKILL: orphan TTL=14400 reproduced; conditional expiry refuses changed checkpoint/owner\n";
    foreach (['read', 'observer'] as $mode) { foreach ([15,9] as $signal) {
        $child($mode, $signal);
        $probe = new DeploymentLease(2, 'fixture', 'after_interruption'); check($probe->get() && $probe->release(), 'New read/observer left a lease after interruption');
    } }
    $child('apply', 9);
    $probe = new DeploymentLease(2, 'fixture', 'interrupted_apply'); check(! $probe->get(), 'Mutating apply lost long protection');
    $record = $repair->inspect(2, 'fixture'); check(strtotime($record['expires_at_utc']) - time() >= 14380, 'Apply TTL shortened');
    $repair->expire(2, 'fixture', $record['proof'], fn () => null);
    check($unrelated->isOwnedByCurrentProcess() && $unrelated->release(), 'Repair changed an unrelated server lease');
    echo "Mutating apply interruption retains TTL=14400; unrelated server lock unchanged passed\n";
    echo "New read/observer: concurrent Start and SIGTERM/SIGKILL leave no mutation lease passed\n";
    $badFs = new class extends \Illuminate\Filesystem\Filesystem { public function delete($paths) { return false; } };
    Cache::extend('failure-file', fn () => Cache::repository(new \Illuminate\Cache\FileStore($badFs, $directory.'/delete-failure')));
    config(['cache.stores.failure_fixture' => ['driver' => 'failure-file'], 'cache.default' => 'failure_fixture']);
    $bad = new DeploymentLease(2, 'fixture', 'release_failure'); check($bad->get() && ! $bad->release(), 'Real FileStore delete failure was not propagated');
    check((bool) array_filter($events, fn ($event) => $event['event'] === 'release_failure' && $event['release_result'] === false && $event['owned_before_release'] === true), 'Release=false diagnostics missing');
    foreach ($events as $event) { check(isset($event['pid'], $event['action'], $event['at_utc'], $event['operation_id']) && ! isset($event['owner'], $event['token']), 'Lease diagnostics leaked owner or omitted context'); }
    echo "FileStore release=false is reported with action/PID/time, without owner tokens passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($directory);
}
$GLOBALS['arcturus_integration_passed'] = true;
