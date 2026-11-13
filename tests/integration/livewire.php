<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
$source = dirname(__DIR__, 2);
use App\Models\User;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;

Schema::create('webhook_configurations', function (Blueprint $t) {
    $t->id();
    $t->string('scope');
    $t->json('events');
    $t->softDeletes();
});
Schema::create('servers', function (Blueprint $t) {
    $t->id();
    $t->string('uuid');
    $t->string('name');
});
foreach (glob($source.'/database/migrations/*.php') as $path) {
    (require $path)->up();
}
Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool
    {
        return true;
    }

    public function checkPermissionTo($permission, $guardName = null): bool
    {
        return true;
    }
});
$admin = Filament::getPanel('admin');
(new ModpackManagerPlugin)->register($admin);
Filament::setCurrentPanel($admin);
foreach (['index', 'create', 'view', 'edit'] as $page) {
    Route::get('/fixture/'.$page.'/{record?}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.'.$page);
}
app('view')->addNamespace('arcturus-modpack-manager', $source.'/resources/views');
$record = ManagedServer::create(['label' => 'Customized GTNH · Crucible', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'provider' => 'github', 'source' => 'GTNewHorizons/GT-New-Horizons-Modpack', 'installed_version' => '2.8.0', 'available_version' => '2.9.0', 'baseline_status' => 'verified', 'adopted_at' => now(), 'runtime_report' => ['loader' => 'crucible', 'minecraft' => '1.7.10', 'java' => 17, 'image' => 'ghcr.io/example/java:17', 'startup' => 'bash startserver.sh', 'observed_at' => now()->toIso8601String()], 'release_metadata' => ['name' => 'GTNH 2.9.0', 'notes' => 'Fixture release notes. Review migration requirements.'], 'preview_summary' => ['from' => '2.8.0', 'to' => '2.9.0', 'counts' => ['add' => 10, 'update' => 128, 'remove' => 4, 'preserve_local_change' => 32, 'skip_disabled' => 3, 'conflict' => 2], 'changes' => [['path' => 'config/Paradigm/'.str_repeat('long-path-', 12).'/server.properties', 'action' => 'conflict', 'classification' => 'merge_conflict', 'blocked' => true, 'base_sha256' => str_repeat('a', 64), 'local_sha256' => str_repeat('b', 64), 'target_sha256' => str_repeat('c', 64)]]]]);
$test = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->id]);
$directUpdates = Livewire\Livewire::withQueryParams(['tab' => 'updates'])->test(ViewManagedServer::class, ['record' => $record->id]);
check($directUpdates->get('tab') === 'updates', 'Console deep link did not open the Updates tab');
$snapshotDir = getenv('ARCTURUS_UI_SNAPSHOTS');
if ($snapshotDir && ! is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0700, true);
}
foreach (['overview', 'updates', 'changes', 'mods', 'protection', 'backups', 'history'] as $tab) {
    $test->call('selectTab', $tab);
    $html = $test->html();
    check(str_contains($html, 'Modpack management'), 'Native tab rendering failed: '.$tab);
    if ($tab === 'updates') {
        $dom = new DOMDocument;
        @$dom->loadHTML('<meta charset="utf-8">'.$html);
        check((new DOMXPath($dom))->query('//button[contains(., "Update with backup") and @disabled]')->length === 1,
            'Destructive update control was not disabled');
    }
    if ($snapshotDir) {
        $manifest = json_decode(file_get_contents(getenv('PELICAN_SOURCE').'/public/build/manifest.json'), true);
        $css = $manifest['resources/css/app.css']['file'];
        file_put_contents($snapshotDir.'/'.$tab.'.html', '<!doctype html><html lang="en" class="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/'.$css.'"></head><body class="bg-gray-50 text-gray-950 dark:bg-gray-950 dark:text-white" style="padding:16px">'.Blade::render('@filamentStyles').$html.'</body></html>');
    }
}
$test->call('selectTab', 'overview')->call('mountAction', 'filesystemBackend');
$test->set('mountedActions.0.data.transport', 'local')->call('callMountedAction');
check($record->refresh()->transport === 'wings', 'Missing local config changed the backend');
$localDir = sys_get_temp_dir().'/arcturus-local-ui-'.bin2hex(random_bytes(6));
mkdir($localDir, 0700);
mkdir($localDir.'/live', 0700);
mkdir($localDir.'/stage', 0700);
config(['arcturus-modpack-manager.local_servers' => [$record->pelican_server_uuid => ['root' => $localDir.'/live', 'staging' => $localDir.'/stage']]]);
$test->call('mountAction', 'filesystemBackend')->set('mountedActions.0.data.transport', 'local')->call('callMountedAction');
check($record->refresh()->transport === 'local' && $record->baseline_status === 'unverified', 'Native local backend action did not pin paths and invalidate baseline');
$test->call('mountAction', 'testFilesystem')->call('callMountedAction');
check(($test->get('nodeCapabilities')['configuration_ready'] ?? false) === true, 'Native filesystem test did not invoke local capability check');
Bus::fake([PrepareUpdate::class]);
config(['arcturus-modpack-manager.local_servers' => []]);
$test->call('mountAction', 'baseline')->call('callMountedAction');
check(Bus::dispatched(PrepareUpdate::class)->isEmpty() && ! $record->refresh()->active_operation_id, 'Missing local mapping queued a doomed job through native UI');
$test->call('mountAction', 'filesystemBackend')->set('mountedActions.0.data.transport', 'wings')->call('callMountedAction');
check($record->refresh()->transport === 'wings', 'Native remote backend selection failed');
rmdir($localDir.'/stage'); rmdir($localDir.'/live'); rmdir($localDir);
$progressOperation = Operation::create([
    'id' => (string) Str::uuid(), 'managed_server_id' => $record->id, 'kind' => 'baseline',
    'phase' => 'preparing', 'heartbeat_at' => now(),
    'context' => ['progress' => ['files_resolved' => 3, 'required_files' => 4]],
]);
$record->update(['active_operation_id' => $progressOperation->id]);
$test->call('selectTab', 'overview');
$legacyChild = Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id]);
check((bool) preg_match('~3\s*/\s*4 required files resolved~', $legacyChild->html()), 'Required-file progress counters were not rendered');
// Independently polled child renders only persisted, sanitized state.
$progressOperation->update(['node_operation_id' => str_repeat('a', 32)]);
\Arcturus\ModpackManager\Services\PreparationProgress::record($progressOperation, ['operation_id' => str_repeat('a', 32),
    'progress' => ['phase' => 'downloading', 'bytes_done' => 1048576, 'bytes_total' => null, 'speed_bytes_per_second' => 1048576, 'updated_at' => time()]]);
