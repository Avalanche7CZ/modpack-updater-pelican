<?php

declare(strict_types=1);
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** Pelican rc.2 invokes this hook on installation and every installed-plugin upgrade. */
final class ArcturusModpackManagerSeeder extends Seeder
{
    public function run(): void
    {
        // Laravel checks this shared-cache signal between jobs. Never kill an active
        // deployment, backup or recovery process; the ordinary supervisor restarts it.
        if (Artisan::call('queue:restart') !== 0) {
            throw new RuntimeException('Could not request a graceful queue reload. Run php artisan queue:restart as the Panel account and verify its normal worker supervisor.');
        }
    }
}
