<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
$source = dirname(__DIR__, 2);
use App\Models\User;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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
    $t->string('startup')->default('bash run.sh');
    $t->string('image')->default('java_21');
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
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Arcturus\ModpackManager\Services\ServerPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function policySnapshot(string $name, string $html): void
{
    $directory = getenv('ARCTURUS_UI_SNAPSHOTS');
    if (! $directory) {
        return;
    }
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $manifest = json_decode(file_get_contents(getenv('PELICAN_SOURCE').'/public/build/manifest.json'), true);
    $css = $manifest['resources/css/app.css']['file'];
    file_put_contents($directory.'/'.$name.'.html', '<!doctype html><html lang="en" class="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/'.$css.'"></head><body class="bg-gray-50 text-gray-950 dark:bg-gray-950 dark:text-white" style="padding:16px">'.Blade::render('@filamentStyles').$html.'</body></html>');
}

DB::table('servers')->insert(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'FTB Evolution fixture']);
DB::table('servers')->insert(['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Other server']);
Http::preventStrayRequests();
$test = Livewire\Livewire::test(CreateManagedServer::class);
$html = $test->html();
// Reproduce the production failure through the *actual* Livewire method.
// A server-side inspection may return partial fields, or throw and trigger
// the page's fallback array without loader/minecraft keys.
$partialResults = [
    ['state' => 'unavailable', 'inspection_complete' => false,
        'warnings' => ['Wings file inspection failed']],
    ['state' => 'unavailable', 'inspection_complete' => false,
        'diagnostic' => ['stage' => 'wings_root_listing', 'code' => 'HTTP_403'],
        'warnings' => ['Wings file listing failed']],
    ['state' => 'unavailable', 'inspection_complete' => false,
        'diagnostic' => ['stage' => 'wings_root_listing'],
        'warnings' => []],
];
foreach ($partialResults as $partial) {
    // Test the page without creating a real Wings request.
    app()->instance(\Arcturus\ModpackManager\Services\AdoptionService::class,
        new class($partial) {
            public function __construct(private array $response) {}
            public function inspect(\App\Models\Server $server): array
            {
                return $this->response;
            }
        });
    $test->call('inspectSelection', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $rendered = $test->html();
    check(str_contains($rendered, 'Loader not verified') &&
        str_contains($rendered, 'Minecraft version not verified'),
        'Livewire inspect action failed to render missing loader/Minecraft fields');
    if (isset($partial['diagnostic']['code'])) {
        check(str_contains($rendered, 'HTTP_403'),
            'Livewire inspect action did not show sanitized diagnostic');
    }
}
// A throwing inspection triggers the exact fallback array from the log.
app()->instance(\Arcturus\ModpackManager\Services\AdoptionService::class,
    new class {
        public function inspect(\App\Models\Server $server): array
        {
            throw new RuntimeException('Fixture Wings listing unavailable');
        }
    });
$test->call('inspectSelection', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
$rendered = $test->html();
check(str_contains($rendered, 'Loader not verified') &&
    str_contains($rendered, 'Minecraft version not verified'),
    'Page-level inspection exception fallback caused a Livewire render failure');
app()->forgetInstance(\Arcturus\ModpackManager\Services\AdoptionService::class);

$dom = new DOMDocument;
@$dom->loadHTML('<meta charset="utf-8">'.$html);
$xpath = new DOMXPath($dom);
check($xpath->query('//button[@*[name()="wire:click"]="create" and contains(., "Create adoption draft")]')->length === 1, 'Wizard final action is not actually rendered');
$test->set('data.label', 'My customized FTB')->set('data.pelican_server_uuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
// Advance the real native wizard; every step validates through its actual schema.
$wizard = $test->instance()->form->getComponents()[0];
$key = $wizard->getKey();
$test->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 0]);
$test->set('data.provider', 'ftb')->set('data.source', '129')->set('data.installed_version', '1234');
$test->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1]);
$test->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 2]);
$html = $test->html();
policySnapshot('adoption-review', $html);
check(str_contains($html, 'FTB Evolution fixture') && str_contains($html, '129') && str_contains($html, 'baseline is unverified'), 'Final review summary missing');
$test->call('create');
$record = ManagedServer::first();
check($record !== null && $record->label === 'My customized FTB' && ! $record->adopted_at && ! $record->enabled, 'Visible wizard action did not create a database-only draft');
check($record->backup_mode === 'pelican' && count($record->file_rules) > 0 && $record->baseline_status === 'unverified', 'Wizard policy/backup defaults were not saved');
check(! Http::recorded()->count(), 'Unavailable fixture runtime caused an unapproved network request');
$test2 = Livewire\Livewire::test(CreateManagedServer::class);
$test2->set('data.label', 'Monitoring')->set('data.pelican_server_uuid', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
    ->set('data.provider', 'modrinth')->set('data.source', 'example')->set('data.installed_version', 'exact-id')
    ->set('data.file_rules', [])->set('data.adoption_mode', 'monitor');
check(str_contains($test2->html(), 'Adopt for monitoring only'), 'Monitoring submit label not rendered');
$test2->call('create');
$other = ManagedServer::where('label', 'Monitoring')->first();
check($other !== null && $other->enabled && $other->adopted_at && ! $other->deploy_enabled && $other->file_rules === [] && $other->baseline_status === 'unverified', 'Monitoring-only wizard adoption falsely verified or lost empty policies');
rejects(fn () => app(DeploymentGate::class)->require($other, str_repeat('a', 64)), 'Unverified adoption permitted deployment');
$invalid = Livewire\Livewire::test(CreateManagedServer::class)->set('data.adoption_mode', 'invalid')->call('create');
check($invalid->instance()->getErrorBag()->isNotEmpty() && ManagedServer::count() === 2, 'Final submission bypassed validation');

$record->update(['approved_plan_sha256' => str_repeat('a', 64), 'approved_at' => now(), 'preview_summary' => ['changes' => []]]);
$prior = $record->file_rules;
app(ServerPolicyService::class)->save($record, [['scope' => 'mod_id', 'pattern' => 'my_extra', 'mode' => 'protect']], ['disabled_example'], $record->policy_revision);
$record->refresh();
$other->refresh();
check($record->file_rules[0]['pattern'] === 'my_extra' && $other->file_rules === [], 'Policies leaked between servers or failed model reload');
check(! $record->approved_plan_sha256 && ! $record->approved_at && $record->preview_summary === ['changes' => []] && $record->policy_revision === 1, 'Policy edit did not invalidate the plan');
$event = $record->events()->where('kind', 'policy')->first();
check($event && $event->details['before']['file_rules'] === $prior && $event->details['after']['file_rules'] === $record->file_rules, 'Policy audit before/after missing');
rejects(fn () => app(ServerPolicyService::class)->save($record, [], [], 0), 'Stale policy edit overwrote newer policy');
// Exercise the actual Filament action modal and bulk form, not just its service.
$record->update(['file_inventory' => ['files' => ['mods/manual-1.jar' => str_repeat('a', 64), 'mods/another.jar' => str_repeat('b', 64)], 'identities' => ['mods/manual-1.jar' => 'manual', 'mods/another.jar' => 'another'],
    'metadata' => ['mods/manual-1.jar' => ['name' => 'Manual mod', 'version' => '1.0', 'loader' => 'forge']], 'excluded_roots' => [], 'observed_at' => now()->toIso8601String()]]);
$view = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->id])->call('selectTab', 'mods');
policySnapshot('mods-files', $view->html());
check(str_contains($view->html(), 'Manual mod') && str_contains($view->html(), 'manual-1.jar'), 'Discovered mod inventory not rendered');
$view->set('selectedPaths', ['mods/manual-1.jar', 'mods/another.jar'])->call('mountAction', 'filePolicy');
$view->set('mountedActions.0.data.mode', 'protect')->call('callMountedAction');
check($record->refresh()->file_rules[1]['scope'] === 'mod_id' && $record->file_rules[1]['pattern'] === 'manual', 'Bulk UI did not save a rename-stable mod-ID policy');
$view->set('selectedPaths', ['mods/manual-1.jar'])->call('mountAction', 'filePolicy')->set('mountedActions.0.data.mode', 'remove')->call('callMountedAction');
check($view->instance()->getErrorBag()->isNotEmpty() && $record->refresh()->file_rules[1]['mode'] === 'protect', 'Destructive bulk policy did not require dependency-review confirmation');
$view->set('mountedActions.0.data.confirm', true)->call('callMountedAction');
check($record->refresh()->file_rules[2]['pattern'] === 'manual' && $record->file_rules[2]['mode'] === 'remove', 'Confirmed destructive bulk policy not saved');
$policyBeforeTest = $record->policy_revision;
$view->call('selectTab', 'protection')->call('mountAction', 'editPolicies');
$modalHtml = $view->effects['partials']['action-modals'] ?? '';
policySnapshot('policy-editor', $modalHtml);
check(str_contains($modalHtml, 'Advanced rules') && str_contains($modalHtml, 'Test rule'), 'Native rule editor or Test rule action did not render');
$modalDom = new DOMDocument;
@$modalDom->loadHTML('<meta charset="utf-8">'.$modalHtml);
$buttons = (new DOMXPath($modalDom))->query('//button[contains(@*[name()="wire:click"], "testRule")]');
$click = $buttons->item($buttons->length - 1)->getAttribute('wire:click');
preg_match_all("/JSON\\.parse\\('([^']+)'\\)/", $click, $encoded);
$decode = fn ($value) => json_decode(json_decode('"'.$value.'"', true, flags: JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
$view->call('mountAction', 'testRule', $decode($encoded[1][0]), $decode($encoded[1][1]));
$testHtml = implode('', $view->effects['partials'] ?? []);
check(str_contains($testHtml, '1 matching files') && str_contains($testHtml, 'manual-1.jar'), 'Test rule did not execute against the discovered server inventory');
check($record->refresh()->policy_revision === $policyBeforeTest, 'Test rule changed saved policies');
$view->call('unmountAction')->call('unmountAction');
$revision = $record->refresh()->policy_revision;
// Use the real preview service and offline deployer on a disposable local fixture.
$dir = sys_get_temp_dir().'/arcturus-policy-'.bin2hex(random_bytes(6));
foreach (['live/config', 'live/custom-world/region', 'stage/config', 'backups'] as $folder) {
    mkdir($dir.'/'.$folder, 0700, true);
}
file_put_contents($dir.'/live/config/example.properties', 'value=custom');
file_put_contents($dir.'/live/server.properties', "level-name=custom-world\n");
file_put_contents($dir.'/live/custom-world/region/r.0.0.mca', 'world-data');
file_put_contents($dir.'/stage/config/example.properties', 'value=new');
$hash = fn ($s) => hash('sha256', $s);
$runtime = ['loader' => 'forge', 'loader_version' => '47.0', 'minecraft' => '1.20.1', 'java' => 17, 'java_confidence' => 'verified', 'runtime_prepared' => true];
$makeManifest = fn ($v, $content) => ['schema' => 2, 'provider' => 'ftb', 'project' => '129', 'version' => $v,
    'verification' => 'upstream-checksums', 'files' => ['config/example.properties' => $hash($content)], 'runtime' => $runtime,
    'artifacts' => [['sha256' => $hash($content), 'hashes' => ['sha256' => $hash($content)]]]];
$store = app(ReleaseStore::class);
$record->update(['transport' => 'local', 'local_root' => $dir.'/live', 'selected_version' => '1235',
    'base_manifest_path' => $store->put($makeManifest('1234', 'value=old')), 'target_manifest_path' => $store->put($makeManifest('1235', 'value=new')),
    'baseline_status' => 'verified', 'adopted_at' => now(), 'runtime_report' => $runtime,
    'file_rules' => [['scope' => 'exact', 'pattern' => 'config/example.properties', 'mode' => 'protect']]]);
config(['arcturus-modpack-manager.local_server_roots.'.$record->pelican_server_uuid => $dir.'/live']);
$previewer = app(PreviewService::class);
$firstPreview = $previewer->generate($record);
check($firstPreview['changes'][0]['action'] === 'preserve', 'Preview ignored keep-local policy');
app(ServerPolicyService::class)->save($record, [['scope' => 'exact', 'pattern' => 'config/example.properties', 'mode' => 'replace']], [], $record->policy_revision);
$record->refresh();
$secondPreview = $previewer->generate($record);
check($secondPreview['changes'][0]['action'] === 'replace_with_override' && $firstPreview['plan_sha256'] !== $secondPreview['plan_sha256'], 'Policy edit did not change preview and approval digest');
$deployer = new LocalDeployment;
$applied = $deployer->apply($dir.'/live', $dir.'/stage', $dir.'/backups', $makeManifest('1234', 'value=old')['files'], $makeManifest('1235', 'value=new')['files'],
    $record->file_rules, true, null, $secondPreview['policy_context'], $secondPreview['changes']);
check($applied['plan']['changes'] === $secondPreview['changes'] && file_get_contents($dir.'/live/config/example.properties') === 'value=new', 'Preview/deploy used different normalized decisions');
check(file_get_contents($dir.'/live/custom-world/region/r.0.0.mca') === 'world-data', 'Offline fixture changed a custom world');
rejects(fn () => $deployer->apply($dir.'/live', $dir.'/stage', $dir.'/backups', $makeManifest('1234', 'value=old')['files'], $makeManifest('1235', 'value=new')['files'],
    [], true, null, $secondPreview['policy_context'], $secondPreview['changes']), 'Deployer accepted decisions different from preview');
// Review a discovered directory through the native adoption action, preserving upstream evidence.
$originalBase = $record->base_manifest_path;
$originalTarget = $record->target_manifest_path;
$withDirectory = function ($version, $value) use ($makeManifest, $hash) {
    $manifest = $makeManifest($version, $value);
    $manifest['files']['tacz/assets/gun.json'] = $hash('{}');
    $manifest['directory_discovery'] = [['path' => 'config', 'classification' => 'standard'], ['path' => 'tacz', 'classification' => 'review'], ['path' => 'world', 'classification' => 'protected']];
    return $manifest;
};
$record->update(['base_manifest_path' => $store->put($withDirectory('1234', 'value=old')),
    'target_manifest_path' => $store->put($withDirectory('1235', 'value=new'))]);
$directoryPolicy = new \Arcturus\ModpackManager\Services\DirectoryPolicy;
check($directoryPolicy->pending($record) === ['tacz'], 'Unknown upstream directory was auto-approved');
config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'retry_after' => 14520]]);
Illuminate\Support\Facades\Bus::fake([\Arcturus\ModpackManager\Jobs\CompareInstallation::class]);
$directoryView = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->getRouteKey()])->call('mountAction', 'directoryDecisions');
$formRows = $directoryView->get('mountedActions')[0]['data']['directories'];
foreach ($formRows as &$row) {
    if ($row['path'] === 'tacz') { $row['mode'] = 'keep'; }
}
unset($row);
$beforeRevision = $record->policy_revision;
$directoryView->set('mountedActions.0.data.directories', $formRows)->call('callMountedAction');
check($directoryView->instance()->getErrorBag()->isEmpty(), 'Directory decision form failed validation');
$record->refresh();
// The UI queues planning; complete the read-only fixture intent before direct service assertions.
app(\Arcturus\ModpackManager\Services\OperationTracker::class)->transition($record->operations()->findOrFail($record->active_operation_id), 'completed');
$record->refresh();
check($record->policy_revision === $beforeRevision + 1 && ! $record->approved_plan_sha256, 'Directory review did not invalidate approval');
check($directoryPolicy->pending($record) === [] && in_array('tacz', $directoryPolicy->scope($record)['exclude'], true), 'Keep-local decision was not persisted');
$plan = $previewer->generate($record);
check(! isset(array_column($plan['changes'], 'action', 'path')['tacz/assets/gun.json']), 'Unchanged excluded file should not enter the delta');
check($store->load($record->base_manifest_path)['files']['tacz/assets/gun.json'] === $hash('{}'), 'Directory review changed immutable evidence');
rejects(fn () => app(ServerPolicyService::class)->save($record, [], [], $record->policy_revision, ['directory_decisions' => [['path' => 'unobserved', 'mode' => 'manage']]]), 'Unobserved directory was approved');
// A later release asks only about its new directory and keeps previous choices.
$next = $withDirectory('1235', 'value=new');
$next['files']['new-assets/item.json'] = $hash('{}');
$next['directory_discovery'][] = ['path' => 'new-assets', 'classification' => 'review'];
$record->update(['target_manifest_path' => $store->put($next)]);
$newReview = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->getRouteKey()])
    ->call('selectTab', 'updates')->call('mountAction', 'directoryDecisions');