$child = Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id]);
check(str_contains($child->html(), '1.00 MiB/s') && str_contains($child->html(), 'Working — total not known yet'), 'Native download telemetry or unknown-total indicator missing');
$dom = new DOMDocument;
@$dom->loadHTML($child->html());
check((new DOMXPath($dom))->query('//progress')->length === 0, 'Unknown download total rendered a fabricated percentage');
$progressOperation->update(['cancel_requested_at' => now()]);
$child->call('$refresh');
check(str_contains($child->html(), 'Cancellation requested'), 'Cancellation intent did not survive refresh');
// Regression fixtures exercise rendered states, not just percentage arithmetic.
$downloadSnapshot = ['phase' => 'downloading', 'bytes_done' => 520761531, 'bytes_total' => 520761531,
    'downloaded_bytes' => 520761531, 'download_total_bytes' => 520761531,
    'speed_bytes_per_second' => 12582912, 'eta_seconds' => 3, 'updated_at' => time() - 120, 'finished' => true];
$extractionSnapshot = ['phase' => 'extracting', 'bytes_done' => 900000000, 'bytes_total' => 900000000,
    'files_done' => 4321, 'files_total' => 4321, 'entries_validated' => 4500, 'finished' => true];
$hashingSnapshot = ['phase' => 'hashing', 'files_done' => 0, 'files_total' => null,
    'bytes_done' => 0, 'bytes_total' => null, 'updated_at' => time()];
$progressOperation->update(['cancel_requested_at' => null, 'phase' => 'hashing', 'progress' => [
    'current' => $hashingSnapshot, 'stages' => ['downloading' => $downloadSnapshot, 'extracting' => $extractionSnapshot, 'hashing' => $hashingSnapshot]]]);
