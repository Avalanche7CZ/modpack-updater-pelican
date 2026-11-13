<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Arcturus\ModpackManager\ConsoleIntegration\ConsoleController;
use Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory;
use Arcturus\ModpackManager\ConsoleIntegration\ConsoleIntegration;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\OperationTracker;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Schema::create('webhook_configurations', function (Blueprint $t): void {
    $t->id(); $t->string('scope'); $t->json('events'); $t->softDeletes();
});
Schema::create('servers', function (Blueprint $t): void {
    $t->id(); $t->string('uuid'); $t->string('name'); $t->unsignedBigInteger('owner_id'); $t->string('status')->nullable();
});
Schema::create('subusers', function (Blueprint $t): void {
    $t->id(); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('user_id'); $t->json('permissions');
});
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) { (require $path)->up(); }
require dirname(__DIR__, 2).'/resources/console-routes.php';
Route::getRoutes()->refreshNameLookups();
$route = Route::getRoutes()->getByName('arcturus-modpack.console.lines');
check(in_array('auth', $route->gatherMiddleware()) && in_array('auth.session', $route->gatherMiddleware()), 'Feed lacks session authentication');
Route::get('/fixture/managed/{record}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.view');
$server = function (int $id): Server {
    DB::table('servers')->insert(['id' => $id, 'uuid' => (string) Str::uuid(), 'name' => 'Console fixture', 'owner_id' => $id === 1 ? 10 : 20, 'status' => null]);
    return Server::findOrFail($id);
};
$a = $server(1); $b = $server(2); $unmanaged = $server(3);
$managed = fn ($server) => ManagedServer::create(['label' => 'Fixture', 'pelican_server_uuid' => $server->uuid, 'provider' => 'custom', 'source' => 'fixture', 'installed_version' => '1']);
$ma = $managed($a); $mb = $managed($b);
$root = (new class extends User { public function isRootAdmin(): bool { return true; } public function checkPermissionTo($permission, $guardName = null): bool { return true; } })->forceFill(['id' => 1]);
$owner = (new class extends User { public function isRootAdmin(): bool { return false; } public function checkPermissionTo($permission, $guardName = null): bool { return false; } })->forceFill(['id' => 10]);
$subuser = clone $owner; $subuser->id = 11;
DB::table('subusers')->insert(['server_id' => $a->id, 'user_id' => 11,
    'permissions' => json_encode([SubuserPermission::FileRead->value, SubuserPermission::WebsocketConnect->value])]);
