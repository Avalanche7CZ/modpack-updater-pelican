<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
use App\Models\User;
use Arcturus\ModpackManager\Filament\Admin\Pages\Overview;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ListManagedServers;
use Arcturus\ModpackManager\Filament\Server\Pages\ArcturusModpack;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

$source = realpath(dirname(__DIR__, 2));
$pluginPath = plugin_path('arcturus-modpack-manager');
if (! is_dir(dirname($pluginPath))) {
    mkdir(dirname($pluginPath), 0700, true);
}
if (! file_exists($pluginPath)) {
    symlink($source, $pluginPath);
}
check(realpath($pluginPath) === $source, 'Disposable test plugin directory already points elsewhere');
$plugin = ModpackManagerPlugin::make();
$admin = Filament::getPanel('admin');
$server = Filament::getPanel('server');
$plugin->register($admin);
$plugin->register($server);
check(in_array(Overview::class, $admin->getPages(), true), 'Admin overview was not discovered');
check(in_array(ManagedServerResource::class, $admin->getResources(), true), 'Managed-server resource not registered');
check(in_array(ArcturusModpack::class, $server->getPages(), true), 'Isolated server page was not discovered');
check(! in_array(ManagedServerResource::class, $server->getResources(), true), 'Admin resources leaked to server panel');
Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool
    {
        return true;
    }
});
Filament::setCurrentPanel($admin);
$create = app(CreateManagedServer::class);
check(count($create->getSteps()) === 4, 'Native adoption wizard is incomplete');
$schema = ManagedServerResource::form(Schema::make($create));
check(count($schema->getComponents()) > 0, 'Managed-server form did not construct');
$table = ManagedServerResource::table(Table::make(app(ListManagedServers::class)));
check(count($table->getColumns()) >= 10, 'Native table did not construct');
check(count($plugin->getSettingsForm()) >= 3, 'Native plugin settings did not construct');
foreach (glob($source.'/resources/views/*.blade.php') as $path) {
    $compiled = app('blade.compiler')->compileString(file_get_contents($path));
    $temp = tempnam(sys_get_temp_dir(), 'arcturus-blade-').'.php';
    file_put_contents($temp, $compiled);
    $result = [];
    exec(escapeshellarg(PHP_BINARY).' -n -l '.escapeshellarg($temp).' 2>&1', $result, $status);
    unlink($temp);
    check($status === 0, 'Invalid compiled Blade template: '.$path.' '.implode(' ', $result));
}
Auth::guard()->forgetUser();
check(! Overview::canAccess() && ! ManagedServerResource::canCreate(), 'Guest reached administrative UI');
echo "Native Filament discovery, wizard/form/table/settings construction and Blade compilation passed\n";

$GLOBALS['arcturus_integration_passed'] = true;