$child->call('$refresh');
$html = $child->html();
check(str_contains($html, 'Counting staged files') && ! str_contains($html, '0 / unknown'), 'Hashing reused an unknown count placeholder');
check(str_contains($html, '4,321 / 4,321 files processed') && str_contains($html, '4,500 archive entries validated'), 'Extraction lost independently recorded counts');
check(str_contains($html, 'Last recorded speed') && str_contains($html, '12.00 MiB/s') && str_contains($html, 'Last recorded ETA') && str_contains($html, '~00:00:03'), 'Historical download metrics were hidden or extrapolated');
$dom = new DOMDocument; @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
check($xpath->query('//li[@data-phase="hashing"]//progress')->length === 0 && $xpath->query('//li[@data-phase="extracting"]//progress')->length === 0, 'Unknown hashing/completed extraction rendered a misleading bar');
check($xpath->query('//details/summary[contains(., "Technical details")]')->length === 1 && $xpath->query('//details[@open]')->length === 0, 'Technical output is not collapsed');
// The real failure case: the snapshot may be marked finished but lack a total.
$hashingSnapshot['finished'] = true;
$progressOperation->update(['phase' => 'failed', 'completed_at' => now(), 'progress' => [
    'current' => $hashingSnapshot, 'stages' => ['downloading' => $downloadSnapshot, 'extracting' => $extractionSnapshot, 'hashing' => $hashingSnapshot],
    'diagnostic' => ['code' => 'PERMISSION_DENIED', 'phase' => 'hashing', 'context' => ['file' => '/private/token=secret']]]]);
$beforeRender = $progressOperation->fresh()->getRawOriginal();
$child->call('$refresh'); $html = $child->html();
$dom = new DOMDocument; @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
check($xpath->query('//progress')->length === 0 && ! str_contains($html, 'data-progress="indeterminate"') && ! str_contains($html, 'Counting staged files'), 'Failed operation retained an active/full indeterminate bar');
check(str_contains($xpath->query('//li[@data-phase="hashing"]')->item(0)->textContent, 'Failed'), 'Failed hashing was labeled completed');
check(str_contains($xpath->query('//li[@data-phase="extracting"]')->item(0)->textContent, 'Completed'), 'Earlier successful extraction lost completion status');
check(! str_contains($html, '/private') && ! str_contains($html, 'token=secret'), 'Technical details exposed unsafe diagnostic data');
check($beforeRender === $progressOperation->fresh()->getRawOriginal(), 'Presentation changed persisted operation state');
// Verified pack files do not imply an unavailable loader runtime is ready.
$progressOperation->update(['phase' => 'completed', 'completed_at' => now(), 'progress' => ['runtime_preparation' => ['state' => 'blocked', 'message' => 'No trusted Java 25 runtime is available']]]);
$child->call('$refresh');
check(str_contains($child->html(), 'Runtime blocked') && str_contains($child->html(), 'No trusted Java 25'), 'Completed pack hashes hid the runtime blocker');
// Known active totals have a measured phase percentage; terminal states have status badges only.
foreach (['hashing' => null, 'completed' => now(), 'cancelled' => now(), 'interrupted' => now()] as $state => $completedAt) {
    $progressOperation->update(['phase' => $state, 'completed_at' => $completedAt, 'progress' => [
        'current' => ['phase' => 'hashing', 'bytes_done' => 50, 'bytes_total' => 200, 'files_done' => 1, 'files_total' => 4, 'updated_at' => time()],
        'stages' => ['downloading' => $downloadSnapshot]]]);
    $child->call('$refresh'); $html = $child->html();
    $dom = new DOMDocument; @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
    check($xpath->query('//progress[@value="25"]')->length === ($completedAt ? 0 : 1), 'Measured active/terminal phase bar is incorrect: '.$state);
    if ($completedAt) { check(str_contains($html, ucfirst($state)), 'Terminal status missing: '.$state); }
}
$progressOperation->update(['phase' => 'downloading', 'completed_at' => null, 'progress' => [
    'current' => ['phase' => 'downloading', 'bytes_done' => 100, 'bytes_total' => null, 'updated_at' => time() - 120]]]);
