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
$snapshotDir = getenv('ARCTURUS_UI_SNAPSHOTS');
if ($snapshotDir && ! is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0700, true);
}
foreach (['overview', 'updates', 'changes', 'protection', 'backups', 'history'] as $tab) {
    $test->call('selectTab', $tab);
    $html = $test->html();
    check(str_contains($html, 'Modpack management'), 'Native tab rendering failed: '.$tab);
    if ($tab === 'updates') {
        $dom = new DOMDocument;
        @$dom->loadHTML('<meta charset="utf-8">'.$html);
        check((new DOMXPath($dom))->query('//button[contains(., "Apply Update") and @disabled]')->length === 1,
            'Destructive update control was not disabled');
    }
    if ($snapshotDir) {
        $manifest = json_decode(file_get_contents(getenv('PELICAN_SOURCE').'/public/build/manifest.json'), true);
        $css = $manifest['resources/css/app.css']['file'];
        file_put_contents($snapshotDir.'/'.$tab.'.html', '<!doctype html><html lang="en" class="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/'.$css.'"></head><body class="bg-gray-50 text-gray-950 dark:bg-gray-950 dark:text-white" style="padding:16px">'.Blade::render('@filamentStyles').$html.'</body></html>');
    }
}
Auth::guard()->forgetUser();
rejects(fn () => Livewire\Livewire::test(ViewManagedServer::class, ['record' => $record->id]), 'Guest mounted the managed-server review page');
echo "All native Livewire detail tabs rendered; destructive control and guest access guards passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