// Keep global admin policies deterministic; native User::can still resolves owner/subuser server permissions.
Gate::before(fn ($user) => $user->id === 1);
Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($a, true);
ConsoleIntegration::register(Filament::getPanel('server'));
$headers = (new ReflectionMethod(\App\Filament\Server\Pages\Console::class, 'getHeaderActions'))->invoke(app(\App\Filament\Server\Pages\Console::class));
check($headers[0] instanceof \Filament\Actions\ActionGroup && end($headers)->getName() === 'arcturusUpdate', 'RC2 native power actions were replaced or Update was not appended');
Auth::guard()->forgetUser();
check(! ConsoleIntegration::canUpdate($a) && ! ConsoleIntegration::action()->isVisible(), 'Guest sees Update');
rejects(fn () => ConsoleIntegration::updateUrl($a), 'Guest opened updater');
Auth::guard()->setUser($owner);
check(! ConsoleIntegration::canUpdate($a) && ! ConsoleIntegration::action()->isAuthorized(), 'Owner gained manual-update authority');
rejects(fn () => ConsoleIntegration::updateUrl($a), 'Owner bypassed backend update gate');
$redirect = new class { public ?string $url = null; public function redirect(string $url): void { $this->url = $url; } };
$invoke = ConsoleIntegration::action()->getActionFunction();
rejects(fn () => $invoke($redirect), 'Forged hidden action bypassed the backend permission check');
Auth::guard()->setUser($root);
check(ConsoleIntegration::action()->isVisible() && ConsoleIntegration::action()->isAuthorized(), 'Authorized administrator cannot use Update');
check(! ConsoleIntegration::canUpdate($unmanaged), 'Unmanaged server shows Update');
rejects(fn () => ConsoleIntegration::updateUrl($unmanaged), 'Unmanaged server opened update');
$tracker = new OperationTracker;
$op = $tracker->create($ma, 'deploy');
Route::getRoutes()->refreshNameLookups();
$url = ConsoleIntegration::updateUrl($a);
check(str_contains($url, (string) $ma->id) && str_contains($url, 'tab=updates'), 'Console link lost server or Updates tab');
$source = dirname(__DIR__, 2);
app('view')->addNamespace('arcturus-modpack-manager', $source.'/resources/views');
$pluginPath = plugin_path('arcturus-modpack-manager');
if (!is_dir(dirname($pluginPath))) { mkdir(dirname($pluginPath), 0700, true); }
if (!file_exists($pluginPath)) { symlink($source, $pluginPath); }
check(realpath($pluginPath) === $source, 'Disposable plugin path points elsewhere');
check(\Arcturus\ModpackManager\ConsoleIntegration\ConsoleOutput::canView(), 'Authorized account cannot see fallback');
$widget = Livewire\Livewire::test(\Arcturus\ModpackManager\ConsoleIntegration\ConsoleOutput::class);
check(str_contains($widget->html(), 'Modpack update') && str_contains($widget->html(), 'arcturus-modpack-output-'.$a->uuid), 'Native fallback widget did not render for this server');
$invoke($redirect);
check($redirect->url === $url, 'Backend action did not redirect into the existing review flow');
check(Operation::count() === 1 && $ma->fresh()->active_operation_id === $op->id, 'Opening Update created another operation');
$op->update(['phase' => 'backing_up', 'progress' => ['message' => 'https://secret.test/?token=private-token']]);
$other = $tracker->create($mb, 'deploy'); $other->update(['phase' => 'restoring']);
$controller = new ConsoleController;
$read = fn ($server, $after = null) => $controller(Request::create('/fixture', 'GET', $after === null ? [] : ['after' => $after]), $server->uuid)->getData(true);
Auth::guard()->setUser($owner);
$first = $read($a);
check(str_contains(end($first['lines'])['text'], 'locked Pelican backup'), 'Offline backup phase is absent');
check(! str_contains(json_encode($first), 'private-token') && ! str_contains(json_encode($first), 'restoring'), 'Cross-server data or secret leaked');
rejects(fn () => $read($b), 'Owner read another server feed');
Auth::guard()->setUser($subuser);
check(! ConsoleIntegration::canUpdate($a) && count($read($a)['lines']) > 0, 'Read-only subuser update/feed permissions are incorrect');
rejects(fn () => $read($b), 'Subuser read another server feed');
DB::table('subusers')->where('user_id', 11)->update(['permissions' => '[]']);
\Illuminate\Support\Facades\Context::flush();
rejects(fn () => $read($a), 'Revoked subuser retained feed access on the next request');
Auth::guard()->forgetUser(); rejects(fn () => $read($a), 'Guest read console history'); Auth::guard()->setUser($owner);
check($read($a, $first['cursor'])['lines'] === [], 'Reconnect duplicated existing lines');
$op->update(['phase' => 'applying', 'progress' => ['files_done' => 1, 'files_total' => 340]]);
$next = $read($a, $first['cursor']);
check(count($next['lines']) === 1 && $next['lines'][0]['id'] > $first['cursor'], 'Reconnect missed or duplicated new progress');
$count = count(ConsoleHistory::read($ma->id, null)['lines']);
$op->update(['progress' => ['files_done' => 2, 'files_total' => 340]]);
check(count(ConsoleHistory::read($ma->id, null)['lines']) === $count, 'Per-file spam was emitted');
\Illuminate\Support\Carbon::setTestNow(now()->addSeconds(16));
$op->update(['progress' => ['files_done' => 112, 'files_total' => 340]]);
check(str_contains(end($read($a)['lines'])['text'], '112 / 340'), 'Throttled real progress missing');
\Illuminate\Support\Carbon::setTestNow();
$contextBefore = $op->getRawOriginal('context');
$applyReport = app(\Arcturus\ModpackManager\Services\ApplyProgress::class)->reporter($op);
$applyReport('stage_verification', 0, 3);
check($op->fresh()->progress['step'] === 'stage_verification', 'Initial Applying step missing before checkpoint');
$applyReport('stage_verification', 1, 3);
check($op->fresh()->progress['files_done'] === 0, 'Progress was persisted per file');
$applyReport('upload', 0, 2); $applyReport('upload', 2, 2);
check($op->fresh()->progress['files_done'] === 2 && $op->fresh()->progress['files_total'] === 2, 'Confirmed upload counts missing');
check($op->fresh()->getRawOriginal('context') === $contextBefore, 'Display progress rewrote recovery receipt');
$tracker->transition($op, 'manual_intervention');
check($ma->fresh()->active_operation_id === $op->id && str_contains(end($read($a)['lines'])['text'], 'Recovery unverified'), 'Console changed recovery safety state');
$refreshed = $read($a);
check(count(array_unique(array_column($refreshed['lines'], 'id'))) === count($refreshed['lines']), 'Refresh duplicated history');
// Simulate an operation already running when this plugin upgrade was installed.
DB::table('arcturus_modpack_console_lines')->where('operation_id', $other->id)->delete();
$old = ConsoleHistory::read($mb->id, null);
check(count($old['lines']) === 1 && str_contains($old['lines'][0]['text'], 'restoring'), 'Existing receipt did not seed a truthful current-state snapshot');
echo "Console authorization, unmanaged servers, offline receipts, server isolation, refresh/reconnect and throttling passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
