<?php

declare(strict_types=1);

use App\Models\{Role, User};
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\{ArchiveTrust, ManagedServer};
use Arcturus\ModpackManager\Modpacks\{JsonClient, Providers\GtnhProvider};
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{Client\Request as WorkerRequest, Request};
use Illuminate\Support\Facades\{Artisan, Auth, Blade, DB, Http, Route, Schema, URL};
use Symfony\Component\Process\Process;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = getenv('PELICAN_SOURCE').'/public';
if (preg_match('~^/(js|css|fonts|pelican[.])/~', $path) && is_file($public.$path)) {
    return false;
}
$app = require dirname(__DIR__).'/bootstrap.php';
$GLOBALS['arcturus_integration_passed'] = true;
$root = getenv('GTNH_BROWSER_FIXTURE');
if (! $root || ! is_dir($root) || ! str_starts_with($root, sys_get_temp_dir().'/amm-gtnh-browser-')) {
    throw new RuntimeException('Use the disposable Chromium fixture runner.');
}
$database = $root.'/panel.sqlite';
if (! is_file($database)) {
    touch($database);
}
config(['app.url' => 'http://'.$_SERVER['HTTP_HOST'], 'database.connections.sqlite.database' => $database, 'logging.default' => 'stderr',
    'session.driver' => 'file', 'session.files' => $root.'/sessions', 'view.compiled' => $root.'/views',
    'queue.default' => 'archive-browser', 'queue.connections.archive-browser' => ['driver' => 'database',
        'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 14520],
    'queue.failed.driver' => null, 'arcturus-modpack-manager.discord_events' => false]);
URL::forceRootUrl(config('app.url'));
URL::useAssetOrigin(config('app.url'));
$app->useStoragePath($root.'/storage');
foreach (['sessions', 'views', 'storage/app'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        mkdir($root.'/'.$directory, 0700, true);
    }
}
if (! Schema::hasTable('users')) {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('username');
        $table->string('email');
    });
    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid');
        $table->string('name');
        $table->string('startup')->default('bash run.sh');
        $table->string('image')->default('java_21');
        $table->unsignedBigInteger('node_id')->nullable();
        $table->unsignedBigInteger('egg_id')->nullable();
    });
    Schema::create('nodes', fn (Blueprint $table) => $table->id());
    Schema::create('eggs', fn (Blueprint $table) => $table->id());
    (require getenv('PELICAN_SOURCE').'/database/migrations/2024_07_19_130942_create_permission_tables.php')->up();
    Schema::create('webhook_configurations', function (Blueprint $table): void {
        $table->id();
        $table->string('scope');
        $table->json('events');
        $table->softDeletes();
    });
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    DB::table('users')->insert(['id' => 1, 'username' => 'fixture', 'email' => 'fixture@example.test']);
    foreach (['2.9.2', '2.9.3', '2.9.4'] as $index => $version) {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
        DB::table('servers')->insert(['uuid' => $uuid, 'name' => 'GTNH fixture']);
        $server = ManagedServer::create(['label' => 'GTNH fixture '.$version, 'pelican_server_uuid' => $uuid,
            'provider' => 'gtnh', 'source' => 'gtnh', 'transport' => 'wings', 'installed_version' => $version,
            'asset_pattern' => 'java17plus', 'gtnh_trust_mode' => 'strict']);
        if ($index === 0) {
            $sha = str_repeat('a', 64);
            $baseline = app(ReleaseStore::class)->put(['schema' => 2, 'provider' => 'gtnh', 'project' => 'gtnh',
                'version' => '2.9.1', 'verification' => 'upstream-checksums', 'runtime' => ['minecraft' => '1.7.10'],
                'artifacts' => [['sha256' => $sha, 'hashes' => ['sha256' => $sha]]],
                'files' => ['config/fixture.cfg' => hash('sha256', 'previous config')]]);
            $server->update(['installed_version' => '2.9.1', 'selected_version' => $version,
                'baseline_status' => 'verified', 'base_manifest_path' => $baseline]);
        }
    }
}
User::retrieved(function (User $user): void {
    $user->setRelation('roles', new Collection([(new Role)->forceFill(['name' => Role::ROOT_ADMIN])]));
});
Auth::guard()->setUser(User::findOrFail(1));
$admin = Filament::getPanel('admin');
(new ModpackManagerPlugin)->register($admin);
Filament::setCurrentPanel($admin);
app('view')->addNamespace('arcturus-modpack-manager', dirname(__DIR__, 3).'/resources/views');

