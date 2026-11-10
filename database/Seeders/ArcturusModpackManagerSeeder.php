<?php

declare(strict_types=1);
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Pelican rc.2 invokes this hook on installation and every installed-plugin upgrade. */
final class ArcturusModpackManagerSeeder extends Seeder
{
    public function run(): void
    {
        // Laravel checks this shared-cache signal between jobs. Never kill an active
        // deployment, backup or recovery process; the ordinary supervisor restarts it.
        try {
            if (Artisan::call('queue:restart') !== 0) { throw new RuntimeException('Graceful reload command failed'); }
        } catch (Throwable) {
            // Optional lifecycle assistance must never make an installed plugin unusable.
            // Report fixed guidance, never the raw exception, path or credentials.
            $message = 'Plugin installation completed, but automatic queue reload could not be requested. Check Panel cache write permissions for the web/queue account. Let active operations finish before reloading workers through the normal supervisor.';
            try { Log::warning('Arcturus: '.$message); } catch (Throwable) { /* A broken logger must not fail installation either. */ }
            if (! app()->runningInConsole()) {
                try { \Filament\Notifications\Notification::make()->title('Queue reload needs attention')->body($message)->warning()->persistent()->send(); }
                catch (Throwable) { /* API/console installs may not have a notification session. */ }
            }
        }
    }
}