$newRows = $newReview->get('mountedActions')[0]['data']['directories'];
check(array_column($newRows, 'path') === ['new-assets'], 'Update review repeated an existing directory decision');
foreach ($newRows as &$row) { $row['mode'] = 'keep'; }
unset($row);
$newReview->set('mountedActions.0.data.directories', $newRows)->call('callMountedAction');
$record->refresh();
check(array_column($record->directory_decisions, 'mode', 'path')['tacz'] === 'keep', 'Review of a new directory erased a saved choice');
check($directoryPolicy->pending($record) === [], 'New directory choice did not persist');
app(\Arcturus\ModpackManager\Services\OperationTracker::class)->transition($record->operations()->findOrFail($record->active_operation_id), 'completed');
$record->refresh();

// Visiting Updates queues one expired-plan check, never a deployment or repeated jobs.
$record->update(['comparison_status' => 'complete', 'last_preview_at' => now()->subMinutes(16),
    'preview_summary' => [...$record->preview_summary, 'applicable' => true]]);
$beforeChecks = $record->operations()->where('kind', 'comparison')->count();
$expiredView = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->getRouteKey()])->call('selectTab', 'updates');
$record->refresh();
check($record->active_operation_id !== null, 'Expired readiness was not refreshed automatically');
$expiredView->call('pollEvidence');
check($record->operations()->where('kind', 'comparison')->count() === $beforeChecks + 1, 'Polling duplicated an automatic comparison');
app(\Arcturus\ModpackManager\Services\OperationTracker::class)->transition($record->operations()->findOrFail($record->active_operation_id), 'failed');
$record->refresh()->update(['comparison_status' => 'failed']);
$expiredView->call('pollEvidence');
check($record->operations()->where('kind', 'comparison')->count() === $beforeChecks + 1, 'Failed comparison was retried in a loop');
$record->update(['directory_decisions' => [], 'base_manifest_path' => $originalBase, 'target_manifest_path' => $originalTarget]);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($dir);
// A policy edit during a remote comparison must never resurrect an old approval.
$record->update(['transport' => 'wings', 'approved_plan_sha256' => str_repeat('b', 64), 'approved_at' => now()]);
config(['arcturus-modpack-manager.nodes.'.$record->pelican_server_uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('x', 64)]]);
Http::fake(function ($request) use ($record, $hash) {
    if (str_ends_with($request->url(), '/capabilities')) { return Http::response(['scoped_inventory' => 1, 'directory_policy' => 1]); }
    $fresh = ManagedServer::findOrFail($record->id);
    app(ServerPolicyService::class)->save($fresh, [['pattern' => 'config/**', 'mode' => 'protect']], [], $fresh->policy_revision);

    return Http::response(['complete' => true, 'scope' => 'manifest-delta-v1', 'requested_paths' => $request['paths'], 'missing' => array_values(array_diff($request['paths'], ['config/example.properties'])), 'files' => ['config/example.properties' => $hash('value=custom')], 'identities' => [], 'excluded_roots' => ['custom-world']]);
});
rejects(fn () => $previewer->generate($record), 'Stale comparison overwrote a concurrent policy edit');
$record->refresh();
check(! $record->approved_plan_sha256 && $record->comparison_status !== 'complete', 'Concurrent policy change restored a stale plan/approval');
// Edit the actual native form and submit its visible header Save action.
$record->update(['provider' => 'github', 'source' => 'fixture/original', 'installed_version' => '1.0',
    'baseline_status' => 'verified', 'base_manifest_path' => '/fixture/old-manifest',
    'selected_version' => '2.0', 'target_manifest_path' => '/fixture/old-target']);