$child->call('$refresh');
check(str_contains($child->html(), 'Waiting for the next progress sample') && ! str_contains($child->html(), 'MiB/s') && ! str_contains($child->html(), '~00:'), 'Stale/absent samples fabricated live speed or ETA');
$diagnosticOperation = Arcturus\ModpackManager\Models\Operation::create([
    'id' => (string) Str::uuid(), 'managed_server_id' => $record->id, 'node_operation_id' => str_repeat('a', 32),
    'kind' => 'prepare', 'phase' => 'failed', 'completed_at' => now(),
    'context' => ['recipe' => ['url' => 'https://private.example/?token=never-export-this'],
        'diagnostic' => ['code' => 'HTTP_STATUS', 'phase' => 'queued', 'context' => ['http_status' => 504]]],
]);
$record->update(['active_operation_id' => $diagnosticOperation->id]);
$statusComponent = new Arcturus\ModpackManager\Livewire\PreparationStatus;
$statusComponent->mount($record->id);
$export = $statusComponent->exportDiagnostics();
check(str_contains($export, $diagnosticOperation->id) && str_contains($export, '504') &&
    ! str_contains($export, 'never-export-this') && ! str_contains($export, 'private.example'), 'Diagnostic export exposed private recipe or lost correlation/status');
$diagnosticOperation->update(['context' => ['recipe' => ['url' => 'https://private.example/?token=never-export-this'],
    'diagnostic' => ['code' => 'DOWNLOAD_POLICY', 'phase' => 'downloading', 'context' => ['reason' => 'REDIRECT_HOST_NOT_ALLOWED', 'host' => 'cdn.example.test', 'url' => 'https://cdn.example.test/file?token=never-export-this']]]]);
$policyExport = $statusComponent->exportDiagnostics();
check(str_contains($policyExport, 'REDIRECT_HOST_NOT_ALLOWED') && str_contains($policyExport, 'cdn.example.test') && str_contains($policyExport, 'current_backend') &&
    ! str_contains($policyExport, 'never-export-this') && ! str_contains($policyExport, 'https://'), 'Policy export dropped its safe host/reason or exposed signed URLs');
// A completed adoption comparison must not masquerade as a ready target update.
$diagnosticOperation->update(['kind' => 'comparison', 'phase' => 'completed', 'completed_at' => now()]);
$record->events()->create(['kind' => 'comparison', 'status' => 'completed', 'message' => 'Historical installation comparison', 'details' => ['operation_id' => $diagnosticOperation->id]]);
$record->update(['active_operation_id' => null, 'target_manifest_path' => null, 'transport' => 'native']);
$targetChild = Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id, 'purpose' => 'target']);
check(! str_contains($targetChild->html(), 'Installation comparison'), 'Old adoption comparison was shown as target readiness');
$test->call('selectTab', 'overview');
check(str_contains($test->html(), 'Update backend: Native Pelican / Wings') && ! str_contains($test->html(), 'Complete Local filesystem setup once'), 'Native overview still requires legacy host setup');
$test->call('selectTab', 'backups');
check(! str_contains($test->html(), 'Installation comparison') && ! str_contains($test->html(), 'outside-volume preservation is available') && str_contains($test->html(), 'without truncation'), 'Native recovery screen retained obsolete or unrelated status');
// Corrupt/unreadable encrypted evidence must not crash the deployment status UI.
$brokenDeployment = Operation::create(['id' => (string) Str::uuid(), 'managed_server_id' => $record->id,
    'kind' => 'deploy', 'phase' => 'verifying', 'context' => ['from' => 'old', 'to' => 'new'], 'heartbeat_at' => now()]);
\Illuminate\Support\Facades\DB::table('arcturus_modpack_operations')->where('id', $brokenDeployment->id)
    ->update(['context' => '{"plaintext":"must-not-be-trusted"}']);
$badStatus = Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\DeploymentStatus::class, ['managedServerId' => $record->id]);
check(str_contains($badStatus->html(), 'encrypted deployment receipt cannot be read') &&
    str_contains($badStatus->html(), $brokenDeployment->id) && ! str_contains($badStatus->html(), 'must-not-be-trusted'),
    'Unreadable receipt crashed status or exposed untrusted plaintext');
check(\Illuminate\Support\Facades\DB::table('arcturus_modpack_operations')->where('id', $brokenDeployment->id)->value('context') === '{"plaintext":"must-not-be-trusted"}',
    'Status rendering rewrote recovery evidence');
Auth::guard()->forgetUser();
rejects(fn () => Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->id]), 'Guest mounted the managed-server review page');
echo "All native Livewire detail tabs rendered; destructive control and guest access guards passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
