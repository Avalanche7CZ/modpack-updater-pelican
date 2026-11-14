<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Native\DeploymentMetrics;
use Arcturus\ModpackManager\Native\DeploymentReceipt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$managed = ManagedServer::create(['pelican_server_uuid' => (string) Str::uuid(), 'label' => 'Receipt fixture', 'provider' => 'github', 'source' => 'fixture', 'installed_version' => 'old']);
$op = Operation::create(['id' => (string) Str::uuid(), 'managed_server_id' => $managed->id, 'kind' => 'deploy', 'phase' => 'applying', 'context' => ['native_execution_id' => 'owned', 'token' => 'must-not-export']]);
$metrics = new DeploymentMetrics; $receipt = new DeploymentReceipt($op, 'owned', $metrics); $queries = [];
DB::listen(function ($q) use (&$queries): void { $queries[] = strtolower($q->sql); });
$receipt->save(fn ($ctx) => ['context' => [...$ctx, 'native_checkpoint' => ['step' => 'upload_batch_intent']]]);
$queries = [];
for ($i = 0; $i < 100; $i++) { $receipt->heartbeat(); }
check($queries === [], 'Fast stream blocks still issue redundant heartbeat SQL');
$receipt->heartbeat(true);
check(count($queries) === 1 && str_starts_with($queries[0], 'select'), 'Mutation ownership check skipped or wrote a redundant heartbeat');
$fresh = $op->fresh(); $fresh->update(['context' => [...$fresh->context, 'external' => 'keep']]);
$receipt->save(fn ($ctx) => ['context' => [...$ctx, 'native_checkpoint' => ['step' => 'upload_batch_verified']]]);
check($op->fresh()->context['external'] === 'keep', 'Checkpoint overwrote fresh context');
// Eloquent failure after ownership read must not be retried by the receipt transaction.
$attempts = 0; $rejectSave = true;
Operation::updating(function (Operation $row) use ($op, &$attempts, &$rejectSave): void {
    if ($row->id === $op->id && $rejectSave) { $attempts++; throw new RuntimeException('ambiguous checkpoint save'); }
});
rejects(fn () => $receipt->save(fn () => []), 'Failed checkpoint accepted');
check($attempts === 1, 'Checkpoint transaction retried an unclear write'); $rejectSave = false;
$fresh = $op->fresh(); $fresh->update(['context' => [...$fresh->context, 'native_execution_id' => 'foreign']]);
rejects(fn () => $receipt->save(fn () => []), 'Foreign execution could checkpoint');
rejects(fn () => $receipt->heartbeat(true), 'Foreign execution could mutate');
$fresh = $op->fresh(); $fresh->update(['context' => [...$fresh->context, 'native_execution_id' => 'owned'], 'completed_at' => now()]);
rejects(fn () => $receipt->save(fn () => []), 'Closed operation could checkpoint');
$metrics->phase('finished'); $fresh = $op->fresh(); $fresh->update(['context' => [...$fresh->context, 'native_metrics' => $metrics->snapshot()]]);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\NativeMeasurements);
$encryptedBefore = $op->fresh()->getRawOriginal('context');
check(Artisan::call('arcturus-modpack:native-measurements', ['operation' => $op->id]) === 0, 'Measurement export failed');
$export = Artisan::output(); check(! str_contains($export, 'must-not-export') && is_array(json_decode($export, true, flags: JSON_THROW_ON_ERROR)['metrics']), 'Measurement export leaked unrelated context');
check($encryptedBefore === $op->fresh()->getRawOriginal('context'), 'Read-only export changed receipt');
$rendered = view('arcturus-modpack-manager::deployment-status', ['operation' => $op->fresh(), 'context' => $op->fresh()->context, 'receiptReadable' => true])->render();
check(str_contains($rendered, 'Native deployment measurements') && str_contains($rendered, 'Transferred MiB') && str_contains($rendered, 'db checkpoint') && ! str_contains($rendered, 'must-not-export'), 'Deployment status did not render recorded telemetry safely');
echo "Native receipt: throttled pulses, forced ownership, fresh merge, no DB retry, terminal/foreign fences and read-only metrics export passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
