<?php

declare(strict_types=1);
$app = require dirname(__DIR__).'/bootstrap.php';
$source = dirname(__DIR__, 3);
use App\Models\User;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use App\Events\ActivityLogged;
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
/**
 * Real Pelican DaemonServerRepository::power() writes activity records.
 * Supply the same audit tables it needs in this disposable in-memory DB,
 * instead of mocking out the repository's native side effects.
 */
Schema::create('activity_logs', function (Blueprint $t) {
    $t->id();
    $t->string('ip');
    $t->text('properties');
    $t->unsignedBigInteger('api_key_id')->nullable();
    $t->unsignedBigInteger('actor_id')->nullable();
    $t->string('actor_type')->nullable();
    $t->string('event');
    $t->text('description')->nullable();
    $t->timestamp('timestamp')->nullable();
});
Schema::create('activity_log_subjects', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('activity_log_id');
    $t->unsignedBigInteger('subject_id');
    $t->string('subject_type');
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
use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Jobs\SendDiscordNotification;
use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use App\Http\Controllers\Api\Remote\Backups\BackupStatusController;
use App\Http\Requests\Api\Remote\ReportBackupCompleteRequest;
use App\Models\Backup;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\DeploymentApproval;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Illuminate\Http\Request;

Schema::table('servers', function (Blueprint $t) {
    $t->unsignedBigInteger('node_id')->default(1); $t->unsignedBigInteger('owner_id')->default(1);
    $t->unsignedBigInteger('egg_id')->nullable(); $t->string('status')->nullable(); $t->integer('backup_limit')->default(20);
    $t->boolean('suspended')->default(false); $t->timestamps();
});
Schema::create('subusers', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('user_id'); $t->json('permissions')->nullable(); });
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('uuid'); $t->string('username'); $t->string('email'); $t->string('password'); $t->timestamps(); });
Schema::create('server_transfers', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->boolean('successful')->nullable(); });
Schema::create('nodes', function (Blueprint $t) {
    $t->id(); $t->string('uuid'); $t->string('daemon_base'); $t->string('scheme'); $t->string('fqdn');
    $t->boolean('maintenance_mode')->default(false); $t->integer('daemon_connect'); $t->text('daemon_token'); $t->string('daemon_token_id'); $t->timestamps();
});
Schema::create('backup_hosts', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('schema'); $t->json('configuration')->nullable(); $t->timestamps(); });
Schema::create('backup_host_node', function (Blueprint $t) { $t->unsignedBigInteger('backup_host_id'); $t->unsignedBigInteger('node_id'); });
Schema::create('backups', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('server_id'); $t->string('uuid'); $t->string('name'); $t->json('ignored_files');
    $t->unsignedBigInteger('backup_host_id'); $t->boolean('is_successful')->default(false); $t->boolean('is_locked')->default(false);
    $t->boolean('is_scheduled')->default(false); $t->string('checksum')->nullable(); $t->unsignedBigInteger('bytes')->default(0);
    $t->string('upload_id')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
});
Schema::create('jobs', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
    $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
});
Schema::create('allocations', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id')->nullable(); $t->unsignedBigInteger('node_id'); $t->string('ip'); $t->integer('port'); });
Schema::create('mounts', function (Blueprint $t) { $t->id(); });
Schema::create('mountables', function (Blueprint $t) { $t->unsignedBigInteger('mount_id'); $t->unsignedBigInteger('mountable_id'); $t->string('mountable_type'); });
Schema::create('egg_variables', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('egg_id'); $t->string('env_variable'); $t->string('default_value')->nullable(); });
Schema::create('server_variables', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('variable_id'); $t->string('variable_value')->nullable(); });
$egg = new \App\Models\Egg;
$egg->forceFill(['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'force_outgoing_ip' => false, 'file_denylist' => [], 'features' => []]);
Server::retrieved(function (Server $server) use ($egg): void {
    $server->setRelation('egg', $egg)->setRelation('allocations', new \Illuminate\Database\Eloquent\Collection)->setRelation('allocation', null)->setRelation('mounts', new \Illuminate\Database\Eloquent\Collection);
});
$pluginPath = plugin_path('arcturus-modpack-manager');
if (! is_dir(dirname($pluginPath))) { mkdir(dirname($pluginPath), 0700, true); }
if (! file_exists($pluginPath)) { symlink($source, $pluginPath); }
$app->register(\Arcturus\ModpackManager\Providers\ModpackManagerServiceProvider::class);
DB::table('users')->insert(['id' => 1, 'uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'username' => 'fixture', 'email' => 'fixture@example.test', 'password' => 'unused']);
$node = new Node;
$node->forceFill(['id' => 1, 'uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'daemon_base' => '/fixture',
    'scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => str_repeat('x', 64), 'daemon_token_id' => 'fixture']);
DB::table('nodes')->insert(array_intersect_key($node->getAttributes(), array_flip(Schema::getColumnListing('nodes'))));
DB::table('backup_hosts')->insert(['id' => 1, 'name' => 'Wings fixture', 'schema' => 'wings', 'configuration' => '{}']);
DB::table('backup_host_node')->insert(['backup_host_id' => 1, 'node_id' => 1]);
$adminUser = Auth::user(); $adminUser->id = 1;
Event::fake([ActivityLogged::class, \App\Events\Server\BackupCompleted::class]);
config(['backups.throttles.period' => 0, 'backups.throttles.limit' => 100,
    'queue.default' => 'deployment-fixture', 'queue.connections.deployment-fixture' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 14520],
    'queue.failed.driver' => null, 'arcturus-modpack-manager.deployment_guard_timeout' => 1, 'arcturus-modpack-manager.deployment_start_timeout' => 2,
    'arcturus-modpack-manager.deployment_backup_timeout' => 1, 'arcturus-modpack-manager.deployment_restore_timeout' => 1,
    'arcturus-modpack-manager.discord_events' => false]);
Http::preventStrayRequests();
