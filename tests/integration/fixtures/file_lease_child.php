<?php

declare(strict_types=1);
require __DIR__.'/deployment-bootstrap.php';
use Arcturus\ModpackManager\Models\{ManagedServer, Operation};
use Illuminate\Support\Facades\Cache;

$directory = $argv[1]; $mode = $argv[2];
config(['cache.stores.lease_fixture' => ['driver' => 'file', 'path' => $directory.'/cache', 'lock_path' => $directory.'/locks'], 'cache.default' => 'lease_fixture']);
\Illuminate\Support\Facades\DB::table('servers')->insert(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Lease child']);
$managed = (new ManagedServer)->forceFill(['id' => 2, 'label' => 'Lease child', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'provider' => 'github', 'source' => 'fixture', 'installed_version' => '1', 'transport' => 'native']); $managed->save();
$operation = Operation::create(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab', 'managed_server_id' => 2, 'kind' => 'deploy', 'phase' => 'manual_intervention', 'context' => []]);
$managed->update(['active_operation_id' => $operation->id]);
file_put_contents($directory.'/child-contract.json', json_encode(['store' => get_class(Cache::getStore()), 'default' => config('cache.default'), 'directory' => $directory]));
$wait = function (): void { echo "READY\n"; fflush(STDOUT); sleep(90); };
if ($mode === 'legacy') {
    // Exact v0.5.100 lifecycle, including the original finally.
    $lease = Cache::lock('arcturus-modpack-manager-deploy-2', 14400);
    if (! $lease->get()) { throw new RuntimeException('Fixture lock already held'); }
    try { $wait(); } finally { $lease->release(); }
} else {
    (new ReflectionMethod(\Arcturus\ModpackManager\Native\ManualStart::class, 'locked'))->invoke(
        new \Arcturus\ModpackManager\Native\ManualStart, $operation->id, $mode === 'observer' ? 'observe' : ($mode === 'apply' ? 'handoff_apply' : 'handoff_read'), $wait, $mode === 'apply');
}
$GLOBALS['arcturus_integration_passed'] = true;