$priorRules = $record->file_rules;
$edit = Livewire\Livewire::test(\Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer::class, ['record' => $record->id]);
check(str_contains($edit->html(), 'Save changes'), 'Edit form has no visible header Save action');
check($edit->instance()->areFormActionsSticky(), 'Bottom save actions are not sticky');
foreach (['provider', 'source', 'installed_version', 'pelican_server_uuid'] as $field) {
    check(! $edit->instance()->getSchema('form')->getComponent($field)->isDisabled(), 'Edit field is still locked: '.$field);
}
$edit->set('data.provider', 'modrinth')->set('data.source', 'reviewed-pack')->set('data.installed_version', '0.13.5')->set('data.label', 'Edited fixture')
    ->call('mountAction', 'saveChanges')->call('callMountedAction');
check($edit->instance()->getErrorBag()->isEmpty(), 'Native Save action returned validation errors: '.json_encode($edit->instance()->getErrorBag()->toArray()));
$record->refresh();
check($record->provider === 'modrinth' && $record->source === 'reviewed-pack' && $record->installed_version === '0.13.5' && $record->label === 'Edited fixture', 'Editable release association did not persist');
check($record->baseline_status === 'unverified' && ! $record->base_manifest_path && ! $record->target_manifest_path && ! $record->selected_version && ! $record->preview_summary && ! $record->deploy_enabled, 'Changed source retained stale evidence or enabled deployment');
check($record->file_rules === $priorRules, 'Source edit changed protection policies');
$edit->set('data.provider', 'gtnh')->set('data.gtnh_variant', 'java17plus')->set('data.installed_version', '2.8.1')
    ->call('mountAction', 'saveChanges')->call('callMountedAction');