app()->instance(JsonClient::class, new class extends JsonClient {
    public function get(string $url, array $headers = []): array
    {
        check($url === GtnhProvider::INDEX, 'Unexpected upstream metadata URL');
        $catalog = [];
        foreach (['2.9.2', '2.9.3', '2.9.4'] as $version) {
            $catalog[$version] = ['title' => 'Stable release', 'maxJavaVersion' => 26,
                'server' => ['java17_2XUrl' => 'https://'.GtnhProvider::HOST.'/ServerPacks/GT_New_Horizons_'.$version.'_Server_Java_17-26.zip']];
        }
        return $catalog;
    }
});
foreach (ManagedServer::pluck('pelican_server_uuid') as $uuid) {
    config(['arcturus-modpack-manager.nodes.'.$uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('x', 64)]]);
}
Http::preventStrayRequests();
Http::fake(['https://worker.fixture.test/*' => function (WorkerRequest $request) use ($root) {
    [, , , $server, $command] = explode('/', parse_url($request->url(), PHP_URL_PATH));
    $worker = new Process(['python3', __DIR__.'/archive-browser-worker.py', $root, $server, $command],
        input: json_encode($request->data(), JSON_THROW_ON_ERROR));
    $worker->mustRun();
    return Http::response(json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR));
}]);

foreach (['index', 'create', 'edit'] as $page) {
    Route::get('/fixture/'.$page.'/{record?}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.'.$page);
}
Route::middleware('web')->get('/fixture/view/{record}', function (int $record) {
    // Official source ships Filament assets. The fixture needs no Pelican Vite bundle.
    return Blade::render(<<<'BLADE'
        <!doctype html>
        <html lang="en" class="fi">
        <head>
            <meta charset="utf-8">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            <link rel="stylesheet" href="/css/filament/filament/app.css">
            @filamentStyles
            @livewireStyles
        </head>
        <body>
            @livewire($managedPage, ['record' => $record])
            @livewire(\Filament\Livewire\Notifications::class)
            @filamentScripts(withCore: true)
            @livewireScripts
        </body>
        </html>
        BLADE, ['managedPage' => ViewManagedServer::class, 'record' => $record]);
})->name('filament.admin.resources.arcturus-managed-modpacks.view');
Route::post('/fixture/work', function () {
    Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1]);
    return response(Artisan::output());
});
Route::get('/fixture/evidence/{record}', function (int $record) use ($root) {
    $server = ManagedServer::findOrFail($record);
    $trust = ArchiveTrust::where('version', $server->targetVersion() ?? $server->installed_version)->first(['level', 'sha256', 'size']);
    return [
        'operations' => $server->operations()->orderBy('created_at')->get(['id', 'phase', 'node_operation_id']),
        'active' => $server->active_operation_id,
        'prepared' => $server->target_metadata['prepared']['archive_trust'] ?? null,
        'pin' => $trust?->level,
        'sha256' => $trust?->sha256,
        'size' => $trust?->size,
        'jobs' => DB::table('jobs')->count(),
        'downloads' => count(@file($root.'/'.$server->pelican_server_uuid.'/downloads') ?: []),
    ];
});
Route::post('/fixture/stale/{record}', fn (int $record) => ManagedServer::findOrFail($record)->update(['asset_pattern' => 'java8']));
$app['router']->getRoutes()->refreshNameLookups();
$app->handleRequest(Request::capture());
