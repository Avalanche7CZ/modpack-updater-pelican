<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Arcturus\ModpackManager\Services\PreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class PrepareUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public readonly int $managedServerId) {}

    public function handle(PrepareRelease $preparer, PreviewService $previewer,
        DiscordNotifier $discord): void
    {
        $server = ManagedServer::findOrFail($this->managedServerId);
        if (!$server->enabled || !$server->hasUpdate()) {
            throw new RuntimeException('No pending release for this server');
        }
        if (!$server->base_manifest_path) {
            throw new RuntimeException('Adopt and verify the installed upstream manifest before preparing updates');
        }
        try {
            $result = $preparer->prepare($server);
            $server->forceFill([
                'prepared_root' => $result['root'],
                'target_manifest_path' => $result['manifest'],
                'artifact_sha256' => $result['artifact_sha256'],
                'preview_summary' => null,
                'last_preview_at' => null,
            ])->save();
            $server->events()->create([
                'kind' => 'prepared',
                'status' => 'success',
                'from_version' => $server->installed_version,
                'to_version' => $server->available_version,
                'message' => 'Target release staged outside the live server',
                'details' => ['staged_root' => $result['root'], 'artifact_sha256' => $result['artifact_sha256']],
            ]);
            $preview = $previewer->generate($server);
            if ($server->policy === 'auto' &&
                config('arcturus-modpack-manager.automatic_deploy', false) &&
                config('arcturus-modpack-manager.enable_deploy', false) &&
                $server->deploy_enabled && $preview['blocked'] === 0 &&
                ($server->provider === 'ftb' || $server->available_asset_sha256 !== null)) {
                DeployManagedServer::dispatch($server->id, true);
            }
        } catch (Throwable $error) {
            $server->events()->create([
                'kind' => 'prepare',
                'status' => 'failed',
                'message' => mb_substr($error->getMessage(), 0, 1500),
            ]);
            try {
                $discord->announce($server, 'failed',
                    'Could not prepare update: ' . mb_substr($error->getMessage(), 0, 1000));
            } catch (Throwable $ignored) {
                report($ignored);
            }
            throw $error;
        }
    }
}
