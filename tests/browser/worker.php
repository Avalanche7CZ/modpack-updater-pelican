<?php
/** Native queue consumption for the browser fixture; never performs a live server scan. */
declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Http;
$server = ManagedServer::lightweight()->findOrFail(1);
config(['arcturus-modpack-manager.nodes.'.$server->pelican_server_uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('x', 64)], 'queue.failed.driver' => null]);
if (($argv[1] ?? '') === 'stale') {
    app(Arcturus\ModpackManager\Services\ServerPolicyService::class)->save($server, [...($server->file_rules ?? []), ['scope' => 'exact', 'pattern' => 'config/protected.cfg', 'mode' => 'protect']], [], $server->policy_revision);
    exit;
}
Http::preventStrayRequests();
Http::fake(function ($request) use ($directory) {
    if (str_ends_with($request->url(), '/capabilities')) { return Http::response(['directory_policy' => 1, 'scoped_inventory' => 1]); }
    if (! str_ends_with($request->url(), '/inventory')) { throw new RuntimeException('Unexpected fixture HTTP request'); }
    $inventory = json_decode(file_get_contents($directory.'/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
    $inventory['files'] = array_intersect_key($inventory['files'], array_fill_keys($request['paths'], true));
    $inventory['missing'] = array_values(array_diff($request['paths'], array_keys($inventory['files'])));
    $inventory['scope'] = 'manifest-delta-v1'; $inventory['requested_paths'] = $request['paths'];
    return Http::response($inventory, 200);
});
app('queue.worker')->runNextJob('database', 'default', new Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
$server->refresh();
if ($server->active_operation_id || $server->comparison_status !== 'complete') { throw new RuntimeException('Queued browser comparison failed'); }
echo "Native queued comparison completed\n";