check($edit->instance()->getErrorBag()->isEmpty(), 'Native GTNH source edit returned validation errors');
$record->refresh();
check($record->provider === 'gtnh' && $record->source === 'gtnh' && $record->asset_pattern === 'java17plus' &&
    $record->installed_version === '2.8.1' && $record->baseline_status === 'unverified' && $record->file_rules === $priorRules, 'GTNH source edit lost fixed source, variant, or policies');
$reopened = Livewire\Livewire::test(\Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer::class, ['record' => $record->id]);
check($reopened->get('data.gtnh_variant') === 'java17plus', 'GTNH variant was not restored in the edit form');
rejects(fn () => app(ServerPolicyService::class)->save($record, $priorRules, [], $record->policy_revision, ['pelican_server_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']), 'Unknown Pelican server binding accepted');
DB::table('servers')->insert(['uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'New binding fixture']);
$gtnhWizard = Livewire\Livewire::test(CreateManagedServer::class)->set('data.label', 'GTNH official draft')
    ->set('data.pelican_server_uuid', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc')->set('data.provider', 'gtnh')->set('data.installed_version', '2.8.1');
$gtnhWizard->call('create');
check($gtnhWizard->instance()->getErrorBag()->has('data.gtnh_variant'), 'GTNH adoption accepted a missing Java variant');
check(($gtnhWizard->instance()->getSchema('form')->getComponent('policy_template')->getOptions()['gtnh_arcturus'] ?? null)
    === 'GTNH / Crucible + Arcturus Network', 'Combined GTNH preset was not available in the wizard');
$gtnhWizard->set('data.gtnh_variant', 'java17plus')->set('data.policy_template', 'gtnh_arcturus')->call('create');
check($gtnhWizard->instance()->getErrorBag()->isEmpty(), 'Native GTNH adoption returned validation errors');
$gtnhDraft = ManagedServer::where('label', 'GTNH official draft')->firstOrFail();
check($gtnhDraft->provider === 'gtnh' && $gtnhDraft->source === 'gtnh' && $gtnhDraft->asset_pattern === 'java17plus' &&
    $gtnhDraft->baseline_status === 'unverified' && ! $gtnhDraft->deploy_enabled, 'GTNH adoption did not persist the fixed source, exact variant or safe defaults');
check($gtnhDraft->file_rules === (new \Arcturus\ModpackManager\Services\ProtectionPolicy)->template('gtnh_arcturus'), 'Selecting the combined GTNH preset did not persist its rules');
$gtnhDraft->delete();
$record->update(['local_root' => '/fixture/old-live', 'staging_parent' => '/fixture/old-stage', 'last_backup_uuid' => 'old-backup', 'runtime_report' => ['state' => 'running']]);
app(ServerPolicyService::class)->save($record, $priorRules, [], $record->policy_revision, ['pelican_server_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
$record->refresh();
check(! $record->local_root && ! $record->staging_parent && ! $record->last_backup_uuid && ! $record->runtime_report, 'Rebinding inherited another server filesystem/runtime/backup state');
$revision = $record->policy_revision;
$record->update(['active_operation_id' => 'fixture-active']);
rejects(fn () => app(ServerPolicyService::class)->save($record, [], [], $revision), 'Active operation allowed policy edit');
$record->update(['active_operation_id' => null]);
Auth::guard()->forgetUser();
rejects(fn () => app(ServerPolicyService::class)->save($record, [], [], $revision), 'Guest changed protection rules');
rejects(fn () => Livewire\Livewire::test(CreateManagedServer::class), 'Guest mounted adoption wizard');
echo "Native wizard steps, visible submission, draft/monitoring creation, validation, server isolation, audit, reload and approval invalidation passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
