<?php
/** Serve only on loopback with a disposable official Pelican checkout. */
declare(strict_types=1);
$source = getenv('PELICAN_SOURCE');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($uri, '/build/') || str_starts_with($uri, '/js/') || str_starts_with($uri, '/css/') || str_starts_with($uri, '/fonts/')) {
    $asset = realpath($source.'/public'.$uri);
    if ($asset && str_starts_with($asset, realpath($source.'/public').'/') && is_file($asset)) {
        header('Content-Type: '.match(pathinfo($asset, PATHINFO_EXTENSION)) { 'css' => 'text/css', 'js' => 'application/javascript', 'woff2' => 'font/woff2', default => 'application/octet-stream' }); readfile($asset); return;
    }
}
$app = require __DIR__.'/bootstrap.php';
foreach (['index', 'create', 'view', 'edit'] as $page) { Illuminate\Support\Facades\Route::get('/fixture/'.$page.'/{record?}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.'.$page); }
Illuminate\Support\Facades\Route::middleware('web')->get('/fixture', function () {
    $html = Livewire\Livewire::mount(Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer::class, ['record' => 1]);
    return Illuminate\Support\Facades\Blade::render('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">@filamentStyles @livewireStyles</head><body class="fi" style="padding:20px">{!! $html !!} @livewire("notifications") @filamentScripts @livewireScripts</body></html>', ['html' => $html]);
});
Illuminate\Support\Facades\URL::forceRootUrl('http://'.$_SERVER['HTTP_HOST']);
Illuminate\Support\Facades\URL::useAssetOrigin('http://'.$_SERVER['HTTP_HOST']);
Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
$request = Illuminate\Http\Request::capture();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
file_put_contents($directory.'/requests.jsonl', json_encode(['method' => $request->method(), 'uri' => $request->path(), 'status' => $response->getStatusCode(), 'peak_mib' => memory_get_peak_usage(true) / 1048576])."\n", FILE_APPEND);
$response->send(); $kernel->terminate($request, $response);
