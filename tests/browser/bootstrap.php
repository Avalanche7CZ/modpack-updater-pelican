<?php
/** Disposable loopback HTTP fixture; never load this router in a production Panel. */
declare(strict_types=1);
$directory = getenv('ARCTURUS_BROWSER_FIXTURE');
if (! $directory || ! is_dir($directory) || ! str_starts_with(realpath($directory), sys_get_temp_dir().'/')) { throw new RuntimeException('Private temporary fixture directory required'); }
if (! defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
if (! is_file($directory.'/fixture.sqlite')) { touch($directory.'/fixture.sqlite'); }
$_SERVER['HTTP_HOST'] ??= '127.0.0.1:8765';
putenv('ASSET_URL=http://'.$_SERVER['HTTP_HOST']);
$app = require dirname(__DIR__).'/integration/bootstrap.php';
$GLOBALS['arcturus_integration_passed'] = true;
config(['app.debug' => false, 'logging.default' => 'single', 'logging.channels.single.path' => $directory.'/laravel.log']);
config(['database.connections.sqlite.database' => $directory.'/fixture.sqlite', 'session.driver' => 'file', 'session.files' => $directory.'/sessions', 'app.url' => 'http://'.$_SERVER['HTTP_HOST']]);
Illuminate\Support\Facades\DB::purge('sqlite');
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Arcturus\ModpackManager\Models\ManagedServer;
if (! Schema::hasTable('servers')) {
    Schema::create('servers', function (Blueprint $t) { $t->id(); $t->string('uuid'); $t->string('name'); });
    Schema::create('webhook_configurations', function (Blueprint $t) { $t->id(); $t->string('scope'); $t->json('events'); $t->softDeletes(); });
    Schema::create('jobs', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) { (require $path)->up(); }
    $files = []; $changes = [];
    for ($i = 0; $i < 22000; $i++) {
        $path = ($i === 0 ? "kubejs/server_scripts/recipe-'\"&ü.js" : sprintf('kubejs/server_scripts/recipe-%05d.js', $i)); $hash = hash('sha256', $path); $files[$path] = $hash;
        $changes[] = ['path' => $path, 'action' => 'conflict', 'classification' => 'merge_conflict', 'file_state' => 'locally_modified', 'blocked' => true,
            'identity' => null, 'metadata' => null, 'rule' => 'follow', 'rule_origin' => 'Three-way comparison', 'base_sha256' => $hash, 'local_sha256' => hash('sha256', 'local '.$path), 'target_sha256' => hash('sha256', 'target '.$path)];
    }
    $manifest = ['schema' => 2, 'provider' => 'custom', 'project' => 'browser-fixture', 'version' => '0.13.15', 'verification' => 'upstream-checksums', 'files' => $files,
        'artifacts' => [['sha256' => str_repeat('a', 64), 'hashes' => ['sha256' => str_repeat('a', 64)]]], 'directory_discovery' => [['path' => 'kubejs', 'classification' => 'standard']]];
    $path = app(Arcturus\ModpackManager\Services\ReleaseStore::class)->put($manifest);
    $manifest['version'] = '0.13.17';
    $manifest['files'] = array_column($changes, 'target_sha256', 'path');
    $targetPath = app(Arcturus\ModpackManager\Services\ReleaseStore::class)->put($manifest);
    $localFiles = array_column($changes, 'local_sha256', 'path');
    file_put_contents($directory.'/inventory.json', json_encode(['complete' => true, 'files' => $localFiles, 'identities' => [], 'metadata' => [], 'excluded_roots' => ['world']]));
    DB::table('servers')->insert(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'TFG browser fixture']);
    ManagedServer::create(['label' => 'TFG 22,000-file browser fixture', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'provider' => 'custom', 'source' => 'browser-fixture', 'installed_version' => '0.13.15', 'selected_version' => '0.13.17',
        'baseline_status' => 'verified', 'base_manifest_path' => $path, 'target_manifest_path' => $targetPath, 'transport' => 'wings', 'adopted_at' => now(),
        'directory_decisions' => [['path' => 'kubejs', 'mode' => 'manage']], 'file_rules' => [], 'disabled_mods' => [],
        'file_inventory' => ['files' => $localFiles, 'identities' => [], 'metadata' => [], 'excluded_roots' => ['world']], 'preview_summary' => ['from' => '0.13.15', 'to' => '0.13.17', 'policy_revision' => 0, 'changes' => $changes, 'blocked' => 22000]]);
    unset($files, $changes, $manifest, $localFiles);
}
Illuminate\Support\Facades\Auth::guard()->setUser(new class extends App\Models\User {
    public function isRootAdmin(): bool { return true; }
    public function checkPermissionTo($permission, $guardName = null): bool { return true; }
});
$panel = Filament\Facades\Filament::getPanel('admin');
(new Arcturus\ModpackManager\ModpackManagerPlugin)->register($panel);
Filament\Facades\Filament::setCurrentPanel($panel);
$app->instance('arcturus.browser.directory', $directory);
app('view')->addNamespace('arcturus-modpack-manager', dirname(__DIR__, 2).'/resources/views');
config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 14520, 'after_commit' => false]]);
if (PHP_SAPI !== 'cli') {
    if (is_file($directory.'/queue-off')) { config(['queue.connections.database.driver' => 'sync']); }
    $app->bind(Arcturus\ModpackManager\Services\PreviewService::class, fn () => throw new RuntimeException('Synchronous comparison attempted in HTTP'));
    $app->bind(Arcturus\ModpackManager\Services\AdoptionService::class, fn () => throw new RuntimeException('Synchronous inventory attempted in HTTP'));
}
DB::listen(function ($query) use ($directory): void {
    file_put_contents($directory.'/queries.jsonl', json_encode(['sql' => $query->sql, 'ms' => $query->time])."\n", FILE_APPEND);
});

return $app;
