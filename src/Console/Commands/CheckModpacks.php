<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\ReleaseChecker;
use Illuminate\Console\Command;

final class CheckModpacks extends Command
{
    protected $signature = 'arcturus-modpack:check {--server= : Managed server database ID}';
    protected $description = 'Check configured modpack sources for new stable releases';

    public function handle(ReleaseChecker $checker): int
    {
        $query = ManagedServer::query()->where('enabled', true);
        if ($id = $this->option('server')) {
            $query->whereKey((int) $id);
        }
        $failed = 0;
        $query->orderBy('id')->chunkById(50, function ($servers) use ($checker, &$failed): void {
            foreach ($servers as $server) {
                $result = $checker->check($server);
                $this->line("{$server->label}: {$result['state']}" .
                    (isset($result['version']) ? " ({$result['version']})" : ''));
                $failed += (int) ($result['state'] === 'failed');
            }
        });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
